// Talks to the PHP API at /api. The sign-in token is kept on this device only.

const TOKEN_KEY = 'tts.token'

export class ApiError extends Error {
  constructor(public status: number, message: string, public field?: string) {
    super(message)
  }
}

export function getToken(): string | null {
  try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
}

export function setToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch { /* private mode: stays signed in for this tab only */ }
}

type Query = Record<string, string | number | undefined | null>

export async function api<T = Record<string, unknown>>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown,
  query?: Query,
  extraHeaders?: Record<string, string>,
): Promise<T> {
  const qs = query
    ? '?' + Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join('&')
    : ''
  const headers: Record<string, string> = { Accept: 'application/json', ...extraHeaders }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  let res: Response
  try {
    res = await fetch(`/api${path}${qs === '?' ? '' : qs}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
  } catch {
    throw new ApiError(0, 'No internet connection. Please check and try again.')
  }
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login') {
      setToken(null)
      window.dispatchEvent(new Event('tts:signed-out'))
    }
    throw new ApiError(res.status, data.error || 'Something went wrong. Please try again.', data.field)
  }
  return data as T
}

export const get = <T,>(path: string, query?: Query) => api<T>('GET', path, undefined, query)
export const post = <T,>(path: string, body: unknown = {}) => api<T>('POST', path, body)
export const put = <T,>(path: string, body: unknown) => api<T>('PUT', path, body)
export const patch = <T,>(path: string, body: unknown) => api<T>('PATCH', path, body)
export const del = <T,>(path: string) => api<T>('DELETE', path)
