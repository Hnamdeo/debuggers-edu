import { useEffect, useState, type FormEvent } from 'react'
import { del, get, post, put } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import type { ExamDetail, ExamRef, MarksSheet, ReportCard, ResultRow, SchoolRef, Setup } from '../lib/types'
import { Alert, Button, Card, Chip, Empty, Field, Input, LinkButton, Loading, PageTitle, Textarea, formatDate, todayIso } from '../ui/kit'

const leaderRoles = ['admin', 'principal', 'academic_head']
const resultPill = (r: ResultRow['result']) => r === 'pass'
  ? <span className="pill pill-ok">{t('Pass')}</span>
  : r === 'needs_support' ? <span className="pill pill-warn">{t('Needs support')}</span> : <span className="pill">{t('Marks pending')}</span>
const fmt = (n: number | null) => n === null ? '—' : String(Number.isInteger(n) ? n : n.toFixed(1))

/** Exams of this school year; leaders create them, teachers open one to enter marks. */
export function ExamsPage({ school }: { school: SchoolRef }) {
  const leader = school.roles.some(r => leaderRoles.includes(r))
  const { data, error, loading, reload } = useApi(() => get<{ exams: (ExamRef & { classes: string | null; papers: number })[] }>(`/schools/${school.id}/exams`), [school.id])
  const [adding, setAdding] = useState(false)
  return (
    <div className="stack">
      <PageTitle title={t('Exams and results')} sub={t('Marks, results and report cards')} />
      {leader && !adding ? <Button onClick={() => setAdding(true)}>➕ {t('New exam')}</Button> : null}
      {adding ? <NewExam school={school} onDone={id => { setAdding(false); reload(); navigate(`/${school.slug}/exams/${id}`) }} onCancel={() => setAdding(false)} /> : null}
      {loading ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.exams.length ? <Empty icon="📝">{leader ? t('No exams yet. Create the first one, for example Unit Test 1.') : t('No exams yet.')}</Empty> : (
        <div className="list">
          {data.exams.map(e => (
            <Card key={e.id} onClick={() => navigate(`/${school.slug}/exams/${e.id}`)}>
              <div className="row">
                <div className="stack-xs"><b>{e.name}</b><span className="muted small">{e.classes ?? ''}{e.startsOn ? ` · ${formatDate(e.startsOn)}` : ''}</span></div>
                {e.published ? <span className="pill pill-ok">✓ {t('Published')}</span> : <span className="pill">{t('Marks entry')}</span>}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function NewExam({ school, onDone, onCancel }: { school: SchoolRef; onDone: (id: number) => void; onCancel: () => void }) {
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const [f, setF] = useState({ name: 'Unit Test 1', startsOn: todayIso(), gradeIds: [] as number[], subjectIds: [] as number[], maxMarks: '100' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const toggle = (key: 'gradeIds' | 'subjectIds', id: number) => setF(p => ({ ...p, [key]: p[key].includes(id) ? p[key].filter(x => x !== id) : [...p[key], id] }))
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      const res = await post<ExamDetail>(`/schools/${school.id}/exams`, { ...f, maxMarks: Number(f.maxMarks) })
      onDone(res.exam.id)
    } catch (err) { setError((err as Error).message); setBusy(false) }
  }
  if (!setup) return <Loading />
  const allG = f.gradeIds.length === setup.grades.length
  const allS = f.subjectIds.length === setup.subjects.length
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <Field label={t('Exam name')}>
          <Input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required list="exam-names" />
          <datalist id="exam-names">{['Unit Test 1', 'Unit Test 2', 'Half-yearly', 'Periodic Test', 'Pre-board', 'Annual'].map(n => <option key={n} value={n} />)}</datalist>
        </Field>
        <Field label={t('Classes')}>
          <div className="chips">
            <Chip active={allG} onClick={() => setF({ ...f, gradeIds: allG ? [] : setup.grades.map(g => g.id) })}>{t('All classes')}</Chip>
            {setup.grades.map(g => <Chip key={g.id} active={f.gradeIds.includes(g.id)} onClick={() => toggle('gradeIds', g.id)}>{g.name}</Chip>)}
          </div>
        </Field>
        <Field label={t('Subjects')}>
          <div className="chips">
            <Chip active={allS} onClick={() => setF({ ...f, subjectIds: allS ? [] : setup.subjects.map(s => s.id) })}>{t('All subjects')}</Chip>
            {setup.subjects.map(s => <Chip key={s.id} active={f.subjectIds.includes(s.id)} onClick={() => toggle('subjectIds', s.id)}>{s.name}</Chip>)}
          </div>
        </Field>
        <div className="grid2">
          <Field label={t('Maximum marks per subject')}>
            <div className="chips">{['20', '25', '40', '50', '80', '100'].map(m => <Chip key={m} active={f.maxMarks === m} onClick={() => setF({ ...f, maxMarks: m })}>{m}</Chip>)}</div>
          </Field>
          <Field label={t('Starts on')}><Input type="date" value={f.startsOn} onChange={e => setF({ ...f, startsOn: e.target.value })} /></Field>
        </div>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end"><Button type="button" variant="ghost" onClick={onCancel}>{t('Cancel')}</Button><Button busy={busy} disabled={!f.gradeIds.length || !f.subjectIds.length}>{t('Create exam')}</Button></div>
      </form>
    </Card>
  )
}

/** One exam: per class, sections with marks progress, papers, publish. */
export function ExamPage({ school, examId }: { school: SchoolRef; examId: number }) {
  const leader = school.roles.some(r => leaderRoles.includes(r))
  const { data, error, loading, reload } = useApi(() => get<ExamDetail>(`/schools/${school.id}/exams/${examId}`), [school.id, examId])
  const [err, setErr] = useState<string | null>(null)
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const publish = async (on: boolean) => { setErr(null); try { await post(`/schools/${school.id}/exams/${examId}/publish`, { published: on }); reload() } catch (e) { setErr((e as Error).message) } }
  return (
    <div className="stack">
      <PageTitle title={data.exam.name} sub={data.exam.published ? t('Results published: parents can see report cards') : t('Enter marks, then publish results')} back={`/${school.slug}/exams`} />
      {err ? <Alert>{err}</Alert> : null}
      {data.classes.map(c => (
        <Card key={c.gradeId} className="stack-sm">
          <b>{c.grade}</b>
          <span className="muted small">{c.papers.map(p => `${p.subject} (${p.maxMarks})`).join(' · ')}</span>
          {c.sections.length ? c.sections.map(s => {
            const pct = s.needed ? Math.round(s.filled * 100 / s.needed) : 0
            return (
              <div key={s.divisionId} className="stack-xs section-row">
                <div className="row">
                  <span><b>{s.label}</b> <span className="muted small">· {s.students} {t('children')}</span></span>
                  <span className={`pill ${pct >= 100 ? 'pill-ok' : ''}`}>{pct}% {t('marks in')}</span>
                </div>
                <div className="chips">
                  {!data.exam.published ? c.papers.map(p => <Chip key={p.id} onClick={() => navigate(`/${school.slug}/exams/${examId}/marks/${s.divisionId}/${p.subjectId}`)}>✏️ {p.subject}</Chip>) : null}
                  <Chip onClick={() => navigate(`/${school.slug}/exams/${examId}/results/${s.divisionId}`)}>📊 {t('Results')}</Chip>
                </div>
              </div>
            )
          }) : <span className="muted small">{t('No sections for this class yet.')}</span>}
        </Card>
      ))}
      {leader ? (
        <div className="row-end btn-wrap">
          {!data.exam.published ? <Button variant="ghost" onClick={() => { void del(`/schools/${school.id}/exams/${examId}`).then(() => navigate(`/${school.slug}/exams`)).catch(e => setErr((e as Error).message)) }}>{t('Delete exam')}</Button> : null}
          {data.exam.published
            ? <Button variant="soft" onClick={() => { void publish(false) }}>{t('Unpublish (to change marks)')}</Button>
            : <Button onClick={() => { void publish(true) }}>📢 {t('Publish results to parents')}</Button>}
        </div>
      ) : null}
    </div>
  )
}

/** Type marks for one section and one subject. Big inputs, roll order, AB for absent. */
export function MarksEntryPage({ school, examId, divisionId, subjectId }: { school: SchoolRef; examId: number; divisionId: number; subjectId: number }) {
  const { data, error, loading } = useApi(() => get<MarksSheet>(`/schools/${school.id}/exams/${examId}/marks`, { divisionId, subjectId }), [school.id, examId, divisionId, subjectId])
  const [rows, setRows] = useState<MarksSheet['students']>([])
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (data) setRows(data.students) }, [data])
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const max = data.paper.maxMarks
  const set = (i: number, v: Partial<MarksSheet['students'][number]>) => setRows(r => r.map((x, j) => j === i ? { ...x, ...v } : x))
  const save = async () => {
    setBusy(true); setMsg(null)
    try {
      await put(`/schools/${school.id}/exams/${examId}/marks`, { divisionId, subjectId, marks: rows.map(r => ({ studentId: r.studentId, marks: r.marks, absent: r.absent })) })
      setMsg({ kind: 'ok', text: t('Marks saved') })
    } catch (e) { setMsg({ kind: 'error', text: (e as Error).message }) } finally { setBusy(false) }
  }
  const filled = rows.filter(r => r.marks !== null || r.absent).length
  return (
    <div className="stack">
      <PageTitle title={`${data.paper.subject} · ${data.division.label}`} sub={`${data.exam.name} · ${t('Out of')} ${max}`} back={`/${school.slug}/exams/${examId}`} />
      <div className="list">
        {rows.map((r, i) => {
          const bad = r.marks !== null && (r.marks > max || r.marks < 0)
          return (
            <div key={r.studentId} className={`marks-row ${bad ? 'bad' : ''}`}>
              <span className="muted roll">{r.rollNo ?? '—'}</span>
              <span className="grow">{r.name}</span>
              <Input className="marks-input" inputMode="decimal" disabled={r.absent} placeholder="—" aria-label={`${r.name} ${t('marks')}`}
                value={r.marks === null ? '' : String(r.marks)}
                onChange={e => { const v = e.target.value.replace(/[^\d.]/g, ''); set(i, { marks: v === '' ? null : Number(v) }) }} />
              <Chip active={r.absent} onClick={() => set(i, { absent: !r.absent, marks: null })}>AB</Chip>
            </div>
          )
        })}
      </div>
      {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}
      <div className="sticky-save row">
        <span className="muted small">{filled} / {rows.length} {t('done')}</span>
        <Button busy={busy} onClick={() => { void save() }}>{t('Save marks')}</Button>
      </div>
    </div>
  )
}

/** Section results: rank, percentage, grade; tap a child for the report card. */
export function ExamResultsPage({ school, examId, divisionId }: { school: SchoolRef; examId: number; divisionId: number }) {
  const { data, error, loading } = useApi(() => get<{ exam: ExamRef; division: { id: number; label: string }; papers: { subject: string; maxMarks: number }[]; students: ResultRow[] }>(`/schools/${school.id}/exams/${examId}/results`, { divisionId }), [school.id, examId, divisionId])
  if (loading) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const done = data.students.filter(s => s.result !== 'pending')
  const avg = done.length ? Math.round(done.reduce((a, s) => a + (s.percent ?? 0), 0) / done.length) : null
  return (
    <div className="stack">
      <PageTitle title={`${t('Results')} · ${data.division.label}`} sub={data.exam.name} back={`/${school.slug}/exams/${examId}`} />
      <div className="stats">
        <div className="stat"><span className="stat-value">{done.length}/{data.students.length}</span><span className="stat-label">{t('Results ready')}</span></div>
        <div className="stat"><span className="stat-value">{avg === null ? '—' : `${avg}%`}</span><span className="stat-label">{t('Class average')}</span></div>
        <div className="stat stat-ok"><span className="stat-value">{done.filter(s => s.result === 'pass').length}</span><span className="stat-label">{t('Pass')}</span></div>
        <div className="stat stat-warn"><span className="stat-value">{done.filter(s => s.result === 'needs_support').length}</span><span className="stat-label">{t('Needs support')}</span></div>
      </div>
      <div className="list">
        {data.students.map(s => (
          <Card key={s.studentId} onClick={() => navigate(`/${school.slug}/exams/${examId}/report/${s.studentId}`)}>
            <div className="row">
              <div className="stack-xs"><b>{s.rank ? `#${s.rank} ` : ''}{s.name}</b><span className="muted small">{s.subjects.map(x => `${x.subject} ${x.absent ? 'AB' : fmt(x.marks)}`).join(' · ')}</span></div>
              <div className="stack-xs right"><b>{s.percent === null ? '—' : `${s.percent}%`} {s.grade ?? ''}</b>{resultPill(s.result)}</div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

/** Printable report card. Class teacher or leaders can write the remark. */
export function ReportCardPage({ school, examId, studentId }: { school: SchoolRef; examId: number; studentId: number }) {
  const staff = school.roles.some(r => [...leaderRoles, 'teacher'].includes(r))
  const { data, error, loading, reload } = useApi(() => get<ReportCard>(`/schools/${school.id}/exams/${examId}/report/${studentId}`), [school.id, examId, studentId])
  const [remark, setRemark] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const saveRemark = async () => {
    setMsg(null)
    try { await put(`/schools/${school.id}/exams/${examId}/remarks`, { studentId, remark }); setRemark(null); reload() } catch (e) { setMsg((e as Error).message) }
  }
  const attPct = data.attendance.days ? Math.round(data.attendance.present * 100 / data.attendance.days) : null
  return (
    <div className="stack">
      <PageTitle title={t('Report card')} back={staff ? `/${school.slug}/exams/${examId}` : `/${school.slug}/child/${studentId}`} />
      <Card className="stack receipt">
        <div className="center stack-xs">
          <b className="receipt-school">{data.school.name}</b>
          <span className="muted small">{[data.school.city, data.school.board].filter(Boolean).join(' · ')}</span>
          <b>{data.exam.name} · {data.year}</b>
        </div>
        <div className="facts">
          <div><span className="muted small">{t('Student')}</span><b>{data.name}</b></div>
          <div><span className="muted small">{t('Class')}</span><b>{data.class}</b></div>
          <div><span className="muted small">{t('Roll no.')}</span><b>{data.rollNo ?? '—'}</b></div>
          <div><span className="muted small">{t('Adm. no.')}</span><b>{data.admissionNo}</b></div>
        </div>
        <table className="marks-table">
          <thead><tr><th>{t('Subject')}</th><th>{t('Marks')}</th><th>{t('Out of')}</th><th>{t('Grade')}</th></tr></thead>
          <tbody>
            {data.subjects.map(s => <tr key={s.subject}><td>{s.subject}</td><td>{s.absent ? 'AB' : fmt(s.marks)}</td><td>{s.maxMarks}</td><td><b>{s.grade ?? '—'}</b></td></tr>)}
            <tr className="total"><td>{t('Total')}</td><td>{fmt(data.total)}</td><td>{data.maxTotal}</td><td><b>{data.grade ?? '—'}</b></td></tr>
          </tbody>
        </table>
        <div className="facts">
          <div><span className="muted small">{t('Percentage')}</span><b>{data.percent === null ? '—' : `${data.percent}%`}</b></div>
          <div><span className="muted small">{t('Rank in class')}</span><b>{data.rank ? `${data.rank} / ${data.classSize}` : '—'}</b></div>
          <div><span className="muted small">{t('Attendance')}</span><b>{attPct === null ? '—' : `${data.attendance.present}/${data.attendance.days} (${attPct}%)`}</b></div>
          <div><span className="muted small">{t('Result')}</span><b>{resultPill(data.result)}</b></div>
        </div>
        <div className="stack-xs">
          <span className="muted small">{t('Class teacher’s remark')}</span>
          {remark !== null ? (
            <div className="stack-sm no-print">
              <Textarea value={remark} maxLength={300} onChange={e => setRemark(e.target.value)} />
              {msg ? <Alert>{msg}</Alert> : null}
              <div className="row-end"><Button variant="ghost" onClick={() => setRemark(null)}>{t('Cancel')}</Button><Button onClick={() => { void saveRemark() }}>{t('Save')}</Button></div>
            </div>
          ) : (
            <span>{data.remark ?? '—'} {staff ? <button className="link small no-print" onClick={() => setRemark(data.remark ?? '')}>✏️ {t('Write remark')}</button> : null}</span>
          )}
        </div>
        <span className="muted small">{t('Grades')}: {data.scale.map(s => `${s.grade} ${s.from}+`).join(' · ')}</span>
      </Card>
      <div className="row-end no-print"><Button variant="soft" onClick={() => window.print()}>🖨️ {t('Print')}</Button></div>
    </div>
  )
}

/** For the child page: report cards the school has published. */
export function ChildReportCards({ school, studentId }: { school: SchoolRef; studentId: number }) {
  const { data } = useApi(() => get<{ exams: ExamRef[] }>(`/schools/${school.id}/report-cards/${studentId}`).catch(() => null), [school.id, studentId])
  if (!data?.exams.length) return null
  return (
    <Card className="stack-sm">
      <b>📊 {t('Report cards')}</b>
      {data.exams.map(e => <LinkButton key={e.id} to={`/${school.slug}/exams/${e.id}/report/${studentId}`}>{e.name}</LinkButton>)}
    </Card>
  )
}
