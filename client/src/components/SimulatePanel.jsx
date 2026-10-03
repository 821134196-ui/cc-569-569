import { useState } from 'react';
import { api } from '../api.js';

/**
 * 场景模拟面板：一键生成跨午夜入住、晚到支付、延迟退房等交易，
 * 全部走后端正式服务，归属规则与真实流程一致。
 */
export default function SimulatePanel({ day, folios, onDone }) {
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);
  const [folioId, setFolioId] = useState('');
  const [amount, setAmount] = useState('258.00');

  const run = async (label, fn) => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await fn();
      setMsg({ type: 'success', text: `${label}：${r}` });
      await onDone();
    } catch (e) {
      setMsg({ type: 'error', text: `${label}失败：${e.message}` });
    } finally {
      setBusy(false);
    }
  };

  // 营业日 D 的凌晨 00:30（自然归属 D-1）
  const afterMidnight = `${day}T00:30:00`;
  const lateEvening = `${day}T23:50:00`;
  const yesterdayEvening = offsetDate(day, -1) + 'T23:58:00';
  const yesterdayCheckout = offsetDate(day, -1) + 'T18:30:00';

  const pickFolio = () => folioId || folios[0]?.folio_id;

  return (
    <div className="panel">
      <div className="panel-head">
        <span>场景模拟（本地模拟支付 / 房态接口驱动）</span>
        <span className="muted" style={{ fontWeight: 400, fontSize: 12 }}>用于检验跨日交易的营业日归属</span>
      </div>
      <div className="panel-body">
        {msg && <div className={`banner ${msg.type}`}>{msg.text}</div>}
        <div className="flex mb16">
          <button className="btn" disabled={busy} onClick={() => run('跨午夜入住', async () => {
            const r = await api.post('/simulate/check-in', {
              guest_name: '周凌晨', room_no: '501', check_in_at: afterMidnight, nights: 1, room_rate_cents: 46000,
            });
            return `房单 ${r.folio_no}，房费归属 ${r.items[0].business_date}`;
          })}>凌晨 00:30 入住</button>

          <button className="btn" disabled={busy} onClick={() => run('深夜入住', async () => {
            const r = await api.post('/simulate/check-in', {
              guest_name: '吴深夜', room_no: '502', check_in_at: lateEvening, nights: 2, room_rate_cents: 39800,
            });
            return `房单 ${r.folio_no}，首夜房费归属 ${r.items[0].business_date}`;
          })}>今夜 23:50 入住</button>

          <label className="muted" style={{ fontSize: 12 }}>目标房单:</label>
          <select value={folioId} onChange={(e) => setFolioId(e.target.value)} style={{ padding: '7px 10px', borderRadius: 8, border: '1px solid var(--line)' }}>
            {folios.map((f) => <option key={f.folio_id} value={f.folio_id}>{f.folio_no} · {f.guest_name}</option>)}
          </select>
          <input
            value={amount} onChange={(e) => setAmount(e.target.value)} size={7}
            style={{ padding: '7px 10px', borderRadius: 8, border: '1px solid var(--line)' }}
          /> 元
        </div>
        <div className="flex">
          <button className="btn" disabled={busy} onClick={() => run('晚到支付回执', async () => {
            const r = await api.post('/simulate/payment', {
              folio_id: Number(pickFolio()), method: 'card',
              amount_cents: Math.round(parseFloat(amount) * 100), occurred_at: yesterdayEvening,
            });
            return `回执 ${r.receipt_no}，落账营业日 ${r.business_date}`;
          })}>模拟昨日 23:58 的支付回执今晚才到</button>

          <button className="btn" disabled={busy} onClick={() => run('延迟退房补收', async () => {
            const r = await api.post('/simulate/late-checkout', {
              folio_id: Number(pickFolio()),
              amount_cents: Math.round(parseFloat(amount) * 100), occurred_at: yesterdayCheckout,
            });
            return `补录昨日 18:30 退房费，落账营业日 ${r.business_date}`;
          })}>补录昨日延迟退房费</button>

          <button className="btn" disabled={busy} onClick={() => run('加购入账', async () => {
            const r = await api.post('/simulate/addon', {
              folio_id: Number(pickFolio()), description: '迷你吧消费',
              amount_cents: Math.round(parseFloat(amount) * 100), occurred_at: `${day}T16:00:00`,
            });
            return `加购落账营业日 ${r.business_date}`;
          })}>今日加购入账</button>
        </div>
        <div className="muted mt8" style={{ fontSize: 12 }}>
          提示：昨日已封账时，晚到交易会落入当前营业日并自动生成调整单（关联原营业日与原交易），昨日快照保持不变。
        </div>
      </div>
    </div>
  );
}

function offsetDate(dateStr, days) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + days);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
