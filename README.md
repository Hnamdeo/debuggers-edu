# Debuggers Edu

School and college management by Debuggers Space Solutions. One app on one address
(edu.debuggersspace.com) for every institution, role and screen: admin, principal, teachers,
other staff and parents sign in at the same place and each sees only their own menu.
Handover: https://claude.ai/artifact/FuJetBMiDcqAbhseXMKBUn

Each school has its own link, for example `edu.debuggersspace.com/bharatmata`, which opens a
sign-in page in that school's logo and colour.

Moved out of the Thinkle Tots repo (its `school/` folder, PRs #130 to #137) with its history,
in November 2026. Colleges (courses, departments, year/semester) are next.

## What is in this first version

| Area | What works |
| --- | --- |
| Debuggers Edu team | Add schools (board, state, starting classes), give each its first principal or admin, pause or activate |
| Sign-in | Mobile or email + password; one-time links shared on WhatsApp; one account per person across schools and roles; locked for 15 min after 8 wrong tries |
| Setup | Year (April to March), classes grouped as Pre-primary, Primary, Middle, Secondary, Higher secondary; sections; class teachers; subjects; school logo and colour |
| People | Students (add one, or import an Excel sheet saved as CSV), parents linked to children, staff (teachers and other employees) |
| Attendance | Class teacher marks the class in one tap per absent child; principal sees which classes are marked; parents see a month calendar |
| Homework | Homework, worksheets, revision and video lessons (a lesson code such as ABC-S1-E01 and a YouTube link) for one or more sections |
| Notices | To everyone, all parents, all staff, or one class |
| Home | "What would you like to do?" box (typed or spoken, English or Hindi) plus the person's own buttons and today's numbers |
| Admissions | Online form for parents at `/<school>/apply` (school opens or closes it); office adds walk-ins; enquiry → registered (fee, receipt, call-back date, notes) → admitted into a section, which creates the student and both parents' sign-up links |
| Syllabus | Per class and subject: chapters with lessons (paste one per line, with a lesson code or video link), day-wise plan that skips Sundays and holidays, teachers tick lessons as taught and give a lesson as homework in one tap, parents see progress |
| Fees | Class fees set once for many classes (one-time, 2/4 parts or monthly), take payment (cash, UPI, cheque, bank, card) with numbered receipts to print or send on WhatsApp, concessions and extra charges per child, "due now" list, today's collection by mode, cancel receipts with a reason (admin/principal), parents see their child's fees |
| Salary (payroll) | Monthly salary per employee (basic, allowances, fixed deductions, PF 12% capped at ₹1,800), salary sheet per month with unpaid days, bonus and advance recovery, finalise, mark paid (bank/cash/UPI/cheque), printable payslips, staff see their own payslips |
| Exams and report cards | Exams per class and subject with maximum marks, teachers type marks per section (AB for absent, half marks), results with total, %, CBSE grade (A1-E), rank and "needs support", class teacher remark, publish to parents, printable report card with attendance |
| Sign-in page | School's own banner on the left (headline, short lines, optional photo, office phone, "Admissions open"), sign-in on the right; stacked on phones |
| Language | English and Hindi switch on every screen |

Next: staff leave, LMS lessons.

## How it is built

```
.
  api/            PHP 8.2 + MySQL, no framework, runs on Hostinger shared hosting
    index.php     the only entry point (/api/*)
    src/Core/     router, sign-in, tenants (schools), permissions, events, audit, migrations
    src/Modules/  one class per feature area: Platform, Auth, Setup, People, Attendance,
                  Homework, Notices, Admissions, Syllabus, Home. A new area (Fees, Payroll, Syllabus, LMS) is a new
                  module added to src/modules.php; nothing else changes.
    migrations/   numbered SQL files, run once each, never edited after release
    tests/run.php integration tests against a real database
  web/            React + TypeScript app (Vite), mobile first, installable
```

- **Multi-tenant:** every school's rows carry `school_id`; every `/schools/{schoolId}/...`
  call checks the person belongs to that school and has the permission the route needs.
- **Loosely coupled:** modules talk through events (`school.created`, `attendance.marked`,
  `homework.given`...). For example, Setup fills in a new school's classes by listening to
  `school.created`, and a future Messages module can text parents of absent children by
  listening to `attendance.marked`, without touching Attendance. Every event is also written
  to `audit_log` (who did what, when).
- **Needs PHP 8.1 or newer** (Hostinger: hPanel → Advanced → PHP Configuration).
- **Safe by default:** prepared SQL only, sign-in tokens stored as hashes, secrets only in the
  server config file, source folders blocked from the web.

## Run it on your computer

```bash
# database (MySQL or MariaDB)
mysql -e "CREATE DATABASE school_dev; CREATE USER 'school'@'localhost' IDENTIFIED BY 'school'; GRANT ALL ON school_dev.* TO 'school'@'localhost'"
cp api/config.sample.php ~/school-config.php   # fill in db_* and deploy_key
SCHOOL_CONFIG=~/school-config.php php api/bin/migrate.php
SCHOOL_CONFIG=~/school-config.php php -S 127.0.0.1:8090 -t api api/index.php
cd web && npm install && npm run dev   # http://localhost:5173, /api goes to :8090
```

Open http://localhost:5173/admin/start once to create the Debuggers Edu team account.

Tests: `php api/tests/run.php` (needs an empty database whose name contains "test",
set with `SCHOOL_TEST_DB=mysql://user:pass@127.0.0.1:3306/school_test`) and
`cd web && npm test`.

## Going live (one time)

1. hPanel (debuggersspace.com) → Domains → Subdomains: create `edu.debuggersspace.com` with folder `public_html/edu`.
2. hPanel → Databases: create a MySQL database and user.
3. Upload `api/config.sample.php`, filled in, as `debuggers-edu-config.edu.debuggersspace.com.php` in
   the hosting home folder (above `public_html`).
4. GitHub → Settings → Secrets: add `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` (debuggersspace.com
   FTP account) and `SCHOOL_DEPLOY_KEY` with the same `deploy_key` value.
5. Merge to `main`. The "Debuggers Edu" workflow tests, uploads, runs migrations and checks health.
6. Open https://edu.debuggersspace.com/admin/start, enter the deploy key, create your account,
   then add the first school.
