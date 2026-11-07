import { useEffect, useState, type FormEvent } from 'react'
import { del, get, post, put } from '../lib/api'
import { useApi } from '../lib/useApi'
import { queryParam } from '../lib/router'
import { t } from '../lib/i18n'
import type { Lesson, SchoolRef, Setup, Syllabus, Unit } from '../lib/types'
import { Alert, Button, Card, Chip, Empty, Field, Input, Loading, PageTitle, Select, Textarea, formatDate, todayIso } from '../ui/kit'

const DAYS: [number, string][] = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']]

/**
 * Syllabus for one section and subject. Office/academic head build chapters and lessons and
 * plan them day by day; teachers tick lessons as taught and give a lesson as homework in one
 * tap; parents (opened from their child's page) see the plan and what is done.
 */
export function SyllabusPage({ school }: { school: SchoolRef }) {
  const r = school.roles
  const canManage = r.some(x => x === 'admin' || x === 'principal' || x === 'academic_head')
  const canTeach = canManage || r.includes('teacher')
  const fixedDivision = Number(queryParam('division')) || null
  const { data: setup, error: setupError } = useApi(() => canTeach ? get<Setup>(`/schools/${school.id}/setup`) : Promise.resolve(null), [school.id])
  const { data: mine } = useApi(() => r.includes('teacher') ? get<{ my_classes?: { id: number }[] }>(`/schools/${school.id}/home`) : Promise.resolve(null), [school.id])
  const [divisionId, setDivisionId] = useState<number | null>(fixedDivision)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const { data: subjectsForParent } = useApi(() => !canTeach ? get<{ subjects: { id: number; name: string }[] }>(`/schools/${school.id}/syllabus/subjects`).catch(() => null) : Promise.resolve(null), [school.id])

  const { data: overview } = useApi(() => canTeach ? get<{ classes: { gradeId: number; subjectId: number; lessons: number }[] }>(`/schools/${school.id}/syllabus/overview`) : Promise.resolve(null), [school.id])

  // Pick a sensible class (a teacher's own) and subject (one that has lessons) the first time
  const waitingForMine = r.includes('teacher') && !mine
  useEffect(() => {
    if (divisionId || !setup || waitingForMine) return
    const own = mine?.my_classes?.[0]?.id
    setDivisionId(own ?? setup.divisions[0]?.id ?? null)
  }, [setup, mine, divisionId, waitingForMine])
  const subjects = setup?.subjects ?? subjectsForParent?.subjects ?? []
  const gradeOfDivision = setup?.divisions.find(d => d.id === divisionId)?.gradeId
  useEffect(() => {
    if (subjectId || !subjects.length || (canTeach && (!overview || !gradeOfDivision))) return
    const withLessons = overview?.classes.find(c => c.gradeId === gradeOfDivision && c.lessons > 0)?.subjectId
    setSubjectId(withLessons ?? subjects[0].id)
  }, [subjects, subjectId, overview, gradeOfDivision, canTeach])

  if (setupError) return <Alert>{setupError}</Alert>
  const division = setup?.divisions.find(d => d.id === divisionId)

  return (
    <div className="stack">
      <PageTitle title={t('Syllabus')} sub={division ? division.label : undefined} back={fixedDivision && !canTeach ? `/${school.slug}/home` : undefined} />
      {canTeach && setup ? (
        <Select value={divisionId ?? ''} onChange={e => { setDivisionId(Number(e.target.value) || null); setSubjectId(null) }} aria-label={t('Class')}>
          {setup.divisions.map(d => <option key={d.id} value={d.id}>{d.label}</option>)}
        </Select>
      ) : null}
      {divisionId ? <TodayCard school={school} divisionId={divisionId} canTeach={canTeach} /> : null}
      <div className="chips scroll-x">
        {subjects.map(s => <Chip key={s.id} active={subjectId === s.id} onClick={() => setSubjectId(s.id)}>{s.name}</Chip>)}
      </div>
      {divisionId && subjectId && (division || !canTeach)
        ? <SubjectPlan key={`${divisionId}-${subjectId}`} school={school} divisionId={divisionId} gradeId={division?.gradeId ?? null} subjectId={subjectId} canManage={canManage} canTeach={canTeach} />
        : !setup && canTeach ? <Loading /> : null}
    </div>
  )
}

function TodayCard({ school, divisionId, canTeach }: { school: SchoolRef; divisionId: number; canTeach: boolean }) {
  const { data, reload } = useApi(() => get<{ date: string; lessons: (Lesson & { subject: string; unit: string })[] }>(`/schools/${school.id}/syllabus/day`, { divisionId }), [school.id, divisionId])
  if (!data?.lessons.length) return null
  return (
    <Card className="stack card-hint">
      <h3>📅 {t('Today’s lessons')}</h3>
      {data.lessons.map(l => (
        <LessonRow key={l.id} school={school} lesson={l} label={`${l.subject}: ${l.title}`} divisionId={divisionId} canTeach={canTeach} canManage={false} onChange={reload} />
      ))}
    </Card>
  )
}

