import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import AccountTypeSwitcher from '../components/AccountTypeSwitcher.jsx'
import ProfileContactEditor from '../components/ProfileContactEditor.jsx'
import ProfileHero from '../components/ProfileHero.jsx'
import {
  BoltIcon,
  ChevronRightIcon,
  ClockIcon,
  FileIcon,
  HelpIcon,
  LogoutIcon,
  ShieldIcon,
  WalletIcon,
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
  const { user, lastLoginAt, lastLogin, logout } = useAuth()
  const navigate = useNavigate()
  const device = lastLogin?.device
  const deviceLabel =
    device?.label && device.label !== device.type
      ? [device.label, device.type].filter(Boolean).join(' · ')
      : (device?.label ?? '')
  const systemLabel = [device?.os, device?.browser].filter(Boolean).join(' · ')
  const hasLoginDetail = Boolean(lastLogin && (lastLogin.ip || lastLogin.location || device))
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
              Email baru dikonfirmasi dengan kode OTP ke email baru; nomor WhatsApp baru
              dikonfirmasi dengan kode OTP ke nomor baru.
            </p>
          </div>
        )}
      </section>

      <AccountTypeSwitcher user={user} />

      <section className="card">
        <div className="section-head">
          <h2 className="section-title">Ringkasan akun</h2>
          <span className="section-hint">
            {user?.accountType === 'personal' ? 'Harga publik' : 'Harga reseller'}
          </span>
        </div>

        <div className="summary">
          <div className="summary__main">
            <span className="summary__icon" aria-hidden="true">
              {user?.accountType === 'personal' ? <BoltIcon /> : <WalletIcon />}
            </span>
            <span className="summary__body">
              <span className="summary__label">Metode bayar</span>
              <span className="summary__value">
                {user?.accountType === 'personal'
                  ? 'Midtrans · bayar langsung'
                  : 'Saldo · isi saldo dulu'}
              </span>
            </span>
          </div>

          <div className="summary__grid">
            <div className="summary__tile summary__tile--wide">
              <span className="summary__label">
                <ClockIcon /> Terakhir masuk
              </span>
              <span className="summary__value">
                {lastLogin?.at || lastLoginAt ? dateTime(lastLogin?.at || lastLoginAt) : '—'}
              </span>

              {hasLoginDetail ? (
                <dl className="summary__facts">
                  <div className="summary__fact">
                    <dt>Lokasi</dt>
                    <dd>{lastLogin?.location || 'Tidak terlacak'}</dd>
                  </div>
                  <div className="summary__fact">
                    <dt>Perangkat</dt>
                    <dd>{deviceLabel || 'Tidak diketahui'}</dd>
                  </div>
                  <div className="summary__fact">
                    <dt>Sistem</dt>
                    <dd>{systemLabel || 'Tidak diketahui'}</dd>
                  </div>
                  <div className="summary__fact">
                    <dt>Alamat IP</dt>
                    <dd>{lastLogin?.ip || '—'}</dd>
                  </div>
                </dl>
              ) : (
                <p className="summary__note">
                  Detail perangkat &amp; lokasi mulai tercatat pada login berikutnya.
                </p>
              )}
            </div>

            <div className="summary__tile">
              <span className="summary__label">
                <ClockIcon /> Jam layanan
              </span>
              <span className="summary__value">{support.hours}</span>
            </div>
            <div className="summary__tile">
              <span className="summary__label">
                <HelpIcon /> Dukungan
              </span>
              <span className="summary__value">{support.email}</span>
            </div>
          </div>
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
