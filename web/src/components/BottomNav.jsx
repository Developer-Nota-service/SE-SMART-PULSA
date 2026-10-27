import { NavLink } from 'react-router-dom'
import { HistoryIcon, HomeIcon, UserIcon, WalletIcon } from './Icons.jsx'

const items = [
  { to: '/', label: 'Beranda', Icon: HomeIcon },
  { to: '/transaksi', label: 'Transaksi', Icon: HistoryIcon },
  { to: '/saldo', label: 'Saldo', Icon: WalletIcon },
  { to: '/profil', label: 'Profil', Icon: UserIcon },
]

export default function BottomNav() {
  return (
    <nav className="bottom-nav" aria-label="Navigasi utama">
      {items.map(({ to, label, Icon }) => (
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
