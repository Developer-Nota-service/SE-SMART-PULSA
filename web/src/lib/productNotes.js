// Keterangan jenis produk untuk halaman beli.
//
// Pembeli tidak selalu paham istilah supplier (Flash, Zona, tengah malam), jadi
// blok keterangan hanya menampilkan penjelasan jenis paket yang sedang dibuka —
// bukan peringatan umum untuk semua jenis sekaligus.
//
// Semua teks diturunkan dari `name` + `instruction` produk setiap kali produk
// dimuat dari server. `instruction` ditulis ulang dari `desc` seller pada tiap
// sinkron Digiflazz, jadi keterangan di sini otomatis ikut berubah saat seller
// atau deskripsi produk dari supplier berganti.

const QUOTA_PATTERN = /(\d+(?:[.,]\d+)?)\s*(?:gb|giga(?:byte)?)\b/i
const DAYS_PATTERN = /(\d+)\s*hari\b/i
const NETWORK_PATTERN = /\b([2-5]g)\b/gi

// Kuota yang hanya berlaku pada jam tertentu, ditulis supplier sebagai
// "(01.00 - 09.00)", "01:00-09:00", atau kata "tengah malam"/"midnight".
const NIGHT_PATTERN =
  /\(\s*0?1[.:]00\s*[-–—]\s*0?9[.:]00\s*\)|0?1[.:]00\s*[-–—]\s*0?9[.:]00|tengah malam|midnight/i

// Kuota area terbatas: zona, lokal, kota, atau nama wilayah tertentu.
const AREA_PATTERN =
  /\bzona\b|\blokal\b|kotamu|kota-mu|kuota di kota|\barea\b|\bwilayah\b|\bregional\b|kalimantan|sumatra|sumatera|jawa|sulawesi|bali|papua|maluku|nusa tenggara|jabodetabek/i

const FLASH_PATTERN = /\bflash\b/i
const UNLIMITED_PATTERN = /\bunlimited\b|\bfup\b/i
const TRANSFER_PATTERN = /\btransfer\b/i

const SUPPLIER_TAIL =
  'Jumlah kuota, masa aktif, dan ketentuan lain mengikuti keterangan supplier di bawah.'

const DATA_NOTES = {
  night: {
    label: 'Paket Data Tengah Malam',
    text: `Kuota paket ini hanya bisa dipakai pada jam yang disebutkan (umumnya 01.00–09.00). Di luar jam itu kuota tidak bisa dipakai, sedangkan kuota reguler yang ikut di paket tetap mengikuti ketentuan supplier. ${SUPPLIER_TAIL}`,
  },
  area: {
    label: 'Paket Data Zona (Area Terbatas)',
    text: `Kuota paket ini hanya berlaku di area/zona yang disebutkan pada nama atau keterangan produk (mis. Kalimantan Zona 1 atau "kuota di kota-mu"). Di luar area tersebut kuota tidak bisa dipakai, jadi pastikan nomor tujuan memang berada di wilayah paket ini. ${SUPPLIER_TAIL}`,
  },
  unlimited: {
    label: 'Paket Data Unlimited (FUP)',
    text: 'Kuota unlimited tetap memakai kebijakan FUP operator: kecepatan bisa diturunkan setelah pemakaian melewati batas tertentu. Masa aktif dan besaran FUP mengikuti keterangan supplier.',
  },
  flash: {
    label: 'Paket Data Flash',
    text: `Paket Flash adalah kuota internet reguler yang langsung aktif setelah transaksi berhasil dan bisa dipakai sepanjang hari (24 jam) — bukan kuota yang hanya berlaku di jam atau area tertentu. ${SUPPLIER_TAIL}`,
  },
  regular: {
    label: 'Paket Data Reguler 24 Jam',
    text: `Kuota utama bisa dipakai sepanjang hari (24 jam) selama masa aktif paket, tanpa pembatasan jam maupun area, kecuali keterangan supplier menyebut ketentuan lain. ${SUPPLIER_TAIL}`,
  },
}

const PULSA_NOTE = {
  label: 'Pulsa Reguler',
  text: 'Pulsa dikirim ke nomor tujuan sesuai nominal yang dipilih. Nomor harus aktif dan sesuai operatornya; saldo pulsa bertambah otomatis setelah transaksi berhasil.',
}

