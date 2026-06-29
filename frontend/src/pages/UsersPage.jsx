import { useState, useEffect, useCallback } from 'react';
import toast from 'react-hot-toast';
import { api } from '../utils/api.js';
import { useAuth } from '../context/AuthContext.jsx';
import Modal from '../components/ui/Modal.jsx';
import Banner from '../components/ui/Banner.jsx';
import '../assets/uicons-solid-rounded/css/uicons-solid-rounded.css';


export default function UsersPage() {
  const { user: me } = useAuth();

  const [users,    setUsers]    = useState([]);
  const [loading,  setLoading]  = useState(true);
  const [banner,   setBanner]   = useState(null);
  const [modal,    setModal]    = useState(null); // null | 'form'
  const [editing,  setEditing]  = useState(null);
  const [deactId,  setDeactId]  = useState(null);

  const EMPTY = { username: '', full_name: '', password: '', role: 'staff' };
  const [form,      setForm]      = useState(EMPTY);
  const [formErrors,setFormErrors]= useState({});
  const [saving,    setSaving]    = useState(false);

  // ── Load users ─────────────────────────────────────────────
  const load = useCallback(async () => {
    setLoading(true); setBanner(null);
    try {
      const r = await api.get('/users/index.php');
      setUsers(r.data || []);
    } catch (err) {
      setBanner({ type: 'error', msg: err.message });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // ── Open add/edit ──────────────────────────────────────────
  const openAdd = () => {
    setForm(EMPTY); setFormErrors({}); setEditing(null); setModal('form');
  };

  const openEdit = (u) => {
    setForm({ username: u.username, full_name: u.full_name, password: '', role: u.role });
    setFormErrors({}); setEditing(u); setModal('form');
  };

  // ── Validate ───────────────────────────────────────────────
  const validate = () => {
    const e = {};
    if (!form.full_name.trim())          e.full_name = 'Full name is required.';
    if (!editing && !form.username.trim()) e.username  = 'Username is required.';
    if (!editing && form.password.length < 6) e.password = 'Password must be at least 6 characters.';
    if (editing && form.password && form.password.length < 6) e.password = 'Password must be at least 6 characters.';
    setFormErrors(e);
    return !Object.keys(e).length;
  };
 
  // ── Save ───────────────────────────────────────────────────
  const handleSave = async () => {
    if (!validate()) return;
    setSaving(true);
    try {
      if (editing) {
        await api.put(`/users/single.php?id=${editing.id}`, {
          full_name: form.full_name.trim(),
          role: form.role,
          password: form.password || undefined,
        });
        toast.success('User updated!');
      } else {
        await api.post('/users/index.php', {
          username:  form.username.trim(),
          full_name: form.full_name.trim(),
          password:  form.password,
          role:      form.role,
        });
        toast.success('User created!');
      }
      setModal(null);
      load();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  // ── Toggle active ──────────────────────────────────────────
  const handleToggle = async () => {
    const u = users.find(u => u.id === deactId);
    if (!u) return;

    try {
      if (u.is_active) {
        // Deactivate — DELETE enforces self-deactivation and last-admin guards
        await api.delete(`/users/single.php?id=${u.id}`);
        toast.success('User deactivated.');
      } else {
        // Reactivate — PATCH, no guards required
        await api.patch(`/users/single.php?id=${u.id}`);
        toast.success('User reactivated.');                                 
      }
      setDeactId(null);
      load();
    } catch (err) {
      toast.error(err.message);
    }
  };

  const setField = (k, v) => { setForm(f => ({ ...f, [k]: v })); setFormErrors(fe => ({ ...fe, [k]: '' })); };
  const toggleUser = users.find(u => u.id === deactId);

  const isLastActiveAdmin = (targetUser) => {
    if (!targetUser || targetUser.role !== 'admin' || !targetUser.is_active) return false;
    const activeAdmins = users.filter(u => u.role === 'admin' && u.is_active);
    return activeAdmins.length === 1;
  };

  const roleLocked = isLastActiveAdmin(editing);
  // ── Render ─────────────────────────────────────────────────
  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Users</h1>
          <p className="page-subtitle">Manage cashiers and administrators</p>
        </div>
        <button className="btn btn-primary" onClick={openAdd}>+ Add User</button>
      </div>

      {banner && <Banner type={banner.type} message={banner.msg} onClose={() => setBanner(null)} />}

      {loading ? (
        <div className="loading-center"><div className="spinner" /><span>Loading…</span></div>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>User</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
                <th style={{ width: 120 }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.length === 0 ? (
                <tr><td colSpan={6} className="table-empty">No users found.</td></tr>
              ) : users.map(u => (
                <tr key={u.id} className={!u.is_active ? 'row-inactive' : ''}>
                  <td>
                    <div className="user-cell">
                      <div className="user-avatar">{u.full_name[0].toUpperCase()}</div>
                      <span>{u.full_name}</span>
                      {u.id === me?.id && <span className="badge badge--info">You</span>}
                    </div>
                  </td>
                  <td><code className="sku-code">{u.username}</code></td>
                  <td>
                    <span className={`badge badge--role badge--${u.role}`}>
                      {u.role === 'admin' ? 'Admin' : 'Staff'}
                    </span>
                  </td>
                  <td>
                    <span className={`badge ${u.is_active ? 'badge--ok' : 'badge--danger'}`}>
                      {u.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="date-secondary">{new Date(u.created_at).toLocaleDateString('en-PH')}</td>
                  <td>
                    <div className="table-actions">
                      <button  className="btn btn-ghost btn-sm" onClick={() => openEdit(u)}>
                        <i className="fi fi-sr-edit" />
                      </button>
                      {u.id !== me?.id && (
                        <button
                          className={`btn btn-sm ${u.is_active ? 'btn-danger' : 'btn-ghost'}`}
                          onClick={() => setDeactId(u.id)}
                          title={u.is_active ? 'Deactivate' : 'Reactivate'}
                        >
                         <i className={u.is_active ? 'fi fi-sr-user-slash' : 'fi fi-sr-user-check'} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ── Add/Edit Modal ── */}
      <Modal
        open={modal === 'form'}
        onClose={() => setModal(null)}
        title={editing ? `Edit User — ${editing.username}` : 'New User'}
        size="sm"
        footer={
          <div className="modal-footer-btns">
            <button className="btn btn-ghost" onClick={() => setModal(null)}>Cancel</button>
            <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
              {saving ? 'Saving…' : 'Save User'}
            </button>
          </div>
        }
      >
        <div className="form-group">
          <label className="form-label">Full Name *</label>
          <input className={`input ${formErrors.full_name ? 'input--error' : ''}`}
            placeholder="e.g. Juan dela Cruz" value={form.full_name}
            onChange={e => setField('full_name', e.target.value)} />
          {formErrors.full_name && <p className="field-error">{formErrors.full_name}</p>}
        </div>

        {!editing && (
          <div className="form-group">
            <label className="form-label">Username *</label>
            <input className={`input ${formErrors.username ? 'input--error' : ''}`}
              placeholder="e.g. cashier1" value={form.username}
              onChange={e => setField('username', e.target.value)} />
            {formErrors.username && <p className="field-error">{formErrors.username}</p>}
          </div>
        )}

        <div className="form-group">
          <label className="form-label">
            {editing ? 'New Password (leave blank to keep current)' : 'Password *'}
          </label>
          <input
            type="password"
            className={`input ${formErrors.password ? 'input--error' : ''}`}
            placeholder={editing ? 'Leave blank to keep current' : 'Min. 6 characters'}
            value={form.password}
            onChange={e => setField('password', e.target.value)}
          />
          {formErrors.password && <p className="field-error">{formErrors.password}</p>}
        </div>

        <div className="form-group">
          <label className="form-label">Role *</label>
          <select
            className="input"
            value={form.role}
            onChange={e => setField('role', e.target.value)}
            disabled={roleLocked}
          >
            <option value="staff">Staff — POS access only</option>
            <option value="admin">Admin — full access</option>
          </select>
          {roleLocked && (
            <p className="form-hint">
              This is the only active admin account — role can't be changed here.
              Promote another user to admin first if you need to change this.
            </p>
          )}
        </div>
      </Modal>

      {/* ── Deactivate/Reactivate Confirmation ── */}
      <Modal
        open={deactId !== null}
        onClose={() => setDeactId(null)}
        title={toggleUser?.is_active ? 'Deactivate User' : 'Reactivate User'}
        danger={toggleUser?.is_active}
        size="sm"
        footer={
          <div className="modal-footer-btns">
            <button className="btn btn-ghost" onClick={() => setDeactId(null)}>Cancel</button>
            <button
              className={`btn ${toggleUser?.is_active ? 'btn-danger' : 'btn-primary'}`}
              onClick={handleToggle}
            >
              {toggleUser?.is_active ? 'Deactivate' : 'Reactivate'}
            </button>
          </div>
        }
      >
        <p>
          {toggleUser?.is_active
            ? `Deactivating "${toggleUser?.full_name}" will prevent them from logging in.`
            : `Reactivating "${toggleUser?.full_name}" will restore their login access.`
          }
        </p>
      </Modal>
    </div>
  );
}
