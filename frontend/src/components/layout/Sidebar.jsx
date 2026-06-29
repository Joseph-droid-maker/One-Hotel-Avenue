import { NavLink, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useAuth } from '../../context/AuthContext.jsx';
import toast from 'react-hot-toast';
import Modal from '../ui/Modal.jsx';
import logo from '../../assets/Logo.png';

const NAV = [
  { to: '/pos',      icon: 'fi fi-sr-apps',       label: 'Menu',     roles: ['admin', 'staff'] },
  { to: '/products', icon: 'fi fi-sr-box',        label: 'Products', roles: ['admin'] },
  { to: '/sales',    icon: 'fi fi-sr-receipt',    label: 'History',  roles: ['admin'] },
  { to: '/expenses', icon: 'fi fi-sr-money-bill-wave', label: 'Expenses', roles: ['admin', 'staff'] },
  { to: '/reports',  icon: 'fi fi-sr-chart-pie', label: 'Reports',  roles: ['admin'] },
  { to: '/users',    icon: 'fi fi-sr-users',      label: 'Users',    roles: ['admin'] },
];

export default function Sidebar({ open, onClose }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [confirmLogout, setConfirmLogout] = useState(false);

  const handleLogout = async () => {
    setConfirmLogout(false);
    await logout();
    toast.success('Logged out.');
    navigate('/login');
  };

  const visible = NAV.filter(n => n.roles.includes(user?.role));

  return (
    <>
      {/* sidebar--open adds `left: 0` on mobile to slide the panel into view */}
      <aside className={`sidebar${open ? ' sidebar--open' : ''}`}>

        {/* Brand + mobile close button */}
        <div className="sidebar__brand">
          <span className="sidebar__logo"><img src={logo} alt="Jing-Jing Store Logo"/></span>
          <span className="sidebar__name">Jing Jing</span>
          {/* Close button: only visible on mobile via CSS */}
          <button
            className="sidebar__close"
            onClick={onClose}
            aria-label="Close navigation menu"
          >
            <i className="fi fi-sr-cross"></i>
          </button>
        </div>

        {/* Navigation links */}
        <nav className="sidebar__nav">
          {visible.map(item => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                `sidebar__link ${isActive ? 'sidebar__link--active' : ''}`
              }
              onClick={onClose}
            >
              <span className="sidebar__icon">
                <i className={item.icon}></i>
              </span>
              <span className="sidebar__label">{item.label}</span>
            </NavLink>
          ))}
        </nav>

        {/* Logout */}
        <button
          className="sidebar__logout"
          onClick={() => setConfirmLogout(true)}
        >
          <span><i className="fi fi-sr-sign-out-alt"></i></span>
          <span id="logout__label">Logout</span>
        </button>
      </aside>

      {/* Logout confirmation modal */}
      <Modal
        open={confirmLogout}
        onClose={() => setConfirmLogout(false)}
        title="Confirm Logout"
        danger
        size="sm"
        footer={
          <div className="modal-footer-btns">
            <button className="btn btn-ghost" onClick={() => setConfirmLogout(false)}>
              Cancel
            </button>
            <button className="btn btn-danger" onClick={handleLogout}>
              Yes, Logout
            </button>
          </div>
        }
      >
        <p>Are you sure you want to log out?</p>
      </Modal>
    </>
  );
}