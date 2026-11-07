import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { get, getToken, setToken, post } from './api'
import { setLang } from './i18n'
import type { Profile, SchoolRef } from './types'

interface SessionValue {
  profile: Profile | null
  loading: boolean
  signIn: (data: Profile & { token: string }) => void
  signOut: () => Promise<void>
  refresh: () => Promise<void>
  schoolBySlug: (slug: string) => SchoolRef | undefined
}

const Ctx = createContext<SessionValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<Profile | null>(null)
  const [loading, setLoading] = useState(!!getToken())

  const refresh = useCallback(async () => {
    if (!getToken()) { setProfile(null); setLoading(false); return }
    try {
      const p = await get<Profile>('/me')
      setProfile(p)
      setLang(p.user.language)
    } catch {
      setProfile(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
    const out = () => setProfile(null)
    window.addEventListener('tts:signed-out', out)
    return () => window.removeEventListener('tts:signed-out', out)
  }, [refresh])

  const value: SessionValue = {
    profile,
    loading,
    signIn: data => {
      setToken(data.token)
      setProfile({ user: data.user, schools: data.schools })
      setLang(data.user.language)
    },
    signOut: async () => {
      try { await post('/auth/logout') } catch { /* already signed out */ }
      setToken(null)
      setProfile(null)
    },
    refresh,
    schoolBySlug: slug => profile?.schools.find(s => s.slug === slug),
  }
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useSession(): SessionValue {
  const v = useContext(Ctx)
  if (!v) throw new Error('useSession outside SessionProvider')
  return v
}
