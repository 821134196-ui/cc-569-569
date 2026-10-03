import React, { useCallback, useEffect, useState } from 'react';
import { api, fen, ADJ_TYPE_LABELS, TYPE_LABELS } from '../api.js';

/**
 * 财务核对：营业日列表 → 封账日报告（快照 + 关联调整 = 有效数字），
 * 以及全局调整流水对账（调整单合计必须等于房账调整条目合计）。
 */
export default function Finance({ me }) {
    const [days, setDays] = useState([]);
    const [recon, setRecon] = useState(null);
    const [selected, setSelected] = useState('');
    const [report, setReport] = useState(null);
    const [error, setError] = useState('');

    const load = useCallback(() => {
        api('/finance/days').then((list) => {
            setDays(list);
            const firstClosed = list.find((d) => d.status === 'closed');
            setSelected((cur) => cur || (firstClosed ? firstClosed.date : ''));
        }).catch((e) => setError(e.message));
        api('/finance/reconciliation').then(setRecon).catch(() => {});
    }, []);

    useEffect(load, [load]);

    useEffect(() => {
        if (!selected) { setReport(null); return; }
        setReport(null);
        api(`/finance/days/${selected}`).then(setReport).catch((e) => setError(e.message));
    }, [selected]);

    return (
        <>
            {error && <div className="notice err">{error}</div>}

            <div className="panel">
                <h2>营业日</h2>
                <table>
                    <thead>
                        <tr><th>营业日</th><th>状态</th><th>封账人</th><th className="num">快照净额</th><th></th></tr>
                    </thead>
                    <tbody>
                        {days.map((d) => (
                            <tr key={d.date}>
                                <td className="mono">{d.date}</td>
                                <td>{d.status === 'open' ? <span className="tag ok">开放中</span> : <span className="tag gray">已封账</span>}</td>
                                <td>{d.closed_by || '—'}</td>
                                <td className="num">{d.snapshot_net_cents === null ? '—' : fen(d.snapshot_net_cents)}</td>
                                <td>
                                    {d.status === 'closed' && (
                                        <button className="btn small" onClick={() => setSelected(d.date)}>查看核对报告</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {recon && (
                <div className="panel">
                    <h2>全局调整流水对账</h2>
                    <div className="row">
                        <span>调整单 {recon.adjustment_count} 笔，合计 <strong className="mono">{fen(recon.adjustment_total_cents)}</strong></span>
                        <span>房账调整条目合计 <strong className="mono">{fen(recon.ledger_adjustment_total_cents)}</strong></span>
                        {recon.totals_tied && recon.per_item_tied
                            ? <span className="tag ok">✓ 汇总金额与调整流水对得上</span>
                            : <span className="tag bad">✗ 流水不平，请检查</span>}
                    </div>
                </div>
            )}

            {report && (
                <div className="panel">
                    <h2>营业日 {report.date} 核对报告</h2>
                    <table>
                        <thead>
                            <tr><th>项目</th><th className="num">快照（冻结）</th><th className="num">后续调整</th><th className="num">当前有效</th></tr>
                        </thead>
                        <tbody>
                            <ReconRow label="房费" snap={report.snapshot.room_charge_cents}
                                adj={sumAdj(report, 'room_charge_correction')} eff={report.effective.room_charge_cents} />
                            <ReconRow label="加购" snap={report.snapshot.addon_cents}
                                adj={sumAdj(report, 'addon_correction')} eff={report.effective.addon_cents} />
                            <ReconRow label="收款（现金+非现金）" snap={report.snapshot.cash_cents + report.snapshot.card_cents}
                                adj={sumAdj(report, 'late_payment')} eff={report.effective.payment_cents} />
                            <ReconRow label="退款" snap={report.snapshot.refund_cents}
                                adj={sumAdj(report, 'refund_correction')} eff={report.effective.refund_cents} />
                            <tr className="total">
                                <td>净额</td>
                                <td className="num">{fen(report.snapshot.net_cents)}</td>
                                <td className={`num ${report.adjustment_total_cents < 0 ? 'bad' : ''}`}>{fen(report.adjustment_total_cents)}</td>
                                <td className="num">{fen(report.effective_net_cents)}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p>
                        {report.reconciled
                            ? <span className="tag ok">✓ 每笔调整与房账条目金额一致，快照 + 调整流水 = 有效数字</span>
                            : <span className="tag bad">✗ 存在调整与流水不一致</span>}
                        <span className="muted">（快照由 {report.snapshot.closed_by} 于 {new Date(report.snapshot.closed_at).toLocaleString('zh-CN')} 封存，不可修改）</span>
                    </p>

                    <h3>关联到本营业日的调整单（{report.adjustments.length}）</h3>
                    {report.adjustments.length === 0 ? <p className="muted">无。</p> : (
                        <table>
                            <thead>
                                <tr><th>调整单号</th><th>入账营业日</th><th>类型</th><th className="num">金额</th><th>差额原因</th><th>原交易（可追溯）</th></tr>
                            </thead>
                            <tbody>
                                {report.adjustments.map((a) => (
                                    <tr key={a.adjustment_no}>
                                        <td className="mono">{a.adjustment_no}</td>
                                        <td className="mono">{a.business_date}</td>
                                        <td><span className="tag">{ADJ_TYPE_LABELS[a.type] || a.type}</span></td>
                                        <td className={`num ${a.amount_cents < 0 ? 'bad' : ''}`}>{fen(a.amount_cents)}</td>
                                        <td>{a.reason}</td>
                                        <td className="muted">
                                            {a.original_item && (
                                                <>#{a.original_item.id} {TYPE_LABELS[a.original_item.type]} {fen(a.original_item.amount_cents)}<br />
                                                归属 {a.original_item.business_date} · 过账 {new Date(a.original_item.posted_at).toLocaleString('zh-CN')}</>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}
        </>
    );
}

function sumAdj(report, type) {
    return report.adjustments.filter((a) => a.type === type).reduce((s, a) => s + a.amount_cents, 0);
}

function ReconRow({ label, snap, adj, eff }) {
    return (
        <tr>
            <td>{label}</td>
            <td className={`num ${snap < 0 ? 'bad' : ''}`}>{fen(snap)}</td>
            <td className={`num ${adj < 0 ? 'bad' : ''}`}>{adj === 0 ? '—' : fen(adj)}</td>
            <td className={`num ${eff < 0 ? 'bad' : ''}`}>{fen(eff)}</td>
        </tr>
    );
}