function SubjectPlan({ school, divisionId, gradeId, subjectId, canManage, canTeach }: {
  school: SchoolRef; divisionId: number; gradeId: number | null; subjectId: number; canManage: boolean; canTeach: boolean
}) {
  const { data, error, loading, reload } = useApi(() => get<Syllabus>(`/schools/${school.id}/syllabus`, { divisionId, subjectId }), [school.id, divisionId, subjectId])
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [unit, setUnit] = useState({ title: '', month: '' })
  const [showPlan, setShowPlan] = useState(false)

  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const run = async (fn: () => Promise<unknown>, ok?: string) => {
    setMsg(null)
    try { await fn(); if (ok) setMsg({ kind: 'ok', text: ok }); reload() } catch (e) { setMsg({ kind: 'error', text: (e as Error).message }) }
  }
  const pct = data.total ? Math.round((data.done / data.total) * 100) : 0

  return (
    <div className="stack">
      {data.total ? (
        <div className="progress-line">
          <div className="progress"><span style={{ width: `${pct}%` }} /></div>
          <span className="small muted">{t('{done} of {total} lessons taught', { done: data.done, total: data.total })}</span>
        </div>
      ) : null}
      {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}
      {!data.units.length ? (
        <Empty icon="📖">{canManage ? t('No chapters yet. Add the first chapter below.') : t('The school has not added this syllabus yet.')}</Empty>
      ) : data.units.map(u => (
        <UnitCard key={u.id} school={school} unit={u} divisionId={divisionId} canManage={canManage} canTeach={canTeach} run={run} reload={reload} />
      ))}
      {canManage && gradeId ? (
        <>
          <Card>
            <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(() => post(`/schools/${school.id}/syllabus/units`, { gradeId, subjectId, title: unit.title, month: unit.month || undefined }), t('Chapter added')).then(() => setUnit({ title: '', month: '' })) }}>
              <h3>➕ {t('Add a chapter')}</h3>
              <p className="muted small">{t('The syllabus is shared by all sections of this class.')}</p>
              <div className="grid2">
                <Field label={t('Chapter name')}><Input value={unit.title} onChange={e => setUnit({ ...unit, title: e.target.value })} required maxLength={160} placeholder={t('e.g. Letters A to E')} /></Field>
                <Field label={t('Month (optional)')}><Input value={unit.month} onChange={e => setUnit({ ...unit, month: e.target.value })} maxLength={20} placeholder="June" /></Field>
              </div>
              <Button type="submit" variant="soft">{t('Add chapter')}</Button>
            </form>
          </Card>
          {data.total ? (showPlan
            ? <PlanCard school={school} gradeId={gradeId} subjectId={subjectId} onDone={text => { setShowPlan(false); setMsg({ kind: 'ok', text }); reload() }} />
            : <Button variant="soft" onClick={() => setShowPlan(true)}>📅 {t('Plan day-wise')}</Button>) : null}
        </>
      ) : null}
    </div>
  )
}

function UnitCard({ school, unit, divisionId, canManage, canTeach, run, reload }: {
  school: SchoolRef; unit: Unit; divisionId: number; canManage: boolean; canTeach: boolean
  run: (fn: () => Promise<unknown>, ok?: string) => Promise<void>; reload: () => void
}) {
  const [adding, setAdding] = useState(unit.lessons.length === 0 && canManage)
  const [lines, setLines] = useState('')
  const done = unit.lessons.filter(l => l.doneOn).length
  return (
    <Card className="stack">
      <div className="row">
        <div><h3>{unit.title}</h3><div className="muted small">{unit.month ? `${unit.month} · ` : ''}{t('{done} of {total} done', { done, total: unit.lessons.length })}</div></div>
        {canManage ? <button className="link-danger small" aria-label={t('Remove chapter')} onClick={() => { if (window.confirm(t('Remove this chapter and its lessons?'))) void run(() => del(`/schools/${school.id}/syllabus/units/${unit.id}`)) }}>✕</button> : null}
      </div>
      {unit.lessons.map((l, i) => (
        <LessonRow key={l.id} school={school} lesson={l} label={`${i + 1}. ${l.title}`} divisionId={divisionId} canTeach={canTeach} canManage={canManage} onChange={reload} />
      ))}
      {canManage ? (adding ? (
        <form className="stack" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(() => post(`/schools/${school.id}/syllabus/units/${unit.id}/lessons`, { lines: lines.split('\n') }), t('Lessons added')).then(() => { setLines(''); setAdding(false) }) }}>
          <Field label={t('Lessons, one per line')} hint={t('Add a Thinkle Tots lesson code or video link after |, e.g. Letter A | ABC-S1-E01')}>
            <Textarea rows={5} value={lines} onChange={e => setLines(e.target.value)} required placeholder={'Letter A | ABC-S1-E01\nLetter B | https://youtu.be/…\nRevision'} />
          </Field>
          <div className="row-start"><Button type="submit" variant="soft">{t('Add lessons')}</Button><Button type="button" variant="ghost" onClick={() => setAdding(false)}>{t('Cancel')}</Button></div>
        </form>
      ) : <button className="btn btn-ghost" onClick={() => setAdding(true)}>➕ {t('Add lessons')}</button>) : null}
    </Card>
  )
}

