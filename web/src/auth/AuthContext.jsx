import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(undefined)
  const [lastLoginAt, setLastLoginAt] = useState(null)
  const [lastLogin, setLastLogin] = useState(null)

  // Simpan hasil /me (profil + info login terakhir) secara konsisten.
  const applySession = useCallback((data) => {
    setUser(data.user)
    setLastLoginAt(data.lastLoginAt ?? null)
    setLastLogin(data.lastLogin ?? null)
    return data.user
  }, [])

  const refresh = useCallback(async () => {
    try {
      return applySession(await api.me())
    } catch (error) {
      setUser(null)
      return null
    }
  }, [applySession])

  useEffect(() => {
    refresh()
  }, [refresh])

  useEffect(() => {
    const onUnauthorized = () => setUser(null)
    window.addEventListener('sesp:unauthorized', onUnauthorized)
    return () => window.removeEventListener('sesp:unauthorized', onUnauthorized)
  }, [])

  const login = useCallback(
    async (email, password) => {
      await api.login(email, password)
      // Re-read the profile so lastLogin reflects the login that just happened.
      return applySession(await api.me())
    },
    [applySession],
  )

  // Registrasi berhenti di tahap verifikasi OTP; sesi baru dibuat setelah kode benar.
  const register = useCallback(async (payload) => api.register(payload), [])

  const verifyOtp = useCallback(
    async (email, code) => {
      await api.verifyOtp(email, code)
      return applySession(await api.me())
    },
    [applySession],
  )

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
      setLastLogin(null)
    }
  }, [])

  const updateBalance = useCallback((balance) => {
    setUser((current) => (current ? { ...current, balance } : current))
  }, [])

  const value = useMemo(
    () => ({
      user,
      lastLoginAt,
      lastLogin,
      login,
      register,
      verifyOtp,
      resendOtp,
      logout,
      refresh,
      updateBalance,
    }),
    [user, lastLoginAt, lastLogin, login, register, verifyOtp, resendOtp, logout, refresh, updateBalance],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return context
}
