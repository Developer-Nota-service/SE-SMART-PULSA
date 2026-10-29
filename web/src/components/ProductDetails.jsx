import { productNote, productSpecs } from '../lib/productNotes.js'

/**
 * Keterangan produk di halaman beli: ringkasan angka penting (khusus paket
 * data), penjelasan jenis produk yang sedang dibuka, lalu `desc` asli supplier
 * dari `products.instruction` yang ikut diperbarui setiap sinkron harga.
 */
export default function ProductDetails({ product }) {
  const description = (product.instruction || '').trim()
  const specs = product.category === 'data' ? productSpecs(product) : []
  const note = productNote(product)

  return (
    <section className="product-notes" aria-label="Keterangan produk">
      <p className="product-notes__title">Keterangan produk</p>

      {specs.length > 0 ? (
        <ul className="product-notes__specs">
          {specs.map((spec) => (
            <li className="product-notes__spec" key={spec.label}>
              <span className="product-notes__label">{spec.label}</span>
              <span className="product-notes__value">{spec.value}</span>
            </li>
          ))}
        </ul>
      ) : null}

      <div className="product-notes__type">
        <p className="product-notes__type-label">{note.label}</p>
        <p className="product-notes__type-text">{note.text}</p>
      </div>

      {description ? (
        <>
          <p className="product-notes__desc-label">Keterangan dari supplier</p>
          <p className="product-notes__desc">{description}</p>
        </>
      ) : null}

      <p className="product-notes__source">
        Keterangan supplier diperbarui otomatis setiap harga produk disinkronkan.
      </p>
    </section>
  )
}
