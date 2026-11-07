import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, api, get, post, setToken } from '../lib/api'
import { useSession } from '../lib/session'
import { navigate, queryParam } from '../lib/router'
import { t } from '../lib/i18n'
import type { LoginBoard, Profile, PublicSchool, SignedIn } from '../lib/types'
import { Alert, Button, Credit, Field, Input, Loading, PasswordInput } from '../ui/kit'


/** After sign-in: go to the school in the link, or the only school, or let them pick. */
export function goAfterSignIn(p: Profile, slug?: string): void {
  if (slug && p.schools.some(s => s.slug === slug)) return navigate(`/${slug}/home`, true)
  if (p.schools.length === 1) return navigate(`/${p.schools[0].slug}/home`, true)
  if (p.user.isPlatformAdmin && p.schools.length === 0) return navigate('/admin', true)
  navigate('/', true)
}

function LoginForm({ slug, color }: { slug?: string; color?: string }) {
  const { signIn } = useSession()
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await post<SignedIn>('/auth/login', { login, password })
      signIn(res)
      if (slug && !res.schools.some(s => s.slug === slug) && !res.user.isPlatformAdmin) {
        setError('You are not added to this school. Please ask the school office.')
        return
      }
      goAfterSignIn(res, slug)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="stack" style={color ? { ['--brand' as string]: color } : undefined}>
      {error ? <Alert>{error}</Alert> : null}
      <Field label={t('Mobile number or email')}>
        <Input value={login} onChange={e => setLogin(e.target.value)} inputMode="email" autoComplete="username" autoCapitalize="none" required placeholder="98765 43210" />
      </Field>
      <Field label={t('Password')}>
        <PasswordInput value={password} onChange={e => setPassword(e.target.value)} autoComplete="current-password" required />
      </Field>
      <Button type="submit" busy={busy} className="btn-block">{t('Sign in')}</Button>
      <p className="muted small center">{t('Forgot password? Ask your school office for a new link.')}</p>
    </form>
  )
}

export function SchoolLogin({ slug }: { slug: string }) {
  const { profile } = useSession()
  const [school, setSchool] = useState<PublicSchool | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (profile?.schools.some(s => s.slug === slug)) { navigate(`/${slug}/home`, true); return }
    get<PublicSchool>(`/public/schools/${encodeURIComponent(slug)}`).then(setSchool).catch(e => setError(e.message))
  }, [slug, profile])

  if (error) return <AuthFrame><Alert>{error}</Alert></AuthFrame>
  if (!school) return <AuthFrame><Loading /></AuthFrame>
  return (
    <div className="login-page" style={{ ['--brand' as string]: school.color }}>
      <SchoolBoard school={school} />
      <section className="login-side">
        <div className="auth-card">
          <div className="school-badge">
            {school.logoUrl ? <img src={school.logoUrl} alt="" /> : <span className="brand-dot big">{school.name.slice(0, 1)}</span>}
            <h1>{school.name}</h1>
            {school.city ? <p className="muted">{school.city}</p> : null}
          </div>
          <div>
            <h2 className="login-hello">{t('Sign in')} 👋</h2>
            <p className="muted small">{t('Teachers, parents and school office')}</p>
          </div>
          {school.paused ? <Alert kind="info">This school account is paused. Please contact the school office.</Alert> : <LoginForm slug={slug} color={school.color} />}
          {school.admissionsOpen && !school.paused ? (
            <a className="btn btn-soft btn-block" href={`/${slug}/apply`} onClick={e => { e.preventDefault(); navigate(`/${slug}/apply`) }}>📝 {t('New admission? Apply online')}</a>
          ) : null}
        </div>
        <Credit />
      </section>
    </div>
  )
}

const DEFAULT_LINES = ['✅ Daily attendance in one tap', '📚 Homework and notices for parents', '📖 Syllabus and lesson progress', '🎬 Video lessons from Thinkle Tots']

/** The big left panel: the school's own banner, headline and lines, or a friendly default. */
function SchoolBoard({ school }: { school: PublicSchool }) {
  const board: LoginBoard = school.loginBoard ?? {}
  const lines = board.lines?.length ? board.lines : DEFAULT_LINES.map(l => t(l))
  return (
    <section className={`login-board ${board.imageUrl ? 'has-image' : ''}`} style={board.imageUrl ? { backgroundImage: `url("${board.imageUrl.replace(/"/g, '')}")` } : undefined}>
      <div className="board-top">
        {school.phone ? <a href={`tel:${school.phone}`} className="board-chip">📞 {t('School office')}: {school.phone}</a> : <span />}
        {school.admissionsOpen && !school.paused ? <a href={`/${school.slug}/apply`} className="board-chip board-chip-strong" onClick={e => { e.preventDefault(); navigate(`/${school.slug}/apply`) }}>📝 {t('Admissions open')}</a> : null}
      </div>
      <div className="board-body">
        <h2>{board.headline || t('Welcome to {school}', { school: school.name })}</h2>
        <ul>{lines.map((l, i) => <li key={i}>{l}</li>)}</ul>
      </div>
      <span className="board-bubble b1" aria-hidden /><span className="board-bubble b2" aria-hidden /><span className="board-bubble b3" aria-hidden />
    </section>
  )
}

