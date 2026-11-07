import { useState, type FormEvent } from 'react'
import { del, get, patch, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { t } from '../lib/i18n'
import { useSession } from '../lib/session'
import { STAGE_LABEL, type Division, type SchoolRef, type Setup, type Stage, type Staff } from '../lib/types'
import { Alert, Button, Card, Field, Input, Loading, PageTitle, Select, Textarea } from '../ui/kit'

export function SetupPage({ school }: { school: SchoolRef }) {
  const { refresh } = useSession()
  const { data, error, loading, reload } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const { data: staff } = useApi(() => get<{ staff: Staff[] }>(`/schools/${school.id}/staff`), [school.id])
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [newGrade, setNewGrade] = useState('')
  const [newSubject, setNewSubject] = useState('')
  const [section, setSection] = useState<{ gradeId: string; name: string }>({ gradeId: '', name: '' })

  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null

  const run = async (fn: () => Promise<unknown>, ok?: string) => {
    setMsg(null)
    try { await fn(); if (ok) setMsg({ kind: 'ok', text: ok }); reload() } catch (e) { setMsg({ kind: 'error', text: (e as Error).message }) }
  }
  const teachers = (staff?.staff ?? []).filter(s => s.status === 'active')
  const year = data.years.find(y => y.isCurrent)

  return (
    <div className="stack-lg">
      <PageTitle title={t('School setup')} sub={`${data.school.board}${year ? ` · Year ${year.name}` : ''}`} />
      {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}

      <Card className="stack">
        <h2>Classes and sections</h2>
        <p className="muted small">Pick each section’s class teacher. They mark its attendance.</p>
        {groupByStage(data.divisions).map(([stage, divisions]) => (
        <div key={stage} className="list">
          <h3 className="group-title">{stage === 'other' ? 'Other classes' : STAGE_LABEL[stage as Stage]}</h3>
          {divisions.map(d => (
            <div key={d.id} className="row setup-row">
              <div><b>{d.label}</b><div className="muted small">{d.students} {t('Students').toLowerCase()}</div></div>
              <div className="row-end">
                <Select value={d.classTeacher?.id ?? ''} aria-label={`Class teacher for ${d.label}`}
                  onChange={e => { void run(() => patch(`/schools/${school.id}/divisions/${d.id}`, { classTeacherId: e.target.value ? Number(e.target.value) : null }), t('Saved')) }}>
                  <option value="">Class teacher…</option>
                  {teachers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                </Select>
                {d.students === 0
                  ? <button className="link-danger small" aria-label={`Remove ${d.label}`} onClick={() => { if (window.confirm(`Remove ${d.label}?`)) void run(() => del(`/schools/${school.id}/divisions/${d.id}`)) }}>✕</button>
                  : <span className="x-spacer" aria-hidden />}
              </div>
            </div>
          ))}
        </div>
        ))}
        <form className="row-start wrap" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(() => post(`/schools/${school.id}/divisions`, { gradeId: Number(section.gradeId), name: section.name }), 'Section added').then(() => setSection({ gradeId: '', name: '' })) }}>
          <Select value={section.gradeId} onChange={e => setSection({ ...section, gradeId: e.target.value })} required aria-label="Class">
            <option value="">Class…</option>{data.grades.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select>
          <Input value={section.name} onChange={e => setSection({ ...section, name: e.target.value })} placeholder="Section, e.g. B" required maxLength={20} className="narrow" />
          <Button type="submit" variant="soft">Add section</Button>
        </form>
        <form className="row-start wrap" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(() => post(`/schools/${school.id}/grades`, { name: newGrade }), 'Class added').then(() => setNewGrade('')) }}>
          <Input value={newGrade} onChange={e => setNewGrade(e.target.value)} placeholder="New class, e.g. Class 6" required maxLength={40} className="narrow" />
          <Button type="submit" variant="soft">Add class</Button>
        </form>
      </Card>

      <Card className="stack">
        <h2>Subjects</h2>
        <div className="chips">
          {data.subjects.map(s => (
            <span key={s.id} className="chip">{s.name} <button className="chip-x" aria-label={`Remove ${s.name}`} onClick={() => { void run(() => del(`/schools/${school.id}/subjects/${s.id}`)) }}>✕</button></span>
          ))}
        </div>
        <form className="row-start wrap" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(() => post(`/schools/${school.id}/subjects`, { name: newSubject })).then(() => setNewSubject('')) }}>
          <Input value={newSubject} onChange={e => setNewSubject(e.target.value)} placeholder="New subject" required maxLength={60} className="narrow" />
          <Button type="submit" variant="soft">{t('Add')}</Button>
        </form>
      </Card>

      <SchoolLook school={school} setup={data} onSaved={() => { void refresh(); reload() }} />
      <LoginLook school={school} setup={data} onSaved={reload} />
    </div>
  )
}

