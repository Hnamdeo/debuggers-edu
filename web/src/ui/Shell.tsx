import type { ReactNode } from 'react'
import { useSession } from '../lib/session'
import { navigate, usePath } from '../lib/router'
import { setLang, t, useLang } from '../lib/i18n'
import { patch } from '../lib/api'
import type { Action, SchoolRef } from '../lib/types'
import { Credit } from './kit'

const NAV: { action: Action | 'home'; icon: string; label: string; path: string }[] = [
  { action: 'home', icon: '🏠', label: 'Home', path: 'home' },
  { action: 'children', icon: '🧒', label: 'My children', path: 'children' },
  { action: 'attendance', icon: '✅', label: 'Attendance', path: 'attendance' },
  { action: 'homework', icon: '📚', label: 'Homework', path: 'homework' },
  { action: 'syllabus', icon: '📖', label: 'Syllabus', path: 'syllabus' },
  { action: 'exams', icon: '📊', label: 'Exams', path: 'exams' },
  { action: 'notice', icon: '📢', label: 'Notices', path: 'notices' },
  { action: 'fees', icon: '💰', label: 'Fees', path: 'fees' },
  { action: 'admissions', icon: '📝', label: 'Admissions', path: 'admissions' },
  { action: 'payroll', icon: '💼', label: 'Salary', path: 'payroll' },
  { action: 'students', icon: '🎒', label: 'Students', path: 'students' },
  { action: 'staff', icon: '🧑‍🏫', label: 'Staff', path: 'staff' },
  { action: 'setup', icon: '⚙️', label: 'School setup', path: 'setup' },
]

export function actionPath(slug: string, action: Action): string {
  const map: Record<Action, string> = {
    children: 'home', attendance: 'attendance', homework: 'homework', notice: 'notices',
    'add-student': 'students/new', students: 'students', staff: 'staff', setup: 'setup',
    admissions: 'admissions', syllabus: 'syllabus', fees: 'fees', payroll: 'payroll', exams: 'exams',
  }
  return `/${slug}/${map[action]}`
}

export function navFor(school: SchoolRef): typeof NAV {
  const r = school.roles
  const leader = r.includes('admin') || r.includes('principal')
  const academic = leader || r.includes('academic_head') || r.includes('teacher')
  const allowed = new Set<string>(['home', 'notice'])
  if (academic) { allowed.add('attendance'); allowed.add('homework'); allowed.add('syllabus'); allowed.add('exams') }
  if (leader || r.includes('accounts')) { allowed.add('admissions'); allowed.add('fees') }
  if (r.includes('parent') && !academic) allowed.add('homework')
  if (academic || r.includes('accounts')) allowed.add('students')
  if (leader || r.includes('academic_head') || r.includes('accounts')) allowed.add('staff')
  if (leader) allowed.add('setup')
  if (r.some(x => x !== 'parent')) allowed.add('payroll')
  return NAV.filter(n => allowed.has(n.action))
}

export function Shell({ school, children }: { school: SchoolRef; children: ReactNode }) {
  const { profile, signOut } = useSession()
  const path = usePath()
  const lang = useLang()
  const items = navFor(school)
  const bottom = items.slice(0, 5)
  const otherSchools = (profile?.schools ?? []).filter(s => s.id !== school.id)

  const switchLang = () => {
    const next = lang === 'en' ? 'hi' : 'en'
    setLang(next)
    patch('/me', { language: next }).catch(() => undefined)
  }

  return (
    <div className="shell" style={{ ['--brand' as string]: school.color }}>
      <header className="topbar">
        <a className="brand" href={`/${school.slug}/home`} onClick={e => { e.preventDefault(); navigate(`/${school.slug}/home`) }}>
          {school.logoUrl ? <img src={school.logoUrl} alt="" className="brand-logo" /> : <span className="brand-dot">{school.name.slice(0, 1)}</span>}
          <span className="brand-name">{school.name}</span>
        </a>
        <div className="topbar-actions">
          <button className="lang" onClick={switchLang} aria-label={t('Language')}>{lang === 'en' ? 'हिं' : 'EN'}</button>
          <details className="menu">
            <summary aria-label="Menu">{(profile?.user.name ?? '?').slice(0, 1).toUpperCase()}</summary>
            <div className="menu-pop">
              <div className="menu-who"><b>{profile?.user.name}</b><span className="muted">{school.roles.map(r => r.replace('_', ' ')).join(', ')}</span></div>
              {items.slice(5).map(n => <button key={n.path} onClick={() => navigate(`/${school.slug}/${n.path}`)}>{n.icon} {t(n.label)}</button>)}
              {otherSchools.map(s => <button key={s.id} onClick={() => navigate(`/${s.slug}/home`)}>🏫 {s.name}</button>)}
              {profile?.user.isPlatformAdmin ? <button onClick={() => navigate('/admin')}>🛠️ Thinkle Tots admin</button> : null}
              <button onClick={() => { void signOut().then(() => navigate(`/${school.slug}`)) }}>↩︎ {t('Sign out')}</button>
            </div>
          </details>
        </div>
      </header>
      <main className="main">{children}</main>
      <nav className="bottomnav" aria-label="Main">
        {bottom.map(n => {
          const to = `/${school.slug}/${n.path}`
          const on = path === to || path.startsWith(to + '/')
          return (
            <a key={n.path} href={to} className={on ? 'on' : ''} onClick={e => { e.preventDefault(); navigate(to) }}>
              <span className="nav-icon" aria-hidden>{n.icon}</span>
              <span className="nav-label">{t(n.label)}</span>
            </a>
          )
        })}
      </nav>
      <footer className="foot muted"><Credit className="" /></footer>
    </div>
  )
}
