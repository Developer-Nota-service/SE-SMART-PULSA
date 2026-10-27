import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import AccountTypeSwitcher from '../components/AccountTypeSwitcher.jsx'
import ProfileContactEditor from '../components/ProfileContactEditor.jsx'
import ProfileHero from '../components/ProfileHero.jsx'
import {
  ChevronRightIcon,
  FileIcon,
  HelpIcon,
  LogoutIcon,
  ShieldIcon,
} from '../components/Icons.jsx'
import { dateTime } from '../lib/format.js'
import { support } from '../lib/support.js'

const menus = [
  { to: '/bantuan', label: 'Pusat bantuan', Icon: HelpIcon },
  { to: '/legal/syarat', label: 'Syarat & ketentuan', Icon: FileIcon },
  { to: '/legal/privasi', label: 'Kebijakan privasi', Icon: ShieldIcon },
]

function verificationBadge(verified) {
  return (
    <span className={`badge badge--xs ${verified ? 'badge--ok' : 'badge--warn'}`}>
      {verified ? 'Terverifikasi' : 'Belum'}
    </span>
  )
}

export default function ProfilePage() {
  const { user, lastLoginAt, logout } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)
  const [editingContact, setEditingContact] = useState(false)

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
      <ProfileHero user={user} />

      <section className="card">
        <div className="section-head">
          <h2 className="section-title">Kontak &amp; keamanan</h2>
          {editingContact ? null : (
            <button
              type="button"
              className="inline-link"
              onClick={() => setEditingContact(true)}
            >
              Ubah
            </button>
          )}
        </div>

        {editingContact ? (
          <ProfileContactEditor
            user={user}
            onDone={() => setEditingContact(false)}
            onCancel={() => setEditingContact(false)}
          />
        ) : (
          <div className="contact-list">
            <div className="contact-row">
              <div className="contact-row__body">
                <span className="contact-row__label">Email</span>
                <span className="contact-row__value">{user?.email}</span>
              </div>
              {verificationBadge(user?.emailVerified)}
            </div>

            <div className="contact-row">
              <div className="contact-row__body">
                <span className="contact-row__label">Nomor WhatsApp</span>
                <span className="contact-row__value">{user?.phone}</span>
              </div>
              {verificationBadge(user?.phoneVerified)}
            </div>

            <p className="note note--muted">
              Perubahan email atau nomor WhatsApp dikonfirmasi dengan kode OTP ke nomor baru.
            </p>
          </div>
        )}
      </section>

      <AccountTypeSwitcher user={user} />

      <section className="card">
        <h2 className="section-title">Ringkasan akun</h2>
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
