import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { dateOnly, rupiah } from '../../lib/format.js'
import { CloseIcon, PlusIcon } from '../Icons.jsx'
import { EmptyState, ErrorState, LoadingState } from '../StateViews.jsx'

const TONES = [
  { key: 'navy', label: 'Navy' },
  { key: 'blue', label: 'Biru' },
  { key: 'green', label: 'Hijau' },
  { key: 'amber', label: 'Kuning' },
]

const RANK_LIMITS = [3, 5, 10]
const RANK_PERIODS = [7, 30, 90]
const ACCOUNT_FILTERS = [
  { key: 'all', label: 'Semua akun' },
  { key: 'reseller', label: 'Reseller' },
  { key: 'personal', label: 'Pribadi' },
]

const toneLabel = (tone) => TONES.find((item) => item.key === tone)?.label || 'Navy'

/**
 * Pengaturan beranda pengguna: banner, promo, dan peringkat pembelian otomatis.
 * Peringkat tidak diisi manual — server menghitungnya dari transaksi sukses
 * akun reseller maupun pribadi sesuai pilihan di sini.
 */
export default function AdminDashboard() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState(null)
  const [confirming, setConfirming] = useState('')
  const [busy, setBusy] = useState(false)
  // Nomor WhatsApp penerima notifikasi kenaikan harga Digiflazz.
  const [alertPhone, setAlertPhone] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      setData(await api.adminDashboard())
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  // Server menyimpan nomor dalam format 62…, jadi input disamakan setelah muat.
  useEffect(() => {
    setAlertPhone(data?.settings?.priceAlertWhatsapp || '')
  }, [data?.settings?.priceAlertWhatsapp])

  const toggleActive = async (kind, row) => {
    setBusy(true)
    setNotice('')
    try {
      if (kind === 'banner') await api.adminUpdateBanner(row.id, { active: !row.active })
      else await api.adminUpdatePromo(row.id, { active: !row.active })
      setNotice(row.active ? 'Disembunyikan dari beranda.' : 'Ditampilkan di beranda.')
      await load()
    } catch (requestError) {
      setNotice(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const remove = async (kind, row) => {
    const key = `${kind}-${row.id}`
    if (confirming !== key) {
      setConfirming(key)
      return
    }

    setBusy(true)
    setNotice('')
    try {
      if (kind === 'banner') await api.adminDeleteBanner(row.id)
      else await api.adminDeletePromo(row.id)
      setConfirming('')
      setNotice(kind === 'banner' ? 'Banner dihapus.' : 'Promo dihapus.')
      await load()
    } catch (requestError) {
      setNotice(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const saveSettings = async (patch) => {
    setBusy(true)
    setNotice('')
    try {
      const result = await api.adminUpdateDashboardSettings(patch)
      setData((previous) => ({
        ...previous,
        settings: result.settings,
        leaderboard: result.leaderboard,
      }))
      setNotice('Pengaturan disimpan.')
    } catch (requestError) {
      setNotice(requestError.message)
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <LoadingState label="Memuat konten beranda…" />
  if (error) return <ErrorState message={error} onRetry={load} />
  if (!data) return null

  const { banners, promos, settings, leaderboard } = data

  return (
    <>
      <div className="section-head">
        <h2 className="section-title">Beranda pengguna</h2>
        <span className="section-hint">banner, promo, peringkat</span>
      </div>

      {notice ? <p className="admin-note">{notice}</p> : null}

      <section className="stack">
        <div className="section-head">
          <h3 className="section-title">Banner</h3>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setEditing({ kind: 'banner', row: null })}
          >
            <PlusIcon /> Tambah
          </button>
        </div>

        {banners.length === 0 ? (
          <EmptyState
            title="Belum ada banner"
            description="Tambahkan banner untuk menyapa pengguna di beranda."
          />
        ) : (
          banners.map((row) => (
            <article className="card admin-card" key={`banner-${row.id}`}>
              {row.imageUrl ? (
                <img
                  className="admin-preview__image"
                  src={row.imageUrl}
                  alt={row.title || 'Banner'}
                />
              ) : (
                <div className={`admin-preview admin-preview--${row.tone}`}>
                  <p className="admin-preview__title">{row.title}</p>
                  {row.subtitle ? <p className="admin-preview__text">{row.subtitle}</p> : null}
                </div>
              )}

              <div className="admin-card__actions">
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => setEditing({ kind: 'banner', row })}
                >
                  Ubah
                </button>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={busy}
                  onClick={() => toggleActive('banner', row)}
                >
                  {row.active ? 'Sembunyikan' : 'Tayangkan'}
                </button>
                <button
                  type="button"
                  className={`btn btn--sm ${confirming === `banner-${row.id}` ? 'btn--danger-ghost' : 'btn--ghost'}`}
                  disabled={busy}
                  onClick={() => remove('banner', row)}
                >
                  {confirming === `banner-${row.id}` ? 'Yakin hapus?' : 'Hapus'}
                </button>
              </div>

              <p className="admin-card__sub">
                {row.imageUrl ? 'Banner gambar' : `Gaya ${toneLabel(row.tone)}`} ·{' '}
                {row.active ? 'tampil di beranda' : 'disembunyikan'}
              </p>
            </article>
          ))
        )}
      </section>

      <section className="stack">
        <div className="section-head">
          <h3 className="section-title">Promo</h3>
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setEditing({ kind: 'promo', row: null })}
          >
            <PlusIcon /> Tambah
          </button>
        </div>

        {promos.length === 0 ? (
          <EmptyState
            title="Belum ada promo"
            description="Promo yang aktif akan muncul di beranda pengguna."
          />
        ) : (
          promos.map((row) => (
            <article className="card admin-card" key={`promo-${row.id}`}>
              <div className="admin-card__head">
                <div>
                  <p className="admin-card__title">{row.title}</p>
                  {row.description ? <p className="admin-card__sub">{row.description}</p> : null}
                </div>
                <div className="admin-card__tags">
                  {row.active ? (
                    <span className="badge badge--xs badge--ok">Aktif</span>
                  ) : (
                    <span className="badge badge--xs badge--muted">Nonaktif</span>
                  )}
                </div>
              </div>

              <div className="rows">
                {row.code ? (
                  <div className="row">
                    <span className="row__label">Kode</span>
                    <span className="row__value">{row.code}</span>
                  </div>
                ) : null}
                {row.discountPercent > 0 ? (
                  <div className="row">
                    <span className="row__label">Potongan harga</span>
                    <span className="row__value">{row.discountPercent}%</span>
                  </div>
                ) : null}
                {row.badge ? (
                  <div className="row">
                    <span className="row__label">Label</span>
                    <span className="row__value">{row.badge}</span>
                  </div>
                ) : null}
                {row.validUntil ? (
                  <div className="row">
                    <span className="row__label">Berlaku sampai</span>
                    <span className="row__value">{dateOnly(row.validUntil)}</span>
                  </div>
                ) : null}
              </div>

              <div className="admin-card__actions">
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  onClick={() => setEditing({ kind: 'promo', row })}
                >
                  Ubah
                </button>
                <button
                  type="button"
                  className="btn btn--ghost btn--sm"
                  disabled={busy}
                  onClick={() => toggleActive('promo', row)}
                >
                  {row.active ? 'Sembunyikan' : 'Tayangkan'}
                </button>
                <button
                  type="button"
                  className={`btn btn--sm ${confirming === `promo-${row.id}` ? 'btn--danger-ghost' : 'btn--ghost'}`}
                  disabled={busy}
                  onClick={() => remove('promo', row)}
                >
                  {confirming === `promo-${row.id}` ? 'Yakin hapus?' : 'Hapus'}
                </button>
              </div>
            </article>
          ))
        )}
      </section>

      <section>
        <div className="section-head">
          <h3 className="section-title">Notifikasi WhatsApp</h3>
          <span className="section-hint">kenaikan harga Digiflazz</span>
        </div>

        <div className="card admin-card">
          <label className="field">
            <span className="field__label">Nomor WhatsApp admin</span>
            <input
              className="input"
              inputMode="tel"
              placeholder="081346043318"
              value={alertPhone}
              disabled={busy}
              onChange={(event) => setAlertPhone(event.target.value.replace(/[^\d+\s-]/g, ''))}
            />
            <span className="field__hint">
              Setiap sinkron harga (tiap 30 menit), kenaikan harga modal produk dikirim ke nomor
              ini lengkap dengan nama produk dan nominal lama → baru. Kosongkan untuk mematikan
              notifikasi.
            </span>
          </label>
          <button
            type="button"
            className="btn btn--primary btn--sm"
            disabled={busy}
            onClick={() => saveSettings({ priceAlertWhatsapp: alertPhone })}
          >
            {busy ? 'Menyimpan…' : 'Simpan nomor'}
          </button>
        </div>
      </section>

      <section>
        <div className="section-head">
          <h3 className="section-title">Peringkat pembelian</h3>
          <span className="section-hint">otomatis dari transaksi</span>
        </div>

        <div className="card admin-card">
          <label className="admin-toggle">
            <input
              type="checkbox"
              checked={settings.leaderboardEnabled}
              disabled={busy}
              onChange={(event) => saveSettings({ leaderboardEnabled: event.target.checked })}
            />
            <span>Tampilkan peringkat di beranda pengguna</span>
          </label>

          <div className="admin-grid">
            <label className="field field--compact">
              <span className="field__label">Jumlah peringkat</span>
              <select
                className="input"
                value={settings.leaderboardLimit}
                disabled={busy}
                onChange={(event) => saveSettings({ leaderboardLimit: Number(event.target.value) })}
              >
                {RANK_LIMITS.map((limit) => (
                  <option key={limit} value={limit}>
                    {limit} teratas
                  </option>
                ))}
              </select>
            </label>

            <label className="field field--compact">
              <span className="field__label">Rentang waktu</span>
              <select
                className="input"
                value={settings.leaderboardPeriodDays}
                disabled={busy}
                onChange={(event) =>
                  saveSettings({ leaderboardPeriodDays: Number(event.target.value) })
                }
              >
                {RANK_PERIODS.map((days) => (
                  <option key={days} value={days}>
                    {days} hari terakhir
                  </option>
                ))}
              </select>
            </label>

            <label className="field field--compact">
              <span className="field__label">Peserta</span>
              <select
                className="input"
                value={settings.leaderboardAccountType}
                disabled={busy}
                onChange={(event) =>
                  saveSettings({ leaderboardAccountType: event.target.value })
                }
              >
                {ACCOUNT_FILTERS.map((filter) => (
                  <option key={filter.key} value={filter.key}>
                    {filter.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <p className="admin-card__sub">
            Dihitung dari jumlah transaksi sukses; transaksi gagal dan akun admin tidak dihitung.
            Nama pembeli disamarkan.
          </p>

          {leaderboard.items.length === 0 ? (
            <p className="admin-card__sub">
              {settings.leaderboardEnabled
                ? 'Belum ada transaksi sukses pada rentang ini.'
                : 'Peringkat sedang tidak ditampilkan.'}
            </p>
          ) : (
            <div className="rows">
              {leaderboard.items.map((row) => (
                <div className="row" key={row.rank}>
                  <span className="row__label">
                    #{row.rank} {row.name} ·{' '}
                    {row.accountType === 'personal' ? 'Pribadi' : 'Reseller'}
                  </span>
                  <span className="row__value">
                    {row.transactions} trx · {rupiah(row.amount)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>

      {editing?.kind === 'banner' ? (
        <BannerSheet
          banner={editing.row}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            setNotice(editing.row ? 'Banner diperbarui.' : 'Banner ditambahkan.')
            load()
          }}
        />
      ) : null}

      {editing?.kind === 'promo' ? (
        <PromoSheet
          promo={editing.row}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            setNotice(editing.row ? 'Promo diperbarui.' : 'Promo ditambahkan.')
            load()
          }}
        />
      ) : null}
    </>
  )
}

function BannerSheet({ banner, onClose, onSaved }) {
  const [title, setTitle] = useState(banner?.title || '')
  const [subtitle, setSubtitle] = useState(banner?.subtitle || '')
  const [tone, setTone] = useState(banner?.tone || 'navy')
  const [active, setActive] = useState(banner?.active ?? true)
  const [image, setImage] = useState(null)
  const [removeImage, setRemoveImage] = useState(false)
  const [preview, setPreview] = useState(banner?.imageUrl || '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useSheetClose(onClose)

  const hasImage = Boolean(image) || (Boolean(banner?.imageUrl) && !removeImage)

  const pickImage = (event) => {
    const picked = event.target.files?.[0] || null
    setImage(picked)
    setRemoveImage(false)
    setPreview(picked ? URL.createObjectURL(picked) : banner?.imageUrl || '')
  }

  const clearImage = () => {
    setImage(null)
    setRemoveImage(Boolean(banner?.imageUrl))
    setPreview('')
  }

  const save = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const form = new FormData()
      form.append('title', title)
      form.append('subtitle', subtitle)
      form.append('tone', tone)
      form.append('active', active ? '1' : '0')
      if (image) form.append('image', image)
      if (removeImage) form.append('removeImage', '1')

      if (banner) await api.adminSaveBanner(banner.id, form)
      else await api.adminCreateBanner(form)
      onSaved()
    } catch (requestError) {
      setError(
        requestError.errors?.image?.[0] ||
          requestError.errors?.title?.[0] ||
          requestError.message,
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onClose}>
      <form
        className="sheet"
        role="dialog"
        aria-label="Banner beranda"
        onClick={(event) => event.stopPropagation()}
        onSubmit={save}
      >
        <div className="sheet__grip" />
        <div className="sheet__head">
          <h2 className="sheet__title">{banner ? 'Ubah banner' : 'Tambah banner'}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Tutup">
            <CloseIcon />
          </button>
        </div>

        <label className="field">
          <span className="field__label">Judul</span>
          <input
            className="input"
            value={title}
            maxLength={80}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="Contoh: Bonus saldo 10% hari ini"
          />
        </label>

        <div className="field">
          <span className="field__label">Gambar banner (JPG, PNG, atau WEBP · maks 4 MB)</span>
          {preview ? (
            <img className="banner-upload__preview" src={preview} alt="Pratinjau banner" />
          ) : null}
          <input
            className="input"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onChange={pickImage}
          />
          {hasImage ? (
            <button type="button" className="btn btn--ghost btn--sm" onClick={clearImage}>
              Hapus gambar
            </button>
          ) : null}
          <span className="field__hint">
            Ukuran ideal 3:1 (mis. 1200 × 400). Tanpa gambar, banner tampil sebagai kartu warna.
          </span>
        </div>

        <label className="field">
          <span className="field__label">Keterangan</span>
          <input
            className="input"
            value={subtitle}
            maxLength={160}
            onChange={(event) => setSubtitle(event.target.value)}
            placeholder="Contoh: Isi saldo minimal Rp100.000"
          />
        </label>

        <div className="field">
          <span className="field__label">Warna (bila tanpa gambar)</span>
          <div className="chips">
            {TONES.map((item) => (
              <button
                key={item.key}
                type="button"
                className={`chip${tone === item.key ? ' is-active' : ''}`}
                onClick={() => setTone(item.key)}
              >
                {item.label}
              </button>
            ))}
          </div>
        </div>

        <label className="admin-toggle">
          <input
            type="checkbox"
            checked={active}
            onChange={(event) => setActive(event.target.checked)}
          />
          <span>Tampilkan di beranda pengguna</span>
        </label>

        {error ? (
          <p className="field__error" role="alert">
            {error}
          </p>
        ) : null}

        <button type="submit" className="btn btn--primary" disabled={busy || (!hasImage && title.trim().length < 3)}>
          {busy ? 'Menyimpan…' : 'Simpan banner'}
        </button>
      </form>
    </div>
  )
}

function PromoSheet({ promo, onClose, onSaved }) {
  const [title, setTitle] = useState(promo?.title || '')
  const [description, setDescription] = useState(promo?.description || '')
  const [code, setCode] = useState(promo?.code || '')
  const [discountPercent, setDiscountPercent] = useState(promo?.discountPercent ?? 0)
  const [badge, setBadge] = useState(promo?.badge || '')
  const [validUntil, setValidUntil] = useState(promo?.validUntil || '')
  const [active, setActive] = useState(promo?.active ?? true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useSheetClose(onClose)

  const save = async (event) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    try {
      const body = {
        title,
        description,
        code,
        discountPercent: Number(discountPercent) || 0,
        badge,
        validUntil: validUntil || null,
        active,
      }
      if (promo) await api.adminUpdatePromo(promo.id, body)
      else await api.adminCreatePromo(body)
      onSaved()
    } catch (requestError) {
      setError(
        requestError.errors?.title?.[0] ||
          requestError.errors?.code?.[0] ||
          requestError.errors?.discountPercent?.[0] ||
          requestError.errors?.validUntil?.[0] ||
          requestError.message,
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="overlay" onClick={onClose}>
      <form
        className="sheet"
        role="dialog"
        aria-label="Promo beranda"
        onClick={(event) => event.stopPropagation()}
        onSubmit={save}
      >
        <div className="sheet__grip" />
        <div className="sheet__head">
          <h2 className="sheet__title">{promo ? 'Ubah promo' : 'Tambah promo'}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Tutup">
            <CloseIcon />
          </button>
        </div>

        <label className="field">
          <span className="field__label">Judul</span>
          <input
            className="input"
            value={title}
            maxLength={80}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="Contoh: Diskon biaya admin"
          />
        </label>

        <label className="field">
          <span className="field__label">Keterangan</span>
          <input
            className="input"
            value={description}
            maxLength={200}
            onChange={(event) => setDescription(event.target.value)}
            placeholder="Contoh: Gratis biaya admin untuk transaksi pertama"
          />
        </label>

        <label className="field">
          <span className="field__label">Kode promo (opsional)</span>
          <input
            className="input"
            value={code}
            maxLength={24}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
            placeholder="HEMAT10"
          />
        </label>

        <label className="field">
          <span className="field__label">Potongan harga (%)</span>
          <input
            className="input"
            type="number"
            min={0}
            max={90}
            inputMode="numeric"
            value={discountPercent}
            onChange={(event) => setDiscountPercent(event.target.value)}
          />
          <span className="field__hint">
            Pembeli memasukkan kode di halaman beli dan harga produk dipotong sebesar ini. Isi 0
            bila promo hanya informasi (tanpa potongan). Maksimal 90%.
          </span>
        </label>

        <label className="field">
          <span className="field__label">Label (opsional)</span>
          <input
            className="input"
            value={badge}
            maxLength={24}
            onChange={(event) => setBadge(event.target.value)}
            placeholder="Contoh: Terbatas"
          />
        </label>

        <label className="field">
          <span className="field__label">Berlaku sampai (opsional)</span>
          <input
            className="input"
            type="date"
            value={validUntil}
            onChange={(event) => setValidUntil(event.target.value)}
          />
        </label>

        <label className="admin-toggle">
          <input
            type="checkbox"
            checked={active}
            onChange={(event) => setActive(event.target.checked)}
          />
          <span>Tampilkan di beranda pengguna</span>
        </label>

        {error ? (
          <p className="field__error" role="alert">
            {error}
          </p>
        ) : null}

        <button type="submit" className="btn btn--primary" disabled={busy || title.trim().length < 3}>
          {busy ? 'Menyimpan…' : 'Simpan promo'}
        </button>
      </form>
    </div>
  )
}

function useSheetClose(onClose) {
  useEffect(() => {
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose])
}