const PULSA_TRANSFER_NOTE = {
  label: 'Pulsa Transfer',
  text: 'Pulsa transfer hanya bisa dikirim ke nomor dengan operator yang sama. Pastikan nomor tujuan benar dan aktif — pulsa yang sudah terkirim tidak bisa ditarik kembali.',
}

const VOUCHER_INJECT_NOTE = {
  label: 'Voucher Inject',
  text: 'Voucher ini di-inject ke voucher kosong Telkomsel, bukan voucher siap pakai. Simpan nomor voucher kosong Anda; kuota masuk ke voucher itu setelah transaksi berhasil.',
}

const VOUCHER_INJECT_AREA_NOTE = {
  label: 'Voucher Inject Zona (Area Terbatas)',
  text: 'Voucher ini di-inject ke voucher kosong Telkomsel, bukan voucher siap pakai. Kuota hanya berlaku di area/zona yang disebutkan (mis. Kalimantan Zona 1); di luar area itu kuota tidak bisa dipakai.',
}

const CATEGORY_NOTES = {
  pulsa: PULSA_NOTE,
  game: {
    label: 'Top Up Game',
    text: 'Item dikirim langsung ke akun game sesuai ID yang Anda isi. ID dan server tidak bisa diubah setelah transaksi diproses, dan item yang sudah masuk tidak bisa dipindahkan ke akun lain.',
  },
  ewallet: {
    label: 'Saldo E-Wallet',
    text: 'Saldo dikirim ke akun e-wallet dengan nomor yang Anda isi. Akun harus sudah aktif dan terdaftar di provider yang sesuai; saldo yang sudah masuk tidak bisa ditarik kembali.',
  },
  voucher: {
    label: 'Voucher Digital',
    text: 'Kode voucher dikirim ke email penerima setelah transaksi berhasil, bukan berupa saldo. Simpan kodenya — voucher bersifat rahasia dan hanya bisa dipakai sekali pada layanan yang sesuai.',
  },
  voucher_inject: VOUCHER_INJECT_NOTE,
  pln_token: {
    label: 'Token Listrik',
    text: 'Token listrik dikirim ke ID pelanggan/nomor meter yang Anda isi. Nomor token (SN) tersimpan di detail pesanan lalu dimasukkan ke meter; pastikan nomor meter sudah benar.',
  },
  pln_bill: {
    label: 'Tagihan Listrik',
    text: 'Pembayaran tagihan diteruskan ke ID pelanggan/nomor meter yang Anda isi. Pastikan tagihan belum dibayar di tempat lain — pembayaran yang sudah masuk tidak bisa dibatalkan.',
  },
}

const FALLBACK_NOTE = {
  label: 'Produk Digital',
  text: 'Produk diproses otomatis oleh supplier setelah pembayaran diterima. Pastikan data tujuan sudah benar; transaksi yang sudah diproses tidak dapat dibatalkan.',
}

// Angka penting dari nama produk + keterangan supplier untuk kategori data.
export function productSpecs(product) {
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

/**
 * Penjelasan satu jenis produk saja, sesuai kategori dan isi keterangan
 * supplier produk yang sedang dibuka.
 *
 * @returns {{ label: string, text: string }}
 */
export function productNote(product) {
  const text = `${product.name || ''} ${product.instruction || ''}`

  if (product.category === 'data') {
    if (NIGHT_PATTERN.test(text)) return DATA_NOTES.night
    if (AREA_PATTERN.test(text)) return DATA_NOTES.area
    if (UNLIMITED_PATTERN.test(text)) return DATA_NOTES.unlimited
    if (FLASH_PATTERN.test(text)) return DATA_NOTES.flash
    return DATA_NOTES.regular
  }

  if (product.category === 'pulsa') {
    return TRANSFER_PATTERN.test(text) ? PULSA_TRANSFER_NOTE : PULSA_NOTE
  }

  if (product.category === 'voucher_inject') {
    return AREA_PATTERN.test(text) ? VOUCHER_INJECT_AREA_NOTE : VOUCHER_INJECT_NOTE
  }

  return CATEGORY_NOTES[product.category] || FALLBACK_NOTE
}
