import { useState, type FormEvent } from 'react'
import { get, patch, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { t } from '../lib/i18n'
import type { Role, SchoolRef, Staff } from '../lib/types'
import { Alert, Button, Card, Empty, Field, Input, Loading, PageTitle, Select } from '../ui/kit'
import { ShareLink } from '../ui/ShareLink'

export const ROLE_LABEL: Record<Role, string> = {
  admin: 'School admin', principal: 'Principal', academic_head: 'Academic head', teacher: 'Teacher',
  accounts: 'Accounts', staff: 'Other staff', parent: 'Parent',
}

export function StaffPage({ school }: { school: SchoolRef }) {
  const canManage = school.roles.some(r => r === 'admin' || r === 'principal')
  const { data, error, loading, reload } = useApi(() => get<{ staff: Staff[] }>(`/schools/${school.id}/staff`), [school.id])
  const [adding, setAdding] = useState(false)
  const [link, setLink] = useState<{ link: string; name: string; phone: string | null } | null>(null)

  const sendLink = async (s: Staff) => {
    const res = await post<{ inviteLink: string | null }>(`/schools/${school.id}/staff/${s.id}/link`)
    if (res.inviteLink) setLink({ link: res.inviteLink, name: s.name, phone: s.phone })
  }
  const markLeft = async (s: Staff) => {
    if (!window.confirm(`${s.name} has left the school? They will lose access straight away.`)) return
    await patch(`/schools/${school.id}/staff/${s.id}`, { status: 'left' })
    reload()
  }

  return (
    <div className="stack">
      <PageTitle title={t('Staff')} sub="Teachers and other employees" />
      {canManage && !adding ? <Button onClick={() => setAdding(true)}>➕ Add staff</Button> : null}
      {adding ? <AddStaff school={school} onDone={(l) => { setAdding(false); if (l) setLink(l); reload() }} /> : null}
      {link ? <ShareLink link={link.link} name={link.name} phone={link.phone} schoolName={school.name} /> : null}
      {loading && !data ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.staff.length ? <Empty icon="🧑‍🏫">No staff yet.</Empty> : (
        <div className="list">
          {data.staff.map(s => (
            <Card key={s.id} className="row">
              <div className="row-start">
                <div className="avatar small">{s.name.slice(0, 1)}</div>
                <div>
                  <b>{s.name}</b>
                  <div className="muted small">{s.designation || ROLE_LABEL[s.role]}{s.phone ? ` · ${s.phone}` : ''}</div>
                </div>
              </div>
              <div className="row-end">
                {!s.canSignIn ? <span className="pill">No app access</span> : s.joined ? <span className="pill pill-ok">Joined</span>
                  : canManage ? <Button variant="soft" onClick={() => { void sendLink(s) }}>Send link</Button> : <span className="pill pill-warn">Not joined</span>}
                {canManage ? <button className="link-danger small" onClick={() => { void markLeft(s) }}>Left</button> : null}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function AddStaff({ school, onDone }: { school: SchoolRef; onDone: (link: { link: string; name: string; phone: string | null } | null) => void }) {
  const [f, setF] = useState({ name: '', phone: '', role: 'teacher', designation: '', employeeCode: '', joinedOn: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
      const res = await post<{ inviteLink: string | null }>(`/schools/${school.id}/staff`, body)
      onDone(res.inviteLink ? { link: res.inviteLink, name: f.name, phone: f.phone } : null)
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <div className="grid2">
          <Field label="Name"><Input value={f.name} onChange={set('name')} required /></Field>
          <Field label="Mobile" hint="Needed to use the app. Leave empty for staff who don’t."><Input type="tel" value={f.phone} onChange={set('phone')} placeholder="98765 43210" /></Field>
          <Field label="Role">
            <Select value={f.role} onChange={set('role')}>
              {(['teacher', 'academic_head', 'principal', 'admin', 'accounts', 'staff'] as Role[]).map(r => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
            </Select>
          </Field>
          <Field label="Designation"><Input value={f.designation} onChange={set('designation')} placeholder="PRT Maths, Driver, Ayah…" /></Field>
          <Field label="Employee code"><Input value={f.employeeCode} onChange={set('employeeCode')} /></Field>
          <Field label="Joined on"><Input type="date" value={f.joinedOn} onChange={set('joinedOn')} /></Field>
        </div>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end"><Button variant="ghost" type="button" onClick={() => onDone(null)}>{t('Cancel')}</Button><Button type="submit" busy={busy}>{t('Save')}</Button></div>
      </form>
    </Card>
  )
}
