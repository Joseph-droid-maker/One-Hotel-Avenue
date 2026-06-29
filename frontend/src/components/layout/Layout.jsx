import { Outlet } from 'react-router-dom';
import { useState } from 'react';
import Sidebar from './Sidebar.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import '../../assets/uicons-solid-rounded/css/uicons-solid-rounded.css';

export default function Layout() {
  const { user } = useAuth();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="app-shell">
      {/* Sidebar — receives open state so it can apply the --open modifier class */}
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Dark scrim behind open sidebar on mobile — click to dismiss */}
      {sidebarOpen && (
        <div className="sidebar-overlay" onClick={() => setSidebarOpen(false)} />
      )}

      <main className="main">
        {/* Top bar */}
        <div className="topbar">
          <div className="topbar__left" style={{ display: 'flex', alignItems: 'center' }}>
            {/* Hamburger — hidden on desktop via CSS, visible on mobile */}
            <button
              className="topbar__hamburger"
              onClick={() => setSidebarOpen(true)}
              aria-label="Open navigation menu"
            >
              <i className="fi fi-sr-menu-burger"></i>
            </button>
            <span className="topbar__store">Jing-Jing Store</span>
          </div>

          <div className="topbar__right">
            <span className="topbar__user">
              <span className="topbar__avatar">
                {(user?.full_name || 'U')[0].toUpperCase()}
              </span>
              <span>{user?.full_name}</span>
              <span className={`badge badge--role badge--${user?.role}`}>
                {user?.role}
              </span>
            </span>
          </div>
        </div>

        {/* Page content */}
        <div className="page-content">
          <Outlet />
        </div>
      </main>
    </div>
  );
}