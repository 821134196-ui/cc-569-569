import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { dt, kindLabel, signedYuan, yuan } from '../format.js';
import SimulatePanel from '../components/SimulatePanel.jsx';

const AUDITORS = ['张夜审', '李夜审'];

export default function AuditPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [auditor, setAuditor] = useState(AUDITORS[0]);
  const [openFolios, setOpenFolios] = useState(new Set());
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setData(await api.get('/audit/current'));
      setError(null);
    } catch (e) {
      setError(e.message);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const run = async (fn, okMsg) => {
    setBusy(true);
    setNotice(null);
    try {
      await fn();
      if (okMsg) setNotice({ type: 'success', text: okMsg });
      await load();
    } catch (e) {
      setNotice({ type: 'error', text: e.message });
    } finally {
      setBusy(false);
    }
  };

  if (error) return <div className="banner error">加载失败：{error}</div>;
  if (!data) return <div className="muted">加载中…</div>;

  const { day, totals, folios, unchecked_count: unchecked, room_status: rooms, room_warnings: warnings } = data;
  const closed = day.status === 'closed';

  const toggle = (id) => {
    setOpenFolios((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const checkFolio = (folioId, checked) =>
    run(async () => {
      if (checked) await api.del(`/audit/${day.date}/folios/${folioId}/check`);
      else await api.post(`/audit/${day.date}/folios/${folioId}/check`, { auditor });
    });

  const postRoomCharges = () =>
    run(async () => {
      const r = await api.post('/audit/post-room-charges', { auditor });
      setNotice({ type: 'info', text: `已过账 ${r.posted} 笔当夜房费` });
    });

  const closeDay = () => {
    if (!window.confirm(`确认封账营业日 ${day.date}？封账后快照不可修改。`)) return;
    return run(
      () => api.post(`/audit/${day.date}/close`, { auditor }),
      `营业日 ${day.date} 已封账，快照已保存`,
    );
  };

  return (
    <div>
      <div className="page-head">
        <div>
          <h1 className="page-title">夜审工作台</h1>
          <div className="page-sub">
            营业日 <b>{day.date}</b>{' '}
            {closed ? <span className="badge grey">已封账 · {day.closed_by}</span> : <span className="badge green">开放中</span>}
            {unchecked > 0 && !closed && <span className="badge amber" style={{ marginLeft: 8 }}>{unchecked} 份房单待核对</span>}
          </div>
        </div>
        <div className="flex">
          <select value={auditor} onChange={(e) => setAuditor(e.target.value)} style={{ padding: '7px 10px', borderRadius: 8, border: '1px solid var(--line)' }}>
            {AUDITORS.map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
          {!closed && (
            <>
              <button className="btn" disabled={busy} onClick={postRoomCharges}>过账当夜房费</button>
              <button
                className="btn gold"
                disabled={busy || unchecked > 0}
                title={unchecked > 0 ? `仍有 ${unchecked} 份房单未核对` : '全部房单已核对，可以封账'}
                onClick={closeDay}
              >
                封账并保存快照
              </button>
            </>
          )}
          {closed && <Link className="btn primary" to={`/days/${day.date}`}>查看该日快照</Link>}
        </div>
      </div>

      {notice && <div className={`banner ${notice.type}`}>{notice.text}</div>}
      {unchecked > 0 && !closed && (
        <div className="banner warn">仍有 {unchecked} 份房单未核对，核对全部房单后才能封账。</div>
      )}
      {warnings.length > 0 && (
        <div className="banner warn">
          房态交叉核对提示：{warnings.map((w) => `${w.room_no} 房（${w.folio_no}）`).join('、')} 在店但本营业日无账务活动，请确认是否漏账。
        </div>
      )}

      <div className="cards">
        <div className="stat accent"><div className="stat-label">房费</div><div className="stat-value">{yuan(totals.room_cents)}</div></div>
        <div className="stat accent"><div className="stat-label">加购项目</div><div className="stat-value">{yuan(totals.addon_cents)}</div></div>
        <div className="stat"><div className="stat-label">现金收款</div><div className="stat-value">{yuan(totals.cash_cents)}</div></div>
        <div className="stat"><div className="stat-label">银行卡收款</div><div className="stat-value">{yuan(totals.card_cents)}</div></div>
        <div className="stat bad"><div className="stat-label">退款</div><div className="stat-value">{yuan(totals.refund_cents)}</div></div>
        <div className="stat"><div className="stat-label">营收性调整</div><div className="stat-value">{signedYuan(totals.adjustment_revenue_cents)}</div>
          <div className="stat-note">备忘性调整 {signedYuan(totals.adjustment_memo_cents)}</div></div>
        <div className="stat good"><div className="stat-label">应收合计</div><div className="stat-value">{yuan(totals.charge_total_cents)}</div></div>
        <div className="stat good"><div className="stat-label">实收合计</div><div className="stat-value">{yuan(totals.received_total_cents)}</div></div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <span>房单逐项核对（{folios.length} 份）</span>
          <span className="muted" style={{ fontWeight: 400, fontSize: 12 }}>点击行展开账目明细，确认无误后点"核对"</span>
        </div>
        <div className="panel-body flush">
          {folios.length === 0 && <div className="panel-body muted">本营业日暂无账务活动。</div>}
          {folios.map((f) => (
            <FolioRow
              key={f.folio_id}
              f={f}
              open={openFolios.has(f.folio_id)}
              onToggle={() => toggle(f.folio_id)}
              onCheck={() => checkFolio(f.folio_id, f.checked)}
              closed={closed}
            />
          ))}
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">房态交叉核对（本地模拟房态接口）</div>
        <div className="panel-body">
          <div className="rooms">
            {rooms.map((r) => (
              <div key={r.room_no} className={`room-chip ${r.status}`}>
                <div className="rn">{r.room_no}</div>
                <div>{r.status === 'occupied' ? '在住' : r.status === 'due_out' ? '今日应退' : '空房'}</div>
                {r.folio_no && <div className="muted" style={{ fontSize: 11 }}>{r.folio_no}</div>}
              </div>
            ))}
          </div>
        </div>
      </div>

      {!closed && <SimulatePanel day={day.date} folios={folios} onDone={load} />}
    </div>
  );
}

function FolioRow({ f, open, onToggle, onCheck, closed }) {
  return (
    <div className={`folio ${open ? 'open' : ''}`}>
      <div className="folio-head" onClick={onToggle}>
        <span className="arrow">▶</span>
        <span className="folio-no">{f.folio_no}</span>
        <span>
          <span className="folio-guest">{f.guest_name}</span>{' '}
          <span className="folio-room">{f.room_no} 房 · {f.status === 'open' ? '在住' : '已退房'}</span>
        </span>
        <span className="folio-num hide-sm muted">房费 {yuan(f.room_cents)}</span>
        <span className="folio-num hide-sm muted">加购 {yuan(f.addon_cents)}</span>
        <span className="folio-num">收付 {signedYuan(f.paid_cents)}</span>
        <span className="right" onClick={(e) => e.stopPropagation()}>
          {f.checked ? (
            <>
              <span className="badge green">已核对 · {f.checked_by}</span>{' '}
              {!closed && <button className="btn small" onClick={onCheck}>撤销</button>}
            </>
          ) : (
            <button className="btn small primary" disabled={closed} onClick={onCheck}>核对</button>
          )}
        </span>
      </div>
      {open && (
        <div className="folio-detail">
          <div className="detail-cols three">
            <div>
              <div className="mini-title">账目项（{f.items.length}）</div>
              {f.items.length === 0 ? <div className="empty-line">无</div> : (
                <table className="mini-table">
                  <thead><tr><th>项目</th><th>发生时间</th><th className="num">金额</th></tr></thead>
                  <tbody>
                    {f.items.map((i) => (
                      <tr key={i.id}>
                        <td><span className="badge grey">{kindLabel(i.kind)}</span> {i.description}</td>
                        <td className="muted">{dt(i.occurred_at)}</td>
                        <td className="num">{yuan(i.amount_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div>
              <div className="mini-title">收退款（{f.payments.length}）</div>
              {f.payments.length === 0 ? <div className="empty-line">无</div> : (
                <table className="mini-table">
                  <thead><tr><th>方式</th><th>回执号</th><th className="num">金额</th></tr></thead>
                  <tbody>
                    {f.payments.map((p) => (
                      <tr key={p.id}>
                        <td><span className={`badge ${p.amount_cents < 0 ? 'red' : 'blue'}`}>{kindLabel(p.method)}{p.amount_cents < 0 ? '·退' : ''}</span></td>
                        <td className="muted">{p.receipt_no}</td>
                        <td className="num">{signedYuan(p.amount_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div>
              <div className="mini-title">关联调整单（{f.adjustments.length}）</div>
              {f.adjustments.length === 0 ? <div className="empty-line">无</div> : (
                <table className="mini-table">
                  <thead><tr><th>类型</th><th>原归属日</th><th className="num">金额</th></tr></thead>
                  <tbody>
                    {f.adjustments.map((a) => (
                      <tr key={a.id} title={a.reason}>
                        <td><span className="badge gold">{kindLabel(a.kind)}</span></td>
                        <td className="muted">{a.original_business_date}</td>
                        <td className="num">{signedYuan(a.amount_cents)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              <div className="mt8"><Link className="trace-link" to={`/folios/${f.folio_id}`}>查看房单全账 →</Link></div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
