import { useState } from 'react'
import { get } from '../lib/api'
import { useApi } from '../lib/useApi'
import { t } from '../lib/i18n'
import type { Guardian, Homework, Mark, SchoolRef, Student } from '../lib/types'
import { ChildFeesCard } from './Fees'
import { Alert, Card, Empty, LinkButton, Loading, PageTitle, formatDate, todayIso } from '../ui/kit'

interface Month { month: string; days: { date: string; status: Mark }[]; counts: Record<Mark, number>; presentPercent: number | null }

/** One child: this month's attendance as a calendar, and their class homework. */
export function ChildPage({ school, studentId }: { school: SchoolRef; studentId: number }) {
  const [month, setMonth] = useState(todayIso().slice(0, 7))
  const { data: child, error } = useApi(() => get<{ student: Student; guardians: Guardian[] }>(`/schools/${school.id}/students/${studentId}`), [school.id, studentId])
  const { data: att } = useApi(() => get<Month>(`/schools/${school.id}/attendance/students/${studentId}`, { month }), [school.id, studentId, month])
  const { data: hw } = useApi(async () => child?.student.divisionId
    ? get<{ homework: Homework[] }>(`/schools/${school.id}/homework`, { divisionId: child.student.divisionId })
    : { homework: [] }, [school.id, child?.student.divisionId])

  if (error) return <Alert>{error}</Alert>
  if (!child) return <Loading />
  const s = child.student
  const shift = (n: number) => {
    const [y, m] = month.split('-').map(Number)
    const d = new Date(Date.UTC(y, m - 1 + n, 1))
    setMonth(d.toISOString().slice(0, 7))
  }

  return (
    <div className="stack-lg">
      <PageTitle title={s.name} sub={s.division ?? ''} back={`/${school.slug}/home`} />
      {s.divisionId ? <LinkButton to={`/${school.slug}/syllabus?division=${s.divisionId}`}>📖 {t('Syllabus and lessons')}</LinkButton> : null}
      <ChildFeesCard school={school} studentId={studentId} />
      <Card className="stack">
        <div className="row">
          <button className="btn btn-ghost" onClick={() => shift(-1)} aria-label="Previous month">‹</button>
          <b>{new Date(month + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}</b>
          <button className="btn btn-ghost" onClick={() => shift(1)} disabled={month >= todayIso().slice(0, 7)} aria-label="Next month">›</button>
        </div>
        {att ? (
          <>
            <Calendar month={month} days={att.days} />
            <div className="row-start wrap small">
              <span className="pill pill-ok">{t('Present')} {att.counts.present + att.counts.late}</span>
              <span className="pill pill-warn">{t('Absent')} {att.counts.absent}</span>
              {att.counts.leave ? <span className="pill">{t('Leave')} {att.counts.leave}</span> : null}
              {att.presentPercent !== null ? <b>{att.presentPercent}%</b> : null}
            </div>
          </>
        ) : <Loading />}
      </Card>
      <section className="stack">
        <h2 className="section-title">{t('Homework')}</h2>
        {!hw ? <Loading /> : hw.homework.length === 0 ? <Empty icon="📚">{t('No homework this week')}</Empty> : hw.homework.map(h => (
          <Card key={h.id}>
            <div className="row"><b>{h.title}</b><span className="muted small">{formatDate(h.createdAt)}</span></div>
            {h.details ? <p className="pre">{h.details}</p> : null}
            {h.linkUrl ? <a href={h.linkUrl} target="_blank" rel="noopener noreferrer">▶ Open</a> : null}
            <div className="muted small">{h.subject ?? ''}{h.dueOn ? ` · ${t('Due on')} ${formatDate(h.dueOn)}` : ''}</div>
          </Card>
        ))}
      </section>
    </div>
  )
}

function Calendar({ month, days }: { month: string; days: { date: string; status: Mark }[] }) {
  const [y, m] = month.split('-').map(Number)
  const first = new Date(Date.UTC(y, m - 1, 1)).getUTCDay()
  const count = new Date(Date.UTC(y, m, 0)).getUTCDate()
  const byDate = Object.fromEntries(days.map(d => [d.date, d.status]))
  const cells = [...Array(first).fill(null), ...Array.from({ length: count }, (_, i) => i + 1)]
  return (
    <div className="cal">
      {['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => <span key={i} className="cal-head">{d}</span>)}
      {cells.map((d, i) => {
        if (d === null) return <span key={i} />
        const iso = `${month}-${String(d).padStart(2, '0')}`
        const st = byDate[iso]
        return <span key={i} className={`cal-day ${st ? `cal-${st}` : ''}`} title={st ?? ''}>{d}</span>
      })}
    </div>
  )
}
