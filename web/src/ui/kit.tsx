import { useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'

export function Button({ variant = 'primary', busy, children, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' | 'soft' | 'danger'; busy?: boolean }) {
  return (
    <button {...rest} className={`btn btn-${variant} ${rest.className ?? ''}`} disabled={busy || rest.disabled}>
      {busy ? <span className="spinner" aria-hidden /> : null}
      <span>{children}</span>
    </button>
  )
}

export function LinkButton({ to, children, variant = 'soft' }: { to: string; children: ReactNode; variant?: 'primary' | 'ghost' | 'soft' }) {
  return <a href={to} className={`btn btn-${variant}`} onClick={e => { e.preventDefault(); navigate(to) }}>{children}</a>
}

export function A({ to, children, className }: { to: string; children: ReactNode; className?: string }) {
  return <a href={to} className={className} onClick={e => { e.preventDefault(); navigate(to) }}>{children}</a>
}

export function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string | null; children: ReactNode }) {
  return (
    <label className={`field ${error ? 'field-error' : ''}`}>
      <span className="field-label">{label}</span>
      {children}
      {error ? <span className="field-msg">{error}</span> : hint ? <span className="field-hint">{hint}</span> : null}
    </label>
  )
}

export const Input = (p: InputHTMLAttributes<HTMLInputElement>) => <input {...p} className={`input ${p.className ?? ''}`} />
export const Textarea = (p: TextareaHTMLAttributes<HTMLTextAreaElement>) => <textarea rows={3} {...p} className={`input ${p.className ?? ''}`} />
export const Select = (p: SelectHTMLAttributes<HTMLSelectElement>) => <select {...p} className={`input ${p.className ?? ''}`} />

export function PasswordInput(p: InputHTMLAttributes<HTMLInputElement>) {
  const [show, setShow] = useState(false)
  return (
    <div className="pw">
      <Input {...p} type={show ? 'text' : 'password'} />
      <button type="button" className="pw-toggle" onClick={() => setShow(s => !s)}>{show ? t('Hide') : t('Show')}</button>
    </div>
  )
}

export function Card({ children, className, onClick }: { children: ReactNode; className?: string; onClick?: () => void }) {
  return <div className={`card ${onClick ? 'card-tap' : ''} ${className ?? ''}`} onClick={onClick} role={onClick ? 'button' : undefined} tabIndex={onClick ? 0 : undefined}
    onKeyDown={onClick ? e => { if (e.key === 'Enter') onClick() } : undefined}>{children}</div>
}

export function Alert({ kind = 'error', children }: { kind?: 'error' | 'ok' | 'info'; children: ReactNode }) {
  return <div className={`alert alert-${kind}`} role={kind === 'error' ? 'alert' : 'status'}>{children}</div>
}

export function Loading() {
  return <div className="loading"><span className="spinner" /> {t('Loading…')}</div>
}

export function Empty({ icon = '🌱', children }: { icon?: string; children?: ReactNode }) {
  return <div className="empty"><div className="empty-icon">{icon}</div><div>{children ?? t('Nothing here yet.')}</div></div>
}

export function Chip({ active, children, onClick }: { active?: boolean; children: ReactNode; onClick?: () => void }) {
  return <button type="button" className={`chip ${active ? 'chip-on' : ''}`} onClick={onClick}>{children}</button>
}

export function PageTitle({ title, sub, back }: { title: string; sub?: string; back?: string }) {
  return (
    <div className="page-title">
      {back ? <A to={back} className="back">← {t('Back')}</A> : null}
      <h1>{title}</h1>
      {sub ? <p className="muted">{sub}</p> : null}
    </div>
  )
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso.length === 10 ? iso + 'T00:00:00' : iso.replace(' ', 'T'))
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
}

export function todayIso(): string {
  // India time, whatever the phone's clock zone is
  return new Date(Date.now() + 330 * 60000).toISOString().slice(0, 10)
}
