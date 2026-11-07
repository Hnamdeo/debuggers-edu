import { useState, type FormEvent } from 'react'
import { del, get, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { t } from '../lib/i18n'
import type { Notice, SchoolRef, Setup } from '../lib/types'
import { Alert, Button, Card, Chip, Empty, Field, Input, Loading, PageTitle, Select, Textarea, formatDate } from '../ui/kit'

const AUDIENCES = [
  { v: 'everyone', label: 'Everyone' },
  { v: 'parents', label: 'All parents' },
  { v: 'staff', label: 'All staff' },
  { v: 'division', label: 'One class' },
]

export function NoticesPage({ school }: { school: SchoolRef }) {
  const leader = school.roles.some(r => r === 'admin' || r === 'principal')
  const canSend = leader || school.roles.some(r => r === 'teacher' || r === 'academic_head')
  const { data, error, loading, reload } = useApi(() => get<{ notices: Notice[] }>(`/schools/${school.id}/notices`), [school.id])
  const [writing, setWriting] = useState(false)

  return (
    <div className="stack">
      <PageTitle title={t('Notices')} />
      {canSend && !writing ? <Button onClick={() => setWriting(true)}>📢 {t('Send a notice')}</Button> : null}
      {writing ? <WriteNotice school={school} leader={leader} onDone={() => { setWriting(false); reload() }} /> : null}
      {loading ? <Loading /> : error ? <Alert>{error}</Alert> : !data?.notices.length ? <Empty icon="📢">No notices yet.</Empty> : (
        <div className="list">
          {data.notices.map(n => (
            <Card key={n.id}>
              <div className="row"><b>{n.title}</b><span className="muted small">{formatDate(n.createdAt)}</span></div>
              {n.body ? <p className="pre">{n.body}</p> : null}
              <div className="row muted small">
                <span>{n.author ?? ''}{n.division ? ` · ${n.division}` : n.audience !== 'everyone' ? ` · ${n.audience}` : ''}</span>
                {n.mine || leader ? <button className="link-danger" onClick={() => { void del(`/schools/${school.id}/notices/${n.id}`).then(reload) }}>{t('Delete')}</button> : null}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function WriteNotice({ school, leader, onDone }: { school: SchoolRef; leader: boolean; onDone: () => void }) {
  const { data: setup } = useApi(() => get<Setup>(`/schools/${school.id}/setup`), [school.id])
  const [f, setF] = useState({ audience: leader ? 'everyone' : 'division', divisionId: '', title: '', body: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await post(`/schools/${school.id}/notices`, { ...f, divisionId: f.audience === 'division' ? Number(f.divisionId) : undefined })
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
        {leader ? <div className="chips">{AUDIENCES.map(a => <Chip key={a.v} active={f.audience === a.v} onClick={() => setF({ ...f, audience: a.v })}>{a.label}</Chip>)}</div> : null}
        {f.audience === 'division' ? (
          <Field label={t('Class')}>
            <Select value={f.divisionId} onChange={e => setF({ ...f, divisionId: e.target.value })} required>
              <option value="">{t('Choose a class')}</option>
              {setup?.divisions.map(d => <option key={d.id} value={d.id}>{d.label}</option>)}
            </Select>
          </Field>
        ) : null}
        <Field label={t('Title')}><Input value={f.title} onChange={e => setF({ ...f, title: e.target.value })} required maxLength={160} placeholder="School closed on Monday" /></Field>
        <Field label={t('Details')}><Textarea value={f.body} onChange={e => setF({ ...f, body: e.target.value })} maxLength={5000} /></Field>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end">
          <Button type="button" variant="ghost" onClick={onDone}>{t('Cancel')}</Button>
          <Button type="submit" busy={busy}>{t('Send')}</Button>
        </div>
      </form>
    </Card>
  )
}
