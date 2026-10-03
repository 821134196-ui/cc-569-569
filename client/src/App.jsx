import { NavLink, Route, Routes } from 'react-router-dom';
import AuditPage from './pages/AuditPage.jsx';
import DaysPage from './pages/DaysPage.jsx';
import DayDetailPage from './pages/DayDetailPage.jsx';
import AdjustmentsPage from './pages/AdjustmentsPage.jsx';
import FolioPage from './pages/FolioPage.jsx';

export default function App() {
  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark">夜</div>
          <div>
            <div className="brand-name">酒店夜审系统</div>
            <div className="brand-sub">跨日房账 · 差额核对</div>
          </div>
        </div>
        <nav>
          <NavLink to="/" end className={({ isActive }) => (isActive ? 'nav-item active' : 'nav-item')}>
            <span className="nav-icon">◈</span>夜审工作台
          </NavLink>
          <NavLink to="/days" className={({ isActive }) => (isActive ? 'nav-item active' : 'nav-item')}>
            <span className="nav-icon">▤</span>营业日与快照
          </NavLink>
          <NavLink to="/adjustments" className={({ isActive }) => (isActive ? 'nav-item active' : 'nav-item')}>
            <span className="nav-icon">✎</span>调整单
          </NavLink>
        </nav>
        <div className="sidebar-foot">支付 / 房态接口：本地模拟</div>
      </aside>
      <main className="content">
        <Routes>
          <Route path="/" element={<AuditPage />} />
          <Route path="/days" element={<DaysPage />} />
          <Route path="/days/:date" element={<DayDetailPage />} />
          <Route path="/adjustments" element={<AdjustmentsPage />} />
          <Route path="/folios/:id" element={<FolioPage />} />
        </Routes>
      </main>
    </div>
  );
}
