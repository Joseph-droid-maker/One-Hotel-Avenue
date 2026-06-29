
import { useState, useEffect, useCallback, useRef } from 'react';
import toast from 'react-hot-toast';
import { api, peso, today } from '../utils/api.js';
import { useAuth } from '../context/AuthContext.jsx';
import Banner from '../components/ui/Banner.jsx';
import Modal from '../components/ui/Modal.jsx';
import '../assets/uicons-solid-rounded/css/uicons-solid-rounded.css';

const CATEGORIES = ['Food', 'Utilities', 'Supplies', 'Transportation', 'Other'];

// ── Small helper: coloured badge per category ────────────────
function CatBadge({ cat }) {
  const map = {
    Food:           'badge--warn',
    Utilities:      'badge--info',
    Supplies:       'badge--cat',
    Transportation: 'badge--admin',
    Other:          'badge--staff',
  };
  return <span className={`badge ${map[cat] ?? 'badge--staff'}`}>{cat}</span>;
}

// ── Log-expense form ─────────────────────────────────────────
function ExpenseForm({ onSaved }) {
  const [amount,      setAmount]      = useState('');
  const [category,    setCategory]    = useState('Food');
  const [description, setDescription] = useState('');
  const [expenseDate, setExpenseDate] = useState(() => today());
  const [saving,      setSaving]      = useState(false);
  const [errors,      setErrors]      = useState({});

  function validate() {
    const e = {};
    const parsed = parseFloat(amount);
    if (!amount || isNaN(parsed) || !isFinite(parsed) || parsed <= 0) {
      e.amount = 'Enter a valid amount greater than zero.';
    }
    if (!description.trim()) {
      e.description = 'Description is required.';
    }
    if (description.trim().length > 255) {
      e.description = 'Max 255 characters.';
    }
    return e;
  }

  const handleSubmit = async () => {
    if (saving) return;

    const e = validate();
    if (Object.keys(e).length > 0) { setErrors(e); return; }
    setErrors({});
    setSaving(true);

    try {
      const saved = await api.post('/expenses/index.php', {
        amount:       parseFloat(amount),
        category,
        description:  description.trim(),
        expense_date: expenseDate,
      });
      onSaved(saved.data);
      toast.success('Expense logged.');
      setAmount('');
      setDescription('');
    } catch (err) {
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="table-wrap" style={{ padding: '20px', marginBottom: '20px' }}>
      <h3 style={{ marginBottom: '16px', color: 'var(--text)' }}>Log Expense</h3>
      <div className="form-grid">

        {/* Date */}
        <div className="form-group">
          <label className="form-label">Date</label>
          <input
            type="date"
            className="input"
            value={expenseDate}
            max={today()}
            onChange={e => setExpenseDate(e.target.value)}
          />
        </div>

        {/* Amount */}
        <div className="form-group">
          <label className="form-label">Amount (₱)</label>
          <input
            type="number"
            className={`input ${errors.amount ? 'input--error' : ''}`}
            placeholder="0.00"
            min="0.01"
            step="0.01"
            value={amount}
            onChange={e => setAmount(e.target.value)}
            // FIX (#14): aria-describedby links the input to its error message
            // so screen readers announce the error when the field has focus.
            aria-describedby={errors.amount ? 'amount-error' : undefined}
          />
          {errors.amount && (
            <span id="amount-error" className="field-error">{errors.amount}</span>
          )}
        </div>

        {/* Category */}
        <div className="form-group">
          <label className="form-label">Category</label>
          <select
            className="input"
            value={category}
            onChange={e => setCategory(e.target.value)}
          >
            {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>

        {/* Description — spans both columns */}
        <div className="form-group form-span-2">
          <label className="form-label">Description</label>
          <input
            type="text"
            className={`input ${errors.description ? 'input--error' : ''}`}
            placeholder="e.g. Bought rice and cooking oil"
            maxLength={255}
            value={description}
            onChange={e => setDescription(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleSubmit()}
            aria-describedby={errors.description ? 'description-error' : undefined}
          />
          {errors.description && (
            <span id="description-error" className="field-error">{errors.description}</span>
          )}
        </div>

      </div>

      <div style={{ marginTop: '16px' }}>
        <button
          className="btn btn-primary"
          onClick={handleSubmit}
          disabled={saving}
        >
          {saving ? <><span className="spinner spinner--sm" /> Saving…</> : 'Log Expense'}
        </button>
      </div>
    </div>
  );
}

// ── Main page ────────────────────────────────────────────────
export default function ExpensesPage() {
  const { user } = useAuth();
  const isAdmin  = user?.role === 'admin';
  const [dateFrom,  setDateFrom]  = useState(() => today());
  const [dateTo,    setDateTo]    = useState(() => today());
  const [expenses,  setExpenses]  = useState([]);
  const [summary,   setSummary]   = useState(null);
  const [loading,   setLoading]   = useState(false);
  const [banner,    setBanner]    = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting,     setDeleting]     = useState(false);
  const loadGenRef = useRef(0);
  const activeRangeRef = useRef({ from: today(), to: today() });
  
  const loadExpenses = useCallback(async (from, to) => {
    activeRangeRef.current = { from, to };

    const gen = ++loadGenRef.current;
    setLoading(true);
    setSummary(null);
    setBanner(null);

    try {
      const q = new URLSearchParams({ date_from: from, date_to: to });
      const r = await api.get('/expenses/index.php?' + q);

      // FIX (#8): Discard response if a newer load has already started.
      if (gen !== loadGenRef.current) return;

      setExpenses(r.data.expenses);
      setSummary(r.data.summary);
    } catch (err) {
      if (gen !== loadGenRef.current) return;
      setBanner({ type: 'error', msg: err.message });
    } finally {
      // Only the most-recent call clears the loading spinner. If an older
      // call finishes after a newer one, it must not kill the new load's spinner.
      if (gen === loadGenRef.current) setLoading(false);
    }
  }, []); 
  useEffect(() => {
    if (!isAdmin) loadExpenses(today(), today());
  }, [isAdmin, loadExpenses]);

  const handleSaved = useCallback(() => {
    const { from, to } = activeRangeRef.current;
    loadExpenses(from, to);
  }, [loadExpenses]);

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await api.delete(`/expenses/index.php?id=${deleteTarget.id}`);
      toast.success('Expense deleted.');
      setDeleteTarget(null);
      const { from, to } = activeRangeRef.current;
      await loadExpenses(from, to);
    } catch (err) {
      toast.error(err.message);
    } finally {
      setDeleting(false);
    }
  };

  const handleGenerate = () => loadExpenses(dateFrom, dateTo);

  return (
    <div className="page">

      {/* ── Page header ──────────────────────────────────────── */}
      <div className="page-header">
        <div>
          <h1 className="page-title">Expenses</h1>
          <p className="page-subtitle">
            {isAdmin
              ? 'Log and review all store expenses'
              : 'Log the amount you took and what you used it for'}
          </p>
        </div>
      </div>

      {/* ── Expense form (both roles) ─────────────────────────── */}
      <ExpenseForm onSaved={handleSaved} />

      {/* ── Admin date filter ────────────────────────────────── */}
      {isAdmin && (
        <div className="filter-bar" style={{ marginBottom: '18px' }}>
          <div className="filter-group">
            <label className="form-label">From</label>
            <input
              type="date"
              className="input"
              value={dateFrom}
              onChange={e => setDateFrom(e.target.value)}
            />
          </div>
          <div className="filter-group">
            <label className="form-label">To</label>
            <input
              type="date"
              className="input"
              value={dateTo}
              onChange={e => setDateTo(e.target.value)}
            />
          </div>
          <button className="btn btn-primary" onClick={handleGenerate}>
            Generate
          </button>
        </div>
      )}

      {banner  && <Banner type={banner.type} message={banner.msg} onClose={() => setBanner(null)} />}
      {loading && <div className="loading-center"><div className="spinner" /></div>}

      {/* ── Summary cards ────────────────────────────────────── */}
      {summary && (
        <div className="stats-row">
          <div className="stat-card stat-card--primary">
            <div className="stat-card__val">{peso(summary.total_expenses)}</div>
            <div className="stat-card__label">Total Expenses</div>
          </div>
          <div className="stat-card stat-card--warn">
            <div className="stat-card__val">{peso(summary.food_expenses)}</div>
            <div className="stat-card__label">Food</div>
          </div>
          <div className="stat-card">
            <div className="stat-card__val">{peso(summary.other_expenses)}</div>
            <div className="stat-card__label">Other Expenses</div>
          </div>
        </div>
      )}

      {/* ── Expenses table ───────────────────────────────────── */}
      {!loading && (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Recorded By</th>
                <th style={{ textAlign: 'right' }}>Amount</th>
                {isAdmin && <th style={{ textAlign: 'center' }}>Action</th>}
              </tr>
            </thead>
            <tbody>
              {expenses.length === 0 ? (
                <tr>
                  <td colSpan={isAdmin ? 6 : 5} className="table-empty">
                    No expenses recorded for this period.
                  </td>
                </tr>
              ) : (
                expenses.map(exp => (
                  <tr key={exp.id}>
                    <td>
                      <span className="date-primary">{exp.expense_date}</span>
                    </td>
                    <td><CatBadge cat={exp.category} /></td>
                    <td>{exp.description}</td>
                    <td>
                      <div className="user-cell">
                        <span className="user-avatar" style={{ width: 28, height: 28, fontSize: 12 }}>
                          {/* FIX (#10): .trim() prevents a whitespace-only name
                              producing a blank avatar: ('  ' || 'U')[0] = ' ' */}
                          {(exp.recorded_by_name?.trim() || 'U')[0].toUpperCase()}
                        </span>
                        {exp.recorded_by_name}
                      </div>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <strong className="price-mono">{peso(exp.amount)}</strong>
                    </td>
                    {isAdmin && (
                      <td style={{ textAlign: 'center' }}>
                        <button
                          className="btn-text-danger"
                          onClick={() => setDeleteTarget(exp)}
                          title="Delete this expense"
                        >
                          Delete
                        </button>
                      </td>
                    )}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* ── Delete confirmation modal (admin only) ───────────── */}
      <Modal
        open={!!deleteTarget}
        onClose={() => !deleting && setDeleteTarget(null)}
        title="Delete Expense"
        danger
        size="sm"
        footer={
          <div className="modal-footer-btns">
            <button
              className="btn btn-ghost"
              onClick={() => setDeleteTarget(null)}
              disabled={deleting}
            >
              Cancel
            </button>
            <button
              className="btn btn-danger"
              onClick={handleDelete}
              disabled={deleting}
            >
              {deleting ? <><span className="spinner spinner--sm" /> Deleting…</> : 'Delete'}
            </button>
          </div>
        }
       >
        <p>
          Delete the{' '}
          <strong>{deleteTarget?.category}</strong> expense of{' '}
          <strong>{peso(deleteTarget?.amount)}</strong>?
        </p>
        <p style={{ marginTop: 6, color: 'var(--text3)', fontSize: 13 }}>
          "{deleteTarget?.description}"
        </p>
        <p style={{ marginTop: 8, color: 'var(--err)', fontSize: 12 }}>
          This cannot be undone.
        </p>
      </Modal>
    </div>
  );
}