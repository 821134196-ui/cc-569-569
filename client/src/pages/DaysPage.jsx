import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { dt, yuan } from '../format.js';

export default function DaysPage() {
  const [days, setDays] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/days').then((d) => setDays(d.days)).catch((e) => setError(e.message));
  }, []);

  if (error) return <div className="banner error">{error}</div>;
  if (!days) return <div className="muted">加载中…</div>;

  return (
    <div>
      <div className="page-head">
        <div>
          <h1 className="page-title">营业日与快照</h1>
          <div className="page-sub">封账后快照不可修改；迟到交易通过调整单关联原营业日</div>
        </div>
      </div>
      <div className="panel">
        <div className="panel-body flush">
          <table className="grid">
            <thead>
              <tr>
                <th>营业日</th><th>状态</th><th>封账人</th><th>封账时间</th>
                <th className="num">房费</th><th className="num">加购</th>
                <th className="num">现金</th><th className="num">银行卡</th><th className="num">退款</th>
                <th className="num">应收合计</th><th></th>
              </tr>
            </thead>
            <tbody>
              {days.map((d) => (
                <tr key={d.date}>
                  <td><b>{d.date}</b></td>
                  <td>{d.status === 'closed' ? <span className="badge grey">已封账</span> : <span className="badge green">开放中</span>}</td>
                  <td>{d.closed_by ?? '—'}</td>
                  <td className="muted">{d.closed_at ? dt(d.closed_at) : '—'}</td>
                  <td className="num">{d.snapshot ? yuan(d.snapshot.room_total_cents) : '—'}</td>
                  <td className="num">{d.snapshot ? yuan(d.snapshot.addon_total_cents) : '—'}</td>
                  <td className="num">{d.snapshot ? yuan(d.snapshot.cash_total_cents) : '—'}</td>
                  <td className="num">{d.snapshot ? yuan(d.snapshot.card_total_cents) : '—'}</td>
                  <td className="num">{d.snapshot ? yuan(d.snapshot.refund_total_cents) : '—'}</td>
                  <td className="num"><b>{d.snapshot ? yuan(d.snapshot.net_total_cents) : '—'}</b></td>
                  <td><Link className="trace-link" to={`/days/${d.date}`}>详情 →</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
