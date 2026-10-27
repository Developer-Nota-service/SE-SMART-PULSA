import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import {
  ChevronRightIcon,
  FileIcon,
  HelpIcon,
  LogoutIcon,
  ShieldIcon,
} from '../components/Icons.jsx'
import { dateTime, initials } from '../lib/format.js'
import { support } from '../lib/support.js'

const menus = [
  { to: '/bantuan', label: 'Pusat bantuan', Icon: HelpIcon },
  { to: '/legal/syarat', label: 'Syarat & ketentuan', Icon: FileIcon },
  { to: '/legal/privasi', label: 'Kebijakan privasi', Icon: ShieldIcon },
]

export default function ProfilePage() {
  const { user, lastLoginAt, logout } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)

  async function handleLogout() {
    setSigningOut(true)
    try {
      await logout()
      navigate('/login', { replace: true })
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <div className="stack">
      <section className="profile-card">
        <span className="profile-card__avatar">{initials(user?.name)}</span>
        <div className="profile-card__body">
          <h2 className="profile-card__name">{user?.name}</h2>
          <p className="profile-card__meta">{user?.email}</p>
          <p className="profile-card__meta">{user?.phone}</p>
        </div>
        <span className={`badge ${user?.role === 'admin' ? 'badge--admin' : 'badge--soft'}`}>
          {user?.role === 'admin' ? 'Admin' : 'Pengguna'}
        </span>
      </section>

      <section className="card">
        <div className="info-row">
          <span className="info-row__label">Tipe akun</span>
          <span className="info-row__value">
            {user?.accountType === 'personal' ? 'Pribadi · harga publik' : 'Reseller · harga khusus'}
          </span>
        </div>
        <div className="info-row">
          <span className="info-row__label">Metode bayar</span>
          <span className="info-row__value">
            {user?.accountType === 'personal'
              ? 'Midtrans · bayar langsung'
              : 'Saldo · isi saldo dulu'}
          </span>
        </div>
        <div className="info-row">
          <span className="info-row__label">Terakhir masuk</span>
          <span className="info-row__value">{lastLoginAt ? dateTime(lastLoginAt) : '—'}</span>
        </div>
        <div className="info-row">
          <span className="info-row__label">Dukungan</span>
          <span className="info-row__value">{support.email}</span>
        </div>
        <div className="info-row">
          <span className="info-row__label">Jam layanan</span>
          <span className="info-row__value">{support.hours}</span>
        </div>
      </section>

      <section className="card card--flush">
        <nav className="menu">
          {menus.map(({ to, label, Icon }) => (
            <Link key={to} to={to} className="menu__item">
              <span className="menu__icon">
                <Icon />
              </span>
              <span className="menu__label">{label}</span>
              <ChevronRightIcon className="menu__chevron" />
            </Link>
          ))}
        </nav>
      </section>

      <p className="note note--muted">
        Pengaturan akun, ubah profil, dan keamanan tambahan aktif setelah terhubung ke backend
        Laravel pada Tahap 2.
      </p>

      <button
        type="button"
        className="btn btn--danger-ghost btn--block"
        onClick={handleLogout}
        disabled={signingOut}
      >
        <LogoutIcon />
        <span>{signingOut ? 'Keluar…' : 'Keluar dari akun'}</span>
      </button>
    </div>
  )
}
