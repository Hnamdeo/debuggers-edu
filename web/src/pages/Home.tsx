import { useState, type FormEvent } from 'react'
import { get, post } from '../lib/api'
import { useApi } from '../lib/useApi'
import { navigate } from '../lib/router'
import { t, useLang } from '../lib/i18n'
import { useSession } from '../lib/session'
import type { Action, Home as HomeData, SchoolRef } from '../lib/types'
import { actionPath } from '../ui/Shell'
import { A, Alert, Card, Loading, formatDate } from '../ui/kit'

const ACTION_UI: Record<Action, { icon: string; label: string }> = {
  children: { icon: '🧒', label: 'My children' },
  attendance: { icon: '✅', label: 'Mark attendance' },
  homework: { icon: '📚', label: 'Give homework' },
  notice: { icon: '📢', label: 'Send a notice' },
  admissions: { icon: '📝', label: 'Admissions' },
  fees: { icon: '💰', label: 'Take fees' },
  payroll: { icon: '💼', label: 'Salary' },
  exams: { icon: '📊', label: 'Exams and marks' },
  'add-student': { icon: '➕', label: 'Add a student' },
  syllabus: { icon: '📖', label: 'Syllabus' },
  students: { icon: '🎒', label: 'Students' },
  staff: { icon: '🧑‍🏫', label: 'Staff' },
  setup: { icon: '⚙️', label: 'School setup' },
}

