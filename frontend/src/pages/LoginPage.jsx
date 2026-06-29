import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import logo from '../assets/Logo.png';
import '../assets/uicons-solid-rounded/css/uicons-solid-rounded.css';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate  = useNavigate();

  const [form,    setForm]    = useState({ username: '', password: '' });
  const [errors,  setErrors]  = useState({});
  const [loading, setLoading] = useState(false);
  const [serverError, setServerError] = useState('');

  // Inline validation
  const validate = () => {
    const e = {};
    if (!form.username.trim()) e.username = 'Username is required.';
    if (!form.password)        e.password = 'Password is required.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm(f => ({ ...f, [name]: value }));
    setErrors(prev => ({ ...prev, [name]: '' }));
    setServerError('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validate()) return;
    setLoading(true);
    try {
      await login(form.username.trim(), form.password);
      navigate('/pos', { replace: true });
    } catch (err) {
      setServerError(err.message || 'Login failed.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      {/* Left decorative panel */}
      <div className="login-deco">
        <div className="login-deco__content">
          <div className="login-deco__icon"><img src={logo} alt="Jing-Jing Store Logo" className="login-deco__logo"/> </div>
          <p className="login-deco__sub">Simple. Fast. Yours.</p>
        </div>
      </div>

      {/* Right form panel */}
      <div className="login-form-panel">
        <form className="login-card" onSubmit={handleSubmit} noValidate>
          <div className="login-card__header">
            <h2>Welcome back</h2>
            <p>Sign in to your account</p>
          </div>

          {/* Server-level error banner */}
          {serverError && (
            <div className="login-error-banner">
              <i className="fi fi-sr-triangle-warning" style={{ marginRight: 8 }} />
              {serverError}
            </div>
          )}

          {/* Username */}
          <div className="form-group">
            <label className="form-label" htmlFor="username">Username</label>
            <input
              id="username"
              name="username"
              type="text"
              className={`input ${errors.username ? 'input--error' : ''}`}
              placeholder="Enter your username"
              value={form.username}
              onChange={handleChange}
              autoComplete="username"
              autoFocus
            />
            {errors.username && <p className="field-error">{errors.username}</p>}
          </div>

          {/* Password */}
          <div className="form-group">
            <label className="form-label" htmlFor="password">Password</label>
            <input
              id="password"
              name="password"
              type="password"
              className={`input ${errors.password ? 'input--error' : ''}`}
              placeholder="Enter your password"
              value={form.password}
              onChange={handleChange}
              autoComplete="current-password"
            />
            {errors.password && <p className="field-error">{errors.password}</p>}
          </div>

          <button type="submit" className="btn btn-primary btn-full" disabled={loading}>
            {loading ? 'Signing in…' : 'Sign In'}
          </button>
        </form>
      </div>
    </div>
  );
}
