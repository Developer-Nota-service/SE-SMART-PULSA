import { Link, Outlet } from 'react-router-dom'
import BottomNav from './BottomNav.jsx'
import NotificationBell from './NotificationBell.jsx'

export default function AppShell() {
  return (
    <div className="app">
      <header className="topbar">
        <Link to="/" className="topbar__brand">
          <span className="brand-mark">SE</span>
          <span className="topbar__titles">
            <span className="topbar__title">SE SMART PULSA</span>
            <span className="topbar__sub">PPOB · Pulsa, Data &amp; Tagihan</span>
          </span>
        </Link>
        <div className="topbar__actions">
          <NotificationBell />
        </div>
      </header>

      <main className="content">
        <Outlet />
      </main>

      <BottomNav />
    </div>
  )
}
