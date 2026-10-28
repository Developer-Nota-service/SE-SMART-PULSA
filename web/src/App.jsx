import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthContext.jsx'
import AppShell from './components/AppShell.jsx'
import AdminPage from './pages/AdminPage.jsx'
import BalancePage from './pages/BalancePage.jsx'
import HelpPage from './pages/HelpPage.jsx'
import HomePage from './pages/HomePage.jsx'
import LegalPage from './pages/LegalPage.jsx'
import LoginPage from './pages/LoginPage.jsx'
import ProfilePage from './pages/ProfilePage.jsx'
import PurchasePage from './pages/PurchasePage.jsx'
import TransactionsPage from './pages/TransactionsPage.jsx'

function Protected() {
  const { user } = useAuth()
  const location = useLocation()

  if (user === undefined) {
    return (
      <div className="boot">
        <span className="spinner" />
        <p>Memuat SE SMART PULSA…</p>
      </div>
    )
  }
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <AppShell />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<Protected />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/transaksi" element={<TransactionsPage />} />
        <Route path="/saldo" element={<BalancePage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="/profil" element={<ProfilePage />} />
        <Route path="/beli/:productId" element={<PurchasePage />} />
        <Route path="/bantuan" element={<HelpPage />} />
        <Route path="/legal/:doc" element={<LegalPage />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
