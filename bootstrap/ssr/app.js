import { useCallback, useEffect, useState } from "react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
//#region resources/js/api.js
var currentUserId = localStorage.getItem("night-audit-user-id") || "";
function setCurrentUserId(id) {
	currentUserId = id;
	localStorage.setItem("night-audit-user-id", id);
}
function getCurrentUserId() {
	return currentUserId;
}
async function api(path, { method = "GET", body } = {}) {
	const res = await fetch(`/api${path}`, {
		method,
		headers: {
			"Accept": "application/json",
			...body ? { "Content-Type": "application/json" } : {},
			...currentUserId ? { "X-User-Id": currentUserId } : {}
		},
		body: body ? JSON.stringify(body) : void 0
	});
	const data = res.status === 204 ? null : await res.json().catch(() => null);
	if (!res.ok) {
		const err = new Error(data?.message || `请求失败（${res.status}）`);
		err.status = res.status;
		err.data = data;
		throw err;
	}
	return data;
}
function fen(cents, { sign = true } = {}) {
	if (cents === null || cents === void 0) return "—";
	const text = `¥${(Math.abs(cents) / 100).toLocaleString("zh-CN", { minimumFractionDigits: 2 })}`;
	if (!sign) return text;
	if (cents < 0) return `-${text}`;
	return text;
}
var TYPE_LABELS = {
	room_charge: "房费",
	addon: "加购",
	payment: "收款",
	refund: "退款",
	adjustment: "调整"
};
var ADJ_TYPE_LABELS = {
	late_payment: "迟到收款",
	room_charge_correction: "房费更正",
	addon_correction: "加购更正",
	refund_correction: "退款更正"
};
var ROLE_LABELS = {
	auditor: "夜审员",
	frontdesk: "前台",
	finance: "财务"
};
//#endregion
//#region resources/js/pages/NightAudit.jsx
/**
* 夜审页面：汇总房费/加购/现金/退款/调整，展开房单逐项核对，
* 全部核对后方可封账；封账后展示不可修改的快照。
*/
function NightAudit({ me }) {
	const [data, setData] = useState(null);
	const [error, setError] = useState("");
	const [notice, setNotice] = useState("");
	const [busy, setBusy] = useState(false);
	const load = useCallback(() => {
		setError("");
		api("/audit/current").then(setData).catch((e) => setError(e.message));
	}, []);
	useEffect(load, [load]);
	async function verify(folioId) {
		setBusy(true);
		setNotice("");
		try {
			setData(await api(`/audit/folios/${folioId}/verify`, { method: "POST" }));
		} catch (e) {
			setError(e.message);
		} finally {
			setBusy(false);
		}
	}
	async function close() {
		if (!window.confirm(`确认封账营业日 ${data.day.date}？封账后快照不可修改。`)) return;
		setBusy(true);
		setError("");
		try {
			const res = await api("/audit/close", { method: "POST" });
			setNotice(`${res.message}（快照净额 ${fen(res.snapshot.net_cents)}，操作人 ${res.snapshot.closed_by}）`);
			load();
		} catch (e) {
			setError(e.message);
			load();
		} finally {
			setBusy(false);
		}
	}
	if (error && !data) return /* @__PURE__ */ jsx("div", {
		className: "notice err",
		children: error
	});
	if (!data) return /* @__PURE__ */ jsx("div", {
		className: "panel",
		children: "加载中…"
	});
	const { day, buckets, folios } = data;
	const isAuditor = me?.role === "auditor";
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		notice && /* @__PURE__ */ jsx("div", {
			className: "notice ok",
			children: notice
		}),
		error && /* @__PURE__ */ jsx("div", {
			className: "notice err",
			children: error
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [
				/* @__PURE__ */ jsxs("div", {
					className: "row",
					style: { justifyContent: "space-between" },
					children: [/* @__PURE__ */ jsxs("h2", {
						style: { margin: 0 },
						children: [
							"营业日 ",
							day.date,
							" ",
							day.status === "open" ? /* @__PURE__ */ jsx("span", {
								className: "tag ok",
								children: "开放中"
							}) : /* @__PURE__ */ jsx("span", {
								className: "tag gray",
								children: "已封账"
							})
						]
					}), day.status === "open" && isAuditor && /* @__PURE__ */ jsxs("button", {
						className: "btn danger",
						disabled: !data.can_close || busy,
						onClick: close,
						title: data.can_close ? "" : "仍有未核对房单",
						children: [
							"封账（",
							day.date,
							"）"
						]
					})]
				}),
				day.status === "open" && data.unverified_count > 0 && /* @__PURE__ */ jsxs("p", {
					className: "warn",
					children: [
						"仍有 ",
						data.unverified_count,
						" 本房单未核对，核对全部房单后才能封账。"
					]
				}),
				day.closed_by && /* @__PURE__ */ jsxs("p", {
					className: "muted",
					children: [
						"由 ",
						day.closed_by,
						" 于 ",
						new Date(day.closed_at).toLocaleString("zh-CN"),
						" 封账"
					]
				})
			]
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "cards",
			children: [
				/* @__PURE__ */ jsx(Card, {
					label: "房费",
					value: buckets.room_charge_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "加购",
					value: buckets.addon_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "现金收款",
					value: buckets.cash_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "非现金收款",
					value: buckets.card_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "退款",
					value: buckets.refund_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "调整",
					value: buckets.adjustment_cents
				}),
				/* @__PURE__ */ jsx(Card, {
					label: "净额合计",
					value: buckets.net_cents,
					strong: true
				})
			]
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [
				/* @__PURE__ */ jsxs("h2", { children: [
					"房单逐项核对（",
					folios.length,
					" 本）"
				] }),
				folios.length === 0 && /* @__PURE__ */ jsx("p", {
					className: "muted",
					children: "本营业日暂无发生额。"
				}),
				folios.map((f) => /* @__PURE__ */ jsxs("details", {
					className: "folio",
					children: [/* @__PURE__ */ jsxs("summary", { children: [
						/* @__PURE__ */ jsx("strong", { children: f.folio_no }),
						/* @__PURE__ */ jsx("span", { children: f.guest_name }),
						/* @__PURE__ */ jsxs("span", {
							className: "tag gray",
							children: [f.room_no, " 房"]
						}),
						/* @__PURE__ */ jsx("span", { className: "grow" }),
						f.verified ? /* @__PURE__ */ jsxs("span", {
							className: "tag ok",
							children: ["已核对 · ", f.verified_by]
						}) : /* @__PURE__ */ jsx("span", {
							className: "tag warn",
							children: "未核对"
						}),
						day.status === "open" && !f.verified && isAuditor && /* @__PURE__ */ jsx("button", {
							className: "btn small primary",
							disabled: busy,
							onClick: (e) => {
								e.preventDefault();
								verify(f.folio_id);
							},
							children: "核对"
						})
					] }), /* @__PURE__ */ jsx("div", {
						className: "items",
						children: /* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
							/* @__PURE__ */ jsx("th", { children: "项目" }),
							/* @__PURE__ */ jsx("th", { children: "类型" }),
							/* @__PURE__ */ jsx("th", { children: "归属营业日" }),
							/* @__PURE__ */ jsx("th", { children: "过账时间" }),
							/* @__PURE__ */ jsx("th", { children: "状态" }),
							/* @__PURE__ */ jsx("th", {
								className: "num",
								children: "金额"
							})
						] }) }), /* @__PURE__ */ jsx("tbody", { children: f.items.map((i) => /* @__PURE__ */ jsxs("tr", { children: [
							/* @__PURE__ */ jsx("td", { children: i.description }),
							/* @__PURE__ */ jsxs("td", { children: [TYPE_LABELS[i.type] || i.type, i.method ? `（${i.method === "cash" ? "现金" : "刷卡"}）` : ""] }),
							/* @__PURE__ */ jsx("td", {
								className: "mono",
								children: i.business_date
							}),
							/* @__PURE__ */ jsx("td", {
								className: "mono",
								children: new Date(i.posted_at).toLocaleString("zh-CN")
							}),
							/* @__PURE__ */ jsx("td", { children: i.status === "pending" ? /* @__PURE__ */ jsx("span", {
								className: "tag warn",
								children: "回执未到"
							}) : /* @__PURE__ */ jsx("span", {
								className: "tag ok",
								children: "已入账"
							}) }),
							/* @__PURE__ */ jsx("td", {
								className: `num ${i.amount_cents < 0 ? "bad" : ""}`,
								children: fen(i.amount_cents)
							})
						] }, i.id)) })] })
					})]
				}, f.folio_id))
			]
		}),
		data.snapshot && /* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [/* @__PURE__ */ jsx("h2", { children: "夜审快照（不可修改）" }), /* @__PURE__ */ jsx(SnapshotView, { snapshot: data.snapshot })]
		})
	] });
}
function Card({ label, value, strong }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "card",
		children: [/* @__PURE__ */ jsx("div", {
			className: "label",
			children: label
		}), /* @__PURE__ */ jsx("div", {
			className: `value ${value < 0 ? "neg" : ""}`,
			style: strong ? { color: "var(--brand)" } : void 0,
			children: fen(value)
		})]
	});
}
function SnapshotView({ snapshot }) {
	const rows = [
		["房费", snapshot.room_charge_cents],
		["加购", snapshot.addon_cents],
		["现金收款", snapshot.cash_cents],
		["非现金收款", snapshot.card_cents],
		["退款", snapshot.refund_cents],
		["调整", snapshot.adjustment_cents]
	];
	return /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsx("table", { children: /* @__PURE__ */ jsxs("tbody", { children: [rows.map(([label, v]) => /* @__PURE__ */ jsxs("tr", { children: [/* @__PURE__ */ jsx("td", { children: label }), /* @__PURE__ */ jsx("td", {
		className: `num ${v < 0 ? "bad" : ""}`,
		children: fen(v)
	})] }, label)), /* @__PURE__ */ jsxs("tr", {
		className: "total",
		children: [/* @__PURE__ */ jsx("td", { children: "净额" }), /* @__PURE__ */ jsx("td", {
			className: "num",
			children: fen(snapshot.net_cents)
		})]
	})] }) }), /* @__PURE__ */ jsxs("p", {
		className: "muted",
		children: [
			"房单 ",
			snapshot.folio_count,
			" 本 · 条目 ",
			snapshot.item_count,
			" 条 · 由 ",
			snapshot.closed_by,
			" 于 ",
			new Date(snapshot.closed_at).toLocaleString("zh-CN"),
			" 封存"
		]
	})] });
}
//#endregion
//#region resources/js/pages/FrontDesk.jsx
/**
* 前台 / 房态 / 支付的本地模拟台。
* 用于制造夜审要处理的各种场景：跨午夜入住、延迟退房、加购、退款、
* 收款（含"回执晚到"）、模拟网关回执到达。
*/
function FrontDesk({ me }) {
	const [stays, setStays] = useState([]);
	const [pending, setPending] = useState([]);
	const [notice, setNotice] = useState("");
	const [error, setError] = useState("");
	const load = useCallback(() => {
		api("/stays").then(setStays).catch((e) => setError(e.message));
		api("/payments/pending").then(setPending).catch(() => {});
	}, []);
	useEffect(load, [load]);
	async function run(action, ok) {
		setError("");
		setNotice("");
		try {
			const res = await action();
			setNotice(typeof ok === "function" ? ok(res) : ok);
			load();
		} catch (e) {
			setError(e.message);
		}
	}
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		notice && /* @__PURE__ */ jsx("div", {
			className: "notice ok",
			children: notice
		}),
		error && /* @__PURE__ */ jsx("div", {
			className: "notice err",
			children: error
		}),
		/* @__PURE__ */ jsx(CheckInForm, { onDone: run }),
		/* @__PURE__ */ jsx(StayOps, {
			stays,
			onDone: run
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [/* @__PURE__ */ jsx("h2", { children: "支付网关（本地模拟）· 待到账回执" }), pending.length === 0 ? /* @__PURE__ */ jsx("p", {
				className: "muted",
				children: "没有待到账的回执。"
			}) : /* @__PURE__ */ jsxs(Fragment, { children: [
				/* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
					/* @__PURE__ */ jsx("th", { children: "回执号" }),
					/* @__PURE__ */ jsx("th", { children: "房单" }),
					/* @__PURE__ */ jsx("th", { children: "客人" }),
					/* @__PURE__ */ jsx("th", { children: "原属营业日" }),
					/* @__PURE__ */ jsx("th", {
						className: "num",
						children: "金额"
					})
				] }) }), /* @__PURE__ */ jsx("tbody", { children: pending.map((p) => /* @__PURE__ */ jsxs("tr", { children: [
					/* @__PURE__ */ jsx("td", {
						className: "mono",
						children: p.receipt_no
					}),
					/* @__PURE__ */ jsx("td", { children: p.folio_no }),
					/* @__PURE__ */ jsx("td", { children: p.guest_name }),
					/* @__PURE__ */ jsx("td", {
						className: "mono",
						children: p.original_business_date
					}),
					/* @__PURE__ */ jsx("td", {
						className: "num",
						children: fen(p.amount_cents, { sign: false })
					})
				] }, p.receipt_id)) })] }),
				/* @__PURE__ */ jsx("p", { children: /* @__PURE__ */ jsx("button", {
					className: "btn primary",
					onClick: () => run(() => api("/payments/settle-pending", {
						method: "POST",
						body: {}
					}), (res) => `回执已到账 ${res.settled.length} 笔` + (res.adjustments.length ? `，其中 ${res.adjustments.length} 笔原营业日已封账，已生成调整单：${res.adjustments.join("、")}` : "，均直接计入原营业日")),
					children: "模拟网关回执到达（结算全部待到账）"
				}) }),
				/* @__PURE__ */ jsx("p", {
					className: "muted",
					children: "若回执原属营业日已封账，系统不会修改昨日数字，而是自动生成\"迟到收款\"调整单，落在当前开放营业日。"
				})
			] })]
		})
	] });
}
function CheckInForm({ onDone }) {
	const [form, setForm] = useState({
		room_no: "",
		guest_name: "",
		check_in_at: ""
	});
	function fillCrossMidnight() {
		const y = /* @__PURE__ */ new Date();
		y.setDate(y.getDate() - 1);
		const pad = (n) => String(n).padStart(2, "0");
		setForm({
			room_no: "305",
			guest_name: "赵六",
			check_in_at: `${y.getFullYear()}-${pad(y.getMonth() + 1)}-${pad(y.getDate())}T23:50`
		});
	}
	return /* @__PURE__ */ jsxs("div", {
		className: "panel",
		children: [/* @__PURE__ */ jsx("h2", { children: "入住登记（房态模拟）" }), /* @__PURE__ */ jsxs("div", {
			className: "form-grid",
			children: [
				/* @__PURE__ */ jsxs("label", { children: ["房号", /* @__PURE__ */ jsx("input", {
					value: form.room_no,
					onChange: (e) => setForm({
						...form,
						room_no: e.target.value
					}),
					placeholder: "305"
				})] }),
				/* @__PURE__ */ jsxs("label", { children: ["客人姓名", /* @__PURE__ */ jsx("input", {
					value: form.guest_name,
					onChange: (e) => setForm({
						...form,
						guest_name: e.target.value
					}),
					placeholder: "赵六"
				})] }),
				/* @__PURE__ */ jsxs("label", { children: ["入住时间（可模拟跨午夜）", /* @__PURE__ */ jsx("input", {
					type: "datetime-local",
					value: form.check_in_at,
					onChange: (e) => setForm({
						...form,
						check_in_at: e.target.value
					})
				})] }),
				/* @__PURE__ */ jsxs("div", {
					className: "row",
					children: [/* @__PURE__ */ jsx("button", {
						className: "btn",
						onClick: fillCrossMidnight,
						children: "填入：昨日 23:50 跨午夜入住"
					}), /* @__PURE__ */ jsx("button", {
						className: "btn primary",
						disabled: !form.room_no || !form.guest_name,
						onClick: () => onDone(() => api("/stays", {
							method: "POST",
							body: {
								...form,
								check_in_at: form.check_in_at || void 0
							}
						}), (res) => `已开房单 ${res.folio_no}`),
						children: "办理入住"
					})]
				})
			]
		})]
	});
}
function StayOps({ stays, onDone }) {
	const [stayId, setStayId] = useState("");
	const stay = stays.find((s) => String(s.id) === String(stayId));
	useEffect(() => {
		if (!stayId && stays.length > 0) setStayId(String(stays[0].id));
	}, [stays]);
	const inHouse = stays.filter((s) => s.status === "in_house");
	return /* @__PURE__ */ jsxs("div", {
		className: "panel",
		children: [
			/* @__PURE__ */ jsx("h2", { children: "在住客人操作" }),
			stays.length === 0 && /* @__PURE__ */ jsx("p", {
				className: "muted",
				children: "暂无住宿记录。"
			}),
			stays.length > 0 && /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsx("div", {
				className: "row",
				style: { marginBottom: 12 },
				children: /* @__PURE__ */ jsxs("label", { children: [
					"住宿记录：",
					" ",
					/* @__PURE__ */ jsx("select", {
						value: stayId,
						onChange: (e) => setStayId(e.target.value),
						children: stays.map((s) => /* @__PURE__ */ jsxs("option", {
							value: s.id,
							children: [
								s.room_no,
								" 房 · ",
								s.guest_name,
								" · ",
								s.folio_no,
								"（",
								s.status === "in_house" ? "在住" : "已退房",
								"，余额 ",
								fen(s.balance_cents),
								"）"
							]
						}, s.id))
					})
				] })
			}), stay && /* @__PURE__ */ jsxs(Fragment, { children: [
				/* @__PURE__ */ jsx(RoomChargeRow, {
					stay,
					onDone
				}),
				/* @__PURE__ */ jsx(AddonRefundRow, {
					stay,
					onDone
				}),
				/* @__PURE__ */ jsx(PaymentRow, {
					stay,
					onDone
				}),
				stay.status === "in_house" && /* @__PURE__ */ jsx(CheckoutRow, {
					stay,
					onDone
				})
			] })] }),
			inHouse.length === 0 && stays.length > 0 && /* @__PURE__ */ jsx("p", {
				className: "muted",
				children: "全部客人已退房。"
			})
		]
	});
}
function RoomChargeRow({ stay, onDone }) {
	const [night, setNight] = useState("");
	const [rate, setRate] = useState("588.00");
	const [postedAt, setPostedAt] = useState("");
	return /* @__PURE__ */ jsxs("div", {
		className: "row",
		style: { marginBottom: 8 },
		children: [
			/* @__PURE__ */ jsx("strong", { children: "过房费：" }),
			/* @__PURE__ */ jsxs("label", { children: ["住宿夜 ", /* @__PURE__ */ jsx("input", {
				type: "date",
				value: night,
				onChange: (e) => setNight(e.target.value)
			})] }),
			/* @__PURE__ */ jsxs("label", { children: ["房价 ", /* @__PURE__ */ jsx("input", {
				value: rate,
				onChange: (e) => setRate(e.target.value),
				style: { width: 90 }
			})] }),
			/* @__PURE__ */ jsxs("label", { children: ["过账时间（可模拟次日凌晨）", /* @__PURE__ */ jsx("input", {
				type: "datetime-local",
				value: postedAt,
				onChange: (e) => setPostedAt(e.target.value)
			})] }),
			/* @__PURE__ */ jsx("button", {
				className: "btn",
				disabled: !night,
				onClick: () => onDone(() => api(`/stays/${stay.id}/room-charges`, {
					method: "POST",
					body: {
						night_date: night,
						rate_cents: Math.round(parseFloat(rate) * 100),
						posted_at: postedAt || void 0
					}
				}), (res) => `房费已过账：归属营业日 ${res.business_date}（过账于 ${new Date(res.posted_at).toLocaleString("zh-CN")}）`),
				children: "过账房费"
			})
		]
	});
}
function AddonRefundRow({ stay, onDone }) {
	const [desc, setDesc] = useState("迷你吧消费");
	const [amount, setAmount] = useState("56.00");
	const cents = () => Math.round(parseFloat(amount || "0") * 100);
	return /* @__PURE__ */ jsxs("div", {
		className: "row",
		style: { marginBottom: 8 },
		children: [
			/* @__PURE__ */ jsx("strong", { children: "加购 / 退款：" }),
			/* @__PURE__ */ jsx("input", {
				value: desc,
				onChange: (e) => setDesc(e.target.value),
				style: { width: 140 }
			}),
			/* @__PURE__ */ jsx("input", {
				value: amount,
				onChange: (e) => setAmount(e.target.value),
				style: { width: 90 }
			}),
			/* @__PURE__ */ jsx("button", {
				className: "btn",
				disabled: !desc || !cents(),
				onClick: () => onDone(() => api(`/folios/${stay.folio_id}/addons`, {
					method: "POST",
					body: {
						description: desc,
						amount_cents: cents()
					}
				}), () => "加购已入账（归属当前开放营业日）"),
				children: "加购"
			}),
			/* @__PURE__ */ jsx("button", {
				className: "btn",
				disabled: !desc || !cents(),
				onClick: () => onDone(() => api(`/folios/${stay.folio_id}/refunds`, {
					method: "POST",
					body: {
						description: `退款：${desc}`,
						amount_cents: cents()
					}
				}), () => "退款已入账（归属当前开放营业日）"),
				children: "退款"
			})
		]
	});
}
function PaymentRow({ stay, onDone }) {
	const [amount, setAmount] = useState("300.00");
	const [method, setMethod] = useState("cash");
	const [delay, setDelay] = useState(false);
	return /* @__PURE__ */ jsxs("div", {
		className: "row",
		style: { marginBottom: 8 },
		children: [
			/* @__PURE__ */ jsx("strong", { children: "收款：" }),
			/* @__PURE__ */ jsx("input", {
				value: amount,
				onChange: (e) => setAmount(e.target.value),
				style: { width: 90 }
			}),
			/* @__PURE__ */ jsxs("select", {
				value: method,
				onChange: (e) => setMethod(e.target.value),
				children: [/* @__PURE__ */ jsx("option", {
					value: "cash",
					children: "现金"
				}), /* @__PURE__ */ jsx("option", {
					value: "card",
					children: "刷卡"
				})]
			}),
			/* @__PURE__ */ jsxs("label", {
				className: "muted",
				children: [/* @__PURE__ */ jsx("input", {
					type: "checkbox",
					checked: delay,
					onChange: (e) => setDelay(e.target.checked)
				}), " 模拟回执晚到"]
			}),
			/* @__PURE__ */ jsx("button", {
				className: "btn",
				disabled: !(parseFloat(amount) > 0),
				onClick: () => onDone(() => api("/payments", {
					method: "POST",
					body: {
						folio_id: stay.folio_id,
						amount_cents: Math.round(parseFloat(amount) * 100),
						method,
						delay_receipt: delay
					}
				}), (res) => res.status === "pending" ? `收款已发起，回执 ${res.receipt_no} 晚到（待到账）` : `收款已到账（回执 ${res.receipt_no}）`),
				children: "收款"
			})
		]
	});
}
function CheckoutRow({ stay, onDone }) {
	const [outAt, setOutAt] = useState("");
	const [fee, setFee] = useState("294.00");
	function fillLate() {
		const d = /* @__PURE__ */ new Date();
		const pad = (n) => String(n).padStart(2, "0");
		setOutAt(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T14:00`);
	}
	return /* @__PURE__ */ jsxs("div", {
		className: "row",
		children: [
			/* @__PURE__ */ jsx("strong", { children: "退房：" }),
			/* @__PURE__ */ jsxs("label", { children: ["退房时间 ", /* @__PURE__ */ jsx("input", {
				type: "datetime-local",
				value: outAt,
				onChange: (e) => setOutAt(e.target.value)
			})] }),
			/* @__PURE__ */ jsxs("label", { children: ["延迟退房费 ", /* @__PURE__ */ jsx("input", {
				value: fee,
				onChange: (e) => setFee(e.target.value),
				style: { width: 90 }
			})] }),
			/* @__PURE__ */ jsx("button", {
				className: "btn",
				onClick: fillLate,
				children: "填入：今日 14:00 延迟退房"
			}),
			/* @__PURE__ */ jsx("button", {
				className: "btn primary",
				onClick: () => onDone(() => api(`/stays/${stay.id}/checkout`, {
					method: "POST",
					body: {
						check_out_at: outAt || void 0,
						late_fee_cents: Math.round(parseFloat(fee || "0") * 100)
					}
				}), () => "已退房（12:00 后自动计延迟退房费，归属退房当天）"),
				children: "办理退房"
			})
		]
	});
}
//#endregion
//#region resources/js/pages/Adjustments.jsx
/**
* 调整单：封账后的迟到收款 / 房费更正等全部记录在此，
* 每笔关联原房账条目与差额原因。
*/
function Adjustments({ me }) {
	const [list, setList] = useState([]);
	const [error, setError] = useState("");
	const [notice, setNotice] = useState("");
	const load = useCallback(() => {
		api("/adjustments").then(setList).catch((e) => setError(e.message));
	}, []);
	useEffect(load, [load]);
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		notice && /* @__PURE__ */ jsx("div", {
			className: "notice ok",
			children: notice
		}),
		error && /* @__PURE__ */ jsx("div", {
			className: "notice err",
			children: error
		}),
		/* @__PURE__ */ jsx(CreateAdjustment, {
			onDone: (msg) => {
				setNotice(msg);
				setError("");
				load();
			},
			onError: (msg) => {
				setError(msg);
				setNotice("");
			}
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [/* @__PURE__ */ jsxs("h2", { children: [
				"调整单流水（",
				list.length,
				"）"
			] }), list.length === 0 ? /* @__PURE__ */ jsx("p", {
				className: "muted",
				children: "暂无调整单。"
			}) : /* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
				/* @__PURE__ */ jsx("th", { children: "单号" }),
				/* @__PURE__ */ jsx("th", { children: "入账营业日" }),
				/* @__PURE__ */ jsx("th", { children: "房单" }),
				/* @__PURE__ */ jsx("th", { children: "类型" }),
				/* @__PURE__ */ jsx("th", {
					className: "num",
					children: "调整金额"
				}),
				/* @__PURE__ */ jsx("th", { children: "差额原因" }),
				/* @__PURE__ */ jsx("th", { children: "原交易" }),
				/* @__PURE__ */ jsx("th", { children: "经办" })
			] }) }), /* @__PURE__ */ jsx("tbody", { children: list.map((a) => /* @__PURE__ */ jsxs("tr", { children: [
				/* @__PURE__ */ jsx("td", {
					className: "mono",
					children: a.adjustment_no
				}),
				/* @__PURE__ */ jsx("td", {
					className: "mono",
					children: a.business_date
				}),
				/* @__PURE__ */ jsxs("td", { children: [
					a.folio_no,
					"（",
					a.guest_name,
					"）"
				] }),
				/* @__PURE__ */ jsx("td", { children: /* @__PURE__ */ jsx("span", {
					className: "tag",
					children: ADJ_TYPE_LABELS[a.type] || a.type
				}) }),
				/* @__PURE__ */ jsx("td", {
					className: `num ${a.amount_cents < 0 ? "bad" : ""}`,
					children: fen(a.amount_cents)
				}),
				/* @__PURE__ */ jsx("td", { children: a.reason }),
				/* @__PURE__ */ jsx("td", {
					className: "muted",
					children: a.original_item ? /* @__PURE__ */ jsxs(Fragment, { children: [
						TYPE_LABELS[a.original_item.type],
						" ",
						fen(a.original_item.amount_cents),
						/* @__PURE__ */ jsx("br", {}),
						"归属 ",
						a.original_item.business_date
					] }) : "—"
				}),
				/* @__PURE__ */ jsx("td", { children: a.created_by })
			] }, a.adjustment_no)) })] })]
		})
	] });
}
function CreateAdjustment({ onDone, onError }) {
	const [folios, setFolios] = useState([]);
	const [folioId, setFolioId] = useState("");
	const [folioDetail, setFolioDetail] = useState(null);
	const [itemId, setItemId] = useState("");
	const [type, setType] = useState("room_charge_correction");
	const [amount, setAmount] = useState("");
	const [reason, setReason] = useState("");
	useEffect(() => {
		api("/stays").then((stays) => {
			setFolios(stays);
			if (stays.length > 0) setFolioId((cur) => cur || String(stays[0].folio_id));
		}).catch(() => {});
	}, []);
	useEffect(() => {
		if (!folioId) return;
		setItemId("");
		api(`/folios/${folioId}`).then((d) => {
			setFolioDetail(d);
			const first = d.items.find((i) => i.type !== "adjustment");
			if (first) setItemId(String(first.id));
		}).catch(() => setFolioDetail(null));
	}, [folioId]);
	const correctable = (folioDetail?.items || []).filter((i) => i.type !== "adjustment");
	async function submit() {
		try {
			const res = await api("/adjustments", {
				method: "POST",
				body: {
					folio_id: Number(folioId),
					original_item_id: Number(itemId),
					type,
					amount_cents: Math.round(parseFloat(amount) * 100),
					reason_code: type,
					reason
				}
			});
			onDone(`调整单 ${res.adjustment_no} 已创建，入账营业日 ${res.business_date}`);
		} catch (e) {
			onError(e.message);
		}
	}
	return /* @__PURE__ */ jsxs("div", {
		className: "panel",
		children: [
			/* @__PURE__ */ jsx("h2", { children: "新建更正调整单" }),
			/* @__PURE__ */ jsxs("div", {
				className: "form-grid",
				children: [
					/* @__PURE__ */ jsxs("label", { children: ["房单", /* @__PURE__ */ jsx("select", {
						value: folioId,
						onChange: (e) => setFolioId(e.target.value),
						children: folios.map((s) => /* @__PURE__ */ jsxs("option", {
							value: s.folio_id,
							children: [
								s.folio_no,
								" · ",
								s.guest_name,
								"（",
								s.room_no,
								" 房）"
							]
						}, s.folio_id))
					})] }),
					/* @__PURE__ */ jsxs("label", { children: ["原交易条目", /* @__PURE__ */ jsx("select", {
						value: itemId,
						onChange: (e) => setItemId(e.target.value),
						children: correctable.map((i) => /* @__PURE__ */ jsxs("option", {
							value: i.id,
							children: [
								"#",
								i.id,
								" ",
								TYPE_LABELS[i.type],
								" ",
								fen(i.amount_cents),
								"（归属 ",
								i.business_date,
								"）"
							]
						}, i.id))
					})] }),
					/* @__PURE__ */ jsxs("label", { children: ["调整类型", /* @__PURE__ */ jsxs("select", {
						value: type,
						onChange: (e) => setType(e.target.value),
						children: [
							/* @__PURE__ */ jsx("option", {
								value: "room_charge_correction",
								children: "房费更正"
							}),
							/* @__PURE__ */ jsx("option", {
								value: "addon_correction",
								children: "加购更正"
							}),
							/* @__PURE__ */ jsx("option", {
								value: "refund_correction",
								children: "退款更正"
							})
						]
					})] }),
					/* @__PURE__ */ jsxs("label", { children: ["调整金额（元，负数为冲减）", /* @__PURE__ */ jsx("input", {
						value: amount,
						onChange: (e) => setAmount(e.target.value),
						placeholder: "-50.00"
					})] }),
					/* @__PURE__ */ jsxs("label", { children: ["差额原因", /* @__PURE__ */ jsx("input", {
						value: reason,
						onChange: (e) => setReason(e.target.value),
						placeholder: "房价录入错误，应为 538.00"
					})] }),
					/* @__PURE__ */ jsx("div", { children: /* @__PURE__ */ jsx("button", {
						className: "btn primary",
						disabled: !itemId || !amount || !reason,
						onClick: submit,
						children: "创建调整单"
					}) })
				]
			}),
			/* @__PURE__ */ jsx("p", {
				className: "muted",
				children: "调整单落在当前开放营业日，不修改原营业日已封账的数字；原交易条目保持不变，可追溯。"
			})
		]
	});
}
//#endregion
//#region resources/js/pages/Finance.jsx
/**
* 财务核对：营业日列表 → 封账日报告（快照 + 关联调整 = 有效数字），
* 以及全局调整流水对账（调整单合计必须等于房账调整条目合计）。
*/
function Finance({ me }) {
	const [days, setDays] = useState([]);
	const [recon, setRecon] = useState(null);
	const [selected, setSelected] = useState("");
	const [report, setReport] = useState(null);
	const [error, setError] = useState("");
	const load = useCallback(() => {
		api("/finance/days").then((list) => {
			setDays(list);
			const firstClosed = list.find((d) => d.status === "closed");
			setSelected((cur) => cur || (firstClosed ? firstClosed.date : ""));
		}).catch((e) => setError(e.message));
		api("/finance/reconciliation").then(setRecon).catch(() => {});
	}, []);
	useEffect(load, [load]);
	useEffect(() => {
		if (!selected) {
			setReport(null);
			return;
		}
		setReport(null);
		api(`/finance/days/${selected}`).then(setReport).catch((e) => setError(e.message));
	}, [selected]);
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		error && /* @__PURE__ */ jsx("div", {
			className: "notice err",
			children: error
		}),
		/* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [/* @__PURE__ */ jsx("h2", { children: "营业日" }), /* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
				/* @__PURE__ */ jsx("th", { children: "营业日" }),
				/* @__PURE__ */ jsx("th", { children: "状态" }),
				/* @__PURE__ */ jsx("th", { children: "封账人" }),
				/* @__PURE__ */ jsx("th", {
					className: "num",
					children: "快照净额"
				}),
				/* @__PURE__ */ jsx("th", {})
			] }) }), /* @__PURE__ */ jsx("tbody", { children: days.map((d) => /* @__PURE__ */ jsxs("tr", { children: [
				/* @__PURE__ */ jsx("td", {
					className: "mono",
					children: d.date
				}),
				/* @__PURE__ */ jsx("td", { children: d.status === "open" ? /* @__PURE__ */ jsx("span", {
					className: "tag ok",
					children: "开放中"
				}) : /* @__PURE__ */ jsx("span", {
					className: "tag gray",
					children: "已封账"
				}) }),
				/* @__PURE__ */ jsx("td", { children: d.closed_by || "—" }),
				/* @__PURE__ */ jsx("td", {
					className: "num",
					children: d.snapshot_net_cents === null ? "—" : fen(d.snapshot_net_cents)
				}),
				/* @__PURE__ */ jsx("td", { children: d.status === "closed" && /* @__PURE__ */ jsx("button", {
					className: "btn small",
					onClick: () => setSelected(d.date),
					children: "查看核对报告"
				}) })
			] }, d.date)) })] })]
		}),
		recon && /* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [/* @__PURE__ */ jsx("h2", { children: "全局调整流水对账" }), /* @__PURE__ */ jsxs("div", {
				className: "row",
				children: [
					/* @__PURE__ */ jsxs("span", { children: [
						"调整单 ",
						recon.adjustment_count,
						" 笔，合计 ",
						/* @__PURE__ */ jsx("strong", {
							className: "mono",
							children: fen(recon.adjustment_total_cents)
						})
					] }),
					/* @__PURE__ */ jsxs("span", { children: ["房账调整条目合计 ", /* @__PURE__ */ jsx("strong", {
						className: "mono",
						children: fen(recon.ledger_adjustment_total_cents)
					})] }),
					recon.totals_tied && recon.per_item_tied ? /* @__PURE__ */ jsx("span", {
						className: "tag ok",
						children: "✓ 汇总金额与调整流水对得上"
					}) : /* @__PURE__ */ jsx("span", {
						className: "tag bad",
						children: "✗ 流水不平，请检查"
					})
				]
			})]
		}),
		report && /* @__PURE__ */ jsxs("div", {
			className: "panel",
			children: [
				/* @__PURE__ */ jsxs("h2", { children: [
					"营业日 ",
					report.date,
					" 核对报告"
				] }),
				/* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
					/* @__PURE__ */ jsx("th", { children: "项目" }),
					/* @__PURE__ */ jsx("th", {
						className: "num",
						children: "快照（冻结）"
					}),
					/* @__PURE__ */ jsx("th", {
						className: "num",
						children: "后续调整"
					}),
					/* @__PURE__ */ jsx("th", {
						className: "num",
						children: "当前有效"
					})
				] }) }), /* @__PURE__ */ jsxs("tbody", { children: [
					/* @__PURE__ */ jsx(ReconRow, {
						label: "房费",
						snap: report.snapshot.room_charge_cents,
						adj: sumAdj(report, "room_charge_correction"),
						eff: report.effective.room_charge_cents
					}),
					/* @__PURE__ */ jsx(ReconRow, {
						label: "加购",
						snap: report.snapshot.addon_cents,
						adj: sumAdj(report, "addon_correction"),
						eff: report.effective.addon_cents
					}),
					/* @__PURE__ */ jsx(ReconRow, {
						label: "收款（现金+非现金）",
						snap: report.snapshot.cash_cents + report.snapshot.card_cents,
						adj: sumAdj(report, "late_payment"),
						eff: report.effective.payment_cents
					}),
					/* @__PURE__ */ jsx(ReconRow, {
						label: "退款",
						snap: report.snapshot.refund_cents,
						adj: sumAdj(report, "refund_correction"),
						eff: report.effective.refund_cents
					}),
					/* @__PURE__ */ jsxs("tr", {
						className: "total",
						children: [
							/* @__PURE__ */ jsx("td", { children: "净额" }),
							/* @__PURE__ */ jsx("td", {
								className: "num",
								children: fen(report.snapshot.net_cents)
							}),
							/* @__PURE__ */ jsx("td", {
								className: `num ${report.adjustment_total_cents < 0 ? "bad" : ""}`,
								children: fen(report.adjustment_total_cents)
							}),
							/* @__PURE__ */ jsx("td", {
								className: "num",
								children: fen(report.effective_net_cents)
							})
						]
					})
				] })] }),
				/* @__PURE__ */ jsxs("p", { children: [report.reconciled ? /* @__PURE__ */ jsx("span", {
					className: "tag ok",
					children: "✓ 每笔调整与房账条目金额一致，快照 + 调整流水 = 有效数字"
				}) : /* @__PURE__ */ jsx("span", {
					className: "tag bad",
					children: "✗ 存在调整与流水不一致"
				}), /* @__PURE__ */ jsxs("span", {
					className: "muted",
					children: [
						"（快照由 ",
						report.snapshot.closed_by,
						" 于 ",
						new Date(report.snapshot.closed_at).toLocaleString("zh-CN"),
						" 封存，不可修改）"
					]
				})] }),
				/* @__PURE__ */ jsxs("h3", { children: [
					"关联到本营业日的调整单（",
					report.adjustments.length,
					"）"
				] }),
				report.adjustments.length === 0 ? /* @__PURE__ */ jsx("p", {
					className: "muted",
					children: "无。"
				}) : /* @__PURE__ */ jsxs("table", { children: [/* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
					/* @__PURE__ */ jsx("th", { children: "调整单号" }),
					/* @__PURE__ */ jsx("th", { children: "入账营业日" }),
					/* @__PURE__ */ jsx("th", { children: "类型" }),
					/* @__PURE__ */ jsx("th", {
						className: "num",
						children: "金额"
					}),
					/* @__PURE__ */ jsx("th", { children: "差额原因" }),
					/* @__PURE__ */ jsx("th", { children: "原交易（可追溯）" })
				] }) }), /* @__PURE__ */ jsx("tbody", { children: report.adjustments.map((a) => /* @__PURE__ */ jsxs("tr", { children: [
					/* @__PURE__ */ jsx("td", {
						className: "mono",
						children: a.adjustment_no
					}),
					/* @__PURE__ */ jsx("td", {
						className: "mono",
						children: a.business_date
					}),
					/* @__PURE__ */ jsx("td", { children: /* @__PURE__ */ jsx("span", {
						className: "tag",
						children: ADJ_TYPE_LABELS[a.type] || a.type
					}) }),
					/* @__PURE__ */ jsx("td", {
						className: `num ${a.amount_cents < 0 ? "bad" : ""}`,
						children: fen(a.amount_cents)
					}),
					/* @__PURE__ */ jsx("td", { children: a.reason }),
					/* @__PURE__ */ jsx("td", {
						className: "muted",
						children: a.original_item && /* @__PURE__ */ jsxs(Fragment, { children: [
							"#",
							a.original_item.id,
							" ",
							TYPE_LABELS[a.original_item.type],
							" ",
							fen(a.original_item.amount_cents),
							/* @__PURE__ */ jsx("br", {}),
							"归属 ",
							a.original_item.business_date,
							" · 过账 ",
							new Date(a.original_item.posted_at).toLocaleString("zh-CN")
						] })
					})
				] }, a.adjustment_no)) })] })
			]
		})
	] });
}
function sumAdj(report, type) {
	return report.adjustments.filter((a) => a.type === type).reduce((s, a) => s + a.amount_cents, 0);
}
function ReconRow({ label, snap, adj, eff }) {
	return /* @__PURE__ */ jsxs("tr", { children: [
		/* @__PURE__ */ jsx("td", { children: label }),
		/* @__PURE__ */ jsx("td", {
			className: `num ${snap < 0 ? "bad" : ""}`,
			children: fen(snap)
		}),
		/* @__PURE__ */ jsx("td", {
			className: `num ${adj < 0 ? "bad" : ""}`,
			children: adj === 0 ? "—" : fen(adj)
		}),
		/* @__PURE__ */ jsx("td", {
			className: `num ${eff < 0 ? "bad" : ""}`,
			children: fen(eff)
		})
	] });
}
//#endregion
//#region resources/js/app.jsx
var TABS = [
	{
		key: "audit",
		label: "夜审"
	},
	{
		key: "frontdesk",
		label: "前台 / 模拟"
	},
	{
		key: "adjustments",
		label: "调整单"
	},
	{
		key: "finance",
		label: "财务核对"
	}
];
function App() {
	const [users, setUsers] = useState([]);
	const [userId, setUserId] = useState(getCurrentUserId());
	const [tab, setTab] = useState("audit");
	useEffect(() => {
		api("/users").then((list) => {
			setUsers(list);
			if (!userId && list.length > 0) {
				const first = list.find((u) => u.role === "auditor") || list[0];
				setUserId(String(first.id));
				setCurrentUserId(String(first.id));
			}
		});
	}, []);
	const me = users.find((u) => String(u.id) === String(userId));
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		/* @__PURE__ */ jsxs("header", {
			className: "topbar",
			children: [
				/* @__PURE__ */ jsx("h1", { children: "酒店夜审系统" }),
				/* @__PURE__ */ jsx("nav", {
					className: "tabs",
					children: TABS.map((t) => /* @__PURE__ */ jsx("button", {
						className: tab === t.key ? "active" : "",
						onClick: () => setTab(t.key),
						children: t.label
					}, t.key))
				}),
				/* @__PURE__ */ jsx("div", { className: "spacer" }),
				/* @__PURE__ */ jsxs("label", {
					className: "muted",
					children: [
						"操作人：",
						" ",
						/* @__PURE__ */ jsx("select", {
							value: userId,
							onChange: (e) => {
								setUserId(e.target.value);
								setCurrentUserId(e.target.value);
							},
							children: users.map((u) => /* @__PURE__ */ jsxs("option", {
								value: u.id,
								children: [
									u.name,
									"（",
									ROLE_LABELS[u.role] || u.role,
									"）"
								]
							}, u.id))
						})
					]
				})
			]
		}),
		tab === "audit" && /* @__PURE__ */ jsx(NightAudit, { me }),
		tab === "frontdesk" && /* @__PURE__ */ jsx(FrontDesk, { me }),
		tab === "adjustments" && /* @__PURE__ */ jsx(Adjustments, { me }),
		tab === "finance" && /* @__PURE__ */ jsx(Finance, { me }),
		/* @__PURE__ */ jsx("footer", {
			className: "hint",
			children: "金额单位：元（收款/退款为负值，冲减应收）。归属规则：房费归住宿夜开始日；加购/退款归发生日； 收款归发起日，回执晚到且原日已封账时以调整单计入当前开放营业日。"
		})
	] });
}
//#endregion
export { App as default };