export function Landing() {
  const { profile, loading } = useSession()
  if (loading) return <AuthFrame><Loading /></AuthFrame>
  if (profile && profile.schools.length > 0) {
    return (
      <AuthFrame>
        <h1 className="center">{t('Welcome!')}</h1>
        <p className="muted center">Choose your school</p>
        <div className="stack">
          {profile.schools.map(s => (
            <button key={s.id} className="school-pick" style={{ ['--brand' as string]: s.color }} onClick={() => navigate(`/${s.slug}/home`)}>
              <span className="brand-dot">{s.name.slice(0, 1)}</span>{s.name}
            </button>
          ))}
          {profile.user.isPlatformAdmin ? <Button variant="ghost" onClick={() => navigate('/admin')}>Thinkle Tots admin</Button> : null}
        </div>
      </AuthFrame>
    )
  }
  if (profile?.user.isPlatformAdmin) { navigate('/admin', true); return null }
  return (
    <AuthFrame>
      <div className="hero">
        <img src="/icon.svg" alt="" width={64} height={64} />
        <h1>Thinkle Tots School</h1>
        <p className="muted">Attendance, homework and notices for your whole school, in one simple app. Open the link your school shared, or sign in here.</p>
      </div>
      <LoginForm />
    </AuthFrame>
  )
}

/** One-time link from the school (WhatsApp): set a password and you are in. */
export function Welcome({ slug }: { slug?: string }) {
  const { signIn } = useSession()
  const token = queryParam('token') ?? ''
  const [info, setInfo] = useState<{ name: string; login: string; school: PublicSchool | null } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    get<{ name: string; login: string; school: PublicSchool | null }>('/auth/invite', { token }).then(setInfo).catch(e => setError(e.message))
  }, [token])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await post<SignedIn>('/auth/welcome', { token, password })
      signIn(res)
      goAfterSignIn(res, slug ?? info?.school?.slug)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }

  if (!info) return <AuthFrame>{error ? <Alert>{error}</Alert> : <Loading />}</AuthFrame>
  return (
    <AuthFrame color={info.school?.color}>
      <div className="school-badge">
        <span className="wave" aria-hidden>👋</span>
        <h1>{t('Welcome!')} {info.name}</h1>
        {info.school ? <p className="muted">{info.school.name}</p> : null}
      </div>
      <form onSubmit={submit} className="stack">
        {error ? <Alert>{error}</Alert> : null}
        <p>{t('Set your password')}. You will sign in with <b>{info.login}</b>.</p>
        <Field label={t('Password')} hint={t('At least 8 letters or numbers')}>
          <PasswordInput value={password} onChange={e => setPassword(e.target.value)} minLength={8} autoComplete="new-password" required autoFocus />
        </Field>
        <Button type="submit" busy={busy} className="btn-block">{t('Start')}</Button>
      </form>
    </AuthFrame>
  )
}

/** First-time only: create the Thinkle Tots team account with the deploy key. */
export function FirstSetup() {
  const { signIn } = useSession()
  const [f, setF] = useState({ key: '', name: '', email: '', password: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const { token } = await api<{ token: string }>('POST', '/system/bootstrap', { name: f.name, email: f.email, password: f.password }, undefined, { 'X-Deploy-Key': f.key })
      setToken(token)
      const me = await get<Profile>('/me')
      signIn({ ...me, token })
      navigate('/admin', true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }
  return (
    <AuthFrame>
      <h1>First-time setup</h1>
      <p className="muted">Creates the Thinkle Tots team account. Works only once.</p>
      <form className="stack" onSubmit={submit}>
        {error ? <Alert>{error}</Alert> : null}
        <Field label="Setup key (deploy_key from the server config)"><PasswordInput value={f.key} onChange={e => setF({ ...f, key: e.target.value })} required /></Field>
        <Field label="Your name"><Input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required /></Field>
        <Field label="Email"><Input type="email" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required /></Field>
        <Field label={t('Password')} hint={t('At least 8 letters or numbers')}><PasswordInput value={f.password} onChange={e => setF({ ...f, password: e.target.value })} minLength={8} required /></Field>
        <Button type="submit" busy={busy}>Create account</Button>
      </form>
    </AuthFrame>
  )
}

export function AuthFrame({ children, color }: { children: ReactNode; color?: string }) {
  return (
    <div className="auth" style={color ? { ['--brand' as string]: color } : undefined}>
      <div className="auth-card">{children}</div>
      <Credit />
    </div>
  )
}
