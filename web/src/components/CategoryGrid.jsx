import { Link } from 'react-router-dom'
import { CATEGORIES } from '../lib/format.js'
import {
  BillIcon,
  BoltIcon,
  GameIcon,
  InjectIcon,
  SignalIcon,
  TicketIcon,
  WalletIcon,
  WifiIcon,
} from './Icons.jsx'

const icons = {
  signal: SignalIcon,
  wifi: WifiIcon,
  bolt: BoltIcon,
  bill: BillIcon,
  wallet: WalletIcon,
  game: GameIcon,
  ticket: TicketIcon,
  inject: InjectIcon,
}

// Setiap kategori adalah tautan ke halamannya sendiri, jadi produk baru
// ditampilkan setelah kategori dipilih.
export default function CategoryGrid({ value }) {
  return (
    <div className="category-grid" aria-label="Kategori produk">
      {CATEGORIES.map((category) => {
        const Icon = icons[category.icon]
        const active = value === category.key
        return (
          <Link
            key={category.key}
            to={`/kategori/${category.key}`}
            className={`category${active ? ' is-active' : ''}`}
            aria-current={active ? 'page' : undefined}
          >
            <span className="category__icon">
              <Icon />
            </span>
            <span className="category__label">{category.label}</span>
          </Link>
        )
      })}
    </div>
  )
}
