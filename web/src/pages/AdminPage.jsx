import { Link, Navigate, NavLink, Route, Routes } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import { HistoryIcon, HomeIcon, TicketIcon, UserIcon, WalletIcon } from '../components/Icons.jsx'
import AdminFinance from '../components/admin/AdminFinance.jsx'
import AdminOverview from '../components/admin/AdminOverview.jsx'
import AdminProducts from '../components/admin/AdminProducts.jsx'
import AdminTransactions from '../components/admin/AdminTransactions.jsx'
import AdminUsers from '../components/admin/AdminUsers.jsx'

const TABS = [
  { to: '/admin', label: 'Ringkasan', Icon: HomeIcon, end: true },
  { to: '/admin/harga', label: 'Harga', Icon: TicketIcon },
  { to: '/admin/transaksi', label: 'Transaksi', Icon: HistoryIcon },
  { to: '/admin/keuangan', label: 'Keuangan', Icon: WalletIcon },
  { to: '/admin/pengguna', label: 'Pengguna', Icon: UserIcon },
]

/**
 * Konsol admin dengan tata letak sendiri — terpisah dari tampilan pengguna
 * (tanpa beranda, saldo, atau keranjang). Isinya pekerjaan pengelola: harga
 * produk, transaksi bermasalah, uang masuk/keluar, dan akun pengguna.
 * Hanya bisa dibuka akun ber-role admin.
 */
export default function AdminPage() {
  const { user, logout } = useAuth()

  if (user === undefined) return null
  if (!user || user.role !== 'admin') return <Navigate to="/" replace />

  return (
    <div className="admin-shell">
      <header className="admin-topbar">
        <div className="admin-topbar__brand">
          <span className="admin-topbar__mark">SE</span>
          <span>
            <span className="admin-topbar__kicker">Konsol Admin</span>
            <span className="admin-topbar__title">Harga &amp; transaksi</span>
          </span>
        </div>
        <div className="admin-topbar__actions">
          <Link to="/" className="admin-topbar__exit">
            Ke aplikasi
          </Link>
          <button type="button" className="admin-topbar__exit" onClick={logout}>
            Keluar
          </button>
        </div>
      </header>

      <p className="admin-identity">
        Masuk sebagai <strong>{user.name}</strong> · {user.email}
      </p>

      <main className="admin-content">
        <Routes>
          <Route index element={<AdminOverview />} />
          <Route path="harga" element={<AdminProducts />} />
          <Route path="transaksi" element={<AdminTransactions />} />
          <Route path="keuangan" element={<AdminFinance />} />
          <Route path="pengguna" element={<AdminUsers />} />
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </main>

      <nav className="bottom-nav admin-nav" aria-label="Navigasi admin">
        {TABS.map(({ to, label, Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) => `nav-item${isActive ? ' is-active' : ''}`}
          >
            <Icon />
            <span>{label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
