import { categoryLabel, dateTime, rupiah } from '../lib/format.js'
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
import StatusBadge from './StatusBadge.jsx'

const icons = {
  pulsa: SignalIcon,
  data: WifiIcon,
  pln_token: BoltIcon,
  pln_bill: BillIcon,
  ewallet: WalletIcon,
  game: GameIcon,
  voucher: TicketIcon,
  voucher_inject: InjectIcon,
}

export default function TransactionItem({ transaction, onClick }) {
  const Icon = icons[transaction.product?.category] || BillIcon
  const content = (
    <>
      <span className={`tx__icon tx__icon--${transaction.product?.category || 'default'}`}>
        <Icon />
      </span>
      <span className="tx__body">
        <span className="tx__name">{transaction.product?.name}</span>
        <span className="tx__meta">
          {categoryLabel(transaction.product?.category)} · {transaction.customerNumber}
        </span>
        <span className="tx__time">{dateTime(transaction.createdAt)}</span>
      </span>
      <span className="tx__right">
        <span className="tx__amount">{rupiah(transaction.amount)}</span>
        <StatusBadge status={transaction.status} />
      </span>
    </>
  )

  if (!onClick) return <div className="tx">{content}</div>

  return (
    <button type="button" className="tx tx--button" onClick={() => onClick(transaction)}>
      {content}
    </button>
  )
}
