import { useState, type FormEvent } from 'react'
import { del, get, patch, post, put } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t } from '../lib/i18n'
import { whatsappLink } from '../lib/whatsapp'
import type { PayLine, Payslip, PayrollRun, PayrollRunRow, SalaryRow, SchoolRef } from '../lib/types'
import { Alert, Button, Card, Chip, Empty, Field, Input, Loading, PageTitle, todayIso } from '../ui/kit'
import { rupees } from './Fees'

const monthName = (m: string) => new Date(m + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
const thisMonth = () => todayIso().slice(0, 7)
const PAY_MODES = [['bank', '🏦 Bank'], ['cash', '💵 Cash'], ['upi', '📱 UPI'], ['cheque', '🧾 Cheque']] as const

/** Salary: payroll staff make the monthly sheet; everyone else sees their own payslips. */
export function PayrollPage({ school }: { school: SchoolRef }) {
  const manager = school.roles.some(r => ['admin', 'principal', 'accounts'].includes(r))
  const [tab, setTab] = useState<'sheets' | 'salaries' | 'mine'>(manager ? 'sheets' : 'mine')
  return (
    <div className="stack">
      <PageTitle title={t('Salary')} sub={manager ? t('Monthly salary sheet and payslips') : t('Your payslips')} />
      {manager ? (
        <div className="chips scroll-x">
          <Chip active={tab === 'sheets'} onClick={() => setTab('sheets')}>🗓️ {t('Salary sheets')}</Chip>
          <Chip active={tab === 'salaries'} onClick={() => setTab('salaries')}>🧑‍🏫 {t('Staff salaries')}</Chip>
          <Chip active={tab === 'mine'} onClick={() => setTab('mine')}>{t('My payslips')}</Chip>
        </div>
      ) : null}
      {tab === 'sheets' ? <Sheets school={school} onNeedSalaries={() => setTab('salaries')} /> : tab === 'salaries' ? <Salaries school={school} /> : <MyPayslips school={school} />}
    </div>
  )
}

function Sheets({ school, onNeedSalaries }: { school: SchoolRef; onNeedSalaries: () => void }) {
  const { data, error, loading } = useApi(() => get<{ runs: PayrollRunRow[] }>(`/schools/${school.id}/payroll/runs`), [school.id])
  const [month, setMonth] = useState(thisMonth())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const make = async () => {
    setBusy(true); setErr(null)
    try {
      const res = await post<PayrollRun>(`/schools/${school.id}/payroll/runs`, { month })
      navigate(`/${school.slug}/payroll/${res.run.id}`)
    } catch (e) { setErr((e as Error).message); setBusy(false) }
  }
  if (loading) return <Loading />
  if (error) return <Alert>{error}</Alert>
  const exists = data?.runs.some(r => r.month === month)
  return (
    <>
      <Card className="stack-sm">
        <b>{t('Make the salary sheet')}</b>
        <div className="row-start btn-wrap">
          <Input type="month" value={month} onChange={e => setMonth(e.target.value)} />
          <Button busy={busy} disabled={!month || exists} onClick={() => { void make() }}>{exists ? t('Already made') : t('Make sheet')}</Button>
        </div>
        {err ? <Alert>{err} {err.includes('salary') ? <button className="link" onClick={onNeedSalaries}>{t('Set salaries')}</button> : null}</Alert> : null}
      </Card>
      {!data?.runs.length ? <Empty icon="🗓️">{t('No salary sheets yet. Set staff salaries, then make this month’s sheet.')}</Empty> : (
        <div className="list">
          {data.runs.map(r => (
            <Card key={r.id} onClick={() => navigate(`/${school.slug}/payroll/${r.id}`)}>
              <div className="row">
                <div className="stack-xs"><b>{monthName(r.month)}</b><span className="muted small">{r.people} {t('people')} · {t('Net')} {rupees(r.net)}</span></div>
                {r.status === 'draft' ? <span className="pill pill-warn">{t('Draft')}</span> : r.paid === r.people ? <span className="pill pill-ok">✓ {t('Paid')}</span> : <span className="pill">{t('Final')} · {r.paid}/{r.people} {t('paid')}</span>}
              </div>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}

function Salaries({ school }: { school: SchoolRef }) {
  const { data, error, loading, reload } = useApi(() => get<{ staff: SalaryRow[]; totalGross: number; totalNet: number }>(`/schools/${school.id}/payroll/salaries`), [school.id])
  const [editing, setEditing] = useState<number | null>(null)
  if (loading && !data) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data?.staff.length) return <Empty icon="🧑‍🏫">{t('Add your staff first.')}</Empty>
  return (
    <div className="list">
      <Card><div className="row"><b>{t('Monthly total')}</b><span>{t('Gross')} <b>{rupees(data.totalGross)}</b> · {t('Net')} <b>{rupees(data.totalNet)}</b></span></div></Card>
      {data.staff.map(s => (
        <Card key={s.staffId} className="stack-sm">
          <div className="row" onClick={() => setEditing(editing === s.staffId ? null : s.staffId)} role="button">
            <div className="stack-xs"><b>{s.name}</b><span className="muted small">{s.designation ?? ''}</span></div>
            {s.set ? <span className="stack-xs right"><b>{rupees(s.net)}</b><span className="muted small">{t('Gross')} {rupees(s.gross)}</span></span> : <span className="pill pill-warn">{t('Set salary')}</span>}
          </div>
          {editing === s.staffId ? <SalaryForm school={school} row={s} onDone={() => { setEditing(null); reload() }} /> : null}
        </Card>
      ))}
    </div>
  )
}

function Lines({ label, lines, onChange, suggestions }: { label: string; lines: PayLine[]; onChange: (l: PayLine[]) => void; suggestions: string[] }) {
  const set = (i: number, v: Partial<PayLine>) => onChange(lines.map((l, j) => j === i ? { ...l, ...v } : l))
  return (
    <Field label={label}>
      <div className="stack-sm">
        {lines.map((l, i) => (
          <div key={i} className="row-start">
            <Input value={l.name} list={`${label}-names`} placeholder={t('Name')} onChange={e => set(i, { name: e.target.value })} />
            <Input inputMode="numeric" placeholder="₹" value={l.amount ? String(l.amount) : ''} onChange={e => set(i, { amount: Number(e.target.value.replace(/\D/g, '')) })} style={{ maxWidth: 120 }} />
            <button type="button" className="link-danger" aria-label={t('Delete')} onClick={() => onChange(lines.filter((_, j) => j !== i))}>✕</button>
          </div>
        ))}
        <datalist id={`${label}-names`}>{suggestions.map(s => <option key={s} value={s} />)}</datalist>
        <button type="button" className="link small" onClick={() => onChange([...lines, { name: '', amount: 0 }])}>➕ {t('Add line')}</button>
      </div>
    </Field>
  )
}

function SalaryForm({ school, row, onDone }: { school: SchoolRef; row: SalaryRow; onDone: () => void }) {
  const [f, setF] = useState({ ...row.salary, basic: row.salary.basic ? String(row.salary.basic) : '', bankName: row.salary.bankName ?? '', accountLast4: row.salary.accountLast4 ?? '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      await put(`/schools/${school.id}/payroll/salaries/${row.staffId}`, { ...f, basic: Number(f.basic || 0), bankName: f.bankName || undefined, accountLast4: f.accountLast4 || undefined })
      onDone()
    } catch (err) { setError((err as Error).message); setBusy(false) }
  }
  const basic = Number(f.basic || 0)
  const gross = basic + f.allowances.reduce((s, a) => s + (a.amount || 0), 0)
  const pf = f.pf ? Math.round(Math.min(basic, 15000) * 0.12) : 0
  const net = gross - pf - f.deductions.reduce((s, d) => s + (d.amount || 0), 0)
  return (
    <form className="stack" onSubmit={submit}>
      <Field label={t('Basic salary per month (₹)')}><Input inputMode="numeric" value={f.basic} onChange={e => setF({ ...f, basic: e.target.value.replace(/\D/g, '') })} required /></Field>
      <Lines label={t('Allowances')} lines={f.allowances} onChange={l => setF({ ...f, allowances: l })} suggestions={['HRA', 'DA', 'Travel allowance', 'Special allowance']} />
      <Lines label={t('Deductions')} lines={f.deductions} onChange={l => setF({ ...f, deductions: l })} suggestions={['Professional tax', 'TDS', 'ESI', 'Hostel / meals']} />
      <label className="row-start"><input type="checkbox" checked={f.pf} onChange={e => setF({ ...f, pf: e.target.checked })} /> {t('Cut PF (12% of basic, up to ₹1,800)')}</label>
      <div className="grid2">
        <Field label={t('Bank (optional)')}><Input value={f.bankName} onChange={e => setF({ ...f, bankName: e.target.value })} /></Field>
        <Field label={t('Account last 4 digits')}><Input inputMode="numeric" maxLength={4} value={f.accountLast4} onChange={e => setF({ ...f, accountLast4: e.target.value.replace(/\D/g, '').slice(0, 4) })} /></Field>
      </div>
      <span className="muted small">{t('Gross')} <b>{rupees(gross)}</b> · {t('Take-home')} <b>{rupees(Math.max(0, net))}</b></span>
      {error ? <Alert>{error}</Alert> : null}
      <Button busy={busy}>{t('Save')}</Button>
    </form>
  )
}

/** One month's salary sheet: unpaid days, bonus, advance per person; finalise; mark paid. */
export function PayrollRunPage({ school, runId }: { school: SchoolRef; runId: number }) {
  const { data, error, loading } = useApi(() => get<PayrollRun>(`/schools/${school.id}/payroll/runs/${runId}`), [school.id, runId])
  const [sheet, setSheet] = useState<PayrollRun | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [mode, setMode] = useState<string>('bank')
  const [ref, setRef] = useState('')
  const [paidOn, setPaidOn] = useState(todayIso())
  const d = sheet ?? data
  if (loading && !d) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!d) return null
  const draft = d.run.status === 'draft'
  const act = async (fn: () => Promise<PayrollRun>) => { setErr(null); try { setSheet(await fn()) } catch (e) { setErr((e as Error).message) } }
  const edit = (slipId: number, body: Record<string, unknown>) => act(() => patch<PayrollRun>(`/schools/${school.id}/payroll/runs/${runId}/slips/${slipId}`, body))
  const unpaid = d.slips.filter(s => !s.paidOn).length
  return (
    <div className="stack">
      <PageTitle title={monthName(d.run.month)} sub={draft ? t('Draft: check unpaid leave, then finalise') : t('Final')} back={`/${school.slug}/payroll`} />
      <div className="stats">
        <div className="stat"><span className="stat-value">{d.slips.length}</span><span className="stat-label">{t('People')}</span></div>
        <div className="stat"><span className="stat-value">{rupees(d.totals.gross)}</span><span className="stat-label">{t('Gross')}</span></div>
        <div className="stat"><span className="stat-value">{rupees(d.totals.deductions)}</span><span className="stat-label">{t('Deductions')}</span></div>
        <div className="stat stat-ok"><span className="stat-value">{rupees(d.totals.net)}</span><span className="stat-label">{t('To pay')}</span></div>
      </div>
      {err ? <Alert>{err}</Alert> : null}
      <div className="list">
        {d.slips.map(s => (
          <Card key={s.id} className="stack-sm">
            <div className="row">
              <div className="stack-xs"><b>{s.name}</b><span className="muted small">{s.designation ?? ''}</span></div>
              <span className="stack-xs right"><b>{rupees(s.net)}</b>{s.paidOn ? <span className="pill pill-ok">✓ {t('Paid')}</span> : null}</span>
            </div>
            {draft ? (
              <div className="grid3">
                <Field label={t('Unpaid days')}><Input inputMode="decimal" defaultValue={s.unpaidDays || ''} placeholder="0" onBlur={e => { if (e.target.value !== String(s.unpaidDays || '')) void edit(s.id, { unpaidDays: e.target.value || '0' }) }} /></Field>
                <Field label={t('Bonus ₹')}><Input inputMode="numeric" defaultValue={s.bonus || ''} placeholder="0" onBlur={e => { if (Number(e.target.value || 0) !== s.bonus) void edit(s.id, { bonus: Number(e.target.value || 0) }) }} /></Field>
                <Field label={t('Advance cut ₹')}><Input inputMode="numeric" defaultValue={s.advance || ''} placeholder="0" onBlur={e => { if (Number(e.target.value || 0) !== s.advance) void edit(s.id, { advance: Number(e.target.value || 0) }) }} /></Field>
              </div>
            ) : (
              <button className="link small" onClick={() => navigate(`/${school.slug}/payroll/slip/${s.id}`)}>📄 {t('Payslip')}</button>
            )}
            <span className="muted small">{t('Gross')} {rupees(s.gross)} − {t('Deductions')} {rupees(s.totalDeductions)}{s.unpaidDays ? ` · ${s.unpaidDays} ${t('unpaid days')}` : ''}</span>
          </Card>
        ))}
      </div>
      {draft ? (
        <div className="row-end btn-wrap">
          <Button variant="ghost" onClick={() => { void del(`/schools/${school.id}/payroll/runs/${runId}`).then(() => navigate(`/${school.slug}/payroll`)) }}>{t('Delete draft')}</Button>
          <Button onClick={() => { void act(() => post<PayrollRun>(`/schools/${school.id}/payroll/runs/${runId}/finalise`)) }}>✓ {t('Finalise')}</Button>
        </div>
      ) : unpaid ? (
        <Card className="stack-sm">
          <b>{t('Mark salaries paid')} ({unpaid})</b>
          <div className="chips">{PAY_MODES.map(([m, label]) => <Chip key={m} active={mode === m} onClick={() => setMode(m)}>{t(label)}</Chip>)}</div>
          <div className="grid2">
            <Input type="date" max={todayIso()} value={paidOn} onChange={e => setPaidOn(e.target.value)} />
            <Input placeholder={t('Reference (optional)')} value={ref} onChange={e => setRef(e.target.value)} />
          </div>
          <Button onClick={() => { void act(() => post<PayrollRun>(`/schools/${school.id}/payroll/runs/${runId}/pay`, { mode, paidOn, reference: ref || undefined })) }}>{t('Mark all paid')}</Button>
        </Card>
      ) : <Alert kind="ok">{t('All salaries for this month are paid.')}</Alert>}
    </div>
  )
}

function MyPayslips({ school }: { school: SchoolRef }) {
  const { data, error, loading } = useApi(() => get<{ payslips: (Payslip & { month: string })[] }>(`/schools/${school.id}/payroll/my-payslips`), [school.id])
  if (loading) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data?.payslips.length) return <Empty icon="📄">{t('No payslips yet.')}</Empty>
  return (
    <div className="list">
      {data.payslips.map(p => (
        <Card key={p.id} onClick={() => navigate(`/${school.slug}/payroll/slip/${p.id}`)}>
          <div className="row"><b>{monthName(p.month)}</b><span className="stack-xs right"><b>{rupees(p.net)}</b>{p.paidOn ? <span className="muted small">{t('Paid')}</span> : null}</span></div>
        </Card>
      ))}
    </div>
  )
}

export function PayslipPage({ school, slipId }: { school: SchoolRef; slipId: number }) {
  const { data, error, loading } = useApi(() => get<{ payslip: Payslip & { month: string; final: boolean; bankName: string | null; accountLast4: string | null }; school: { name: string; city: string | null; phone: string | null } }>(`/schools/${school.id}/payroll/payslips/${slipId}`), [school.id, slipId])
  if (loading) return <Loading />
  if (error) return <Alert>{error}</Alert>
  if (!data) return null
  const p = data.payslip
  const text = `${data.school.name}\nPayslip ${monthName(p.month)}\n${p.name}\nGross ${rupees(p.gross)}, deductions ${rupees(p.totalDeductions)}, net pay ${rupees(p.net)}.`
  const table = (title: string, lines: PayLine[], total: number) => (
    <div className="stack-xs">
      <b>{title}</b>
      {lines.map(l => <div key={l.name} className="row small"><span>{t(l.name)}</span><span>{rupees(l.amount)}</span></div>)}
      <div className="row small"><b>{t('Total')}</b><b>{rupees(total)}</b></div>
    </div>
  )
  return (
    <div className="stack">
      <PageTitle title={t('Payslip')} back={`/${school.slug}/payroll`} />
      <Card className="stack receipt">
        <div className="center stack-xs"><b className="receipt-school">{data.school.name}</b><span className="muted small">{[data.school.city, data.school.phone].filter(Boolean).join(' · ')}</span></div>
        <div className="row"><b>{t('Payslip')}</b><span>{monthName(p.month)}</span></div>
        <div className="facts">
          <div><span className="muted small">{t('Name')}</span><b>{p.name}</b></div>
          <div><span className="muted small">{t('Designation')}</span><b>{p.designation ?? '—'}</b></div>
          <div><span className="muted small">{t('Days paid')}</span><b>{p.daysInMonth - p.unpaidDays} / {p.daysInMonth}</b></div>
          <div><span className="muted small">{t('Bank')}</span><b>{p.bankName ?? '—'}{p.accountLast4 ? ` ••${p.accountLast4}` : ''}</b></div>
        </div>
        <div className="grid2">{table(t('Earnings'), p.earnings, p.gross)}{table(t('Deductions'), p.deductions, p.totalDeductions)}</div>
        <div className="row receipt-total"><b>{t('Net pay')}</b><b>{rupees(p.net)}</b></div>
        <span className="muted small">{p.paidOn ? `${t('Paid on')} ${p.paidOn}${p.payMode ? ` · ${p.payMode}` : ''}` : p.final ? t('Not paid yet') : t('Draft')}</span>
      </Card>
      <div className="row-end btn-wrap no-print">
        <Button variant="soft" onClick={() => window.print()}>🖨️ {t('Print')}</Button>
        <a className="btn btn-soft" href={whatsappLink(null, text)} target="_blank" rel="noopener noreferrer">💬 {t('Send on WhatsApp')}</a>
      </div>
    </div>
  )
}
