import { dateOnly } from '../lib/format.js'
import CopyButton from './CopyButton.jsx'

/** Promo aktif dari admin, tampil di beranda pengguna. */
export default function HomePromos({ promos = [] }) {
  if (promos.length === 0) return null

  return (
    <section>
      <div className="section-head">
        <h2 className="section-title">Promo</h2>
        <span className="section-hint">{promos.length} promo aktif</span>
      </div>

      <div className="stack promo-list">
        {promos.map((promo) => (
          <article className="card promo-card" key={promo.id}>
            <div className="promo-card__head">
              <p className="promo-card__title">{promo.title}</p>
              {promo.badge ? <span className="badge badge--soft">{promo.badge}</span> : null}
            </div>

            {promo.description ? (
              <p className="promo-card__text">{promo.description}</p>
            ) : null}

            {promo.code ? (
              <div className="promo-card__code">
                <span className="promo-card__code-label">Kode</span>
                <strong>{promo.code}</strong>
                <CopyButton value={promo.code} label="Salin" />
              </div>
            ) : null}

            {promo.validUntil ? (
              <p className="promo-card__meta">Berlaku sampai {dateOnly(promo.validUntil)}</p>
            ) : null}
          </article>
        ))}
      </div>
    </section>
  )
}
