import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { AuthProvider, useAuth } from './context/AuthContext.jsx';
import Layout from './components/layout/Layout.jsx';
import LoginPage from './pages/LoginPage.jsx';
import POSPage from './pages/POSPage.jsx';
import ProductsPage from './pages/ProductsPage.jsx';
import SalesPage from './pages/SalesPage.jsx';
import ReportsPage from './pages/ReportsPage.jsx';
import UsersPage from './pages/UsersPage.jsx';
import ExpensesPage from './pages/ExpensesPage.jsx'; 


function Guard({ adminOnly = false, children }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="app-loading"><div className="spinner" /></div>;
  if (!user) return <Navigate to="/login" replace />;
  if (adminOnly && user.role !== 'admin') return <Navigate to="/pos" replace />;
  return children;
}

export default function App() {
  return (
    <AuthProvider>
     
      <Toaster
        position="top-right"
        toastOptions={{
          style: {
            fontFamily: "'Plus Jakarta Sans', sans-serif",
            fontSize: '14px',
            borderRadius: '10px',
            boxShadow: '0 4px 20px rgba(80,55,15,0.15)',
          },
          success: { iconTheme: { primary: '#227040', secondary: '#fff' } },
          error:   { iconTheme: { primary: '#B83030', secondary: '#fff' } },
        }}
      />
      {/* basename="/Jing-Jing-Store" */}
      <BrowserRouter  >
        <Routes>
     
          <Route path="/login" element={<LoginPage />} />

          <Route
            path="/"
            element={<Guard><Layout /></Guard>}
          >
            <Route index element={<Navigate to="/pos" replace />} />

       
            <Route path="pos"   element={<POSPage />} />
            <Route path="expenses" element={<Guard><ExpensesPage /></Guard>} />
            <Route path="sales" element={<Guard adminOnly><SalesPage /></Guard>} />

            
            <Route path="products" element={<Guard adminOnly><ProductsPage /></Guard>} />
            <Route path="reports"  element={<Guard adminOnly><ReportsPage /></Guard>} />
            <Route path="users"    element={<Guard adminOnly><UsersPage /></Guard>} />
          </Route>

       
          <Route path="*" element={<Navigate to="/pos" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
