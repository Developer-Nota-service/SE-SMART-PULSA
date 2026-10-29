import { useSearchParams } from 'react-router-dom'
import AdminDeposits from './AdminDeposits.jsx'
import AdminWithdrawals from './AdminWithdrawals.jsx'

const TABS = [
  { key: 'deposit', label: 'Deposit masuk' },
  { key: 'penarikan', label: 'Penarikan saldo' },
]

/**
 * Uang masuk (deposit Midtrans) dan uang keluar (penarikan) dalam satu
 * halaman — keduanya pekerjaan rutin pengelola, tapi alurnya berbeda.
 */
export default function AdminFinance() {
  const [params, setParams] = useSearchParams()
  const active = params.get('tab') === 'penarikan' ? 'penarikan' : 'deposit'

  return (
    <>
      <div className="chips admin-tabs">
        {TABS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            className={`chip${active === tab.key ? ' is-active' : ''}`}
            onClick={() => setParams(tab.key === 'deposit' ? {} : { tab: tab.key })}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {active === 'penarikan' ? <AdminWithdrawals /> : <AdminDeposits />}
    </>
  )
}
