import { useState, type FormEvent } from 'react'
import { get, post, del } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import { whatsappLink } from '../lib/whatsapp'
import type { FeeAccount, FeePayment, FeeStructure, FeeStudentRow, FeeSummary, SchoolRef } from '../lib/types'
import { A, Alert, Button, Card, Chip, Empty, Field, Input, LinkButton, Loading, PageTitle, formatDate, todayIso } from '../ui/kit'

export const rupees = (n: number) => '₹' + Math.abs(n).toLocaleString('en-IN') + (n < 0 ? ' off' : '')
const MODES: { mode: FeePayment['mode']; label: string; icon: string }[] = [
  { mode: 'cash', label: 'Cash', icon: '💵' },
  { mode: 'upi', label: 'UPI', icon: '📱' },
  { mode: 'cheque', label: 'Cheque', icon: '🧾' },
  { mode: 'bank', label: 'Bank transfer', icon: '🏦' },
  { mode: 'card', label: 'Card', icon: '💳' },
]
const fullDate = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })

/** Fees home: what came in today, who owes, find a child and take a payment. */
export function FeesPage({ school }: { school: SchoolRef }) {
  const [tab, setTab] = useState<'due' | 'pending' | 'all' | 'today'>('due')
  const [q, setQ] = useState('')
  const { data: sum } = useApi(() => get<FeeSummary>(`/schools/${school.id}/fees/summary`), [school.id])
  const list = useApi(() => tab === 'today'
    ? Promise.resolve(null)
    : get<{ students: FeeStudentRow[] }>(`/schools/${school.id}/fees/students`, { only: tab === 'all' ? undefined : tab, q: q.trim() || undefined }), [school.id, tab, q])
  const today = useApi(() => tab === 'today' ? get<{ payments: (FeePayment & { student: string; studentId: number })[]; total: number; byMode: Record<string, number> }>(`/schools/${school.id}/fees/payments`) : Promise.resolve(null), [school.id, tab])

  return (
    <div className="stack">
      <PageTitle title={t('Fees')} sub={t('Take fees, give receipts, see who has not paid')} />
      {sum && !sum.hasStructure ? (
        <Card className="stack">
          <b>👋 {t('First, set the fees for each class')}</b>
          <span className="muted">{t('For example Tuition fee ₹3,000 every 3 months. It takes a minute.')}</span>
          <LinkButton to={`/${school.slug}/fees/setup`} variant="primary">{t('Set class fees')}</LinkButton>
        </Card>
      ) : null}
      {sum ? (
        <div className="stats">
          <button className="stat stat-ok" onClick={() => setTab('today')}><span className="stat-value">{rupees(sum.today)}</span><span className="stat-label">{t('Collected today')}</span></button>
          <div className="stat"><span className="stat-value">{rupees(sum.month)}</span><span className="stat-label">{t('This month')}</span></div>
          <button className="stat stat-warn" onClick={() => setTab('due')}><span className="stat-value">{sum.overdueCount}</span><span className="stat-label">{t('Children with fees due')}</span></button>
          <button className="stat" onClick={() => setTab('pending')}><span className="stat-value">{rupees(sum.balance)}</span><span className="stat-label">{t('Still to come this year')}</span></button>
        </div>
      ) : null}
      <div className="row-start btn-wrap">
        <Input placeholder={t('Search child by name or admission no.')} value={q} onChange={e => { setQ(e.target.value); if (tab === 'today') setTab('all') }} />
        <LinkButton to={`/${school.slug}/fees/setup`}>⚙️ {t('Class fees')}</LinkButton>
      </div>
      <div className="chips scroll-x">
        <Chip active={tab === 'due'} onClick={() => setTab('due')}>⏰ {t('Due now')}</Chip>
        <Chip active={tab === 'pending'} onClick={() => setTab('pending')}>{t('Any balance')}</Chip>
        <Chip active={tab === 'all'} onClick={() => setTab('all')}>{t('All children')}</Chip>
        <Chip active={tab === 'today'} onClick={() => setTab('today')}>🧾 {t('Today’s receipts')}</Chip>
      </div>
      {tab === 'today' ? (
        today.loading ? <Loading /> : !today.data?.payments.length ? <Empty icon="🧾">{t('No payments today yet.')}</Empty> : (
          <div className="list">
            <Card><div className="row"><b>{t('Total')}: {rupees(today.data.total)}</b><span className="muted small">{Object.entries(today.data.byMode).map(([m, v]) => `${t(MODES.find(x => x.mode === m)?.label ?? m)} ${rupees(v)}`).join(' · ')}</span></div></Card>
            {today.data.payments.map(p => (
              <Card key={p.id} onClick={() => navigate(`/${school.slug}/fees/receipt/${p.id}`)}>
                <div className="row"><b>{p.student}</b><b className={p.cancelled ? 'strike muted' : ''}>{rupees(p.amount)}</b></div>
                <div className="row muted small"><span>{p.receiptNo} · {t(MODES.find(x => x.mode === p.mode)?.label ?? p.mode)}</span><span>{p.cancelled ? t('Cancelled') : p.receivedBy ?? ''}</span></div>
              </Card>
            ))}
          </div>
        )
      ) : list.loading ? <Loading /> : list.error ? <Alert>{list.error}</Alert> : !list.data?.students.length ? (
        <Empty icon="🎉">{tab === 'due' ? t('Nobody has fees due right now.') : t('No children found.')}</Empty>
      ) : (
        <div className="list">
          {list.data.students.map(s => (
            <Card key={s.studentId} onClick={() => navigate(`/${school.slug}/fees/student/${s.studentId}`)}>
              <div className="row">
                <div className="stack-xs"><b>{s.name}</b><span className="muted small">{s.class} · {s.admissionNo}</span></div>
                <div className="stack-xs right">
                  {s.overdue > 0 ? <span className="pill pill-warn">{t('Due')} {rupees(s.overdue)}</span> : s.balance > 0 ? <span className="pill">{t('Balance')} {rupees(s.balance)}</span> : <span className="pill pill-ok">✓ {t('Paid')}</span>}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

/** What each class pays this year. One form adds a fee (or instalments) to many classes at once. */
export function FeeSetupPage({ school }: { school: SchoolRef }) {
  const { data, error, loading, reload } = useApi(() => get<FeeStructure>(`/schools/${school.id}/fees/structure`), [school.id])
  const [adding, setAdding] = useState(false)
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  return (
    <div className="stack">
      <PageTitle title={t('Class fees')} sub={t('What each class pays this year')} back={`/${school.slug}/fees`} />
      {adding ? <AddFee school={school} data={data} onDone={() => { setAdding(false); reload() }} onCancel={() => setAdding(false)} /> : <Button onClick={() => setAdding(true)}>➕ {t('Add a fee')}</Button>}
      {data.grades.map(g => (
        <Card key={g.id} className="stack-sm">
          <div className="row"><b>{g.name}</b><b>{rupees(g.total)}</b></div>
          {g.items.length ? g.items.map(i => (
            <div key={i.id} className="row small">
              <span>{i.head}</span>
              <span className="row-end"><span className="muted">{t('Due')} {fullDate(i.dueOn)}</span><b>{rupees(i.amount)}</b>
                <button className="link-danger" aria-label={t('Delete')} onClick={() => { void del(`/schools/${school.id}/fees/items/${i.id}`).then(reload) }}>✕</button></span>
            </div>
          )) : <span className="muted small">{t('No fees set yet')}</span>}
        </Card>
      ))}
    </div>
  )
}

function AddFee({ school, data, onDone, onCancel }: { school: SchoolRef; data: FeeStructure; onDone: () => void; onCancel: () => void }) {
  const [f, setF] = useState({ gradeIds: [] as number[], head: 'Tuition fee', amount: '', dueOn: todayIso(), instalments: 1 })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const toggle = (id: number) => setF(p => ({ ...p, gradeIds: p.gradeIds.includes(id) ? p.gradeIds.filter(x => x !== id) : [...p.gradeIds, id] }))
  const all = f.gradeIds.length === data.grades.length
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      await post(`/schools/${school.id}/fees/items`, { ...f, amount: Number(f.amount), everyMonths: 12 / f.instalments })
      onDone()
    } catch (err) { setError((err as Error).message) } finally { setBusy(false) }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <Field label={t('Classes')} hint={t('Tap one or more')}>
          <div className="chips">
            <Chip active={all} onClick={() => setF({ ...f, gradeIds: all ? [] : data.grades.map(g => g.id) })}>{t('All classes')}</Chip>
            {data.grades.map(g => <Chip key={g.id} active={f.gradeIds.includes(g.id)} onClick={() => toggle(g.id)}>{g.name}</Chip>)}
          </div>
        </Field>
        <Field label={t('Fee name')}>
          <Input list="fee-heads" value={f.head} onChange={e => setF({ ...f, head: e.target.value })} required />
          <datalist id="fee-heads">{data.heads.map(h => <option key={h} value={h} />)}</datalist>
        </Field>
        <Field label={t('How is it paid?')}>
          <div className="chips">
            {[[1, 'Once'], [2, '2 parts (every 6 months)'], [4, '4 parts (every 3 months)'], [12, 'Monthly']].map(([n, label]) => (
              <Chip key={n} active={f.instalments === n} onClick={() => setF({ ...f, instalments: n as number })}>{t(label as string)}</Chip>
            ))}
          </div>
        </Field>
        <div className="grid2">
          <Field label={f.instalments > 1 ? t('Amount for each part (₹)') : t('Amount (₹)')}><Input inputMode="numeric" value={f.amount} onChange={e => setF({ ...f, amount: e.target.value.replace(/\D/g, '') })} required /></Field>
          <Field label={f.instalments > 1 ? t('First due date') : t('Due date')}><Input type="date" value={f.dueOn} onChange={e => setF({ ...f, dueOn: e.target.value })} required /></Field>
        </div>
        {f.amount && f.instalments > 1 ? <span className="muted small">{t('Year total per child')}: <b>{rupees(Number(f.amount) * f.instalments)}</b></span> : null}
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end"><Button type="button" variant="ghost" onClick={onCancel}>{t('Cancel')}</Button><Button busy={busy} disabled={!f.gradeIds.length}>{t('Save')}</Button></div>
      </form>
    </Card>
  )
}

/** One child's fees: what is due, take a payment, receipts, concessions. Parents see it read-only. */
export function FeeAccountPage({ school, studentId }: { school: SchoolRef; studentId: number }) {
  const staff = school.roles.some(r => ['admin', 'principal', 'accounts'].includes(r))
  const { data, error, loading, reload } = useApi(() => get<FeeAccount>(`/schools/${school.id}/fees/students/${studentId}`), [school.id, studentId])
  const [paying, setPaying] = useState(false)
  const [concession, setConcession] = useState(false)
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const statusPill = (s: FeeAccount['lines'][number]['status']) => ({
    paid: <span className="pill pill-ok">✓ {t('Paid')}</span>,
    part: <span className="pill">{t('Part paid')}</span>,
    overdue: <span className="pill pill-warn">{t('Due')}</span>,
    upcoming: <span className="pill">{t('Upcoming')}</span>,
    concession: <span className="pill pill-ok">{t('Concession')}</span>,
  })[s]
  return (
    <div className="stack">
      <PageTitle title={data.student.name} sub={`${data.student.class ?? ''} · ${t('Adm. no.')} ${data.student.admissionNo}`} back={staff ? `/${school.slug}/fees` : `/${school.slug}/home`} />
      <div className="stats">
        <div className={`stat ${data.overdue > 0 ? 'stat-warn' : 'stat-ok'}`}><span className="stat-value">{rupees(data.overdue)}</span><span className="stat-label">{t('Due now')}</span></div>
        <div className="stat"><span className="stat-value">{rupees(data.balance)}</span><span className="stat-label">{t('Balance for the year')}</span></div>
        <div className="stat stat-ok"><span className="stat-value">{rupees(data.paid)}</span><span className="stat-label">{t('Paid')}</span></div>
        <div className="stat"><span className="stat-value">{rupees(data.total)}</span><span className="stat-label">{t('Year total')}</span></div>
      </div>
      {staff && !paying ? <Button onClick={() => setPaying(true)}>💵 {t('Take payment')}</Button> : null}
      {paying ? <TakePayment school={school} account={data} onDone={id => navigate(`/${school.slug}/fees/receipt/${id}`)} onCancel={() => setPaying(false)} /> : null}
      <Card className="stack-sm">
        <b>{t('Fees this year')}</b>
        {data.lines.length ? data.lines.map(l => (
          <div key={`${l.kind}${l.id}`} className="row small">
            <span className="stack-xs"><span>{l.label}</span><span className="muted">{t('Due')} {fullDate(l.dueOn)}</span></span>
            <span className="row-end">{statusPill(l.status)}<b>{rupees(l.amount)}</b>
              {staff && l.kind === 'adjustment' ? <button className="link-danger" aria-label={t('Delete')} onClick={() => { void del(`/schools/${school.id}/fees/adjustments/${l.id}`).then(reload) }}>✕</button> : null}</span>
          </div>
        )) : <span className="muted small">{t('No fees set for this class yet.')}</span>}
        {staff ? (concession ? <AddAdjustment school={school} studentId={studentId} onDone={() => { setConcession(false); reload() }} /> : <button className="link small" onClick={() => setConcession(true)}>➕ {t('Add concession or extra charge')}</button>) : null}
      </Card>
      <Card className="stack-sm">
        <b>{t('Payments')}</b>
        {data.payments.length ? data.payments.map(p => (
          <A key={p.id} to={`/${school.slug}/fees/receipt/${p.id}`} className="row small plain-link">
            <span className="stack-xs"><span>{p.receiptNo} · {t(MODES.find(m => m.mode === p.mode)?.label ?? p.mode)}</span><span className="muted">{fullDate(p.paidOn)}{p.cancelled ? ` · ${t('Cancelled')}` : ''}</span></span>
            <b className={p.cancelled ? 'strike muted' : ''}>{rupees(p.amount)}</b>
          </A>
        )) : <span className="muted small">{t('No payments yet.')}</span>}
      </Card>
    </div>
  )
}

function TakePayment({ school, account, onDone, onCancel }: { school: SchoolRef; account: FeeAccount; onDone: (paymentId: number) => void; onCancel: () => void }) {
  const suggested = account.overdue > 0 ? account.overdue : account.next ? account.next.amount - (account.next.paid ?? 0) : account.balance
  const [f, setF] = useState({ amount: suggested > 0 ? String(suggested) : '', mode: 'cash' as FeePayment['mode'], reference: '', paidOn: todayIso(), note: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const needsRef = ['upi', 'cheque', 'bank'].includes(f.mode)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      const res = await post<{ payment: FeePayment }>(`/schools/${school.id}/fees/students/${account.student.id}/payments`, { ...f, amount: Number(f.amount), reference: f.reference || undefined, note: f.note || undefined })
      onDone(res.payment.id)
    } catch (err) { setError((err as Error).message); setBusy(false) }
  }
  return (
    <Card>
      <form className="stack" onSubmit={submit}>
        <Field label={t('Amount (₹)')}><Input inputMode="numeric" autoFocus value={f.amount} onChange={e => setF({ ...f, amount: e.target.value.replace(/\D/g, '') })} required /></Field>
        <div className="chips">
          {account.overdue > 0 ? <Chip active={f.amount === String(account.overdue)} onClick={() => setF({ ...f, amount: String(account.overdue) })}>{t('Due now')} {rupees(account.overdue)}</Chip> : null}
          {account.balance > 0 ? <Chip active={f.amount === String(account.balance)} onClick={() => setF({ ...f, amount: String(account.balance) })}>{t('Full year')} {rupees(account.balance)}</Chip> : null}
        </div>
        <Field label={t('Paid by')}>
          <div className="chips">{MODES.map(m => <Chip key={m.mode} active={f.mode === m.mode} onClick={() => setF({ ...f, mode: m.mode })}>{m.icon} {t(m.label)}</Chip>)}</div>
        </Field>
        {needsRef ? <Field label={f.mode === 'cheque' ? t('Cheque number') : t('Transaction ID')}><Input value={f.reference} onChange={e => setF({ ...f, reference: e.target.value })} required /></Field> : null}
        <div className="grid2">
          <Field label={t('Date')}><Input type="date" max={todayIso()} value={f.paidOn} onChange={e => setF({ ...f, paidOn: e.target.value })} /></Field>
          <Field label={t('Note (optional)')}><Input value={f.note} onChange={e => setF({ ...f, note: e.target.value })} /></Field>
        </div>
        {error ? <Alert>{error}</Alert> : null}
        <div className="row-end"><Button type="button" variant="ghost" onClick={onCancel}>{t('Cancel')}</Button><Button busy={busy}>{t('Save and give receipt')}</Button></div>
      </form>
    </Card>
  )
}

function AddAdjustment({ school, studentId, onDone }: { school: SchoolRef; studentId: number; onDone: () => void }) {
  const [kind, setKind] = useState<'off' | 'extra'>('off')
  const [f, setF] = useState({ label: 'Sibling concession', amount: '' })
  const [error, setError] = useState<string | null>(null)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await post(`/schools/${school.id}/fees/students/${studentId}/adjustments`, { label: f.label, amount: (kind === 'off' ? '-' : '') + f.amount })
      onDone()
    } catch (err) { setError((err as Error).message) }
  }
  return (
    <form className="stack-sm" onSubmit={submit}>
      <div className="chips">
        <Chip active={kind === 'off'} onClick={() => { setKind('off'); setF({ ...f, label: 'Sibling concession' }) }}>{t('Concession (less)')}</Chip>
        <Chip active={kind === 'extra'} onClick={() => { setKind('extra'); setF({ ...f, label: 'Transport' }) }}>{t('Extra charge (more)')}</Chip>
      </div>
      <div className="grid2">
        <Input value={f.label} onChange={e => setF({ ...f, label: e.target.value })} required />
        <Input inputMode="numeric" placeholder="₹" value={f.amount} onChange={e => setF({ ...f, amount: e.target.value.replace(/\D/g, '') })} required />
      </div>
      {error ? <Alert>{error}</Alert> : null}
      <Button>{t('Add')}</Button>
    </form>
  )
}

/** A receipt to print or send on WhatsApp. */
export function FeeReceiptPage({ school, paymentId }: { school: SchoolRef; paymentId: number }) {
  const canCancel = school.roles.some(r => ['admin', 'principal'].includes(r))
  const { data, error, loading, reload } = useApi(() => get<{ payment: FeePayment & { student: string; studentId: number; admissionNo: string; class: string | null; year: string }; school: { name: string; city: string | null; phone: string | null } }>(`/schools/${school.id}/fees/payments/${paymentId}`), [school.id, paymentId])
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const p = data.payment
  const mode = MODES.find(m => m.mode === p.mode)?.label ?? p.mode
  const text = `${data.school.name}\nFee receipt ${p.receiptNo}\n${p.student} (${p.class ?? ''}), Adm. no. ${p.admissionNo}\nReceived ${rupees(p.amount)} by ${mode} on ${fullDate(p.paidOn)}.\nThank you.`
  return (
    <div className="stack">
      <PageTitle title={t('Receipt')} back={`/${school.slug}/fees/student/${p.studentId}`} />
      <Card className="stack receipt">
        <div className="center stack-xs"><b className="receipt-school">{data.school.name}</b><span className="muted small">{[data.school.city, data.school.phone].filter(Boolean).join(' · ')}</span></div>
        <div className="row"><b>{t('Fee receipt')}</b><span>{p.receiptNo}</span></div>
        {p.cancelled ? <Alert>{t('This receipt was cancelled')}{p.cancelReason ? `: ${p.cancelReason}` : ''}</Alert> : null}
        <div className="facts">
          <div><span className="muted small">{t('Student')}</span><b>{p.student}</b></div>
          <div><span className="muted small">{t('Class')}</span><b>{p.class ?? '—'}</b></div>
          <div><span className="muted small">{t('Adm. no.')}</span><b>{p.admissionNo}</b></div>
          <div><span className="muted small">{t('School year')}</span><b>{p.year}</b></div>
          <div><span className="muted small">{t('Date')}</span><b>{fullDate(p.paidOn)}</b></div>
          <div><span className="muted small">{t('Paid by')}</span><b>{t(mode)}{p.reference ? ` (${p.reference})` : ''}</b></div>
        </div>
        <div className="row receipt-total"><b>{t('Amount received')}</b><b className={p.cancelled ? 'strike' : ''}>{rupees(p.amount)}</b></div>
        {p.note ? <span className="muted small">{p.note}</span> : null}
        <span className="muted small">{t('Received by')} {p.receivedBy ?? '—'}</span>
      </Card>
      <div className="row-end btn-wrap no-print">
        <Button variant="soft" onClick={() => window.print()}>🖨️ {t('Print')}</Button>
        <a className="btn btn-soft" href={whatsappLink(null, text)} target="_blank" rel="noopener noreferrer">💬 {t('Send on WhatsApp')}</a>
      </div>
      {canCancel && !p.cancelled ? (
        cancelling ? (
          <Card className="stack-sm no-print">
            <Field label={t('Why cancel this receipt?')}><Input value={reason} onChange={e => setReason(e.target.value)} /></Field>
            <Button variant="danger" disabled={!reason.trim()} onClick={() => { void post(`/schools/${school.id}/fees/payments/${p.id}/cancel`, { reason }).then(() => { setCancelling(false); reload() }) }}>{t('Cancel receipt')}</Button>
          </Card>
        ) : <button className="link-danger small no-print" onClick={() => setCancelling(true)}>{t('Cancel this receipt')}</button>
      ) : null}
    </div>
  )
}

/** Small fees card for a parent's child page. */
export function ChildFeesCard({ school, studentId }: { school: SchoolRef; studentId: number }) {
  const { data } = useApi(() => get<FeeAccount>(`/schools/${school.id}/fees/students/${studentId}`).catch(() => null), [school.id, studentId])
  if (!data || !data.lines.length) return null
  return (
    <Card className="stack-sm" onClick={() => navigate(`/${school.slug}/fees/student/${studentId}`)}>
      <div className="row"><b>💰 {t('Fees')}</b>{data.overdue > 0 ? <span className="pill pill-warn">{t('Due')} {rupees(data.overdue)}</span> : <span className="pill pill-ok">✓ {t('Nothing due now')}</span>}</div>
      {data.next ? <span className="muted small">{t('Next')}: {data.next.label} · {rupees(data.next.amount - (data.next.paid ?? 0))} · {t('Due')} {formatDate(data.next.dueOn)}</span> : null}
      <span className="muted small">{t('Paid this year')}: {rupees(data.paid)} / {rupees(data.total)}</span>
    </Card>
  )
}

