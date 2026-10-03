export function fen(cents) {
  const v = (cents ?? 0) / 100;
  return v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function yuan(cents) {
  return `¥${fen(cents)}`;
}

export function signedYuan(cents) {
  const sign = cents > 0 ? '+' : cents < 0 ? '-' : '';
  return `${sign}¥${fen(Math.abs(cents ?? 0))}`;
}

export function dt(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export const KIND_LABELS = {
  room_charge: '房费',
  addon: '加购',
  cash: '现金',
  card: '银行卡',
  late_payment: '迟到收款',
  late_charge: '迟到入账',
  room_correction: '房费更正',
  addon_correction: '加购更正',
};

export function kindLabel(kind) {
  return KIND_LABELS[kind] ?? kind;
}
