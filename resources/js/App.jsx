import React, { useEffect, useState } from 'react';
import { api, getCurrentUserId, setCurrentUserId, ROLE_LABELS } from './api.js';
import NightAudit from './pages/NightAudit.jsx';
import FrontDesk from './pages/FrontDesk.jsx';
import Adjustments from './pages/Adjustments.jsx';
import Finance from './pages/Finance.jsx';

const TABS = [
    { key: 'audit', label: '夜审' },
    { key: 'frontdesk', label: '前台 / 模拟' },
    { key: 'adjustments', label: '调整单' },
    { key: 'finance', label: '财务核对' },
];

export default function App() {
    const [users, setUsers] = useState([]);
    const [userId, setUserId] = useState(getCurrentUserId());
    const [tab, setTab] = useState('audit');

    useEffect(() => {
        api('/users').then((list) => {
            setUsers(list);
            if (!userId && list.length > 0) {
                const first = list.find((u) => u.role === 'auditor') || list[0];
                setUserId(String(first.id));
                setCurrentUserId(String(first.id));
            }
        });
    }, []);

    const me = users.find((u) => String(u.id) === String(userId));

    return (
        <>
            <header className="topbar">
                <h1>酒店夜审系统</h1>
                <nav className="tabs">
                    {TABS.map((t) => (
                        <button key={t.key} className={tab === t.key ? 'active' : ''} onClick={() => setTab(t.key)}>
                            {t.label}
                        </button>
                    ))}
                </nav>
                <div className="spacer" />
                <label className="muted">
                    操作人：{' '}
                    <select
                        value={userId}
                        onChange={(e) => {
                            setUserId(e.target.value);
                            setCurrentUserId(e.target.value);
                        }}
                    >
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}（{ROLE_LABELS[u.role] || u.role}）
                            </option>
                        ))}
                    </select>
                </label>
            </header>

            {tab === 'audit' && <NightAudit me={me} />}
            {tab === 'frontdesk' && <FrontDesk me={me} />}
            {tab === 'adjustments' && <Adjustments me={me} />}
            {tab === 'finance' && <Finance me={me} />}

            <footer className="hint">
                金额单位：元（收款/退款为负值，冲减应收）。归属规则：房费归住宿夜开始日；加购/退款归发生日；
                收款归发起日，回执晚到且原日已封账时以调整单计入当前开放营业日。
            </footer>
        </>
    );
}
