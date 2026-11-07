export type Role = 'admin' | 'principal' | 'academic_head' | 'teacher' | 'accounts' | 'staff' | 'parent'

export interface User { id: number; name: string; email: string | null; phone: string | null; language: 'en' | 'hi'; isPlatformAdmin: boolean }
export interface SchoolRef { id: number; slug: string; name: string; logoUrl: string | null; color: string; status: string; roles: Role[] }
export interface Profile { user: User; schools: SchoolRef[] }
export interface SignedIn extends Profile { token: string }

export interface School {
  id: number; slug: string; name: string; board: string; state: string | null; city: string | null
  phone: string | null; address: string | null; logoUrl: string | null; color: string; status: string
  students: number | null; staff: number | null; createdAt: string
  admissionsOpen: boolean; loginBoard: LoginBoard | null
}

/** Left panel of a school's sign-in page, set by the school. */
export interface LoginBoard { imageUrl?: string; headline?: string; lines?: string[] }
export interface PublicSchool {
  name: string; slug: string; logoUrl: string | null; color: string; city: string | null; phone: string | null
  paused: boolean; admissionsOpen: boolean; loginBoard: LoginBoard | null
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

export type Action = 'children' | 'attendance' | 'homework' | 'notice' | 'admissions' | 'fees' | 'add-student' | 'syllabus' | 'students' | 'staff' | 'setup'

export interface Home {
  school: School; roles: Role[]; actions: Action[]; today: string
  school_stats?: { students: number; staff: number; classes: number; presentToday: number; absentToday: number; classesNotMarked: number; openAdmissions: number }
  setup_needed?: boolean
  my_classes?: { id: number; label: string; students: number; markedToday: boolean }[]
  children?: { id: number; name: string; division: string | null; today: Mark | null; homeworkThisWeek: number }[]
  notices: Notice[]
}

export type AdmissionStatus = 'enquiry' | 'registered' | 'admitted' | 'not_admitted'
export const ADMISSION_STATUS_LABEL: Record<AdmissionStatus, string> = { enquiry: 'New enquiry', registered: 'Registered', admitted: 'Admitted', not_admitted: 'Not admitted' }

/** Optional admission form fields (stored as details on the server). */
export interface AdmissionDetails {
  bloodGroup?: string; category?: string; religion?: string; motherTongue?: string; nationality?: string
  previousClass?: string; tcNumber?: string; fatherOccupation?: string; motherOccupation?: string
  guardianName?: string; guardianPhone?: string; transport?: string; siblingInSchool?: string
  medicalNotes?: string; rte?: string; howHeard?: string
}

export interface Admission {
  id: number; applicationNo: string; status: AdmissionStatus; source: 'online' | 'office'
  academicYear: string | null; gradeId: number | null; grade: string | null
  firstName: string; lastName: string | null; name: string; gender: 'girl' | 'boy' | 'other' | null; dob: string | null
  fatherName: string | null; fatherPhone: string | null; motherName: string | null; motherPhone: string | null
  email: string | null; address: string | null; previousSchool: string | null; details: AdmissionDetails
  registrationFee: number | null; receiptNo: string | null; followUpOn: string | null; notes: string | null
  studentId: number | null; createdAt: string
}

export interface Lesson { id: number; unitId: number; title: string; details: string | null; plannedOn: string | null; lessonCode: string | null; linkUrl: string | null; doneOn: string | null }
export interface Unit { id: number; title: string; month: string | null; lessons: Lesson[] }
export interface Syllabus { units: Unit[]; total: number; done: number }

export interface FeeItem { id: number; gradeId: number; head: string; amount: number; dueOn: string }
export interface FeeStructure { yearId: number; heads: string[]; grades: { id: number; name: string; items: FeeItem[]; total: number }[] }
export interface FeeBalance { total: number; paid: number; balance: number; overdue: number }
export interface FeeStudentRow extends FeeBalance { studentId: number; name: string; admissionNo: string; rollNo: number | null; class: string }
export interface FeeSummary extends FeeBalance { today: number; month: number; hasStructure: boolean; overdueCount: number }
export interface FeePayment {
  id: number; receiptNo: string; amount: number; mode: 'cash' | 'upi' | 'cheque' | 'bank' | 'card' | 'online'
  reference: string | null; paidOn: string; note: string | null; receivedBy: string | null; cancelled: boolean; cancelReason: string | null
}
export interface FeeLine { kind: 'fee' | 'adjustment'; id: number; label: string; amount: number; dueOn: string; paid?: number; status: 'paid' | 'part' | 'overdue' | 'upcoming' | 'concession' }
export interface FeeAccount extends FeeBalance {
  student: { id: number; name: string; admissionNo: string; class: string | null }
  yearId: number; lines: FeeLine[]; payments: FeePayment[]; next: FeeLine | null
}
