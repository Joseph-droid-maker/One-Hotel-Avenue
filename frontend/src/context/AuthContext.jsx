import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { api } from '../utils/api.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser]       = useState(null);  
  const [loading, setLoading] = useState(true);  
  const [csrfToken,  setCsrfToken]  = useState('');

  const applyAuthResponse = (data) => {
    const { csrf_token, ...userData } = data;
    setUser(userData);
    setCsrfToken(csrf_token || '');
  };

  const checkSession = useCallback(async () => {
    try {
      const res = await api.get('/auth/check.php');
      applyAuthResponse(res.data);
    } catch {
      setUser(null);
      setCsrfToken('');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { checkSession(); }, [checkSession]);

  const login = async (username, password) => {
    const res = await api.post('/auth/login.php', { username, password });
    applyAuthResponse(res.data);
    return res.data;
  };

  const logout = async () => {
    try { await api.post('/auth/logout.php', {}); } catch { /* ignore */ }
    setUser(null);
    setCsrfToken('');
    sessionStorage.removeItem('pos_cart');  
    sessionStorage.removeItem('pos_cash')
  };

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, csrfToken}}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