function LessonRow({ school, lesson, label, divisionId, canTeach, canManage, onChange }: {
  school: SchoolRef; lesson: Lesson; label: string; divisionId: number; canTeach: boolean; canManage: boolean; onChange: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [given, setGiven] = useState(false)
  const done = !!lesson.doneOn
  const tick = async () => {
    setBusy(true)
    try { await put(`/schools/${school.id}/syllabus/lessons/${lesson.id}/done`, { divisionId, done: !done }); onChange() } finally { setBusy(false) }
  }
  const giveHomework = async () => {
    await post(`/schools/${school.id}/homework`, {
      divisionIds: [divisionId], title: lesson.title, kind: lesson.lessonCode || lesson.linkUrl ? 'lesson' : 'revision',
      lessonCode: lesson.lessonCode ?? undefined, linkUrl: lesson.linkUrl ?? undefined, details: lesson.details ?? undefined,
    })
    setGiven(true)
  }
  const today = lesson.plannedOn === todayIso()
  return (
    <div className={`lesson ${done ? 'lesson-done' : ''}`}>
      {canTeach
        ? <button className={`tick ${done ? 'on' : ''}`} onClick={() => { void tick() }} disabled={busy} aria-label={done ? t('Mark not taught') : t('Mark taught')} aria-pressed={done}>{done ? '✓' : ''}</button>
        : <span className={`tick ${done ? 'on' : ''}`} aria-label={done ? t('Taught') : t('Not taught yet')}>{done ? '✓' : ''}</span>}
      <div className="lesson-body">
        <div>{label}</div>
        <div className="lesson-meta">
          {lesson.plannedOn ? <span className={`pill ${today ? 'pill-today' : ''}`}>📅 {today ? t('Today') : formatDate(lesson.plannedOn)}</span> : null}
          {lesson.lessonCode ? <span className="pill">🎬 {lesson.lessonCode}</span> : null}
          {lesson.linkUrl ? <a className="pill" href={lesson.linkUrl} target="_blank" rel="noopener noreferrer">▶ {t('Video')}</a> : null}
          {done ? <span className="pill pill-ok">{t('Taught')} {formatDate(lesson.doneOn)}</span> : null}
        </div>
      </div>
      {canTeach ? (
        <button className="btn btn-ghost small" onClick={() => { void giveHomework() }} disabled={given} title={t('Give as homework')}>{given ? `✓ ${t('Given')}` : `📚 ${t('Homework')}`}</button>
      ) : null}
      {canManage ? <button className="link-danger small" aria-label={t('Remove lesson')} onClick={() => { if (window.confirm(t('Remove this lesson?'))) void del(`/schools/${school.id}/syllabus/lessons/${lesson.id}`).then(onChange) }}>✕</button> : null}
    </div>
  )
}

function PlanCard({ school, gradeId, subjectId, onDone }: { school: SchoolRef; gradeId: number; subjectId: number; onDone: (text: string) => void }) {
  const [startOn, setStartOn] = useState(todayIso())
  const [days, setDays] = useState<number[]>([1, 2, 3, 4, 5, 6])
  const [perDay, setPerDay] = useState('1')
  const [skip, setSkip] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await post<{ lessons: number; from: string; to: string }>(`/schools/${school.id}/syllabus/plan`, {
        gradeId, subjectId, startOn, weekdays: days, perDay: Number(perDay) || 1,
        skip: skip.split(/[\s,]+/).filter(s => /^\d{4}-\d{2}-\d{2}$/.test(s)),
      })
      onDone(t('{n} lessons planned from {from} to {to}', { n: res.lessons, from: formatDate(res.from), to: formatDate(res.to) }))
    } catch (x) { setError((x as Error).message) } finally { setBusy(false) }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <h3>📅 {t('Plan day-wise')}</h3>
        <p className="muted small">{t('Gives every lesson a day, in order. You can change any day later.')}</p>
        <div className="grid2">
          <Field label={t('Start on')}><Input type="date" value={startOn} onChange={e => setStartOn(e.target.value)} required /></Field>
          <Field label={t('Lessons per day')}><Select value={perDay} onChange={e => setPerDay(e.target.value)}>{['1', '2', '3'].map(n => <option key={n}>{n}</option>)}</Select></Field>
        </div>
        <div className="chips">
          {DAYS.map(([n, label]) => <Chip key={n} active={days.includes(n)} onClick={() => setDays(days.includes(n) ? days.filter(d => d !== n) : [...days, n].sort())}>{t(label)}</Chip>)}
        </div>
        <Field label={t('Holidays to skip (optional)')} hint="2026-11-14, 2026-12-25"><Input value={skip} onChange={e => setSkip(e.target.value)} /></Field>
        {error ? <Alert>{error}</Alert> : null}
        <Button type="submit" busy={busy}>{t('Plan lessons')}</Button>
      </form>
    </Card>
  )
}
