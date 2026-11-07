// Reads a CSV saved from Excel or Google Sheets (commas or semicolons, quoted cells, BOM).

export function parseCsv(text: string): string[][] {
  const clean = text.replace(/^﻿/, '')
  const firstLine = clean.split(/\r?\n/, 1)[0] ?? ''
  const sep = (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ','
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  for (let i = 0; i < clean.length; i++) {
    const c = clean[i]
    if (quoted) {
      if (c === '"' && clean[i + 1] === '"') { cell += '"'; i++ }
      else if (c === '"') quoted = false
      else cell += c
    } else if (c === '"') quoted = true
    else if (c === sep) { row.push(cell.trim()); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && clean[i + 1] === '\n') i++
      row.push(cell.trim()); cell = ''
      if (row.some(v => v !== '')) rows.push(row)
      row = []
    } else cell += c
  }
  row.push(cell.trim())
  if (row.some(v => v !== '')) rows.push(row)
  return rows
}

/** Header names people may type, mapped to API fields. */
const HEADERS: Record<string, string> = {
  'admission no': 'admissionNo', 'admission number': 'admissionNo', 'admissionno': 'admissionNo', 'adm no': 'admissionNo', 'sr no': 'admissionNo',
  'first name': 'firstName', 'firstname': 'firstName', 'name': 'firstName', 'student name': 'firstName',
  'last name': 'lastName', 'lastname': 'lastName', 'surname': 'lastName',
  'gender': 'gender', 'dob': 'dob', 'date of birth': 'dob', 'birth date': 'dob',
  'class': 'class', 'grade': 'class', 'section': 'section', 'division': 'section', 'roll no': 'rollNo', 'roll number': 'rollNo', 'roll': 'rollNo',
  'parent name': 'parentName', 'father name': 'parentName', 'mother name': 'parentName', 'guardian name': 'parentName',
  'parent mobile': 'parentPhone', 'parent phone': 'parentPhone', 'mobile': 'parentPhone', 'phone': 'parentPhone', 'mobile no': 'parentPhone',
  'parent email': 'parentEmail', 'email': 'parentEmail', 'relation': 'relation',
}

export function rowsToStudents(rows: string[][]): { students: Record<string, string>[]; unknown: string[] } {
  if (rows.length < 2) return { students: [], unknown: [] }
  const header = rows[0].map(h => h.toLowerCase().replace(/[._*:]/g, ' ').replace(/\s+/g, ' ').trim())
  const keys = header.map(h => HEADERS[h] ?? null)
  const unknown = rows[0].filter((_, i) => keys[i] === null && rows[0][i] !== '')
  const students = rows.slice(1).map(r => {
    const s: Record<string, string> = {}
    keys.forEach((k, i) => { if (k && r[i]) s[k] = r[i] })
    if (s.gender) s.gender = /^(f|g|girl|female)/i.test(s.gender) ? 'girl' : /^(m|b|boy|male)/i.test(s.gender) ? 'boy' : 'other'
    if (s.relation) s.relation = /mother|mom|maa/i.test(s.relation) ? 'mother' : /father|dad|papa/i.test(s.relation) ? 'father' : 'guardian'
    if (s.dob) s.dob = toIsoDate(s.dob)
    return s
  })
  return { students, unknown }
}

/** 05/08/2019 or 5-8-2019 (Indian day first) or 2019-08-05 -> 2019-08-05 */
export function toIsoDate(v: string): string {
  const iso = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/)
  if (iso) return `${iso[1]}-${iso[2].padStart(2, '0')}-${iso[3].padStart(2, '0')}`
  const dmy = v.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$/)
  if (dmy) {
    const year = dmy[3].length === 2 ? `20${dmy[3]}` : dmy[3]
    return `${year}-${dmy[2].padStart(2, '0')}-${dmy[1].padStart(2, '0')}`
  }
  return v
}

export const TEMPLATE_CSV =
  'Admission No,First Name,Last Name,Gender,Date of Birth,Class,Section,Roll No,Parent Name,Parent Mobile,Relation\n' +
  'A001,Aarav,Sahu,Boy,05/08/2019,Class 1,A,1,Ravi Sahu,9876543210,Father\n'
