import { useState, type FormEvent } from 'react'
import { get, patch, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useSession } from '../lib/session'
import { navigate } from '../lib/router'
import type { School } from '../lib/types'
import { Alert, Button, Card, Field, Input, Loading, Select } from '../ui/kit'
import { ShareLink } from '../ui/ShareLink'

/** Debuggers Edu team only: add schools, give them their first admin, pause or activate. */
export function PlatformPage() {
  const { profile, signOut, refresh } = useSession()
  const { data, error, loading, reload } = useApi(() => get<{ schools: School[] }>('/platform/schools'), [])
  const [adding, setAdding] = useState(false)
  const [adminFor, setAdminFor] = useState<School | null>(null)

  if (!profile?.user.isPlatformAdmin) return <div className="auth"><div className="auth-card"><Alert>Only the Debuggers Edu team can open this page.</Alert></div></div>
  return (
    <div className="shell">
      <header className="topbar">
        <span className="brand"><img src="/icon.svg" alt="" className="brand-logo" /><span className="brand-name">Debuggers Edu · Institutions</span></span>
        <Button variant="ghost" onClick={() => { void signOut().then(() => navigate('/')) }}>Sign out</Button>
      </header>
      <main className="main stack-lg">
        <div className="row"><h1>Schools</h1>{!adding ? <Button onClick={() => setAdding(true)}>➕ New school</Button> : null}</div>
        {adding ? <NewSchool onDone={() => { setAdding(false); reload() }} /> : null}
        {adminFor ? <AddAdmin school={adminFor} onDone={() => { void refresh() }} onClose={() => setAdminFor(null)} /> : null}
        {loading && !data ? <Loading /> : error ? <Alert>{error}</Alert> : (
          <div className="list">
            {data?.schools.map(s => (
              <Card key={s.id} className="stack">
                <div className="row">
                  <div className="row-start">
                    <span className="brand-dot" style={{ background: s.color }}>{s.name.slice(0, 1)}</span>
                    <div><b>{s.name}</b><div className="muted small">/{s.slug} · {s.board} · {s.city ?? ''} {s.state ?? ''}</div></div>
                  </div>
                  <span className={`pill ${s.status === 'active' ? 'pill-ok' : s.status === 'paused' ? 'pill-warn' : ''}`}>{s.status}</span>
                </div>
                <div className="muted small">{s.students ?? 0} students · {s.staff ?? 0} staff</div>
                <div className="row-start wrap">
                  <Button variant="soft" onClick={() => navigate(`/${s.slug}/home`)}>Open</Button>
                  <Button variant="soft" onClick={() => setAdminFor(s)}>Add school admin</Button>
                  <Select value={s.status} onChange={e => { void patch(`/platform/schools/${s.id}`, { status: e.target.value }).then(reload) }} aria-label="Status" className="narrow">
                    <option value="trial">Trial</option><option value="active">Active</option><option value="paused">Paused</option>
                  </Select>
                </div>
              </Card>
            ))}
            {data?.schools.length === 0 ? <p className="muted">No schools yet. Add the first one.</p> : null}
          </div>
        )}
      </main>
    </div>
  )
}

function NewSchool({ onDone }: { onDone: () => void }) {
  const [f, setF] = useState({ name: '', slug: '', board: 'CGBSE', state: 'Chhattisgarh', city: '', phone: '', template: 'k10', color: '#6C4CF1' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await post('/platform/schools', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')))
      onDone()
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <h2>New school</h2>
        <div className="grid2">
          <Field label="School name"><Input value={f.name} onChange={e => setF({ ...f, name: e.target.value, slug: f.slug || '' })} required /></Field>
          <Field label="Link name" hint={`edu.debuggersspace.com/${f.slug || 'bharatmata-raipur'}`}>
            <Input value={f.slug} onChange={e => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-') })} required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" />
          </Field>
          <Field label="Board">
            <Select value={f.board} onChange={set('board')}>{['CGBSE', 'MPBSE', 'CBSE', 'ICSE', 'STATE', 'OTHER'].map(b => <option key={b}>{b}</option>)}</Select>
          </Field>
          <Field label="State">
            <Select value={f.state} onChange={set('state')}>{['Chhattisgarh', 'Madhya Pradesh', 'Maharashtra', 'Other'].map(b => <option key={b}>{b}</option>)}</Select>
          </Field>
          <Field label="City"><Input value={f.city} onChange={set('city')} /></Field>
          <Field label="Office phone"><Input type="tel" value={f.phone} onChange={set('phone')} /></Field>
          <Field label="Classes to start with">
            <Select value={f.template} onChange={set('template')}>
              <option value="preschool">Pre-school (Playgroup to Sr KG)</option>
              <option value="primary">Up to Class 5</option>
              <option value="k10">Up to Class 10</option>
              <option value="k12">Up to Class 12</option>
              <option value="empty">None, add by hand</option>
            </Select>
          </Field>
          <Field label="School colour"><Input type="color" value={f.color} onChange={set('color')} className="color" /></Field>
        </div>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end"><Button variant="ghost" type="button" onClick={onDone}>Cancel</Button><Button type="submit" busy={busy}>Create school</Button></div>
      </form>
    </Card>
  )
}

function AddAdmin({ school, onDone, onClose }: { school: School; onDone: () => void; onClose: () => void }) {
  const [f, setF] = useState({ name: '', phone: '', role: 'principal' })
  const [link, setLink] = useState<string | null | undefined>(undefined)
  const [error, setError] = useState<string | null>(null)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const res = await post<{ inviteLink: string | null }>(`/platform/schools/${school.id}/admins`, f)
      setLink(res.inviteLink)
      onDone()
    } catch (err) {
      setError((err as Error).message)
    }
  }
  return (
    <Card className="stack">
      <div className="row"><h2>Admin for {school.name}</h2><button className="btn btn-ghost" onClick={onClose}>✕</button></div>
      {link !== undefined ? (link ? <ShareLink link={link} name={f.name} phone={f.phone} schoolName={school.name} /> : <p>Added. They already have an account and can sign in now.</p>) : (
        <form className="stack" onSubmit={submit}>
          <div className="grid2">
            <Field label="Name"><Input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required /></Field>
            <Field label="Mobile"><Input type="tel" value={f.phone} onChange={e => setF({ ...f, phone: e.target.value })} required /></Field>
            <Field label="Role"><Select value={f.role} onChange={e => setF({ ...f, role: e.target.value })}><option value="principal">Principal</option><option value="admin">School admin</option></Select></Field>
          </div>
          {error ? <Alert>{error}</Alert> : null}
          <Button type="submit">Add and get link</Button>
        </form>
      )}
    </Card>
  )
}
