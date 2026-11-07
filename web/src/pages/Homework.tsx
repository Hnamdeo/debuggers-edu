import { useState, type FormEvent } from 'react'
import { del, get, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { t } from '../lib/i18n'
import type { Homework as Hw, SchoolRef, Setup } from '../lib/types'
import { Alert, Button, Card, Chip, Empty, Field, Input, Loading, PageTitle, Select, Textarea, formatDate } from '../ui/kit'

const KINDS: { kind: Hw['kind']; label: string; icon: string }[] = [
  { kind: 'homework', label: 'Homework', icon: '📚' },
  { kind: 'worksheet', label: 'Worksheet', icon: '📝' },
  { kind: 'revision', label: 'Revision', icon: '🔁' },
  { kind: 'lesson', label: 'Video lesson', icon: '🎬' },
]

export function HomeworkPage({ school }: { school: SchoolRef }) {
  const canGive = school.roles.some(r => ['admin', 'principal', 'academic_head', 'teacher'].includes(r))
  const { data, error, loading, reload } = useApi(() => get<{ homework: Hw[] }>(`/schools/${school.id}/homework`), [school.id])
  const [giving, setGiving] = useState(false)

  return (
    <div className="stack">
      <PageTitle title={t('Homework')} sub={canGive ? undefined : 'Homework and worksheets from your child’s teachers'} />
      {canGive && !giving ? <Button onClick={() => setGiving(true)}>➕ {t('Give homework')}</Button> : null}
      {giving ? <GiveHomework school={school} onDone={() => { setGiving(false); reload() }} /> : null}
      {loading ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.homework.length ? <Empty icon="📚">No homework yet.</Empty> : (
        <div className="list">
          {data.homework.map(h => {
            const k = KINDS.find(x => x.kind === h.kind)
            return (
              <Card key={h.id}>
                <div className="row">
                  <span className="pill">{k?.icon} {t(k?.label ?? h.kind)}</span>
                  <span className="muted small">{h.division}{h.subject ? ` · ${h.subject}` : ''}</span>
                </div>
                <h3>{h.title}</h3>
                {h.details ? <p className="pre">{h.details}</p> : null}
                {h.linkUrl ? <p><a href={h.linkUrl} target="_blank" rel="noopener noreferrer">▶ Open {h.kind === 'lesson' ? 'video' : 'link'}</a></p> : null}
                <div className="row muted small">
                  <span>{h.teacher ?? ''} · {formatDate(h.createdAt)}{h.lessonCode ? ` · ${h.lessonCode}` : ''}</span>
                  {h.dueOn ? <span>{t('Due on')}: <b>{formatDate(h.dueOn)}</b></span> : null}
                </div>
                {h.mine ? <button className="link-danger small" onClick={() => { void del(`/schools/${school.id}/homework/${h.id}`).then(reload) }}>{t('Delete')}</button> : null}
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}

function GiveHomework({ school, onDone }: { school: SchoolRef; onDone: () => void }) {
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const [f, setF] = useState({ kind: 'homework' as Hw['kind'], divisionIds: [] as number[], subjectId: '', title: '', details: '', linkUrl: '', dueOn: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await post(`/schools/${school.id}/homework`, { ...f, subjectId: f.subjectId || undefined, linkUrl: f.linkUrl || undefined, dueOn: f.dueOn || undefined })
      onDone()
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }
  const toggle = (id: number) => setF(p => ({ ...p, divisionIds: p.divisionIds.includes(id) ? p.divisionIds.filter(x => x !== id) : [...p.divisionIds, id] }))

  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <div className="chips">{KINDS.map(k => <Chip key={k.kind} active={f.kind === k.kind} onClick={() => setF({ ...f, kind: k.kind })}>{k.icon} {t(k.label)}</Chip>)}</div>
        <Field label={t('Class')} hint="Tap one or more">
          <div className="chips">{setup?.divisions.map(d => <Chip key={d.id} active={f.divisionIds.includes(d.id)} onClick={() => toggle(d.id)}>{d.label}</Chip>) ?? <Loading />}</div>
        </Field>
        <Field label={t('Subject')}>
          <Select value={f.subjectId} onChange={e => setF({ ...f, subjectId: e.target.value })}>
            <option value="">—</option>
            {setup?.subjects.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
        </Field>
        <Field label={t('Title')}><Input value={f.title} onChange={e => setF({ ...f, title: e.target.value })} required maxLength={160} placeholder="Write letters A to E, 5 times" /></Field>
        <Field label={t('Details')}><Textarea value={f.details} onChange={e => setF({ ...f, details: e.target.value })} maxLength={5000} /></Field>
        <Field label={f.kind === 'lesson' ? 'Video link (YouTube)' : 'Link (optional)'}><Input type="url" value={f.linkUrl} onChange={e => setF({ ...f, linkUrl: e.target.value })} placeholder="https://" /></Field>
        <Field label={t('Due on')}><Input type="date" value={f.dueOn} onChange={e => setF({ ...f, dueOn: e.target.value })} /></Field>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end">
          <Button type="button" variant="ghost" onClick={onDone}>{t('Cancel')}</Button>
          <Button type="submit" busy={busy} disabled={!f.divisionIds.length}>{t('Send')}</Button>
        </div>
      </form>
    </Card>
  )
}
