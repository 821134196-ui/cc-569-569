import { useCallback, useEffect, useState } from 'react';
import { api } from '../api.js';
import { yuan } from '../format.js';
import { AdjustmentsTable } from './DayDetailPage.jsx';

export default function AdjustmentsPage() {
  const [rows, setRows] = useState(null);
  const [folios, setFolios] = useState([]);
  const [folioItems, setFolioItems] = useState([]);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const [form, setForm] = useState({
    folio_id: '',
    kind: 'room_correction',
    original_item_id: '',
    amount: '',
    reason: '',
    created_by: '财务',
  });

  const load = useCallback(async () => {
    try {
      const [a, f] = await Promise.all([api.get('/adjustments'), api.get('/folios')]);
      setRows(a.adjustments);
      setFolios(f.folios);
      if (!form.folio_id && f.folios.length > 0) {
        setForm((prev) => ({ ...prev, folio_id: String(f.folios[0].id) }));
      }
    } catch (e) {
      setError(e.message);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // 选中房单后加载其账目项，供关联原交易
  useEffect(() => {
    if (!form.folio_id) return;
    api.get(`/folios/${form.folio_id}`).then((d) => {
      setFolioItems(d.items);
      setForm((prev) => ({ ...prev, original_item_id: d.items.length ? String(d.items[0].id) : '' }));
    }).catch(() => setFolioItems([]));
  }, [form.folio_id]);

  const submit = async (e) => {
    e.preventDefault();
    setNotice(null);
    try {
      const r = await api.post('/adjustments', {
        folio_id: Number(form.folio_id),
        kind: form.kind,
        amount_cents: Math.round(parseFloat(form.amount) * 100),
        reason: form.reason,
        original_item_id: Number(form.original_item_id),
        created_by: form.created_by,
      });
      setNotice({ type: 'success', text: `调整单 ${r.adjustment_no} 已创建，落账营业日 ${r.business_date}，原归属 ${r.original_business_date}` });
      setForm((prev) => ({ ...prev, amount: '', reason: '' }));
      await load();
    } catch (e2) {
      setNotice({ type: 'error', text: e2.message });
    }
  };

  if (error) return <div className="banner error">{error}</div>;

  return (
    <div>
      <div className="page-head">
        <div>
          <h1 className="page-title">调整单</h1>
          <div className="page-sub">封账后的迟到收款与房费/加购更正，只增不改，逐笔关联原交易</div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">新建调整单（计入当前开放营业日）</div>
        <div className="panel-body">
          {notice && <div className={`banner ${notice.type}`}>{notice.text}</div>}
          <form onSubmit={submit}>
            <div className="form-grid">
              <div className="field">
                <label>房单</label>
                <select value={form.folio_id} onChange={(e) => setForm({ ...form, folio_id: e.target.value })} required>
                  {folios.map((f) => <option key={f.id} value={f.id}>{f.folio_no} · {f.guest_name}（{f.room_no}）</option>)}
                </select>
              </div>
              <div className="field">
                <label>类型</label>
                <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                  <option value="room_correction">房费更正</option>
                  <option value="addon_correction">加购更正</option>
                </select>
              </div>
              <div className="field">
                <label>关联原账目项（追溯依据）</label>
                <select value={form.original_item_id} onChange={(e) => setForm({ ...form, original_item_id: e.target.value })} required>
                  {folioItems.map((i) => (
                    <option key={i.id} value={i.id}>#{i.id} {i.description} · {yuan(i.amount_cents)} · 归属 {i.business_date}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label>金额（元，负数为调减）</label>
                <input type="number" step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="-30.00" />
              </div>
              <div className="field">
                <label>经办人</label>
                <input value={form.created_by} onChange={(e) => setForm({ ...form, created_by: e.target.value })} required />
              </div>
              <div className="field" style={{ gridColumn: '1 / -1' }}>
                <label>差额原因</label>
                <input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required placeholder="例：房价录错，应为 428 元" />
              </div>
            </div>
            <div className="mt16">
              <button className="btn primary" type="submit">创建调整单</button>
            </div>
          </form>
        </div>
      </div>

      {rows === null ? <div className="muted">加载中…</div> : (
        <AdjustmentsTable title={`调整单流水（${rows.length}）`} rows={rows} showLandingDay empty="暂无调整单" />
      )}
    </div>
  );
}
