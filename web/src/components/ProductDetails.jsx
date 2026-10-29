const QUOTA_PATTERN = /(\d+(?:[.,]\d+)?)\s*(?:gb|giga(?:byte)?)\b/i
const DAYS_PATTERN = /(\d+)\s*hari\b/i
const NETWORK_PATTERN = /\b([2-5]g)\b/gi

// Pembeli paket data sering salah paham karena nama produk tidak menyebut kuota
// atau masa aktifnya. Angka penting dirangkum dari nama + keterangan supplier;
// keterangan aslinya tetap ditampilkan utuh di bawah supaya tidak ada yang hilang.
function dataSpecs(product) {
  const text = `${product.name || ''} ${product.instruction || ''}`
  const quota = (text.match(QUOTA_PATTERN) || [])[0]
  const days = (text.match(DAYS_PATTERN) || [])[0]
  const networks = [...new Set((text.match(NETWORK_PATTERN) || []).map((item) => item.toUpperCase()))]

  return [
    quota ? { label: 'Kuota', value: quota.replace(/\s+/g, ' ').trim() } : null,
    days ? { label: 'Masa aktif', value: days.replace(/\s+/g, ' ').trim() } : null,
    networks.length > 0 ? { label: 'Jaringan', value: networks.join('/') } : null,
  ].filter(Boolean)
}

const DATA_NOTE =
  'Kuota, masa aktif, dan area pemakaian mengikuti keterangan supplier di atas. Paket dengan ' +
  'kuota lokal/zona hanya berlaku di area yang disebutkan, dan paket dengan jam tertentu ' +
  '(mis. 01.00–09.00) hanya bisa dipakai pada jam itu. Pastikan paket sesuai kebutuhan — ' +
  'transaksi yang sudah diproses tidak dapat dibatalkan.'

/**
 * Keterangan produk di halaman beli, diisi dari `products.instruction` yang
 * berasal dari `desc` seller Digiflazz dan ikut diperbarui setiap sinkron harga.
 */
export default function ProductDetails({ product }) {
  const description = (product.instruction || '').trim()
  const isData = product.category === 'data'
  const specs = isData ? dataSpecs(product) : []

  if (!description && specs.length === 0) return null

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

      {description ? <p className="product-notes__desc">{description}</p> : null}

      {isData ? <p className="product-notes__warn">{DATA_NOTE}</p> : null}

      <p className="product-notes__source">
        Keterangan ini dikirim langsung oleh supplier dan diperbarui otomatis setiap harga produk
        disinkronkan.
      </p>
    </section>
  )
}
