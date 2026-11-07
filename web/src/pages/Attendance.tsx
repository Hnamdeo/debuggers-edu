import { useEffect, useState } from 'react'
import { get, put } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import type { Home, Mark, SchoolRef } from '../lib/types'
import { Alert, Button, Card, Empty, Input, Loading, PageTitle, todayIso } from '../ui/kit'

interface Summary { classes: { divisionId: number; label: string; teacher: string | null; total: number; marked: boolean; present: number; absent: number }[]; classesMarked: number; classesToMark: number; presentPercent: number | null }

/** Pick a class: leaders see the whole school with what is marked; teachers see their own classes. */
export function AttendanceClasses({ school }: { school: SchoolRef }) {
  const leader = school.roles.some(r => ['admin', 'principal', 'academic_head'].includes(r))
  const { data, error, loading } = useApi(async () => {
    if (leader) return (await get<Summary>(`/schools/${school.id}/attendance/summary`)).classes
    const home = await get<Home>(`/schools/${school.id}/home`)
    return (home.my_classes ?? []).map(c => ({ divisionId: c.id, label: c.label, teacher: null, total: c.students, marked: c.markedToday, present: 0, absent: 0 }))
  }, [school.id, leader])

  return (
    <div className="stack">
      <PageTitle title={t('Attendance')} sub={t('Choose a class')} />
      {loading ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.length ? (
        <Empty icon="🧑‍🏫">{leader ? 'No classes yet. Add them in School setup.' : 'You are not a class teacher yet. Ask the principal to assign your class.'}</Empty>
      ) : (
        <div className="list">
          {data.map(c => (
            <Card key={c.divisionId} className="row" onClick={() => navigate(`/${school.slug}/attendance/${c.divisionId}`)}>
              <div>
                <b>{c.label}</b>
                <div className="muted small">{c.teacher ? `${c.teacher} · ` : ''}{c.total} {t('Students').toLowerCase()}</div>
              </div>
              {c.total === 0 ? <span className="muted small">—</span> : c.marked
                ? <span className="pill pill-ok">{leader ? `${c.present}/${c.total} ${t('present')}` : t('Marked')}</span>
                : <span className="pill pill-warn">{t('Not marked yet')}</span>}
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

interface Sheet { division: { id: number; label: string }; date: string; marked: boolean; canEdit: boolean; students: { id: number; name: string; rollNo: number | null; status: Mark | null }[] }

const NEXT: Record<Mark, Mark> = { present: 'absent', absent: 'present', late: 'present', leave: 'present' }

export function AttendanceSheet({ school, divisionId }: { school: SchoolRef; divisionId: number }) {
  const [date, setDate] = useState(todayIso())
  const { data, error, loading, reload } = useApi(() => get<Sheet>(`/schools/${school.id}/attendance/divisions/${divisionId}`, { date }), [school.id, divisionId, date])
  const [marks, setMarks] = useState<Record<number, Mark>>({})
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)

  useEffect(() => {
    if (!data) return
    setMarks(Object.fromEntries(data.students.map(s => [s.id, s.status ?? 'present'])))
  }, [data])

  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null

  const counts = Object.values(marks).reduce((acc, m) => ({ ...acc, [m]: (acc[m] ?? 0) + 1 }), {} as Record<string, number>)
  const save = async () => {
    setBusy(true)
    setMsg(null)
    try {
      await put(`/schools/${school.id}/attendance/divisions/${divisionId}`, {
        date,
        marks: Object.entries(marks).filter(([, m]) => m !== 'present').map(([id, status]) => ({ studentId: Number(id), status })),
      })
      setMsg({ kind: 'ok', text: t('Attendance saved') + ' ✅' })
      reload()
    } catch (e) {
      setMsg({ kind: 'error', text: (e as Error).message })
    } finally {
      setBusy(false)
    }
  }
  const setOne = (id: number, m: Mark) => { if (data.canEdit) setMarks(prev => ({ ...prev, [id]: m })) }

  return (
    <div className="stack sheet">
      <PageTitle title={`${t('Attendance')} · ${data.division.label}`} back={`/${school.slug}/attendance`} />
      <div className="row">
        <Input type="date" value={date} max={todayIso()} onChange={e => setDate(e.target.value || todayIso())} aria-label="Date" className="date" />
        <span className={`pill ${data.marked ? 'pill-ok' : 'pill-warn'}`}>{data.marked ? t('Marked') : t('Not marked yet')}</span>
      </div>
      {data.students.length === 0 ? <Empty icon="🎒">No children in this class yet.</Empty> : (
        <>
          {data.canEdit && !data.marked ? <p className="hint-banner">{t('Everyone is present. Tap a child who is absent.')}</p> : null}
          {!data.canEdit ? <Alert kind="info">You can view this class but not change it.</Alert> : null}
          <div className="att-list">
            {data.students.map(s => {
              const m = marks[s.id] ?? 'present'
              return (
                <div key={s.id} className={`att att-${m}`}>
                  <button className="att-main" onClick={() => setOne(s.id, NEXT[m])} disabled={!data.canEdit}>
                    <span className="att-roll">{s.rollNo ?? '·'}</span>
                    <span className="att-name">{s.name}</span>
                    <span className="att-status">{t(m[0].toUpperCase() + m.slice(1))}</span>
                  </button>
                  {data.canEdit ? (
                    <div className="att-more">
                      <button className={m === 'late' ? 'on' : ''} onClick={() => setOne(s.id, m === 'late' ? 'present' : 'late')}>{t('Late')}</button>
                      <button className={m === 'leave' ? 'on' : ''} onClick={() => setOne(s.id, m === 'leave' ? 'present' : 'leave')}>{t('Leave')}</button>
                    </div>
                  ) : null}
                </div>
              )
            })}
          </div>
          {msg ? <Alert kind={msg.kind}>{msg.text}</Alert> : null}
          {data.canEdit ? (
            <div className="savebar">
              <span className="savebar-counts">
                <b className="ok">{(counts.present ?? 0) + (counts.late ?? 0)}</b> {t('present')} · <b className="warn">{counts.absent ?? 0}</b> {t('absent')}
                {counts.leave ? <> · {counts.leave} {t('Leave').toLowerCase()}</> : null}
              </span>
              <Button busy={busy} onClick={save}>{t('Save attendance')}</Button>
            </div>
          ) : null}
        </>
      )}
    </div>
  )
}
