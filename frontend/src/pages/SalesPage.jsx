import { useState, useEffect, useCallback } from 'react';
import { api, peso, today, firstOfMonth } from '../utils/api.js';
import Modal from '../components/ui/Modal.jsx';
import Banner from '../components/ui/Banner.jsx';
import '../assets/uicons-solid-rounded/css/uicons-solid-rounded.css';


const LIMIT = 20;

// ── Transaction detail modal ─────────────────────────────────
function TxnModal({ txn, onClose }) {
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get(`/transactions/single.php?id=${txn.id}`)
      .then(r => setDetail(r.data))
      .catch(() => setDetail({ ...txn, items: [] }))
      .finally(() => setLoading(false));
  }, [txn.id]);

  const d = new Date(txn.created_at);

  return (
      <Modal open onClose={onClose} title={`${txn.transaction_code}`} size="md">
      {loading ? (
        <div className="loading-center"><div className="spinner" /></div>
      ) : (
        <>
          <div className="txn-meta">
            <div className="txn-meta__row">
              <span>Date</span>
              <span>{d.toLocaleDateString('en-PH')} {d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })}</span>
            </div>
            <div className="txn-meta__row">
              <span>Cashier</span>
              <span>{detail.cashier_name || '—'}</span>
            </div>
          </div>

          <div className="table-wrap" style={{ marginTop: 16 }}>
            <table className="table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>SKU</th>
                  <th style={{ textAlign: 'right' }}>Qty</th>
                  <th style={{ textAlign: 'right' }}>Unit Price</th>
                  <th style={{ textAlign: 'right' }}>Subtotal</th>
                </tr>
              </thead>
              <tbody>
                {(detail.items || []).map((item, i) => (
                  <tr key={i}>
                    <td>{item.product_name}</td>
                    <td><code className="sku-code">{item.product_sku || '—'}</code></td>
                    <td style={{ textAlign: 'right' }}>{item.quantity}</td>
                    <td style={{ textAlign: 'right' }}>{peso(item.unit_price)}</td>
                    <td style={{ textAlign: 'right' }}><strong>{peso(item.subtotal)}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="txn-totals">
            <div className="txn-totals__row txn-totals__row--total">
              <span>TOTAL</span><strong>{peso(detail.total)}</strong>
            </div>
            <div className="txn-totals__row">
              <span>Cash Given</span><span>{peso(detail.cash_given)}</span>
            </div>
            <div className="txn-totals__row">
              <span>Change</span><span>{peso(detail.change_amount)}</span>
            </div>
          </div>
        </>
      )}
      <div className="modal-footer">
        <div className="modal-footer-btns">
          <button className="btn btn-primary" onClick={onClose}>Close</button>
        </div>
      </div>
    </Modal>
  );
}

// ── Main Page ────────────────────────────────────────────────
export default function SalesPage() {
  const [rows,     setRows]     = useState([]);
  const [total,    setTotal]    = useState(0);
  const [dateFrom, setDateFrom] = useState(firstOfMonth());
  const [dateTo,   setDateTo]   = useState(today());
  const [loading,  setLoading]  = useState(true);
  const [selected, setSelected] = useState(null);
  const [banner,   setBanner]   = useState(null);
  const [page,     setPage]     = useState(0);

  const load = useCallback(async () => {
    setLoading(true); setBanner(null);
    try {
      const q = new URLSearchParams({
        date_from: dateFrom, date_to: dateTo,
        limit: LIMIT, offset: page * LIMIT,
      });
      const res = await api.get('/transactions/index.php?' + q);
      setRows(res.data.transactions || []);
      setTotal(res.data.total || 0);
    } catch (err) {
      setBanner({ type: 'error', msg: err.message });
    } finally {
      setLoading(false);
    }
  }, [dateFrom, dateTo, page]);

  useEffect(() => { load(); }, [load]);

  const pageRevenue = rows.reduce((s, t) => s + parseFloat(t.total), 0);
  const totalPages  = Math.ceil(total / LIMIT);

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Sales History</h1>
          <p className="page-subtitle">All processed transactions</p>
        </div>
      </div>

      {banner && <Banner type={banner.type} message={banner.msg} onClose={() => setBanner(null)} />}

      {/* Date filter */}
      <div className="filter-bar">
        <div className="filter-group">
          <label className="form-label">From</label>
          <input type="date" className="input" value={dateFrom}
            onChange={e => { setDateFrom(e.target.value); setPage(0); }} />
        </div>
        <div className="filter-group">
          <label className="form-label">To</label>
          <input type="date" className="input" value={dateTo}
            onChange={e => { setDateTo(e.target.value); setPage(0); }} />
        </div>
        <button className="btn btn-primary" onClick={() => { setPage(0); load(); }}>Apply</button>
      </div>

      {/* Stats */}
      <div className="stats-row">
        <div className="stat-card">
          <div className="stat-card__val">{total}</div>
          <div className="stat-card__label">Total Transactions</div>
        </div>
        <div className="stat-card stat-card--primary">
          <div className="stat-card__val">{peso(pageRevenue)}</div>
          <div className="stat-card__label">Revenue (this page)</div>
        </div>
      </div>

      {/* Table */}
      {loading ? (
        <div className="loading-center"><div className="spinner" /><span>Loading…</span></div>
      ) : (
        <>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Date &amp; Time</th>
                  <th>TXN Code</th>
                  <th>Cashier</th>
                  <th style={{ textAlign: 'right' }}>Items</th>
                  <th style={{ textAlign: 'right' }}>Total</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr><td colSpan={6} className="table-empty">No transactions found.</td></tr>
                ) : rows.map(t => {
                  const d = new Date(t.created_at);
                  return (
                    <tr key={t.id}>
                      <td>
                        <div className="date-primary">{d.toLocaleDateString('en-PH')}</div>
                        <div className="date-secondary">{d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })}</div>
                      </td>
                      <td><code className="txn-code">{t.transaction_code}</code></td>
                      <td>{t.cashier_name || '—'}</td>
                      <td style={{ textAlign: 'right' }}>{t.item_count}</td>
                      <td style={{ textAlign: 'right' }}>
                        <strong className="price-mono">{peso(t.total)}</strong>
                      </td>
                      <td>
                        <button className="btn btn-ghost btn-sm" onClick={() => setSelected(t)}>View</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="pagination">
              <button className="btn btn-ghost btn-sm" disabled={page === 0}
                onClick={() => setPage(p => p - 1)}>
                <i className="fi fi-sr-angle-left" /> Prev
              </button>
              <span className="pagination__info">
                Page {page + 1} of {totalPages} &nbsp;·&nbsp; {total} total
              </span>
              <button className="btn btn-ghost btn-sm" disabled={page + 1 >= totalPages}
                onClick={() => setPage(p => p + 1)}>
                Next <i className="fi fi-sr-angle-right" />
              </button>
            </div>
          )}
        </>
      )}

      {selected && <TxnModal txn={selected} onClose={() => setSelected(null)} />}
    </div>
  );
}
