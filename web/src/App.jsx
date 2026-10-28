import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthContext.jsx'
import AppShell from './components/AppShell.jsx'
import LoginPage from './pages/LoginPage.jsx'

// Halaman selain login dimuat mengikuti rute: layar pertama tidak perlu
// mengunduh seluruh aplikasi (admin, pembelian, legal) sebelum bisa dipakai.
const AdminPage = lazy(() => import('./pages/AdminPage.jsx'))
const BalancePage = lazy(() => import('./pages/BalancePage.jsx'))
const CategoryPage = lazy(() => import('./pages/CategoryPage.jsx'))
const HelpPage = lazy(() => import('./pages/HelpPage.jsx'))
const HomePage = lazy(() => import('./pages/HomePage.jsx'))
const LegalPage = lazy(() => import('./pages/LegalPage.jsx'))
const ProfilePage = lazy(() => import('./pages/ProfilePage.jsx'))
const PurchasePage = lazy(() => import('./pages/PurchasePage.jsx'))
const TransactionsPage = lazy(() => import('./pages/TransactionsPage.jsx'))

function Boot() {
  return (
    <div className="boot">
      <span className="spinner" />
      <p>Memuat SE SMART PULSA…</p>
    </div>
  )
}

function Protected() {
  const { user } = useAuth()
  const location = useLocation()

  if (user === undefined) return <Boot />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <AppShell />
}

export default function App() {
  return (
    <Suspense fallback={<Boot />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />

        <Route element={<Protected />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/kategori/:category" element={<CategoryPage />} />
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
    </Suspense>
  )
}
