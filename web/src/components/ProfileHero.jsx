import { useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { CameraIcon, CheckIcon, ShieldIcon } from './Icons.jsx'
import { api } from '../lib/api.js'
import { initials } from '../lib/format.js'

/**
 * Kartu identitas profil: foto (bisa diunggah), nama (bisa diubah), dan status
 * verifikasi nomor WhatsApp.
 */
export default function ProfileHero({ user }) {
  const { refresh } = useAuth()
  const fileRef = useRef(null)
  const [uploading, setUploading] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const [editingName, setEditingName] = useState(false)
  const [name, setName] = useState(user?.name ?? '')
  const [nameError, setNameError] = useState('')
  const [savingName, setSavingName] = useState(false)

  async function pickPhoto(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    setUploading(true)
    setPhotoError('')
    try {
      await api.uploadAvatar(file)
      await refresh()
    } catch (error) {
      setPhotoError(error.errors?.avatar?.[0] || error.message || 'Foto gagal diunggah.')
    } finally {
      setUploading(false)
    }
  }

  async function saveName(event) {
    event.preventDefault()
    setSavingName(true)
    setNameError('')
    try {
      await api.updateName(name.trim())
      await refresh()
      setEditingName(false)
    } catch (error) {
      setNameError(error.errors?.name?.[0] || error.message || 'Nama gagal disimpan.')
    } finally {
      setSavingName(false)
    }
  }

  return (
    <section className="profile-hero">
      <div className="profile-hero__top">
        <button
          type="button"
          className="profile-hero__avatar"
          onClick={() => fileRef.current?.click()}
          aria-label="Ubah foto profil"
          disabled={uploading}
        >
          {user?.avatarUrl ? (
            <img src={user.avatarUrl} alt={`Foto profil ${user?.name || ''}`} />
          ) : (
            <span>{initials(user?.name)}</span>
          )}
          <span className="profile-hero__camera" aria-hidden="true">
            <CameraIcon />
          </span>
        </button>
        <input
          ref={fileRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="visually-hidden"
          onChange={pickPhoto}
        />

        <div className="profile-hero__body">
          <h2 className="profile-hero__name">{user?.name}</h2>
          <p className="profile-hero__meta">{user?.email}</p>
          <p className="profile-hero__meta">{user?.phone}</p>
          <div className="profile-hero__tags">
            <span className="hero-tag">
              {user?.phoneVerified ? <CheckIcon /> : <ShieldIcon />}
              {user?.phoneVerified ? 'WhatsApp terverifikasi' : 'Belum terverifikasi'}
            </span>
            <span className="hero-tag">
              {user?.accountType === 'personal' ? 'Akun pribadi' : 'Reseller'}
            </span>
            {user?.role === 'admin' ? <span className="hero-tag">Admin</span> : null}
          </div>
        </div>
      </div>

      {uploading ? <p className="profile-hero__hint">Mengunggah foto…</p> : null}
      {photoError ? (
        <p className="profile-hero__hint profile-hero__hint--error" role="alert">
          {photoError}
        </p>
      ) : null}

      {editingName ? (
        <form className="profile-hero__form" onSubmit={saveName}>
          <input
            className="input"
            value={name}
            onChange={(event) => {
              setName(event.target.value)
              setNameError('')
            }}
            maxLength={60}
            autoComplete="name"
            placeholder="Nama lengkap"
          />
          {nameError ? <span className="field__error">{nameError}</span> : null}
          <div className="profile-hero__form-actions">
            <button type="submit" className="btn btn--light btn--sm" disabled={savingName}>
              {savingName ? 'Menyimpan…' : 'Simpan nama'}
            </button>
            <button
              type="button"
              className="btn btn--ghost-light btn--sm"
              onClick={() => {
                setEditingName(false)
                setName(user?.name ?? '')
                setNameError('')
              }}
            >
              Batal
            </button>
          </div>
        </form>
      ) : (
        <button
          type="button"
          className="btn btn--ghost-light btn--sm profile-hero__edit"
          onClick={() => setEditingName(true)}
        >
          Ubah nama
        </button>
      )}
    </section>
  )
}
