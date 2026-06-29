const BASE = '/Jing-Jing-Store/backend/api';

async function request(path, opts = {}) {
  const isFormData = opts.body instanceof FormData;

  const res = await fetch(BASE + path, {
    credentials: 'include',                           // Required for PHP session cookies
    headers: isFormData ? {} : { 'Content-Type': 'application/json' },
    ...opts,
  });

  // Parse JSON response (PHP always returns JSON)
  let data;
  try { data = await res.json(); }
  catch { throw new Error('Invalid server response'); }

  // Throw so callers can catch in try/catch
  if (!res.ok) throw new Error(data?.message || `HTTP ${res.status}`);

  return data;
}

export const api = {
  get:    (path)       => request(path, { method: 'GET' }),
  post:   (path, body) => request(path, {
    method: 'POST',
    body: body instanceof FormData ? body : JSON.stringify(body),
  }),
  patch:  (path, body) => request(path, {
    method: 'PATCH',
    body: body ? JSON.stringify(body) : undefined
  }),
  put:    (path, body) => request(path, { method: 'PUT',    body: JSON.stringify(body) }),
  delete: (path)       => request(path, { method: 'DELETE' }),
};

// Helper: build image URL from relative path stored in DB
export function imgUrl(path) {
  if (!path) return null;
  return `/Jing-Jing-Store/backend/${path}`;
}

// Helper: Philippine peso format
export function peso(amount) {
  return '₱' + parseFloat(amount || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

// Helper: today's date as YYYY-MM-DD
export function today() {
  const d = new Date();
  // Build from local date components, not UTC
  const yyyy = d.getFullYear();
  const mm   = String(d.getMonth() + 1).padStart(2, '0');
  const dd   = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

// Helper: first day of this month as YYYY-MM-DD
export function firstOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}
