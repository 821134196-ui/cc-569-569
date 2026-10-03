import React, { useCallback, useEffect, useState } from 'react';
import { api, fen, ADJ_TYPE_LABELS, TYPE_LABELS } from '../api.js';

/**
 * 调整单：封账后的迟到收款 / 房费更正等全部记录在此，
 * 每笔关联原房账条目与差额原因。
 */
export default function Adjustments({ me }) {
    const [list, setList] = useState([]);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const load = useCallback(() => {
        api('/adjustments').then(setList).catch((e) => setError(e.message));
    }, []);

    useEffect(load, [load]);

    return (
        <>
            {notice && <div className="notice ok">{notice}</div>}
            {error && <div className="notice err">{error}</div>}

            <CreateAdjustment
                onDone={(msg) => { setNotice(msg); setError(''); load(); }}
                onError={(msg) => { setError(msg); setNotice(''); }}
            />

            <div className="panel">
                <h2>调整单流水（{list.length}）</h2>
                {list.length === 0 ? <p className="muted">暂无调整单。</p> : (
                    <table>
                        <thead>
                            <tr>
                                <th>单号</th><th>入账营业日</th><th>房单</th><th>类型</th>
                                <th className="num">调整金额</th><th>差额原因</th><th>原交易</th><th>经办</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.map((a) => (
                                <tr key={a.adjustment_no}>
                                    <td className="mono">{a.adjustment_no}</td>
                                    <td className="mono">{a.business_date}</td>
                                    <td>{a.folio_no}（{a.guest_name}）</td>
                                    <td><span className="tag">{ADJ_TYPE_LABELS[a.type] || a.type}</span></td>
                                    <td className={`num ${a.amount_cents < 0 ? 'bad' : ''}`}>{fen(a.amount_cents)}</td>
                                    <td>{a.reason}</td>
                                    <td className="muted">
                                        {a.original_item
                                            ? <>{TYPE_LABELS[a.original_item.type]} {fen(a.original_item.amount_cents)}<br />归属 {a.original_item.business_date}</>
                                            : '—'}
                                    </td>
                                    <td>{a.created_by}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}

function CreateAdjustment({ onDone, onError }) {
    const [folios, setFolios] = useState([]);
    const [folioId, setFolioId] = useState('');
    const [folioDetail, setFolioDetail] = useState(null);
    const [itemId, setItemId] = useState('');
    const [type, setType] = useState('room_charge_correction');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');

    useEffect(() => {
        api('/stays').then((stays) => {
            setFolios(stays);
            if (stays.length > 0) setFolioId((cur) => cur || String(stays[0].folio_id));
        }).catch(() => {});
    }, []);

    useEffect(() => {
        if (!folioId) return;
        setItemId('');
        api(`/folios/${folioId}`).then((d) => {
            setFolioDetail(d);
            const first = d.items.find((i) => i.type !== 'adjustment');
            if (first) setItemId(String(first.id));
        }).catch(() => setFolioDetail(null));
    }, [folioId]);

    const correctable = (folioDetail?.items || []).filter((i) => i.type !== 'adjustment');

    async function submit() {
        try {
            const res = await api('/adjustments', {
                method: 'POST',
                body: {
                    folio_id: Number(folioId),
                    original_item_id: Number(itemId),
                    type,
                    amount_cents: Math.round(parseFloat(amount) * 100),
                    reason_code: type,
                    reason,
                },
            });
            onDone(`调整单 ${res.adjustment_no} 已创建，入账营业日 ${res.business_date}`);
        } catch (e) {
            onError(e.message);
        }
    }

    return (
        <div className="panel">
            <h2>新建更正调整单</h2>
            <div className="form-grid">
                <label>房单
                    <select value={folioId} onChange={(e) => setFolioId(e.target.value)}>
                        {folios.map((s) => (
                            <option key={s.folio_id} value={s.folio_id}>{s.folio_no} · {s.guest_name}（{s.room_no} 房）</option>
                        ))}
                    </select>
                </label>
                <label>原交易条目
                    <select value={itemId} onChange={(e) => setItemId(e.target.value)}>
                        {correctable.map((i) => (
                            <option key={i.id} value={i.id}>
                                #{i.id} {TYPE_LABELS[i.type]} {fen(i.amount_cents)}（归属 {i.business_date}）
                            </option>
                        ))}
                    </select>
                </label>
                <label>调整类型
                    <select value={type} onChange={(e) => setType(e.target.value)}>
                        <option value="room_charge_correction">房费更正</option>
                        <option value="addon_correction">加购更正</option>
                        <option value="refund_correction">退款更正</option>
                    </select>
                </label>
                <label>调整金额（元，负数为冲减）
                    <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="-50.00" />
                </label>
                <label>差额原因
                    <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="房价录入错误，应为 538.00" />
                </label>
                <div>
                    <button className="btn primary" disabled={!itemId || !amount || !reason} onClick={submit}>
                        创建调整单
                    </button>
                </div>
            </div>
            <p className="muted">调整单落在当前开放营业日，不修改原营业日已封账的数字；原交易条目保持不变，可追溯。</p>
        </div>
    );
}
