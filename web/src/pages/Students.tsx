import { useMemo, useState, type FormEvent } from 'react'
import { get, patch, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import { TEMPLATE_CSV, parseCsv, rowsToStudents } from '../lib/csv'
import type { Guardian, SchoolRef, Setup, Student } from '../lib/types'
import { A, Alert, Button, Card, Chip, Empty, Field, Input, LinkButton, Loading, PageTitle, Select, formatDate } from '../ui/kit'
import { ShareLink } from '../ui/ShareLink'

export function StudentsPage({ school }: { school: SchoolRef }) {
  const canManage = school.roles.some(r => r === 'admin' || r === 'principal')
  const [divisionId, setDivisionId] = useState<number | null>(null)
  const [q, setQ] = useState('')
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const { data, error, loading } = useApi(() => get<{ students: Student[] }>(`/schools/${school.id}/students`, { divisionId, q: q.length >= 2 ? q : undefined }), [school.id, divisionId, q.length >= 2 ? q : ''])

  return (
    <div className="stack">
      <PageTitle title={t('Students')} sub={data ? `${data.students.length} ${t('Students').toLowerCase()}` : undefined} />
      {canManage ? (
        <div className="row-start">
          <LinkButton to={`/${school.slug}/students/new`} variant="primary">➕ {t('Add a student')}</LinkButton>
          <LinkButton to={`/${school.slug}/students/import`}>📥 Import from Excel</LinkButton>
        </div>
      ) : null}
      <Input type="search" value={q} onChange={e => setQ(e.target.value)} placeholder={t('Search by name or admission no.')} />
      <div className="chips scroll-x">
        <Chip active={divisionId === null} onClick={() => setDivisionId(null)}>All</Chip>
        {setup?.divisions.filter(d => d.students > 0).map(d => <Chip key={d.id} active={divisionId === d.id} onClick={() => setDivisionId(d.id)}>{d.label}</Chip>)}
      </div>
      {loading && !data ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.students.length ? <Empty icon="🎒">No students found.</Empty> : (
        <div className="list">
          {data.students.map(s => (
            <Card key={s.id} className="row" onClick={() => navigate(`/${school.slug}/students/${s.id}`)}>
              <div className="row-start">
                <div className="avatar small">{s.firstName.slice(0, 1)}</div>
                <div><b>{s.name}</b><div className="muted small">{s.division ?? 'No class'}{s.rollNo ? ` · Roll ${s.rollNo}` : ''} · {s.admissionNo}</div></div>
              </div>
              <span aria-hidden>›</span>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

export function NewStudent({ school }: { school: SchoolRef }) {
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const empty = { admissionNo: '', firstName: '', lastName: '', gender: '', dob: '', divisionId: '', rollNo: '', parentName: '', parentPhone: '', relation: 'mother' }
  const [f, setF] = useState(empty)
  const [error, setError] = useState<{ text: string; field?: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<{ id: number; name: string; link: string | null; phone: string } | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
      const res = await post<{ id: number; inviteLink: string | null }>(`/schools/${school.id}/students`, body)
      setDone({ id: res.id, name: f.parentName, link: res.inviteLink, phone: f.parentPhone })
    } catch (err) {
      const e2 = err as Error & { field?: string }
      setError({ text: e2.message, field: e2.field })
    } finally {
      setBusy(false)
    }
  }
  const set = (k: keyof typeof empty) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const err = (k: string) => (error?.field === k ? error.text : null)

  if (done) {
    return (
      <div className="stack">
        <PageTitle title="Student added 🎉" back={`/${school.slug}/students`} />
        {done.link ? <ShareLink link={done.link} name={done.name} phone={done.phone} schoolName={school.name} /> : <p>The parent already has an account and will see this child when they sign in.</p>}
        <div className="row-start">
          <Button onClick={() => { setF({ ...empty, divisionId: f.divisionId, relation: f.relation }); setDone(null) }}>➕ Add another</Button>
          <LinkButton to={`/${school.slug}/students/${done.id}`}>Open profile</LinkButton>
        </div>
      </div>
    )
  }

  return (
    <form className="stack" onSubmit={submit}>
      <PageTitle title={t('Add a student')} back={`/${school.slug}/students`} />
      <Card className="stack">
        <h3>Child</h3>
        <div className="grid2">
          <Field label="First name" error={err('firstName')}><Input value={f.firstName} onChange={set('firstName')} required /></Field>
          <Field label="Last name"><Input value={f.lastName} onChange={set('lastName')} /></Field>
          <Field label="Admission no." error={err('admissionNo')}><Input value={f.admissionNo} onChange={set('admissionNo')} required /></Field>
          <Field label="Gender">
            <Select value={f.gender} onChange={set('gender')}><option value="">—</option><option value="girl">Girl</option><option value="boy">Boy</option><option value="other">Other</option></Select>
          </Field>
          <Field label="Date of birth" error={err('dob')}><Input type="date" value={f.dob} onChange={set('dob')} /></Field>
          <Field label={t('Class')}>
            <Select value={f.divisionId} onChange={set('divisionId')}><option value="">—</option>{setup?.divisions.map(d => <option key={d.id} value={d.id}>{d.label}</option>)}</Select>
          </Field>
          <Field label="Roll no."><Input inputMode="numeric" value={f.rollNo} onChange={set('rollNo')} /></Field>
        </div>
      </Card>
      <Card className="stack">
        <h3>Parent</h3>
        <div className="grid2">
          <Field label="Parent name"><Input value={f.parentName} onChange={set('parentName')} /></Field>
          <Field label="Parent mobile" error={err('parentPhone')}><Input type="tel" inputMode="tel" value={f.parentPhone} onChange={set('parentPhone')} placeholder="98765 43210" /></Field>
          <Field label="Relation">
            <Select value={f.relation} onChange={set('relation')}><option value="mother">Mother</option><option value="father">Father</option><option value="guardian">Guardian</option></Select>
          </Field>
        </div>
      </Card>
      {error && !error.field ? <Alert>{error.text}</Alert> : null}
      <Button type="submit" busy={busy}>{t('Save')}</Button>
    </form>
  )
}

export function ImportStudents({ school }: { school: SchoolRef }) {
  const [rows, setRows] = useState<Record<string, string>[] | null>(null)
  const [unknown, setUnknown] = useState<string[]>([])
  const [result, setResult] = useState<{ added: number; errors: { row: number; error: string }[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const templateHref = useMemo(() => URL.createObjectURL(new Blob(['﻿' + TEMPLATE_CSV], { type: 'text/csv' })), [])

  const read = async (file: File) => {
    setResult(null)
    setError(null)
    if (/\.xlsx?$/i.test(file.name)) { setError('Please save the sheet as CSV first: in Excel, File → Save As → CSV.'); return }
    const parsed = rowsToStudents(parseCsv(await file.text()))
    setRows(parsed.students)
    setUnknown(parsed.unknown)
  }
  const upload = async () => {
    if (!rows) return
    setBusy(true)
    try {
      setResult(await post(`/schools/${school.id}/students/import`, { rows }))
      setRows(null)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="stack">
      <PageTitle title="Import students" sub="Add a whole class or school from an Excel sheet." back={`/${school.slug}/students`} />
      <Card className="stack">
        <ol className="steps">
          <li><a href={templateHref} download="students-template.csv">Download the template</a> and open it in Excel or Google Sheets.</li>
          <li>Fill one row per child. Class names must match School setup (for example "Class 1" and section "A").</li>
          <li>Save as <b>CSV</b> and choose the file below.</li>
        </ol>
        <input type="file" accept=".csv,text/csv" onChange={e => { const file = e.target.files?.[0]; if (file) void read(file) }} />
      </Card>
      {error ? <Alert>{error}</Alert> : null}
      {unknown.length ? <Alert kind="info">These columns were skipped: {unknown.join(', ')}</Alert> : null}
      {rows ? (
        <Card className="stack">
          <p><b>{rows.length}</b> children found. First few:</p>
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Adm. no.</th><th>Name</th><th>Class</th><th>Parent</th></tr></thead>
              <tbody>{rows.slice(0, 5).map((r, i) => <tr key={i}><td>{r.admissionNo}</td><td>{r.firstName} {r.lastName ?? ''}</td><td>{r.class} {r.section ?? ''}</td><td>{r.parentName ?? ''} {r.parentPhone ?? ''}</td></tr>)}</tbody>
            </table>
          </div>
          <Button busy={busy} onClick={upload} disabled={!rows.length}>Add {rows.length} children</Button>
        </Card>
      ) : null}
      {result ? (
        <Alert kind={result.errors.length ? 'info' : 'ok'}>
          <b>{result.added}</b> children added.
          {result.errors.length ? <ul>{result.errors.slice(0, 20).map(e => <li key={e.row}>Row {e.row}: {e.error}</li>)}</ul> : null}
          {result.added ? <p className="small">Parents get their links from each child’s profile (Share on WhatsApp).</p> : null}
        </Alert>
      ) : null}
    </div>
  )
}

export function StudentProfile({ school, studentId }: { school: SchoolRef; studentId: number }) {
  const canManage = school.roles.some(r => r === 'admin' || r === 'principal')
  const { data, error, loading, reload } = useApi(() => get<{ student: Student; guardians: Guardian[] }>(`/schools/${school.id}/students/${studentId}`), [school.id, studentId])
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const [link, setLink] = useState<{ link: string; name: string; phone: string | null } | null>(null)
  const [adding, setAdding] = useState(false)
  const [g, setG] = useState({ name: '', phone: '', relation: 'father' })
  const [msg, setMsg] = useState<string | null>(null)

  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const s = data.student

  const moveClass = async (divisionId: string) => {
    await patch(`/schools/${school.id}/students/${studentId}`, { divisionId: Number(divisionId) })
    reload()
  }
  const addGuardian = async (e: FormEvent) => {
    e.preventDefault()
    setMsg(null)
    try {
      const res = await post<{ inviteLink: string | null }>(`/schools/${school.id}/students/${studentId}/guardians`, g)
      if (res.inviteLink) setLink({ link: res.inviteLink, name: g.name, phone: g.phone })
      setAdding(false)
      reload()
    } catch (err) {
      setMsg((err as Error).message)
    }
  }
  const newLink = async (gd: Guardian) => {
    const res = await post<{ inviteLink: string | null }>(`/schools/${school.id}/students/${studentId}/guardians/${gd.userId}/link`)
    if (res.inviteLink) setLink({ link: res.inviteLink, name: gd.name, phone: gd.phone })
  }

  return (
    <div className="stack">
      <PageTitle title={s.name} sub={`${s.division ?? 'No class'} · Adm. no. ${s.admissionNo}`} back={`/${school.slug}/students`} />
      {school.roles.some(r => ['admin', 'principal', 'accounts'].includes(r)) ? <LinkButton to={`/${school.slug}/fees/student/${s.id}`}>💰 {t('Fees and receipts')}</LinkButton> : null}
      <Card className="stack">
        <div className="facts">
          <div><span className="muted small">Gender</span><b>{s.gender ?? '—'}</b></div>
          <div><span className="muted small">Date of birth</span><b>{s.dob ? formatDate(s.dob) + ' ' + s.dob.slice(0, 4) : '—'}</b></div>
          <div><span className="muted small">Roll no.</span><b>{s.rollNo ?? '—'}</b></div>
          <div><span className="muted small">Admitted</span><b>{s.admittedOn ? formatDate(s.admittedOn) + ' ' + s.admittedOn.slice(0, 4) : '—'}</b></div>
        </div>
        {canManage ? (
          <Field label="Class">
            <Select value={s.divisionId ?? ''} onChange={e => { void moveClass(e.target.value) }}>
              <option value="" disabled>—</option>
              {setup?.divisions.map(d => <option key={d.id} value={d.id}>{d.label}</option>)}
            </Select>
          </Field>
        ) : null}
        <A to={`/${school.slug}/child/${s.id}`}>📅 Attendance and homework</A>
      </Card>
      <Card className="stack">
        <h3>Parents</h3>
        {data.guardians.length === 0 ? <p className="muted">No parent added yet.</p> : data.guardians.map(gd => (
          <div key={gd.userId} className="row">
            <div><b>{gd.name}</b> <span className="muted small">({gd.relation})</span><div className="muted small">{gd.phone ?? gd.email ?? ''}</div></div>
            {gd.joined ? <span className="pill pill-ok">Joined</span> : canManage ? <Button variant="soft" onClick={() => { void newLink(gd) }}>Send link</Button> : <span className="pill">Not joined</span>}
          </div>
        ))}
        {link ? <ShareLink link={link.link} name={link.name} phone={link.phone} schoolName={school.name} /> : null}
        {canManage && !adding ? <Button variant="ghost" onClick={() => setAdding(true)}>➕ Add parent</Button> : null}
        {adding ? (
          <form className="stack" onSubmit={addGuardian}>
            <div className="grid2">
              <Field label="Name"><Input value={g.name} onChange={e => setG({ ...g, name: e.target.value })} required /></Field>
              <Field label="Mobile"><Input type="tel" value={g.phone} onChange={e => setG({ ...g, phone: e.target.value })} required /></Field>
              <Field label="Relation"><Select value={g.relation} onChange={e => setG({ ...g, relation: e.target.value })}><option value="mother">Mother</option><option value="father">Father</option><option value="guardian">Guardian</option></Select></Field>
            </div>
            {msg ? <Alert>{msg}</Alert> : null}
            <div className="row-end"><Button variant="ghost" type="button" onClick={() => setAdding(false)}>{t('Cancel')}</Button><Button type="submit">{t('Add')}</Button></div>
          </form>
        ) : null}
      </Card>
    </div>
  )
}
