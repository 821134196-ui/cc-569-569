import React, { useCallback, useEffect, useState } from 'react';
import { api, fen, TYPE_LABELS } from '../api.js';

/**
 * 夜审页面：汇总房费/加购/现金/退款/调整，展开房单逐项核对，
 * 全部核对后方可封账；封账后展示不可修改的快照。
 */
export default function NightAudit({ me }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setError('');
        api('/audit/current').then(setData).catch((e) => setError(e.message));
    }, []);

    useEffect(load, [load]);

    async function verify(folioId) {
        setBusy(true);
        setNotice('');
        try {
            setData(await api(`/audit/folios/${folioId}/verify`, { method: 'POST' }));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }

    async function close() {
        if (!window.confirm(`确认封账营业日 ${data.day.date}？封账后快照不可修改。`)) return;
        setBusy(true);
        setError('');
        try {
            const res = await api('/audit/close', { method: 'POST', body: { business_day_id: data.day.id } });
            setNotice(`${res.message}（快照净额 ${fen(res.snapshot.net_cents)}，操作人 ${res.snapshot.closed_by}）`);
            load();
        } catch (e) {
            // 409：另一夜审员已封账；422：仍有未核对房单
            setError(e.message);
            load();
        } finally {
            setBusy(false);
        }
    }

    if (error && !data) return <div className="notice err">{error}</div>;
    if (!data) return <div className="panel">加载中…</div>;

    const { day, buckets, folios } = data;
    const isAuditor = me?.role === 'auditor';

    return (
        <>
            {notice && <div className="notice ok">{notice}</div>}
            {error && <div className="notice err">{error}</div>}

            <div className="panel">
                <div className="row" style={{ justifyContent: 'space-between' }}>
                    <h2 style={{ margin: 0 }}>
                        营业日 {day.date}{' '}
                        {day.status === 'open' ? <span className="tag ok">开放中</span> : <span className="tag gray">已封账</span>}
                    </h2>
                    {day.status === 'open' && isAuditor && (
                        <button className="btn danger" disabled={!data.can_close || busy} onClick={close}
                            title={data.can_close ? '' : '仍有未核对房单'}>
                            封账（{day.date}）
                        </button>
                    )}
                </div>
                {day.status === 'open' && data.unverified_count > 0 && (
                    <p className="warn">仍有 {data.unverified_count} 本房单未核对，核对全部房单后才能封账。</p>
                )}
                {day.closed_by && (
                    <p className="muted">由 {day.closed_by} 于 {new Date(day.closed_at).toLocaleString('zh-CN')} 封账</p>
                )}
            </div>

            <div className="cards">
                <Card label="房费" value={buckets.room_charge_cents} />
                <Card label="加购" value={buckets.addon_cents} />
                <Card label="现金收款" value={buckets.cash_cents} />
                <Card label="非现金收款" value={buckets.card_cents} />
                <Card label="退款" value={buckets.refund_cents} />
                <Card label="调整" value={buckets.adjustment_cents} />
                <Card label="净额合计" value={buckets.net_cents} strong />
            </div>

            <div className="panel">
                <h2>房单逐项核对（{folios.length} 本）</h2>
                {folios.length === 0 && <p className="muted">本营业日暂无发生额。</p>}
                {folios.map((f) => (
                    <details className="folio" key={f.folio_id}>
                        <summary>
                            <strong>{f.folio_no}</strong>
                            <span>{f.guest_name}</span>
                            <span className="tag gray">{f.room_no} 房</span>
                            <span className="grow" />
                            {f.verified ? (
                                <span className="tag ok">已核对 · {f.verified_by}</span>
                            ) : (
                                <span className="tag warn">未核对</span>
                            )}
                            {day.status === 'open' && !f.verified && isAuditor && (
                                <button className="btn small primary" disabled={busy}
                                    onClick={(e) => { e.preventDefault(); verify(f.folio_id); }}>
                                    核对
                                </button>
                            )}
                        </summary>
                        <div className="items">
                            <table>
                                <thead>
                                    <tr>
                                        <th>项目</th><th>类型</th><th>归属营业日</th><th>过账时间</th>
                                        <th>状态</th><th className="num">金额</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {f.items.map((i) => (
                                        <tr key={i.id}>
                                            <td>{i.description}</td>
                                            <td>{TYPE_LABELS[i.type] || i.type}{i.method ? `（${i.method === 'cash' ? '现金' : '刷卡'}）` : ''}</td>
                                            <td className="mono">{i.business_date}</td>
                                            <td className="mono">{new Date(i.posted_at).toLocaleString('zh-CN')}</td>
                                            <td>{i.status === 'pending' ? <span className="tag warn">回执未到</span> : <span className="tag ok">已入账</span>}</td>
                                            <td className={`num ${i.amount_cents < 0 ? 'bad' : ''}`}>{fen(i.amount_cents)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </details>
                ))}
            </div>

            {data.snapshot && (
                <div className="panel">
                    <h2>夜审快照（不可修改）</h2>
                    <SnapshotView snapshot={data.snapshot} />
                </div>
            )}
        </>
    );
}

function Card({ label, value, strong }) {
    return (
        <div className="card">
            <div className="label">{label}</div>
            <div className={`value ${value < 0 ? 'neg' : ''}`} style={strong ? { color: 'var(--brand)' } : undefined}>
                {fen(value)}
            </div>
        </div>
    );
}

export function SnapshotView({ snapshot }) {
    const rows = [
        ['房费', snapshot.room_charge_cents],
        ['加购', snapshot.addon_cents],
        ['现金收款', snapshot.cash_cents],
        ['非现金收款', snapshot.card_cents],
        ['退款', snapshot.refund_cents],
        ['调整', snapshot.adjustment_cents],
    ];
    return (
        <>
            <table>
                <tbody>
                    {rows.map(([label, v]) => (
                        <tr key={label}><td>{label}</td><td className={`num ${v < 0 ? 'bad' : ''}`}>{fen(v)}</td></tr>
                    ))}
                    <tr className="total"><td>净额</td><td className="num">{fen(snapshot.net_cents)}</td></tr>
                </tbody>
            </table>
            <p className="muted">
                房单 {snapshot.folio_count} 本 · 条目 {snapshot.item_count} 条 ·
                由 {snapshot.closed_by} 于 {new Date(snapshot.closed_at).toLocaleString('zh-CN')} 封存
            </p>
        </>
    );
}
