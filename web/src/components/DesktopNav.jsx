import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext.jsx'
import { HistoryIcon, HomeIcon, ShieldIcon, UserIcon, WalletIcon } from './Icons.jsx'

const links = [
  { to: '/', label: 'Beranda', Icon: HomeIcon, end: true },
  { to: '/transaksi', label: 'Transaksi', Icon: HistoryIcon },
  { to: '/saldo', label: 'Saldo', Icon: WalletIcon },
  { to: '/profil', label: 'Profil', Icon: UserIcon },
]

export default function DesktopNav() {
  const { user } = useAuth()
  const items =
    user?.role === 'admin'
      ? [...links, { to: '/admin', label: 'Admin', Icon: ShieldIcon }]
      : links

  return (
    <nav className="desktop-nav" aria-label="Navigasi utama">
      {items.map(({ to, label, Icon, end }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) => `desktop-nav__link${isActive ? ' is-active' : ''}`}
        >
          <Icon />
          <span>{label}</span>
        </NavLink>
      ))}
    </nav>
  )
}
