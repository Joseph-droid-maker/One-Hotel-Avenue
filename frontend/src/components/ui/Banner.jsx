export default function Banner({ type = 'error', message, onClose }) {
  if (!message) return null;

  const styles = {
    error:   { bg: 'var(--err-bg)',  border: 'var(--err)',  icon: '⚠' },
    warning: { bg: 'var(--warn-bg)', border: 'var(--warn)', icon: '⚡' },
    info:    { bg: 'var(--info-bg)', border: 'var(--info)', icon: 'ℹ' },
  };
  const s = styles[type] ?? styles.error;

  return (
    <div
      className="banner"
      style={{ 
        background: s.bg,
        borderLeft: `4px solid ${s.border}`,
      }}
    >
      <span className="banner__icon">{s.icon}</span>
      <span className="banner__msg">{message}</span>
      {onClose && (
        <button className="banner__close" onClick={onClose} aria-label="Dismiss">✕</button>
      )}
    </div>
  );
}
