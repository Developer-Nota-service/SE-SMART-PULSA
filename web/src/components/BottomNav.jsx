import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import { HistoryIcon, HomeIcon, ShieldIcon, UserIcon, WalletIcon } from './Icons.jsx'

const items = [
  { to: '/', label: 'Beranda', Icon: HomeIcon },
  { to: '/transaksi', label: 'Transaksi', Icon: HistoryIcon },
  { to: '/saldo', label: 'Saldo', Icon: WalletIcon },
  { to: '/profil', label: 'Profil', Icon: UserIcon },
]

export default function BottomNav() {
  const { user } = useAuth()

  // Konsol admin hanya muncul untuk akun ber-role admin.
  const links =
    user?.role === 'admin'
      ? [...items.slice(0, 3), { to: '/admin', label: 'Admin', Icon: ShieldIcon }, items[3]]
      : items

  return (
    <nav className="bottom-nav" aria-label="Navigasi utama">
      {links.map(({ to, label, Icon }) => (
        <NavLink
          key={to}
          to={to}
          end={to === '/'}
          className={({ isActive }) => `nav-item${isActive ? ' is-active' : ''}`}
        >
          <Icon />
          <span>{label}</span>
        </NavLink>
      ))}
    </nav>
  )
}
