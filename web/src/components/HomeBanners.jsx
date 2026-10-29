import { useEffect, useState } from 'react'

/** Jeda perpindahan slide otomatis (ms). */
const SLIDE_INTERVAL = 4000

/**
 * Slider banner di atas beranda yang bergeser otomatis ke samping. Isinya diatur
 * admin di konsol (`AdminDashboard`); banner tanpa gambar tetap tampil sebagai
 * kartu warna (`tone`) supaya banner lama tidak hilang.
 */
export default function HomeBanners({ banners = [] }) {
  const [index, setIndex] = useState(0)
  const count = banners.length

  useEffect(() => {
    if (count < 2) return undefined

    const timer = setInterval(() => {
      setIndex((current) => (current + 1) % count)
    }, SLIDE_INTERVAL)

    return () => clearInterval(timer)
  }, [count])

  if (count === 0) return null

  const active = index % count

  return (
    <section className="banner-slider" aria-label="Informasi terbaru">
      <div
        className="banner-slider__track"
        style={{ transform: `translateX(-${active * 100}%)` }}
      >
        {banners.map((banner) => (
          <article className="banner-slide" key={banner.id}>
            {banner.imageUrl ? (
              <img
                className="banner-slide__image"
                src={banner.imageUrl}
                alt={banner.title || 'Banner'}
                loading="lazy"
              />
            ) : (
              <div className={`banner banner--${banner.tone}`}>
                <p className="banner__title">{banner.title}</p>
                {banner.subtitle ? <p className="banner__text">{banner.subtitle}</p> : null}
              </div>
            )}
          </article>
        ))}
      </div>

      {count > 1 ? (
        <div className="banner-slider__dots">
          {banners.map((banner, dotIndex) => (
            <button
              key={banner.id}
              type="button"
              className={`banner-slider__dot${dotIndex === active ? ' is-active' : ''}`}
              aria-label={`Banner ${dotIndex + 1}`}
              onClick={() => setIndex(dotIndex)}
            />
          ))}
        </div>
      ) : null}
    </section>
  )
}
