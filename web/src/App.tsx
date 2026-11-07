import { match, navigate, usePath } from './lib/router'
import { useSession } from './lib/session'
import { useLang } from './lib/i18n'
import { Shell } from './ui/Shell'
import { Alert, Loading } from './ui/kit'
import { AuthFrame, FirstSetup, Landing, SchoolLogin, Welcome } from './pages/auth'
import { Home } from './pages/Home'
import { AttendanceClasses, AttendanceSheet } from './pages/Attendance'
import { HomeworkPage } from './pages/Homework'
import { NoticesPage } from './pages/Notices'
import { ImportStudents, NewStudent, StudentProfile, StudentsPage } from './pages/Students'
import { StaffPage } from './pages/Staff'
import { SetupPage } from './pages/Setup'
import { ChildPage } from './pages/Child'
import { PlatformPage } from './pages/Platform'
import { AdmissionDetail, AdmissionsPage, ApplyPage, NewAdmission } from './pages/Admissions'
import { SyllabusPage } from './pages/Syllabus'
import { ExamPage, ExamResultsPage, ExamsPage, MarksEntryPage, ReportCardPage } from './pages/Exams'
import { PayrollPage, PayrollRunPage, PayslipPage } from './pages/Payroll'
import { FeeAccountPage, FeeReceiptPage, FeeSetupPage, FeesPage } from './pages/Fees'
import type { ReactNode } from 'react'
import { useApi } from './lib/useApi'
import { get } from './lib/api'
import type { School, SchoolRef } from './lib/types'

/** Screens inside a school, by path after /:slug/ */
const SCHOOL_ROUTES: [string, (s: SchoolRef, p: Record<string, string>) => ReactNode][] = [
  ['home', s => <Home school={s} />],
  ['attendance', s => <AttendanceClasses school={s} />],
  ['attendance/:id', (s, p) => <AttendanceSheet school={s} divisionId={Number(p.id)} />],
  ['homework', s => <HomeworkPage school={s} />],
  ['notices', s => <NoticesPage school={s} />],
  ['students', s => <StudentsPage school={s} />],
  ['students/new', s => <NewStudent school={s} />],
  ['students/import', s => <ImportStudents school={s} />],
  ['students/:id', (s, p) => <StudentProfile school={s} studentId={Number(p.id)} />],
  ['admissions', s => <AdmissionsPage school={s} />],
  ['admissions/new', s => <NewAdmission school={s} />],
  ['admissions/:id', (s, p) => <AdmissionDetail school={s} admissionId={Number(p.id)} />],
  ['syllabus', s => <SyllabusPage school={s} />],
  ['fees', s => <FeesPage school={s} />],
  ['fees/setup', s => <FeeSetupPage school={s} />],
  ['fees/student/:id', (s, p) => <FeeAccountPage school={s} studentId={Number(p.id)} />],
  ['fees/receipt/:id', (s, p) => <FeeReceiptPage school={s} paymentId={Number(p.id)} />],
  ['exams', s => <ExamsPage school={s} />],
  ['exams/:id', (s, p) => <ExamPage school={s} examId={Number(p.id)} />],
  ['exams/:id/marks/:div/:sub', (s, p) => <MarksEntryPage school={s} examId={Number(p.id)} divisionId={Number(p.div)} subjectId={Number(p.sub)} />],
  ['exams/:id/results/:div', (s, p) => <ExamResultsPage school={s} examId={Number(p.id)} divisionId={Number(p.div)} />],
  ['exams/:id/report/:student', (s, p) => <ReportCardPage school={s} examId={Number(p.id)} studentId={Number(p.student)} />],
  ['payroll', s => <PayrollPage school={s} />],
  ['payroll/slip/:id', (s, p) => <PayslipPage school={s} slipId={Number(p.id)} />],
  ['payroll/:id', (s, p) => <PayrollRunPage school={s} runId={Number(p.id)} />],
  ['staff', s => <StaffPage school={s} />],
  ['setup', s => <SetupPage school={s} />],
  ['child/:id', (s, p) => <ChildPage school={s} studentId={Number(p.id)} />],
]

export function App() {
  const path = usePath()
  const { profile, loading } = useSession()
  useLang() // re-render on language change

  if (path === '/') return <Landing />
  if (path === '/admin/start') return <FirstSetup />
  if (path === '/welcome') return <Welcome />
  if (loading) return <AuthFrame><Loading /></AuthFrame>
  if (path === '/admin') {
    if (!profile) { navigate('/', true); return null }
    return <PlatformPage />
  }

  const [slug, ...rest] = path.split('/').filter(Boolean)
  const sub = rest.join('/')
  if (sub === '') return <SchoolLogin slug={slug} />
  if (sub === 'welcome') return <Welcome slug={slug} />
  if (sub === 'apply') return <ApplyPage slug={slug} />

  if (!profile) { navigate(`/${slug}`, true); return null }
  const school = profile.schools.find(s => s.slug === slug)
  if (!school) {
    // The Thinkle Tots team can open any school for support
    return profile.user.isPlatformAdmin
      ? <PlatformSchool slug={slug} sub={sub} />
      : <AuthFrame><Alert>You are not part of this school. <a href="/">Go back</a></Alert></AuthFrame>
  }
  return <SchoolScreen school={school} sub={sub} />
}

function SchoolScreen({ school, sub }: { school: SchoolRef; sub: string }) {
  for (const [pattern, render] of SCHOOL_ROUTES) {
    const params = match(pattern, sub)
    if (params) return <Shell school={school}>{render(school, params)}</Shell>
  }
  return <Shell school={school}><Alert>Page not found.</Alert></Shell>
}

/** Team support view: load the school by slug and act with admin rights (the API allows it). */
function PlatformSchool({ slug, sub }: { slug: string; sub: string }) {
  const { data, error } = useApi(() => get<{ schools: School[] }>('/platform/schools'), [])
  if (error) return <AuthFrame><Alert>{error}</Alert></AuthFrame>
  if (!data) return <AuthFrame><Loading /></AuthFrame>
  const s = data.schools.find(x => x.slug === slug)
  if (!s) return <AuthFrame><Alert>School not found.</Alert></AuthFrame>
  return <SchoolScreen school={{ id: s.id, slug: s.slug, name: s.name, logoUrl: s.logoUrl, color: s.color, status: s.status, roles: ['admin'] }} sub={sub} />
}
