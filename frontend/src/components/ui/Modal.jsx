import { useEffect } from 'react';

export default function Modal({ open, onClose, title, children, footer, danger = false, size = 'md' }) {
  useEffect(() => {
    if (!open) return;
    const handler = (e) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, onClose]);

  if (!open) return null;

  const maxWidths = { sm: '400px', md: '540px', lg: '720px' };

  return (
    <div className="modal-backdrop" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="modal-panel" style={{ maxWidth: maxWidths[size] ?? '540px' }}>
        {/* Header */}
        <div className={`modal-header ${danger ? 'modal-header--danger' : ''}`}>
          <h3 className="modal-title">{title}</h3>
          <button className="modal-close" onClick={onClose} aria-label="Close">✕</button>
        </div>

        {/* Body */}
        <div className="modal-body">{children}</div>

        {/* Footer */}
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  );
}