type SpeechCtor = new () => { lang: string; interimResults: boolean; onresult: (e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void; onend: () => void; start: () => void }

function greeting(): string {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
}

export function Home({ school }: { school: SchoolRef }) {
  const { profile } = useSession()
  const lang = useLang()
  const { data, error, loading } = useApi(() => get<HomeData>(`/schools/${school.id}/home`), [school.id])
  const [text, setText] = useState('')
  const [reply, setReply] = useState<string | null>(null)
  const [listening, setListening] = useState(false)
  const Speech = (window as unknown as { SpeechRecognition?: SpeechCtor; webkitSpeechRecognition?: SpeechCtor }).SpeechRecognition
    ?? (window as unknown as { webkitSpeechRecognition?: SpeechCtor }).webkitSpeechRecognition

  const ask = async (q: string) => {
    if (!q.trim()) return
    setReply(null)
    try {
      const res = await post<{ action: Action | null; reply: string | null }>(`/schools/${school.id}/assistant`, { text: q })
      if (res.action) navigate(actionPath(school.slug, res.action))
      else setReply(res.reply)
    } catch (e) {
      setReply((e as Error).message)
    }
  }
  const submit = (e: FormEvent) => { e.preventDefault(); void ask(text) }
  const listen = () => {
    if (!Speech) return
    const rec = new Speech()
    rec.lang = lang === 'hi' ? 'hi-IN' : 'en-IN'
    rec.interimResults = false
    rec.onresult = e => { const said = e.results[0][0].transcript; setText(said); void ask(said) }
    rec.onend = () => setListening(false)
    setListening(true)
    rec.start()
  }

  const firstName = (profile?.user.name ?? '').split(' ')[0]
  return (
    <div className="stack-lg">
      <section className="assistant">
        <p className="hello">{t(greeting())}{firstName ? `, ${firstName}` : ''} 👋</p>
        <h1>{t('What would you like to do?')}</h1>
        <form className="ask" onSubmit={submit}>
          <input value={text} onChange={e => setText(e.target.value)} placeholder={t('Type or speak, e.g. "mark attendance"')} aria-label={t('What would you like to do?')} />
          {Speech ? <button type="button" className={`mic ${listening ? 'on' : ''}`} onClick={listen} aria-label="Speak">🎤</button> : null}
          <button type="submit" className="ask-go" aria-label={t('Go')}>➜</button>
        </form>
        {reply ? <p className="assistant-reply">{reply}</p> : null}
        {data ? (
          <div className="quick">
            {data.actions.filter(a => a !== 'children').map(a => (
              <button key={a} className="quick-btn" onClick={() => navigate(actionPath(school.slug, a))}>
                <span className="quick-icon">{ACTION_UI[a].icon}</span>{t(ACTION_UI[a].label)}
              </button>
            ))}
          </div>
        ) : null}
      </section>

      {loading ? <Loading /> : error ? <Alert>{error}</Alert> : data ? <HomeCards data={data} school={school} /> : null}
    </div>
  )
}

function HomeCards({ data, school }: { data: HomeData; school: SchoolRef }) {
  return (
    <>
      {data.setup_needed ? (
        <Card className="card-hint">
          <h2>Let’s set up your school 🚀</h2>
          <ol className="steps">
            <li><A to={`/${school.slug}/setup`}>Check classes, sections and subjects</A></li>
            <li><A to={`/${school.slug}/staff`}>Add teachers</A> and send them their links on WhatsApp</li>
            <li><A to={`/${school.slug}/students/import`}>Add students</A> from your Excel sheet</li>
          </ol>
        </Card>
      ) : null}

      {data.school_stats ? (
        <div className="stats">
          <Stat value={data.school_stats.students} label={t('Students')} />
          <Stat value={data.school_stats.presentToday} label={t('Present')} tone="ok" />
          <Stat value={data.school_stats.absentToday} label={t('Absent')} tone="warn" />
          <Stat value={data.school_stats.classesNotMarked} label={t('Classes not marked')} tone={data.school_stats.classesNotMarked ? 'warn' : 'ok'}
            onClick={() => navigate(`/${school.slug}/attendance`)} />
        </div>
      ) : null}

      {data.school_stats?.openAdmissions && data.actions.includes('admissions') ? (
        <Card className="row" onClick={() => navigate(`/${school.slug}/admissions`)}>
          <div className="row-start"><span className="big-emoji" aria-hidden>📝</span>
            <div><b>{t('{n} admission forms waiting', { n: data.school_stats.openAdmissions })}</b><div className="muted small">{t('Call parents, register and admit')}</div></div>
          </div>
          <span aria-hidden>›</span>
        </Card>
      ) : null}

      {data.my_classes?.length ? (
        <section>
          <h2 className="section-title">{t('Today’s attendance')}</h2>
          <div className="list">
            {data.my_classes.map(c => (
              <Card key={c.id} onClick={() => navigate(`/${school.slug}/attendance/${c.id}`)} className="row">
                <div><b>{c.label}</b><div className="muted small">{c.students} {t('Students').toLowerCase()}</div></div>
                <span className={`pill ${c.markedToday ? 'pill-ok' : 'pill-warn'}`}>{c.markedToday ? t('Marked') : t('Not marked yet')}</span>
              </Card>
            ))}
          </div>
        </section>
      ) : null}

      {data.children ? (
        <section>
          <h2 className="section-title">{t('My children')}</h2>
          <div className="list">
            {data.children.map(c => (
              <Card key={c.id} onClick={() => navigate(`/${school.slug}/child/${c.id}`)} className="child">
                <div className="avatar">{c.name.slice(0, 1)}</div>
                <div className="grow">
                  <b>{c.name}</b>
                  <div className="muted small">{c.division ?? ''}</div>
                  <div className="small">
                    {t('Today')}: {c.today ? <span className={`pill pill-${c.today === 'absent' ? 'warn' : 'ok'}`}>{t(c.today)}</span> : <span className="muted">{t('Not marked yet')}</span>}
                    {' · '}{c.homeworkThisWeek ? `${c.homeworkThisWeek} ${t('homework this week')}` : t('No homework this week')}
                  </div>
                </div>
                <span aria-hidden>›</span>
              </Card>
            ))}
            {data.children.length === 0 ? <p className="muted">Your school has not linked a child to your account yet.</p> : null}
          </div>
        </section>
      ) : null}

      <section>
        <div className="section-head">
          <h2 className="section-title">{t('Notices')}</h2>
          <A to={`/${school.slug}/notices`} className="small">See all</A>
        </div>
        {data.notices.length ? (
          <div className="list">
            {data.notices.map(n => (
              <Card key={n.id}>
                <div className="row"><b>{n.title}</b><span className="muted small">{formatDate(n.createdAt)}</span></div>
                {n.body ? <p className="clamp">{n.body}</p> : null}
              </Card>
            ))}
          </div>
        ) : <p className="muted">No notices yet.</p>}
      </section>
    </>
  )
}

function Stat({ value, label, tone, onClick }: { value: number; label: string; tone?: 'ok' | 'warn'; onClick?: () => void }) {
  return (
    <button className={`stat ${tone ? `stat-${tone}` : ''}`} onClick={onClick} disabled={!onClick}>
      <span className="stat-value">{value}</span>
      <span className="stat-label">{label}</span>
    </button>
  )
}
