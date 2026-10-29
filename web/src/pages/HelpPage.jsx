import { useNavigate } from 'react-router-dom'
import { HelpIcon } from '../components/Icons.jsx'
import { support } from '../lib/support.js'

const faqs = [
  {
    q: 'Bagaimana cara mengisi saldo?',
    a: 'Buka menu Saldo, masukkan nominal antara Rp10.000 dan Rp2.000.000, lalu buat pesanan deposit. Saldo baru bertambah setelah pembayaran terverifikasi oleh server.',
  },
  {
    q: 'Mengapa saldo saya belum bertambah?',
    a: 'Deposit berstatus Menunggu sampai pembayaran diterima. Saldo hanya dikreditkan oleh server setelah notifikasi pembayaran yang sah diverifikasi — tidak bisa dipercepat dari aplikasi.',
  },
  {
    q: 'Apakah produk yang dijual sudah aktif?',
    a: 'Belum. Seluruh produk bertanda DEMO hanya untuk pengujian alur aplikasi. Tidak ada pulsa, token listrik, atau voucher yang benar-benar dikirim pada tahap ini.',
  },
  {
    q: 'Transaksi saya gagal, apakah dana kembali?',
    a: 'Saldo hanya dipotong ketika transaksi dinyatakan berhasil. Transaksi gagal atau masih menunggu tidak memotong saldo. Untuk pembayaran Midtrans, dana dikembalikan ke metode pembayaran asal; bila metode itu tidak mendukung pengembalian, nominalnya otomatis masuk ke saldo akun Anda (semua tipe akun) dan bisa ditarik lewat menu Saldo.',
  },
  {
    q: 'Bagaimana melaporkan masalah transaksi?',
    a: 'Sertakan nomor pesanan (format TRX-YYYYMMDD-XXXX) saat menghubungi dukungan agar riwayat transaksi dapat ditelusuri.',
  },
]

export default function HelpPage() {
  const navigate = useNavigate()

  return (
    <div className="stack">
      <button type="button" className="back-link" onClick={() => navigate(-1)}>
        ← Kembali
      </button>

      <section className="card">
        <span className="help-mark">
          <HelpIcon />
        </span>
        <h2 className="section-title">Pusat bantuan</h2>
        <p className="section-hint">Pertanyaan yang sering diajukan seputar SE SMART PULSA.</p>
      </section>

      <section className="card card--flush">
        {faqs.map((faq) => (
          <details key={faq.q} className="faq">
            <summary className="faq__question">{faq.q}</summary>
            <p className="faq__answer">{faq.a}</p>
          </details>
        ))}
      </section>

      <section className="card">
        <h2 className="section-title">Hubungi dukungan</h2>
        <div className="info-row">
          <span className="info-row__label">Email</span>
          <a className="info-row__value inline-link" href={`mailto:${support.email}`}>
            {support.email}
          </a>
        </div>
        <div className="info-row">
          <span className="info-row__label">WhatsApp</span>
          <a
            className="info-row__value inline-link"
            href={`https://wa.me/${support.whatsapp.replace(/\D/g, '')}`}
            target="_blank"
            rel="noreferrer"
          >
            {support.whatsapp}
          </a>
        </div>
        <div className="info-row">
          <span className="info-row__label">Jam layanan</span>
          <span className="info-row__value">{support.hours}</span>
        </div>
      </section>
    </div>
  )
}