function SchoolLook({ school, setup, onSaved }: { school: SchoolRef; setup: Setup; onSaved: () => void }) {
  const s = setup.school
  const [f, setF] = useState({ name: s.name, phone: s.phone ?? '', address: s.address ?? '', color: s.color, logoUrl: s.logoUrl ?? '' })
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    try {
      await patch(`/schools/${school.id}/settings`, Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')))
      setMsg({ kind: 'ok', text: t('Saved') })
      onSaved()
    } catch (err) {
      setMsg({ kind: 'error', text: (err as Error).message })
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <h2>School details and look</h2>
        <p className="muted small">Your school link: <b>{location.origin}/{school.slug}</b></p>
        <div className="grid2">
          <Field label="School name"><Input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required /></Field>
          <Field label="Office phone"><Input type="tel" value={f.phone} onChange={e => setF({ ...f, phone: e.target.value })} /></Field>
          <Field label="Address"><Input value={f.address} onChange={e => setF({ ...f, address: e.target.value })} /></Field>
          <Field label="Logo link" hint="https:// link to a square logo image"><Input type="url" value={f.logoUrl} onChange={e => setF({ ...f, logoUrl: e.target.value })} /></Field>
          <Field label="School colour"><Input type="color" value={f.color} onChange={e => setF({ ...f, color: e.target.value })} className="color" /></Field>
        </div>
        {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}
        <Button type="submit" busy={busy}>{t('Save')}</Button>
      </form>
    </Card>
  )
}

/** The big panel on the school's sign-in page, and whether parents can apply online. */
function LoginLook({ school, setup, onSaved }: { school: SchoolRef; setup: Setup; onSaved: () => void }) {
  const b = setup.school.loginBoard ?? {}
  const [f, setF] = useState({ headline: b.headline ?? '', imageUrl: b.imageUrl ?? '', lines: (b.lines ?? []).join('\n'), admissionsOpen: setup.school.admissionsOpen })
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setMsg(null)
    try {
      const lines = f.lines.split('\n').map(l => l.trim()).filter(Boolean)
      const board = f.headline || f.imageUrl || lines.length ? { headline: f.headline || undefined, imageUrl: f.imageUrl || undefined, lines } : null
      await patch(`/schools/${school.id}/settings`, { loginBoard: board, admissionsOpen: f.admissionsOpen })
      setMsg({ kind: 'ok', text: t('Saved') })
      onSaved()
    } catch (err) {
      setMsg({ kind: 'error', text: (err as Error).message })
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <h2>{t('Sign-in page')}</h2>
        <p className="muted small">{t('Parents and teachers see this next to the sign-in box. Use it for admissions, events or your school photo.')}{' '}
          <a href={`/${school.slug}`} target="_blank" rel="noopener noreferrer">{t('Preview')} ↗</a></p>
        <div className="grid2">
          <Field label={t('Headline')}><Input value={f.headline} onChange={e => setF({ ...f, headline: e.target.value })} maxLength={100} placeholder={t('e.g. Admissions open for 2027-28')} /></Field>
          <Field label={t('Banner picture link')} hint={t('https:// link to a wide photo (optional)')}><Input type="url" value={f.imageUrl} onChange={e => setF({ ...f, imageUrl: e.target.value })} /></Field>
        </div>
        <Field label={t('Short lines (up to 4, one per line)')}><Textarea rows={4} value={f.lines} onChange={e => setF({ ...f, lines: e.target.value })} placeholder={'Smart classes from Nursery\nSchool bus in all areas'} /></Field>
        <label className="check"><input type="checkbox" checked={f.admissionsOpen} onChange={e => setF({ ...f, admissionsOpen: e.target.checked })} /> {t('Admissions open: parents can apply online')}</label>
        {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}
        <Button type="submit" busy={busy}>{t('Save')}</Button>
      </form>
    </Card>
  )
}

const STAGE_ORDER: (Stage | 'other')[] = ['pre_primary', 'primary', 'middle', 'secondary', 'higher_secondary', 'other']

export function groupByStage(divisions: Division[]): [Stage | 'other', Division[]][] {
  const groups = new Map<Stage | 'other', Division[]>()
  for (const d of divisions) {
    const key = d.stage ?? 'other'
    groups.set(key, [...(groups.get(key) ?? []), d])
  }
  return STAGE_ORDER.filter(s => groups.has(s)).map(s => [s, groups.get(s)!])
}
