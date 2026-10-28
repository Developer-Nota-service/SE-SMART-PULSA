import { useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import AdminDeposits from '../components/admin/AdminDeposits.jsx'
import AdminOverview from '../components/admin/AdminOverview.jsx'
import AdminTransactions from '../components/admin/AdminTransactions.jsx'
import AdminUsers from '../components/admin/AdminUsers.jsx'
import AdminWithdrawals from '../components/admin/AdminWithdrawals.jsx'

const TABS = [
  { key: 'overview', label: 'Ringkasan' },
  { key: 'withdrawals', label: 'Penarikan' },
  { key: 'deposits', label: 'Deposit' },
  { key: 'transactions', label: 'Transaksi' },
  { key: 'users', label: 'Pengguna' },
]

/**
 * Konsol admin: satu halaman dengan tab untuk memantau dan menyelesaikan
 * aktivitas akun pengguna. Hanya bisa dibuka akun ber-role admin.
 */
export default function AdminPage() {
  const { user } = useAuth()
  const [tab, setTab] = useState('overview')

  if (user && user.role !== 'admin') {
    return (
      <div className="card">
        <p className="state__title">Halaman ini hanya untuk admin</p>
        <p className="state__text">
          Masuk dengan akun admin untuk melihat aktivitas pengguna dan memproses penarikan.
        </p>
      </div>
    )
  }

  return (
    <div className="stack">
      <div className="section-head">
        <h2 className="section-title">Konsol admin</h2>
        <span className="section-hint">{user?.name || '—'}</span>
      </div>

      <div className="chips admin-tabs">
        {TABS.map((item) => (
          <button
            key={item.key}
            type="button"
            className={`chip${tab === item.key ? ' is-active' : ''}`}
            onClick={() => setTab(item.key)}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'overview' ? <AdminOverview onOpenTab={setTab} /> : null}
      {tab === 'withdrawals' ? <AdminWithdrawals /> : null}
      {tab === 'deposits' ? <AdminDeposits /> : null}
      {tab === 'transactions' ? <AdminTransactions /> : null}
      {tab === 'users' ? <AdminUsers /> : null}
    </div>
  )
}
