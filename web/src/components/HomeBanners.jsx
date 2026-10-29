/**
 * Strip banner di atas beranda. Isinya diatur admin di konsol
 * (`AdminDashboard`); banner nonaktif tidak pernah dikirim ke sini.
 */
export default function HomeBanners({ banners = [] }) {
  if (banners.length === 0) return null

  return (
    <section className="banner-strip" aria-label="Informasi terbaru">
      {banners.map((banner) => (
        <article className={`banner banner--${banner.tone}`} key={banner.id}>
          <p className="banner__title">{banner.title}</p>
          {banner.subtitle ? <p className="banner__text">{banner.subtitle}</p> : null}
        </article>
      ))}
    </section>
  )
}
