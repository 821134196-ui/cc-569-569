import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { dt, kindLabel, signedYuan, yuan } from '../format.js';

export default function DayDetailPage() {
  const { date } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get(`/days/${date}`).then(setData).catch((e) => setError(e.message));
  }, [date]);

  if (error) return <div className="banner error">{error}</div>;
  if (!data) return <div className="muted">加载中…</div>;

  const { day, snapshot, landed_adjustments: landed, later_adjustments: later, reconciliation: recon } = data;

  return (
    <div>
      <div className="page-head">
        <div>
          <h1 className="page-title">营业日 {day.date}</h1>
          <div className="page-sub">
            {day.status === 'closed'
              ? <>已封账 · {day.closed_by} · {dt(day.closed_at)} · 快照不可修改</>
              : <>开放中 · 封账后生成快照</>}
          </div>
        </div>
        <Link className="btn" to="/days">← 返回列表</Link>
      </div>

      {snapshot ? (
        <>
          <div className="cards">
            <div className="stat accent"><div className="stat-label">房费（快照）</div><div className="stat-value">{yuan(snapshot.room_total_cents)}</div></div>
            <div className="stat accent"><div className="stat-label">加购（快照）</div><div className="stat-value">{yuan(snapshot.addon_total_cents)}</div></div>
            <div className="stat"><div className="stat-label">现金（快照）</div><div className="stat-value">{yuan(snapshot.cash_total_cents)}</div></div>
            <div className="stat"><div className="stat-label">银行卡（快照）</div><div className="stat-value">{yuan(snapshot.card_total_cents)}</div></div>
            <div className="stat bad"><div className="stat-label">退款（快照）</div><div className="stat-value">{yuan(snapshot.refund_total_cents)}</div></div>
            <div className="stat"><div className="stat-label">营收性调整（快照）</div><div className="stat-value">{signedYuan(snapshot.adjustment_total_cents)}</div></div>
            <div className="stat good"><div className="stat-label">应收合计（快照）</div><div className="stat-value">{yuan(snapshot.net_total_cents)}</div></div>
          </div>

          <div className="panel">
            <div className="panel-head">封账后差额核对</div>
            <div className="panel-body">
              <div className="recon">
                <div className="box"><div className="v">{yuan(snapshot.net_total_cents)}</div><div className="l">快照应收（冻结）</div></div>
                <span className="op">+</span>
                <div className="box"><div className="v">{signedYuan(recon.revenue_delta_cents)}</div><div className="l">后续营收性调整</div></div>
                <span className="op">=</span>
                <div className="box" style={{ borderColor: 'var(--gold)' }}><div className="v">{yuan(recon.revised_charge_total_cents)}</div><div className="l">调整后应收</div></div>
                <div className="box"><div className="v">{signedYuan(recon.memo_delta_cents)}</div><div className="l">备忘性调整（迟到收/入账，金额已计入落账日）</div></div>
              </div>
              <div className="muted mt8" style={{ fontSize: 12 }}>
                快照数字永不改写；封账后的差额全部通过调整单说明，逐笔可追溯到原交易。
              </div>
            </div>
          </div>

          <div className="panel">
            <div className="panel-head">快照房单明细（{snapshot.folio_count} 份）</div>
            <div className="panel-body flush">
              <table className="grid">
                <thead>
                  <tr><th>房单号</th><th>客人</th><th>房号</th><th className="num">房费</th><th className="num">加购</th><th className="num">收款</th><th className="num">退款</th><th className="num">余额</th><th></th></tr>
                </thead>
                <tbody>
                  {snapshot.folios.map((f) => (
                    <tr key={f.folio_no}>
                      <td><b>{f.folio_no}</b></td>
                      <td>{f.guest_name}</td>
                      <td>{f.room_no}</td>
                      <td className="num">{yuan(f.room_total_cents)}</td>
                      <td className="num">{yuan(f.addon_total_cents)}</td>
                      <td className="num">{yuan(f.payment_total_cents)}</td>
                      <td className="num">{yuan(f.refund_total_cents)}</td>
                      <td className="num">{signedYuan(f.balance_cents)}</td>
                      <td><Link className="trace-link" to={`/folios/${f.folio_id}`}>追溯 →</Link></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      ) : (
        <div className="banner info">该营业日尚未封账，暂无快照。</div>
      )}

      <AdjustmentsTable
        title={`关联本营业日的后续调整（${later.length}）—— 差额来源追溯`}
        rows={later}
        showLandingDay
        empty="封账后暂无关联本日的调整"
      />
      <AdjustmentsTable
        title={`落账于本营业日的调整流水（${landed.length}）`}
        rows={landed}
        empty="本日无调整流水"
      />
    </div>
  );
}

export function AdjustmentsTable({ title, rows, empty, showLandingDay = false }) {
  return (
    <div className="panel">
      <div className="panel-head">{title}</div>
      <div className="panel-body flush">
        {rows.length === 0 ? <div className="panel-body muted">{empty}</div> : (
          <table className="grid">
            <thead>
              <tr>
                <th>调整单号</th><th>类型</th><th>房单</th>
                {showLandingDay && <th>落账日</th>}
                <th>原归属日</th><th className="num">金额</th><th>影响营收</th><th>差额原因</th><th>原交易</th><th>经办</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((a) => (
                <tr key={a.id}>
                  <td className="muted">{a.adjustment_no}</td>
                  <td><span className="badge gold">{kindLabel(a.kind)}</span></td>
                  <td><Link className="trace-link" to={`/folios/${a.folio_id}`}>{a.folio_no}</Link><div className="muted" style={{ fontSize: 11 }}>{a.guest_name}</div></td>
                  {showLandingDay && <td>{a.business_date}</td>}
                  <td>{a.original_business_date}</td>
                  <td className="num"><b>{signedYuan(a.amount_cents)}</b></td>
                  <td>{a.affects_revenue ? <span className="badge amber">是</span> : <span className="badge grey">备忘</span>}</td>
                  <td style={{ maxWidth: 320 }}>{a.reason}</td>
                  <td className="muted" style={{ fontSize: 12 }}>
                    {a.original_item_id && `账目 #${a.original_item_id}`}
                    {a.original_payment_id && `收款 #${a.original_payment_id}`}
                    {!a.original_item_id && !a.original_payment_id && '—'}
                  </td>
                  <td className="muted">{a.created_by}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
