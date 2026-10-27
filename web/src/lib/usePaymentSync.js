import { useEffect, useRef } from 'react'
import { useAuth } from '../auth/AuthContext.jsx'
import { api } from './api.js'

/**
 * Menyelaraskan pesanan Midtrans yang masih pending dengan status resmi
 * Midtrans setiap kali halaman dibuka — termasuk saat pengguna kembali dari
 * halaman pembayaran (`?order_id=...`).
 *
 * Webhook tetap jalur utama. Pemeriksaan ini hanya cadangan supaya saldo dan
 * status transaksi tetap benar ketika notifikasi Midtrans tidak sampai ke
 * server. Server memverifikasi signature Midtrans; browser tidak pernah
 * menentukan status pesanan.
 */
export default function usePaymentSync(onSynced) {
  const { refresh } = useAuth()
  const started = useRef(false)

  useEffect(() => {
    if (started.current) return
    started.current = true

    let active = true
    const orderNumber = new URLSearchParams(window.location.search).get('order_id')

    api
      .syncPayments(orderNumber || undefined)
      .then(async (data) => {
        if (!active || !data?.updated) return
        await refresh()
        if (active) onSynced?.()
      })
      .catch(() => {})

    return () => {
      active = false
    }
    // Cukup sekali per pemuatan halaman.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
}
