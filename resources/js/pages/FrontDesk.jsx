import React, { useCallback, useEffect, useState } from 'react';
import { api, fen } from '../api.js';

/**
 * 前台 / 房态 / 支付的本地模拟台。
 * 用于制造夜审要处理的各种场景：跨午夜入住、延迟退房、加购、退款、
 * 收款（含"回执晚到"）、模拟网关回执到达。
 */
export default function FrontDesk({ me }) {
    const [stays, setStays] = useState([]);
    const [pending, setPending] = useState([]);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');

    const load = useCallback(() => {
        api('/stays').then(setStays).catch((e) => setError(e.message));
        api('/payments/pending').then(setPending).catch(() => {});
    }, []);

    useEffect(load, [load]);

    async function run(action, ok) {
        setError(''); setNotice('');
        try {
            const res = await action();
            setNotice(typeof ok === 'function' ? ok(res) : ok);
            load();
        } catch (e) {
            setError(e.message);
        }
    }

    return (
        <>
            {notice && <div className="notice ok">{notice}</div>}
            {error && <div className="notice err">{error}</div>}

            <CheckInForm onDone={run} />
            <StayOps stays={stays} onDone={run} />

            <div className="panel">
                <h2>支付网关（本地模拟）· 待到账回执</h2>
                {pending.length === 0 ? (
                    <p className="muted">没有待到账的回执。</p>
                ) : (
                    <>
                        <table>
                            <thead>
                                <tr><th>回执号</th><th>房单</th><th>客人</th><th>原属营业日</th><th className="num">金额</th></tr>
                            </thead>
                            <tbody>
                                {pending.map((p) => (
                                    <tr key={p.receipt_id}>
                                        <td className="mono">{p.receipt_no}</td>
                                        <td>{p.folio_no}</td>
                                        <td>{p.guest_name}</td>
                                        <td className="mono">{p.original_business_date}</td>
                                        <td className="num">{fen(p.amount_cents, { sign: false })}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p>
                            <button className="btn primary" onClick={() =>
                                run(
                                    () => api('/payments/settle-pending', { method: 'POST', body: {} }),
                                    (res) => `回执已到账 ${res.settled.length} 笔` +
                                        (res.adjustments.length
                                            ? `，其中 ${res.adjustments.length} 笔原营业日已封账，已生成调整单：${res.adjustments.join('、')}`
                                            : '，均直接计入原营业日'),
                                )
                            }>
                                模拟网关回执到达（结算全部待到账）
                            </button>
                        </p>
                        <p className="muted">若回执原属营业日已封账，系统不会修改昨日数字，而是自动生成"迟到收款"调整单，落在当前开放营业日。</p>
                    </>
                )}
            </div>
        </>
    );
}

function CheckInForm({ onDone }) {
    const [form, setForm] = useState({ room_no: '', guest_name: '', check_in_at: '' });

    function fillCrossMidnight() {
        const y = new Date();
        y.setDate(y.getDate() - 1);
        const pad = (n) => String(n).padStart(2, '0');
        setForm({
            room_no: '305',
            guest_name: '赵六',
            check_in_at: `${y.getFullYear()}-${pad(y.getMonth() + 1)}-${pad(y.getDate())}T23:50`,
        });
    }

    return (
        <div className="panel">
            <h2>入住登记（房态模拟）</h2>
            <div className="form-grid">
                <label>房号<input value={form.room_no} onChange={(e) => setForm({ ...form, room_no: e.target.value })} placeholder="305" /></label>
                <label>客人姓名<input value={form.guest_name} onChange={(e) => setForm({ ...form, guest_name: e.target.value })} placeholder="赵六" /></label>
                <label>入住时间（可模拟跨午夜）<input type="datetime-local" value={form.check_in_at} onChange={(e) => setForm({ ...form, check_in_at: e.target.value })} /></label>
                <div className="row">
                    <button className="btn" onClick={fillCrossMidnight}>填入：昨日 23:50 跨午夜入住</button>
                    <button className="btn primary" disabled={!form.room_no || !form.guest_name}
                        onClick={() => onDone(
                            () => api('/stays', { method: 'POST', body: { ...form, check_in_at: form.check_in_at || undefined } }),
                            (res) => `已开房单 ${res.folio_no}`,
                        )}>
                        办理入住
                    </button>
                </div>
            </div>
        </div>
    );
}

function StayOps({ stays, onDone }) {
    const [stayId, setStayId] = useState('');
    const stay = stays.find((s) => String(s.id) === String(stayId));

    useEffect(() => {
        if (!stayId && stays.length > 0) setStayId(String(stays[0].id));
    }, [stays]);

    const inHouse = stays.filter((s) => s.status === 'in_house');

    return (
        <div className="panel">
            <h2>在住客人操作</h2>
            {stays.length === 0 && <p className="muted">暂无住宿记录。</p>}
            {stays.length > 0 && (
                <>
                    <div className="row" style={{ marginBottom: 12 }}>
                        <label>住宿记录：{' '}
                            <select value={stayId} onChange={(e) => setStayId(e.target.value)}>
                                {stays.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.room_no} 房 · {s.guest_name} · {s.folio_no}（{s.status === 'in_house' ? '在住' : '已退房'}，余额 {fen(s.balance_cents)}）
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {stay && (
                        <>
                            <RoomChargeRow stay={stay} onDone={onDone} />
                            <AddonRefundRow stay={stay} onDone={onDone} />
                            <PaymentRow stay={stay} onDone={onDone} />
                            {stay.status === 'in_house' && <CheckoutRow stay={stay} onDone={onDone} />}
                        </>
                    )}
                </>
            )}
            {inHouse.length === 0 && stays.length > 0 && <p className="muted">全部客人已退房。</p>}
        </div>
    );
}

function RoomChargeRow({ stay, onDone }) {
    const [night, setNight] = useState('');
    const [rate, setRate] = useState('588.00');
    const [postedAt, setPostedAt] = useState('');

    return (
        <div className="row" style={{ marginBottom: 8 }}>
            <strong>过房费：</strong>
            <label>住宿夜 <input type="date" value={night} onChange={(e) => setNight(e.target.value)} /></label>
            <label>房价 <input value={rate} onChange={(e) => setRate(e.target.value)} style={{ width: 90 }} /></label>
            <label>过账时间（可模拟次日凌晨）<input type="datetime-local" value={postedAt} onChange={(e) => setPostedAt(e.target.value)} /></label>
            <button className="btn" disabled={!night}
                onClick={() => onDone(
                    () => api(`/stays/${stay.id}/room-charges`, {
                        method: 'POST',
                        body: {
                            night_date: night,
                            rate_cents: Math.round(parseFloat(rate) * 100),
                            posted_at: postedAt || undefined,
                        },
                    }),
                    (res) => `房费已过账：归属营业日 ${res.business_date}（过账于 ${new Date(res.posted_at).toLocaleString('zh-CN')}）`,
                )}>
                过账房费
            </button>
        </div>
    );
}

function AddonRefundRow({ stay, onDone }) {
    const [desc, setDesc] = useState('迷你吧消费');
    const [amount, setAmount] = useState('56.00');

    const cents = () => Math.round(parseFloat(amount || '0') * 100);

    return (
        <div className="row" style={{ marginBottom: 8 }}>
            <strong>加购 / 退款：</strong>
            <input value={desc} onChange={(e) => setDesc(e.target.value)} style={{ width: 140 }} />
            <input value={amount} onChange={(e) => setAmount(e.target.value)} style={{ width: 90 }} />
            <button className="btn" disabled={!desc || !cents()}
                onClick={() => onDone(
                    () => api(`/folios/${stay.folio_id}/addons`, { method: 'POST', body: { description: desc, amount_cents: cents() } }),
                    () => '加购已入账（归属当前开放营业日）',
                )}>
                加购
            </button>
            <button className="btn" disabled={!desc || !cents()}
                onClick={() => onDone(
                    () => api(`/folios/${stay.folio_id}/refunds`, { method: 'POST', body: { description: `退款：${desc}`, amount_cents: cents() } }),
                    () => '退款已入账（归属当前开放营业日）',
                )}>
                退款
            </button>
        </div>
    );
}

function PaymentRow({ stay, onDone }) {
    const [amount, setAmount] = useState('300.00');
    const [method, setMethod] = useState('cash');
    const [delay, setDelay] = useState(false);

    return (
        <div className="row" style={{ marginBottom: 8 }}>
            <strong>收款：</strong>
            <input value={amount} onChange={(e) => setAmount(e.target.value)} style={{ width: 90 }} />
            <select value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="cash">现金</option>
                <option value="card">刷卡</option>
            </select>
            <label className="muted">
                <input type="checkbox" checked={delay} onChange={(e) => setDelay(e.target.checked)} /> 模拟回执晚到
            </label>
            <button className="btn" disabled={!(parseFloat(amount) > 0)}
                onClick={() => onDone(
                    () => api('/payments', {
                        method: 'POST',
                        body: {
                            folio_id: stay.folio_id,
                            amount_cents: Math.round(parseFloat(amount) * 100),
                            method,
                            delay_receipt: delay,
                        },
                    }),
                    (res) => res.status === 'pending'
                        ? `收款已发起，回执 ${res.receipt_no} 晚到（待到账）`
                        : `收款已到账（回执 ${res.receipt_no}）`,
                )}>
                收款
            </button>
        </div>
    );
}

function CheckoutRow({ stay, onDone }) {
    const [outAt, setOutAt] = useState('');
    const [fee, setFee] = useState('294.00');

    function fillLate() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        setOutAt(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T14:00`);
    }

    return (
        <div className="row">
            <strong>退房：</strong>
            <label>退房时间 <input type="datetime-local" value={outAt} onChange={(e) => setOutAt(e.target.value)} /></label>
            <label>延迟退房费 <input value={fee} onChange={(e) => setFee(e.target.value)} style={{ width: 90 }} /></label>
            <button className="btn" onClick={fillLate}>填入：今日 14:00 延迟退房</button>
            <button className="btn primary"
                onClick={() => onDone(
                    () => api(`/stays/${stay.id}/checkout`, {
                        method: 'POST',
                        body: {
                            check_out_at: outAt || undefined,
                            late_fee_cents: Math.round(parseFloat(fee || '0') * 100),
                        },
                    }),
                    () => '已退房（12:00 后自动计延迟退房费，归属退房当天）',
                )}>
                办理退房
            </button>
        </div>
    );
}
