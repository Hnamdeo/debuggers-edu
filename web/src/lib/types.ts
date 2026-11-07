export type Role = 'admin' | 'principal' | 'academic_head' | 'teacher' | 'accounts' | 'staff' | 'parent'

export interface User { id: number; name: string; email: string | null; phone: string | null; language: 'en' | 'hi'; isPlatformAdmin: boolean }
export interface SchoolRef { id: number; slug: string; name: string; logoUrl: string | null; color: string; status: string; roles: Role[] }
export interface Profile { user: User; schools: SchoolRef[] }
export interface SignedIn extends Profile { token: string }

export interface School {
  id: number; slug: string; name: string; board: string; state: string | null; city: string | null
  phone: string | null; address: string | null; logoUrl: string | null; color: string; status: string
  students: number | null; staff: number | null; createdAt: string
}

export type Stage = 'pre_primary' | 'primary' | 'middle' | 'secondary' | 'higher_secondary'
export const STAGE_LABEL: Record<Stage, string> = { pre_primary: 'Pre-primary', primary: 'Primary', middle: 'Middle', secondary: 'Secondary', higher_secondary: 'Higher secondary' }

export interface Division { id: number; gradeId: number; name: string; stage: Stage | null; label: string; classTeacher: { id: number; name: string } | null; students: number }
export interface Setup {
  school: School
  years: { id: number; name: string; startsOn: string; endsOn: string; isCurrent: boolean }[]
  grades: { id: number; name: string; stage: Stage | null; sortOrder: number }[]
  divisions: Division[]
  subjects: { id: number; name: string }[]
}

export interface Student {
  id: number; admissionNo: string; firstName: string; lastName: string | null; name: string
  gender: 'girl' | 'boy' | 'other' | null; dob: string | null; admittedOn: string | null; status: string
  divisionId: number | null; division: string | null; rollNo: number | null
}
export interface Guardian { userId: number; name: string; relation: string; joined: boolean; phone: string | null; email: string | null }

export interface Staff {
  id: number; name: string; phone: string | null; email: string | null; employeeCode: string | null
  designation: string | null; department: string | null; role: Role; joinedOn: string | null; status: string; canSignIn: boolean; joined: boolean
}

export type Mark = 'present' | 'absent' | 'late' | 'leave'

export interface Homework {
  id: number; kind: 'homework' | 'worksheet' | 'revision' | 'lesson'; title: string; details: string | null; linkUrl: string | null
  lessonCode: string | null; dueOn: string | null; divisionId: number; division: string; subject: string | null
  teacher: string | null; createdAt: string; mine: boolean
}

export interface Notice { id: number; title: string; body: string | null; audience: string; division: string | null; author: string | null; createdAt: string; mine: boolean }

export type Action = 'children' | 'attendance' | 'homework' | 'notice' | 'add-student' | 'students' | 'staff' | 'setup'

export interface Home {
  school: School; roles: Role[]; actions: Action[]; today: string
  school_stats?: { students: number; staff: number; classes: number; presentToday: number; absentToday: number; classesNotMarked: number }
  setup_needed?: boolean
  my_classes?: { id: number; label: string; students: number; markedToday: boolean }[]
  children?: { id: number; name: string; division: string | null; today: Mark | null; homeworkThisWeek: number }[]
  notices: Notice[]
}
