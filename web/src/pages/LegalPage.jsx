import { useNavigate, useParams } from 'react-router-dom'
import { support } from '../lib/support.js'

const documents = {
  syarat: {
    title: 'Syarat & ketentuan',
    sections: [
      {
        heading: '1. Penggunaan layanan',
        body: 'SE SMART PULSA menyediakan layanan pengisian pulsa, paket data, token listrik, pembayaran tagihan, dan produk digital. Pengguna wajib memberikan data yang benar saat melakukan transaksi.',
      },
      {
        heading: '2. Saldo dan transaksi',
        body: 'Saldo merupakan titipan untuk pembelian produk dan tidak dapat dipindahtangankan. Nominal, harga, dan status transaksi ditentukan oleh server. Transaksi yang telah berhasil diproses tidak dapat dibatalkan.',
      },
      {
        heading: '3. Pembatasan',
        body: 'Penyalahgunaan layanan, termasuk upaya mengubah nominal atau status pembayaran dari sisi pengguna, dapat mengakibatkan penangguhan akun.',
      },
    ],
  },
  privasi: {
    title: 'Kebijakan privasi',
    sections: [
      {
        heading: '1. Data yang kami simpan',
        body: 'Nama, email, nomor HP, riwayat transaksi, dan catatan mutasi saldo disimpan untuk menjalankan layanan dan memenuhi kewajiban pencatatan.',
      },
      {
        heading: '2. Kata sandi',
        body: 'Kata sandi disimpan dalam bentuk hash dan tidak pernah ditampilkan kembali. Jangan membagikan kata sandi kepada siapa pun, termasuk kepada petugas dukungan.',
      },
      {
        heading: '3. Pembayaran',
        body: 'Pemrosesan pembayaran dilakukan oleh penyedia pembayaran. Kunci rahasia penyedia tidak pernah dikirim ke aplikasi frontend.',
      },
      {
        heading: '4. Pertanyaan',
        body: `Pertanyaan terkait data pribadi dapat dikirim ke ${support.email}.`,
      },
    ],
  },
}

export default function LegalPage() {
  const { doc } = useParams()
  const navigate = useNavigate()
  const content = documents[doc] || documents.syarat

  return (
    <div className="stack">
      <button type="button" className="back-link" onClick={() => navigate(-1)}>
        ← Kembali
      </button>

      {content.sections.map((section) => (
        <section key={section.heading} className="card">
          <h2 className="legal-heading">{section.heading}</h2>
          <p className="legal-body">{section.body}</p>
        </section>
      ))}

      <p className="note note--muted">
        Dokumen contoh untuk tahap pengembangan. Versi final perlu ditinjau sebelum aplikasi
        dipublikasikan.
      </p>
    </div>
  )
}
