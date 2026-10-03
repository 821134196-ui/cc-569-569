// 统一 fetch 封装：携带当前操作人（X-User-Id），解析 JSON 错误。
let currentUserId = localStorage.getItem('night-audit-user-id') || '';

export function setCurrentUserId(id) {
    currentUserId = id;
    localStorage.setItem('night-audit-user-id', id);
}

export function getCurrentUserId() {
    return currentUserId;
}

export async function api(path, { method = 'GET', body } = {}) {
    const res = await fetch(`/api${path}`, {
        method,
        headers: {
            'Accept': 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(currentUserId ? { 'X-User-Id': currentUserId } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = res.status === 204 ? null : await res.json().catch(() => null);
    if (!res.ok) {
        const err = new Error(data?.message || `请求失败（${res.status}）`);
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

// 分 → 元 显示。收款/退款为负值（冲减应收）。
export function fen(cents, { sign = true } = {}) {
    if (cents === null || cents === undefined) return '—';
    const abs = Math.abs(cents) / 100;
    const text = `¥${abs.toLocaleString('zh-CN', { minimumFractionDigits: 2 })}`;
    if (!sign) return text;
    if (cents < 0) return `-${text}`;
    return text;
}

export const TYPE_LABELS = {
    room_charge: '房费',
    addon: '加购',
    payment: '收款',
    refund: '退款',
    adjustment: '调整',
};

export const ADJ_TYPE_LABELS = {
    late_payment: '迟到收款',
    room_charge_correction: '房费更正',
    addon_correction: '加购更正',
    refund_correction: '退款更正',
};

export const ROLE_LABELS = {
    auditor: '夜审员',
    frontdesk: '前台',
    finance: '财务',
};
