import { CATEGORIES } from '../lib/format.js'
import {
  BillIcon,
  BoltIcon,
  GameIcon,
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
}

export default function CategoryGrid({ value, onChange }) {
  return (
    <div className="category-grid" role="tablist" aria-label="Kategori produk">
      {CATEGORIES.map((category) => {
        const Icon = icons[category.icon]
        const active = value === category.key
        return (
          <button
            key={category.key}
            type="button"
            role="tab"
            aria-selected={active}
            className={`category${active ? ' is-active' : ''}`}
            onClick={() => onChange(category.key)}
          >
            <span className="category__icon">
              <Icon />
            </span>
            <span className="category__label">{category.label}</span>
          </button>
        )
      })}
    </div>
  )
}
