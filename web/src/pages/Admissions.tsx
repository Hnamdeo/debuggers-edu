import { useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, get, patch, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import { whatsappLink } from '../lib/whatsapp'
import { ADMISSION_STATUS_LABEL, type Admission, type AdmissionStatus, type SchoolRef, type Setup } from '../lib/types'
import { A, Alert, Button, Card, Chip, Credit, Empty, Field, Input, LinkButton, Loading, PageTitle, Select, Textarea, formatDate } from '../ui/kit'
import { ShareLink } from '../ui/ShareLink'

/** October to March, schools mostly take admissions for the next school year. */
function isAdmissionSeason(): boolean {
  const m = new Date().getMonth() + 1
  return m >= 10 || m <= 3
}

// ---------- the form (shared by parents online and the office) ----------

type FormState = Record<string, string>
const EMPTY: FormState = {
  firstName: '', lastName: '', gender: '', dob: '', gradeId: '', academicYear: '',
  fatherName: '', fatherPhone: '', motherName: '', motherPhone: '', email: '', address: '',
  previousSchool: '', previousClass: '', tcNumber: '', bloodGroup: '', category: '', religion: '', motherTongue: '',
  fatherOccupation: '', motherOccupation: '', transport: '', siblingInSchool: '', medicalNotes: '', rte: '', howHeard: '',
}

export function fromAdmission(a: Admission): FormState {
  const f: FormState = { ...EMPTY }
  for (const k of Object.keys(EMPTY)) {
    const v = (a as unknown as Record<string, unknown>)[k] ?? (a.details as Record<string, unknown>)[k]
    if (v !== null && v !== undefined) f[k] = String(v)
  }
  return f
}

interface FormProps {
  grades: { id: number; name: string }[]
  years: string[]
  initial?: FormState
  submitLabel: string
  onSubmit: (body: Record<string, string>) => Promise<void>
  extra?: ReactNode
}

/** Child, parents and more details. Only the child's name and one parent mobile are required. */
export function AdmissionForm({ grades, years, initial, submitLabel, onSubmit, extra }: FormProps) {
  const [f, setF] = useState<FormState>(initial ?? { ...EMPTY, academicYear: (isAdmissionSeason() ? years[1] : years[0]) ?? '' })
  const [more, setMore] = useState(false)
  const [error, setError] = useState<{ text: string; field?: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: string) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const err = (k: string) => (error?.field === k ? error.text : null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await onSubmit(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')))
    } catch (x) {
      const ex = x as ApiError
      setError({ text: ex.message, field: ex.field })
      if (ex.field && !more && !['firstName', 'lastName', 'gender', 'dob', 'gradeId', 'academicYear', 'fatherName', 'fatherPhone', 'motherName', 'motherPhone'].includes(ex.field)) setMore(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="stack" onSubmit={submit}>
      <Card className="stack">
        <h3>🧒 {t('Child')}</h3>
        <div className="grid2">
          <Field label={t('First name')} error={err('firstName')}><Input value={f.firstName} onChange={set('firstName')} required autoComplete="off" /></Field>
          <Field label={t('Last name')}><Input value={f.lastName} onChange={set('lastName')} autoComplete="off" /></Field>
          <Field label={t('Admission for class')} error={err('gradeId')}>
            <Select value={f.gradeId} onChange={set('gradeId')} required><option value="">—</option>{grades.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}</Select>
          </Field>
          <Field label={t('Year')}>
            <Select value={f.academicYear} onChange={set('academicYear')}>{years.map(y => <option key={y} value={y}>{y}</option>)}</Select>
          </Field>
          <Field label={t('Date of birth')} error={err('dob')}><Input type="date" value={f.dob} onChange={set('dob')} /></Field>
          <Field label={t('Gender')}>
            <Select value={f.gender} onChange={set('gender')}><option value="">—</option><option value="girl">{t('Girl')}</option><option value="boy">{t('Boy')}</option><option value="other">{t('Other')}</option></Select>
          </Field>
        </div>
      </Card>
      <Card className="stack">
        <h3>👪 {t('Parents')}</h3>
        <p className="muted small">{t("Give at least one mobile number. The school will call or WhatsApp on it.")}</p>
        <div className="grid2">
          <Field label={t("Father's name")}><Input value={f.fatherName} onChange={set('fatherName')} /></Field>
          <Field label={t("Father's mobile")} error={err('fatherPhone')}><Input type="tel" inputMode="tel" value={f.fatherPhone} onChange={set('fatherPhone')} placeholder="98765 43210" /></Field>
          <Field label={t("Mother's name")}><Input value={f.motherName} onChange={set('motherName')} /></Field>
          <Field label={t("Mother's mobile")} error={err('motherPhone')}><Input type="tel" inputMode="tel" value={f.motherPhone} onChange={set('motherPhone')} placeholder="98765 43210" /></Field>
          <Field label={t('Email')} error={err('email')}><Input type="email" value={f.email} onChange={set('email')} /></Field>
          <Field label={t('Address')}><Input value={f.address} onChange={set('address')} /></Field>
        </div>
      </Card>
      {more ? (
        <Card className="stack">
          <h3>📋 {t('More details')}</h3>
          <div className="grid2">
            <Field label={t('Previous school')}><Input value={f.previousSchool} onChange={set('previousSchool')} /></Field>
            <Field label={t('Previous class')}><Input value={f.previousClass} onChange={set('previousClass')} /></Field>
            <Field label={t('TC number')}><Input value={f.tcNumber} onChange={set('tcNumber')} /></Field>
            <Field label={t('Blood group')}>
              <Select value={f.bloodGroup} onChange={set('bloodGroup')}><option value="">—</option>{['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map(b => <option key={b}>{b}</option>)}</Select>
            </Field>
            <Field label={t('Category')}>
              <Select value={f.category} onChange={set('category')}><option value="">—</option>{['General', 'OBC', 'SC', 'ST', 'EWS'].map(c => <option key={c}>{c}</option>)}</Select>
            </Field>
            <Field label={t('Religion')}><Input value={f.religion} onChange={set('religion')} /></Field>
            <Field label={t('Mother tongue')}><Input value={f.motherTongue} onChange={set('motherTongue')} /></Field>
            <Field label={t("Father's work")}><Input value={f.fatherOccupation} onChange={set('fatherOccupation')} /></Field>
            <Field label={t("Mother's work")}><Input value={f.motherOccupation} onChange={set('motherOccupation')} /></Field>
            <Field label={t('Needs school bus?')}>
              <Select value={f.transport} onChange={set('transport')}><option value="">—</option><option value="yes">{t('Yes')}</option><option value="no">{t('No')}</option></Select>
            </Field>
            <Field label={t('RTE seat?')}>
              <Select value={f.rte} onChange={set('rte')}><option value="">—</option><option value="yes">{t('Yes')}</option><option value="no">{t('No')}</option></Select>
            </Field>
            <Field label={t('Brother or sister in this school')}><Input value={f.siblingInSchool} onChange={set('siblingInSchool')} placeholder={t('Name and class')} /></Field>
            <Field label={t('Health notes (allergy etc.)')}><Input value={f.medicalNotes} onChange={set('medicalNotes')} /></Field>
            <Field label={t('How did you hear about us?')}><Input value={f.howHeard} onChange={set('howHeard')} /></Field>
          </div>
        </Card>
      ) : (
        <button type="button" className="btn btn-ghost btn-wrap" onClick={() => setMore(true)}>➕ {t('More details (previous school, blood group, bus…)')}</button>
      )}
      {extra}
      {error ? <Alert>{error.text}</Alert> : null}
      <Button type="submit" busy={busy} className="btn-block">{submitLabel}</Button>
    </form>
  )
}

// ---------- public: parents apply online at /:slug/apply ----------

interface PublicForm { school: { name: string; slug: string; logoUrl: string | null; color: string; city: string | null; phone: string | null }; open: boolean; grades: { id: number; name: string }[]; years: string[] }

export function ApplyPage({ slug }: { slug: string }) {
  const { data, error } = useApi(() => get<PublicForm>(`/public/schools/${encodeURIComponent(slug)}/admission-form`), [slug])
  const [done, setDone] = useState<string | null>(null)
  const [trap, setTrap] = useState('')

  if (error) return <div className="apply"><Alert>{error}</Alert></div>
  if (!data) return <div className="apply"><Loading /></div>
  const s = data.school
  return (
    <div className="apply" style={{ ['--brand' as string]: s.color }}>
      <header className="apply-head">
        {s.logoUrl ? <img src={s.logoUrl} alt="" /> : <span className="brand-dot big">{s.name.slice(0, 1)}</span>}
        <div><h1>{s.name}</h1><p className="muted">{t('Admission form')}{s.city ? ` · ${s.city}` : ''}</p></div>
      </header>
      {done ? (
        <Card className="stack center">
          <div className="wave">🎉</div>
          <h2>{t('Thank you! Your form is sent.')}</h2>
          <p>{t('Your form number is')} <b>{done}</b>. {t('The school office will call you soon.')}</p>
          {s.phone ? <p className="muted">{t('School office')}: <a href={`tel:${s.phone}`}>{s.phone}</a></p> : null}
          <A to={`/${s.slug}`} className="btn btn-soft">{t('Back to school page')}</A>
        </Card>
      ) : !data.open ? (
        <Alert kind="info">{t('Online admission is closed right now. Please contact the school office.')}{s.phone ? ` ${s.phone}` : ''}</Alert>
      ) : (
        <AdmissionForm grades={data.grades} years={data.years} submitLabel={t('Send admission form')}
          extra={<input className="trap" tabIndex={-1} autoComplete="off" aria-hidden value={trap} onChange={e => setTrap(e.target.value)} name="website" />}
          onSubmit={async body => {
            const res = await post<{ applicationNo: string }>(`/public/schools/${encodeURIComponent(slug)}/admissions`, { ...body, website: trap || undefined })
            setDone(res.applicationNo)
            window.scrollTo(0, 0)
          }} />
      )}
      <Credit />
    </div>
  )
}

// ---------- office ----------

const TABS: { key: string; label: string }[] = [
  { key: 'open', label: 'Waiting' }, { key: 'admitted', label: 'Admitted' }, { key: 'not_admitted', label: 'Not admitted' }, { key: 'all', label: 'All' },
]

export function AdmissionsPage({ school }: { school: SchoolRef }) {
  const [status, setStatus] = useState('open')
  const [q, setQ] = useState('')
  const { data, error, loading } = useApi(
    () => get<{ admissions: Admission[]; counts: Record<AdmissionStatus, number> }>(`/schools/${school.id}/admissions`, { status, q: q.length >= 2 ? q : undefined }),
    [school.id, status, q.length >= 2 ? q : ''],
  )
  const { data: setup, reload } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const link = `${location.origin}/${school.slug}/apply`
  const open = setup?.school.admissionsOpen ?? false
  const [busy, setBusy] = useState(false)

  const toggle = async () => {
    setBusy(true)
    try { await patch(`/schools/${school.id}/settings`, { admissionsOpen: !open }); reload() } finally { setBusy(false) }
  }
  const waiting = data ? data.counts.enquiry + data.counts.registered : 0

  return (
    <div className="stack">
      <PageTitle title={t('Admissions')} sub={data ? t('{n} waiting · {m} admitted', { n: waiting, m: data.counts.admitted }) : undefined} />
      <Card className="stack">
        <div className="row">
          <div><b>{t('Online admission form')}</b><div className="muted small">{open ? t('Open: parents can apply from your link') : t('Closed: only the office can add forms')}</div></div>
          <Button variant={open ? 'soft' : 'primary'} busy={busy} onClick={() => { void toggle() }}>{open ? t('Close') : t('Open')}</Button>
        </div>
        {open ? (
          <div className="share-buttons">
            <a className="btn btn-wa" target="_blank" rel="noopener noreferrer"
              href={whatsappLink(null, t('Admissions are open at {school}. Fill the form here: {link}', { school: school.name, link }))}>🟢 {t('Share on WhatsApp')}</a>
            <button className="btn btn-soft" onClick={() => { void navigator.clipboard?.writeText(link) }}>{t('Copy link')}</button>
          </div>
        ) : null}
      </Card>
      <div className="row-start">
        <LinkButton to={`/${school.slug}/admissions/new`} variant="primary">➕ {t('New admission form')}</LinkButton>
      </div>
      <Input type="search" value={q} onChange={e => setQ(e.target.value)} placeholder={t('Search name, mobile or form no.')} />
      <div className="chips scroll-x">
        {TABS.map(tb => <Chip key={tb.key} active={status === tb.key} onClick={() => setStatus(tb.key)}>{t(tb.label)}</Chip>)}
      </div>
      {loading && !data ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.admissions.length ? (
        <Empty icon="📝">{status === 'open' ? t('No forms waiting. Share your online form link with parents.') : t('Nothing here yet.')}</Empty>
      ) : (
        <div className="list">
          {data.admissions.map(a => (
            <Card key={a.id} className="row" onClick={() => navigate(`/${school.slug}/admissions/${a.id}`)}>
              <div className="row-start">
                <div className="avatar small">{a.firstName.slice(0, 1)}</div>
                <div>
                  <b>{a.name}</b>
                  <div className="muted small">{a.grade ?? '—'} · {a.applicationNo} · {formatDate(a.createdAt)}{a.source === 'online' ? ' · 🌐' : ''}</div>
                </div>
              </div>
              <span className={`badge badge-${a.status}`}>{t(ADMISSION_STATUS_LABEL[a.status])}</span>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function useYears(setup: Setup | null): string[] {
  const cur = setup?.years.find(y => y.isCurrent)?.name
  const start = cur ? Number(cur.slice(0, 4)) : new Date().getFullYear()
  const fmt = (y: number) => `${y}-${String((y + 1) % 100).padStart(2, '0')}`
  return [fmt(start), fmt(start + 1)]
}

export function NewAdmission({ school }: { school: SchoolRef }) {
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const years = useYears(setup)
  if (!setup) return <Loading />
  return (
    <div className="stack">
      <PageTitle title={t('New admission form')} back={`/${school.slug}/admissions`} />
      <AdmissionForm grades={setup.grades} years={years} submitLabel={t('Save form')}
        onSubmit={async body => {
          const res = await post<{ admission: Admission }>(`/schools/${school.id}/admissions`, body)
          navigate(`/${school.slug}/admissions/${res.admission.id}`, true)
        }} />
    </div>
  )
}

export function AdmissionDetail({ school, admissionId }: { school: SchoolRef; admissionId: number }) {
  const { data, error, reload } = useApi(() => get<{ admission: Admission }>(`/schools/${school.id}/admissions/${admissionId}`), [school.id, admissionId])
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const years = useYears(setup)
  const [editing, setEditing] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const canAdmit = school.roles.some(r => r === 'admin' || r === 'principal')

  if (error) return <Alert>{error}</Alert>
  if (!data || !setup) return <Loading />
  const a = data.admission
  const save = async (body: Record<string, unknown>, ok: string) => {
    setMsg(null)
    try { await patch(`/schools/${school.id}/admissions/${a.id}`, body); setMsg({ kind: 'ok', text: ok }); reload() } catch (e) { setMsg({ kind: 'error', text: (e as Error).message }) }
  }

  if (editing) {
    return (
      <div className="stack">
        <PageTitle title={t('Edit form')} sub={a.applicationNo} />
        <AdmissionForm grades={setup.grades} years={years} initial={fromAdmission(a)} submitLabel={t('Save')}
          onSubmit={async body => { await patch(`/schools/${school.id}/admissions/${a.id}`, body); setEditing(false); reload() }} />
        <Button variant="ghost" onClick={() => setEditing(false)}>{t('Cancel')}</Button>
      </div>
    )
  }

  const d = a.details
  const rows: [string, ReactNode][] = [
    ['Class', `${a.grade ?? '—'}${a.academicYear ? ` (${a.academicYear})` : ''}`],
    ['Date of birth', a.dob ? formatDate(a.dob) + ' ' + a.dob.slice(0, 4) : null],
    ['Gender', a.gender ? t(a.gender === 'girl' ? 'Girl' : a.gender === 'boy' ? 'Boy' : 'Other') : null],
    ["Father", a.fatherName || a.fatherPhone ? <Contact name={a.fatherName} phone={a.fatherPhone} /> : null],
    ["Mother", a.motherName || a.motherPhone ? <Contact name={a.motherName} phone={a.motherPhone} /> : null],
    ['Email', a.email], ['Address', a.address], ['Previous school', [a.previousSchool, d.previousClass].filter(Boolean).join(', ') || null],
    ['TC number', d.tcNumber], ['Blood group', d.bloodGroup], ['Category', d.category], ['Religion', d.religion], ['Mother tongue', d.motherTongue],
    ["Father's work", d.fatherOccupation], ["Mother's work", d.motherOccupation], ['Needs school bus?', d.transport], ['RTE seat?', d.rte],
    ['Brother or sister in this school', d.siblingInSchool], ['Health notes (allergy etc.)', d.medicalNotes], ['How did you hear about us?', d.howHeard],
  ]

  return (
    <div className="stack">
      <PageTitle title={a.name} sub={`${a.applicationNo} · ${t(a.source === 'online' ? 'Applied online' : 'Added by office')} · ${formatDate(a.createdAt)}`} back={`/${school.slug}/admissions`} />
      <div className="row-start wrap">
        <span className={`badge badge-${a.status}`}>{t(ADMISSION_STATUS_LABEL[a.status])}</span>
        {a.status !== 'admitted' ? <Button variant="ghost" onClick={() => setEditing(true)}>✏️ {t('Edit form')}</Button> : null}
      </div>
      {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}

      {a.status === 'admitted' && a.studentId ? (
        <Alert kind="ok">{t('Admitted.')} <A to={`/${school.slug}/students/${a.studentId}`}>{t('Open student profile')}</A></Alert>
      ) : (
        <>
          <Steps status={a.status} />
          <OfficeCard a={a} onSave={save} />
          {canAdmit ? <AdmitCard school={school} a={a} setup={setup} onDone={reload} /> : null}
          {a.status !== 'not_admitted'
            ? <Button variant="ghost" onClick={() => { void save({ status: 'not_admitted' }, t('Marked as not admitted')) }}>{t('Mark as not admitted')}</Button>
            : <Button variant="ghost" onClick={() => { void save({ status: 'enquiry' }, t('Opened again')) }}>{t('Open again')}</Button>}
        </>
      )}

      <Card className="stack">
        <h3>{t('Form details')}</h3>
        <dl className="details">
          {rows.filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => <div key={k}><dt>{t(k)}</dt><dd>{v}</dd></div>)}
        </dl>
      </Card>
    </div>
  )
}

function Contact({ name, phone }: { name: string | null; phone: string | null }) {
  return (
    <span>{name ?? ''}{phone ? <> · <a href={`tel:${phone}`}>{phone}</a> · <a href={whatsappLink(phone, '')} target="_blank" rel="noopener noreferrer">WhatsApp</a></> : null}</span>
  )
}

function Steps({ status }: { status: AdmissionStatus }) {
  const order: AdmissionStatus[] = ['enquiry', 'registered', 'admitted']
  const at = order.indexOf(status)
  return (
    <ol className="steps-bar" aria-label={t('Progress')}>
      {order.map((s, i) => <li key={s} className={i <= at ? 'on' : ''}>{t(ADMISSION_STATUS_LABEL[s])}</li>)}
    </ol>
  )
}

function OfficeCard({ a, onSave }: { a: Admission; onSave: (body: Record<string, unknown>, ok: string) => Promise<void> }) {
  const [f, setF] = useState({ registrationFee: a.registrationFee?.toString() ?? '', receiptNo: a.receiptNo ?? '', followUpOn: a.followUpOn ?? '', notes: a.notes ?? '' })
  const body = (extra: Record<string, unknown> = {}) => ({ ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), ...extra })
  return (
    <Card className="stack">
      <h3>🏫 {t('Office')}</h3>
      <div className="grid2">
        <Field label={t('Registration fee (₹)')}><Input inputMode="numeric" value={f.registrationFee} onChange={e => setF({ ...f, registrationFee: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label={t('Receipt no.')}><Input value={f.receiptNo} onChange={e => setF({ ...f, receiptNo: e.target.value })} /></Field>
        <Field label={t('Call back on')}><Input type="date" value={f.followUpOn} onChange={e => setF({ ...f, followUpOn: e.target.value })} /></Field>
      </div>
      <Field label={t('Notes')}><Textarea value={f.notes} onChange={e => setF({ ...f, notes: e.target.value })} placeholder={t('e.g. Visited school, interview on Monday')} /></Field>
      <div className="row-start wrap">
        <Button variant="soft" onClick={() => { void onSave(body(), t('Saved')) }}>{t('Save')}</Button>
        {a.status === 'enquiry' ? <Button onClick={() => { void onSave(body({ status: 'registered' }), t('Registered')) }}>✔ {t('Mark registered')}</Button> : null}
      </div>
    </Card>
  )
}

function AdmitCard({ school, a, setup, onDone }: { school: SchoolRef; a: Admission; setup: Setup; onDone: () => void }) {
  const sections = setup.divisions.filter(d => !a.gradeId || d.gradeId === a.gradeId)
  const [divisionId, setDivisionId] = useState(sections.length === 1 ? String(sections[0].id) : '')
  const [admissionNo, setAdmissionNo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ studentId: number; admissionNo: string; parents: { relation: string; name: string; phone: string | null; inviteLink: string | null }[] } | null>(null)

  if (result) {
    return (
      <Card className="stack">
        <h3>🎉 {t('{name} is admitted', { name: a.firstName })}</h3>
        <p>{t('Admission no.')} <b>{result.admissionNo}</b></p>
        {result.parents.filter(p => p.inviteLink).map(p => <ShareLink key={p.relation} link={p.inviteLink!} name={p.name} phone={p.phone} schoolName={school.name} />)}
        <div className="row-start wrap">
          <LinkButton to={`/${school.slug}/students/${result.studentId}`}>{t('Open student profile')}</LinkButton>
          <Button variant="ghost" onClick={onDone}>{t('Done')}</Button>
        </div>
      </Card>
    )
  }
  const admit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      setResult(await post(`/schools/${school.id}/admissions/${a.id}/admit`, { divisionId: Number(divisionId), admissionNo: admissionNo || undefined }))
    } catch (x) { setError((x as Error).message) } finally { setBusy(false) }
  }
  return (
    <Card>
      <form className="stack" onSubmit={admit}>
        <h3>🎒 {t('Admit to a class')}</h3>
        <div className="grid2">
          <Field label={t('Class and section')}>
            <Select value={divisionId} onChange={e => setDivisionId(e.target.value)} required>
              <option value="">—</option>{(sections.length ? sections : setup.divisions).map(d => <option key={d.id} value={d.id}>{d.label}</option>)}
            </Select>
          </Field>
          <Field label={t('Admission no.')} hint={t('Leave empty for the next number')}><Input value={admissionNo} onChange={e => setAdmissionNo(e.target.value)} /></Field>
        </div>
        {error ? <Alert>{error}</Alert> : null}
        <Button type="submit" busy={busy}>✔ {t('Admit and add parents')}</Button>
      </form>
    </Card>
  )
}
