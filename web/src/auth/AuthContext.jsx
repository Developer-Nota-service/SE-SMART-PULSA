import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(undefined)
  const [lastLoginAt, setLastLoginAt] = useState(null)

  const refresh = useCallback(async () => {
    try {
      const data = await api.me()
      setUser(data.user)
      setLastLoginAt(data.lastLoginAt ?? null)
      return data.user
    } catch (error) {
      setUser(null)
      return null
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  useEffect(() => {
    const onUnauthorized = () => setUser(null)
    window.addEventListener('sesp:unauthorized', onUnauthorized)
    return () => window.removeEventListener('sesp:unauthorized', onUnauthorized)
  }, [])

  const login = useCallback(async (email, password) => {
    await api.login(email, password)
    // Re-read the profile so lastLoginAt reflects the login that just happened.
    const data = await api.me()
    setUser(data.user)
    setLastLoginAt(data.lastLoginAt ?? null)
    return data.user
  }, [])

  // Registrasi berhenti di tahap verifikasi OTP; sesi baru dibuat setelah kode benar.
  const register = useCallback(async (payload) => api.register(payload), [])

  const verifyOtp = useCallback(async (email, code) => {
    await api.verifyOtp(email, code)
    const data = await api.me()
    setUser(data.user)
    setLastLoginAt(data.lastLoginAt ?? null)
    return data.user
  }, [])

  const resendOtp = useCallback(async (email) => {
    const data = await api.resendOtp(email)
    return data.verification
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } finally {
      setUser(null)
      setLastLoginAt(null)
    }
  }, [])

  const updateBalance = useCallback((balance) => {
    setUser((current) => (current ? { ...current, balance } : current))
  }, [])

  const value = useMemo(
    () => ({
      user,
      lastLoginAt,
      login,
      register,
      verifyOtp,
      resendOtp,
      logout,
      refresh,
      updateBalance,
    }),
    [user, lastLoginAt, login, register, verifyOtp, resendOtp, logout, refresh, updateBalance],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return context
}
