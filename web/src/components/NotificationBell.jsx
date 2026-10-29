import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { api } from '../lib/api.js'
import { dateTime } from '../lib/format.js'
import { BellIcon, CloseIcon } from './Icons.jsx'

/**
 * Lonceng pemberitahuan akun di header.
 *
 * Titik merah muncul selama ada pemberitahuan yang lebih baru dari penanda baca
 * pengguna. Isinya datang dari server: transaksi gagal beserta alasan supplier
 * dan status pengembalian dana, serta status penarikan saldo.
 */
export default function NotificationBell() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState([])
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    try {
      const data = await api.notifications()
      setItems(data.items ?? [])
      setUnread(data.unreadCount ?? 0)
    } catch (error) {
      // Pemberitahuan bersifat pelengkap: gagal memuat tidak mengganggu halaman.
    }
  }, [])

  useEffect(() => {
    load()
    const timer = window.setInterval(load, 60_000)
    return () => window.clearInterval(timer)
  }, [load])

  const markRead = useCallback(async () => {
    if (unread === 0) return
    setUnread(0)
    setItems((current) => current.map((item) => ({ ...item, unread: false })))
    try {
      await api.markNotificationsRead()
    } catch (error) {
      // Biarkan; titik merah kembali saat pemuatan berikutnya bila gagal.
    }
  }, [unread])

  const close = useCallback(() => {
    setOpen(false)
    markRead()
  }, [markRead])

  useEffect(() => {
    if (!open) return undefined

    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const onKey = (event) => {
      if (event.key === 'Escape') close()
    }
    window.addEventListener('keydown', onKey)

    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [open, close])

  function openPanel() {
    setOpen(true)
    setLoading(true)
    load().finally(() => setLoading(false))
  }

  function visit(item) {
    setOpen(false)
    markRead()
    if (item.href) navigate(item.href)
  }

  return (
    <>
      <button
        type="button"
        className="icon-btn icon-btn--notif"
        onClick={openPanel}
        aria-label={unread > 0 ? `Notifikasi, ${unread} baru` : 'Notifikasi'}
      >
        <BellIcon />
        {unread > 0 ? <span className="notif-dot" aria-hidden="true" /> : null}
      </button>

      {/* Header memakai backdrop-filter sehingga position: fixed di dalamnya
          terkurung di header; panel dirender ke body agar jadi bottom sheet. */}
      {open
        ? createPortal(
            <div className="overlay" onClick={close}>
              <div
                className="sheet sheet--notif"
                role="dialog"
                aria-label="Notifikasi"
                onClick={(event) => event.stopPropagation()}
              >
                <div className="sheet__grip" />
                <div className="sheet__head">
                  <h2 className="sheet__title">Notifikasi</h2>
                  <button type="button" className="icon-btn" onClick={close} aria-label="Tutup">
                    <CloseIcon />
                  </button>
                </div>

                <div className="notif-head">
                  <span className="section-hint">
                    {unread > 0
                      ? `${unread} pemberitahuan baru`
                      : 'Semua pemberitahuan sudah dibaca'}
                  </span>
                  {unread > 0 ? (
                    <button type="button" className="link-btn" onClick={markRead}>
                      Tandai dibaca
                    </button>
                  ) : null}
                </div>

                {loading && items.length === 0 ? (
                  <p className="notif-empty">Memuat pemberitahuan…</p>
                ) : items.length === 0 ? (
                  <p className="notif-empty">
                    Belum ada pemberitahuan. Kabar transaksi gagal, pengembalian dana, dan
                    penarikan saldo akan muncul di sini.
                  </p>
                ) : (
                  <ul className="notif-list">
                    {items.map((item) => (
                      <li key={item.id}>
                        <button
                          type="button"
                          className={`notif-item notif-item--${item.tone}${item.unread ? ' is-unread' : ''}`}
                          onClick={() => visit(item)}
                        >
                          <span className="notif-item__mark" aria-hidden="true" />
                          <span className="notif-item__body">
                            <span className="notif-item__title">{item.title}</span>
                            <span className="notif-item__text">{item.body}</span>
                            <span className="notif-item__meta">
                              <span>{item.reference}</span>
                              <span>{dateTime(item.at)}</span>
                            </span>
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>,
            document.body,
          )
        : null}
    </>
  )
}
