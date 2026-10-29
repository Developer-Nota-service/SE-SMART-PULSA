import { rupiah } from '../lib/format.js'

const ACCOUNT_LABEL = { reseller: 'Reseller', personal: 'Pribadi' }

/**
 * Peringkat pembelian otomatis dari transaksi sukses. Peserta dan jumlah
 * barisnya diatur admin; nama sudah disamarkan server.
 */
export default function HomeLeaderboard({ leaderboard }) {
  if (!leaderboard?.enabled || leaderboard.items.length === 0) return null

  return (
    <section>
      <div className="section-head">
        <h2 className="section-title">Peringkat pembelian</h2>
        <span className="section-hint">{leaderboard.periodDays} hari terakhir</span>
      </div>

      <div className="card card--flush">
        <ol className="rank-list">
          {leaderboard.items.map((row) => (
            <li className="rank-row" key={row.rank}>
              <span
                className={`rank-row__badge${row.rank <= 3 ? ' rank-row__badge--top' : ''}`}
              >
                {row.rank}
              </span>
              <span className="rank-row__body">
                <span className="rank-row__name">
                  {row.name}
                  <span className="badge badge--xs badge--muted">
                    {ACCOUNT_LABEL[row.accountType] || 'Reseller'}
                  </span>
                </span>
                <span className="rank-row__meta">{row.transactions} transaksi sukses</span>
              </span>
              <span className="rank-row__amount">{rupiah(row.amount)}</span>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}
