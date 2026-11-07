import { useEffect, useState } from 'react'

// A tiny path router: the app has a handful of screens, so no router library is needed.

export function navigate(to: string, replace = false): void {
  if (to === location.pathname + location.search) return
  if (replace) history.replaceState(null, '', to)
  else history.pushState(null, '', to)
  window.dispatchEvent(new Event('tts:navigate'))
  window.scrollTo(0, 0)
}

export function usePath(): string {
  const [path, setPath] = useState(location.pathname)
  useEffect(() => {
    const update = () => setPath(location.pathname)
    window.addEventListener('popstate', update)
    window.addEventListener('tts:navigate', update)
    return () => {
      window.removeEventListener('popstate', update)
      window.removeEventListener('tts:navigate', update)
    }
  }, [])
  return path
}

/** match('/:slug/students/:id', '/dps/students/4') -> { slug: 'dps', id: '4' } */
export function match(pattern: string, path: string): Record<string, string> | null {
  const p = pattern.split('/').filter(Boolean)
  const s = path.split('/').filter(Boolean)
  if (p.length !== s.length) return null
  const params: Record<string, string> = {}
  for (let i = 0; i < p.length; i++) {
    if (p[i].startsWith(':')) params[p[i].slice(1)] = decodeURIComponent(s[i])
    else if (p[i] !== s[i]) return null
  }
  return params
}

export function queryParam(name: string): string | null {
  return new URLSearchParams(location.search).get(name)
}
