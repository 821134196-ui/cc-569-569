import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { dt, kindLabel, signedYuan, yuan } from '../format.js';

export default function FolioPage() {
  const { id } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get(`/folios/${id}`).then(setData).catch((e) => setError(e.message));
  }, [id]);

  if (error) return <div className="banner error">{error}</div>;
  if (!data) return <div className="muted">加载中…</div>;

  const { folio, items, payments, adjustments, balance } = data;

  return (
    <div>
      <div className="page-head">
        <div>
          <h1 className="page-title">房单 {folio.folio_no}</h1>
          <div className="page-sub">
            {folio.guest_name} · {folio.room_no} 房 · {folio.status === 'open' ? '在住' : '已退房'} ·
            入住 {dt(folio.check_in_at)} · {folio.nights} 晚 · 房价 {yuan(folio.room_rate_cents)}/晚
          </div>
        </div>
        <Link className="btn" to="/">← 夜审工作台</Link>
      </div>

      <div className="cards">
        <div className="stat accent"><div className="stat-label">应收合计（含调整）</div><div className="stat-value">{yuan(balance.charge_total_cents)}</div></div>
        <div className="stat good"><div className="stat-label">实收合计</div><div className="stat-value">{yuan(balance.paid_total_cents)}</div></div>
        <div className="stat bad"><div className="stat-label">余额</div><div className="stat-value">{signedYuan(balance.balance_cents)}</div></div>
      </div>

      <div className="panel">
        <div className="panel-head">账目项（{items.length}）</div>
        <div className="panel-body flush">
          <table className="grid">
            <thead><tr><th>#</th><th>类型</th><th>项目</th><th>发生时间</th><th>归属营业日</th><th className="num">金额</th></tr></thead>
            <tbody>
              {items.map((i) => (
                <tr key={i.id}>
                  <td className="muted">{i.id}</td>
                  <td><span className="badge grey">{kindLabel(i.kind)}</span></td>
                  <td>{i.description}</td>
                  <td className="muted">{dt(i.occurred_at)}</td>
                  <td><Link className="trace-link" to={`/days/${i.business_date}`}>{i.business_date}</Link></td>
                  <td className="num">{yuan(i.amount_cents)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">收退款（{payments.length}）</div>
        <div className="panel-body flush">
          <table className="grid">
            <thead><tr><th>#</th><th>方式</th><th>回执号</th><th>发生时间</th><th>归属营业日</th><th className="num">金额</th></tr></thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id}>
                  <td className="muted">{p.id}</td>
                  <td><span className={`badge ${p.amount_cents < 0 ? 'red' : 'blue'}`}>{kindLabel(p.method)}{p.amount_cents < 0 ? '·退款' : ''}</span></td>
                  <td className="muted">{p.receipt_no}</td>
                  <td className="muted">{dt(p.occurred_at)}</td>
                  <td><Link className="trace-link" to={`/days/${p.business_date}`}>{p.business_date}</Link></td>
                  <td className="num">{signedYuan(p.amount_cents)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">关联调整单（{adjustments.length}）</div>
        <div className="panel-body flush">
          {adjustments.length === 0 ? <div className="panel-body muted">无</div> : (
            <table className="grid">
              <thead><tr><th>调整单号</th><th>类型</th><th>落账营业日</th><th>原归属日</th><th className="num">金额</th><th>差额原因</th><th>原交易</th></tr></thead>
              <tbody>
                {adjustments.map((a) => (
                  <tr key={a.id}>
                    <td className="muted">{a.adjustment_no}</td>
                    <td><span className="badge gold">{kindLabel(a.kind)}</span></td>
                    <td><Link className="trace-link" to={`/days/${a.business_date}`}>{a.business_date}</Link></td>
                    <td><Link className="trace-link" to={`/days/${a.original_business_date}`}>{a.original_business_date}</Link></td>
                    <td className="num"><b>{signedYuan(a.amount_cents)}</b></td>
                    <td style={{ maxWidth: 360 }}>{a.reason}</td>
                    <td className="muted">
                      {a.original_item_id && `账目 #${a.original_item_id}`}
                      {a.original_payment_id && `收款 #${a.original_payment_id}`}
                      {!a.original_item_id && !a.original_payment_id && '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
