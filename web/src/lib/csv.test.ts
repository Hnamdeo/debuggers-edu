import { describe, expect, it } from 'vitest'
import { parseCsv, rowsToStudents, toIsoDate } from './csv'
import { match } from './router'

describe('CSV import', () => {
  it('reads Excel CSV with quotes, BOM and semicolons', () => {
    expect(parseCsv('﻿a,b\r\n"x, y","say ""hi"""\r\n')).toEqual([['a', 'b'], ['x, y', 'say "hi"']])
    expect(parseCsv('a;b\n1;2')).toEqual([['a', 'b'], ['1', '2']])
  })
  it('maps common header names and cleans values', () => {
    const { students, unknown } = rowsToStudents(parseCsv(
      'Adm No,Student Name,Surname,Gender,DOB,Class,Section,Roll No,Father Name,Mobile No,Relation,Blood group\n' +
      'A1,Aarav,Sahu,M,5/8/2019,Class 1,A,1,Ravi,98765 43210,Papa,O+\n',
    ))
    expect(unknown).toEqual(['Blood group'])
    expect(students[0]).toMatchObject({ admissionNo: 'A1', firstName: 'Aarav', lastName: 'Sahu', gender: 'boy', dob: '2019-08-05', class: 'Class 1', section: 'A', rollNo: '1', parentName: 'Ravi', parentPhone: '98765 43210', relation: 'father' })
  })
  it('reads Indian day-first dates', () => {
    expect(toIsoDate('05/08/2019')).toBe('2019-08-05')
    expect(toIsoDate('5-8-19')).toBe('2019-08-05')
    expect(toIsoDate('2019-8-5')).toBe('2019-08-05')
  })
})

describe('router', () => {
  it('matches paths with params', () => {
    expect(match('students/:id', 'students/42')).toEqual({ id: '42' })
    expect(match('students/new', 'students/42')).toBeNull()
    expect(match('home', 'home/x')).toBeNull()
  })
})
