import { useCallback, useEffect, useState } from 'react'
import { api } from '../../lib/api.js'
import { ErrorState, LoadingState } from '../StateViews.jsx'

const SOURCE_LABEL = {
  dashboard: 'Dari dashboard',
  env: 'Dari env',
  unset: 'Belum diisi',
}

const SOURCE_CLASS = {
  dashboard: 'badge--ok',
  env: 'badge--muted',
  unset: 'badge--warn',
}

/**
 * Pengaturan API key semua integrasi (Midtrans, Digiflazz, Fonnte, email).
 *
 * Nilai lama hanya tampil tersamarkan. Setiap penyimpanan wajib password admin
 * dan server menyimpannya terenkripsi di database, sehingga kunci bisa diganti
 * tanpa menyentuh file .env atau me-restart container.
 */
export default function AdminIntegrations() {
  const [groups, setGroups] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [drafts, setDrafts] = useState({})
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [formError, setFormError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await api.adminIntegrations()
      setGroups(data.groups || [])
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const setDraft = (key, value) => {
    setDrafts((previous) => ({ ...previous, [key]: value }))
    setNotice('')
    setFormError('')
  }

  const save = async (values) => {
    const keys = Object.keys(values)
    if (keys.length === 0) {
      setFormError('Isi minimal satu nilai baru.')
      return
    }
    if (!password) {
      setFormError('Masukkan password admin untuk menyimpan perubahan.')
      return
    }

    setBusy(true)
    setFormError('')
    setNotice('')
    try {
      const result = await api.adminSaveIntegrations({ password, values })
      setGroups(result.settings?.groups || [])
      setDrafts((previous) => {
        const next = { ...previous }
        for (const key of result.changed || []) delete next[key]
        return next
      })
      setPassword('')
      setNotice(`${result.changed?.length || 0} kunci diperbarui.`)
    } catch (requestError) {
      const errors = requestError.errors || {}
      const first = Object.values(errors)[0]
      setFormError(first?.[0] || requestError.message)
    } finally {
      setBusy(false)
    }
  }

  const saveGroup = (group) => {
    const values = {}
    for (const field of group.fields) {
      if (Object.prototype.hasOwnProperty.call(drafts, field.key) && drafts[field.key] !== '') {
        values[field.key] = drafts[field.key]
      }
    }
    save(values)
  }

  const resetField = (field) => {
    if (!window.confirm(`Kembalikan ${field.label} ke nilai env?`)) return
    save({ [field.key]: null })
  }

  if (loading) return <LoadingState label="Memuat integrasi…" />
  if (error) return <ErrorState message={error} onRetry={load} />

  return (
    <>
      <section className="card admin-card">
        <div className="admin-card__head">
          <div>
            <h2 className="admin-card__title">API key &amp; integrasi</h2>
            <p className="admin-card__sub">
              Nilai disimpan terenkripsi dan langsung dipakai tanpa restart. Setiap
              perubahan dicatat di audit log.
            </p>
          </div>
        </div>

        <label className="field">
          <span className="field__label">Password admin</span>
          <input
            className="input"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            placeholder="Wajib untuk menyimpan"
          />
        </label>
      </section>

      {formError ? (
        <p className="field__error" role="alert">
          {formError}
        </p>
      ) : null}
      {notice ? (
        <p className="admin-note" role="status">
          {notice}
        </p>
      ) : null}

      {groups.map((group) => (
        <section key={group.key} className="card admin-card">
          <div className="admin-card__head">
            <div>
              <h2 className="admin-card__title">{group.label}</h2>
              {group.description ? <p className="admin-card__sub">{group.description}</p> : null}
            </div>
          </div>

          <div className="integration-fields">
            {group.fields.map((field) => (
              <div key={field.key} className="integration-field">
                <div className="integration-field__head">
                  <span className="field__label">{field.label}</span>
                  <span className={`badge badge--xs ${SOURCE_CLASS[field.source] || 'badge--muted'}`}>
                    {SOURCE_LABEL[field.source] || field.source}
                  </span>
                </div>

                {field.type === 'toggle' ? (
                  <label className="admin-toggle">
                    <input
                      type="checkbox"
                      checked={(drafts[field.key] ?? (field.value === 'Aktif' ? '1' : '0')) === '1'}
                      onChange={(event) => setDraft(field.key, event.target.checked ? '1' : '0')}
                    />
                    <span>{field.value === 'Aktif' ? 'Aktif' : 'Nonaktif'}</span>
                  </label>
                ) : field.type === 'select' ? (
                  <select
                    className="input"
                    value={drafts[field.key] ?? field.value ?? ''}
                    onChange={(event) => setDraft(field.key, event.target.value)}
                  >
                    <option value="">— pilih —</option>
                    {Object.entries(field.options || {}).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    className="input"
                    type={field.type === 'secret' ? 'password' : 'text'}
                    autoComplete="off"
                    value={drafts[field.key] ?? ''}
                    placeholder="Kosongkan bila tidak diubah"
                    onChange={(event) => setDraft(field.key, event.target.value)}
                  />
                )}

                {field.type !== 'toggle' && field.value ? (
                  <p className="integration-field__current">
                    Saat ini: <strong>{field.value}</strong>
                  </p>
                ) : null}

                {field.hint ? <p className="integration-hint">{field.hint}</p> : null}

                {field.source === 'dashboard' ? (
                  <button
                    type="button"
                    className="integration-reset"
                    onClick={() => resetField(field)}
                    disabled={busy}
                  >
                    Kembalikan ke nilai env
                  </button>
                ) : null}
              </div>
            ))}
          </div>

          <div className="integration-save">
            <button
              type="button"
              className="btn btn--primary btn--sm"
              onClick={() => saveGroup(group)}
              disabled={busy}
            >
              Simpan {group.label}
            </button>
          </div>
        </section>
      ))}
    </>
  )
}
