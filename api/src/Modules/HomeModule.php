<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, Module, Request, Roles, Router, Services};

/**
 * The "Today" screen and the assistant box. Home shows each person only what they need to
 * do now; the assistant turns plain words (English or Hindi) into one of those actions.
 * The matcher is rule-based today; an AI model can replace intent() later without the
 * app or other modules changing.
 */
final class HomeModule implements Module
{
    /** action => words that mean it */
    private const INTENTS = [
        'attendance' => ['attendance', 'present', 'absent', 'haziri', 'hajiri', 'hazri', 'हाज़िरी', 'हाजिरी', 'उपस्थिति', 'अनुपस्थित'],
        'homework' => ['homework', 'home work', 'worksheet', 'revision', 'grihkarya', 'गृहकार्य', 'होमवर्क', 'वर्कशीट'],
        'notice' => ['notice', 'circular', 'announcement', 'message', 'soochna', 'suchna', 'सूचना', 'नोटिस', 'संदेश'],
        'admissions' => ['admission', 'admissions', 'enquiry', 'inquiry', 'apply', 'application', 'pravesh', 'dakhila', 'प्रवेश', 'दाखिला', 'आवेदन', 'पूछताछ'],
        'fees' => ['fee', 'fees', 'payment', 'receipt', 'dues', 'balance', 'collect', 'shulk', 'fees baki', 'फीस', 'शुल्क', 'रसीद', 'बकाया', 'भुगतान'],
        'payroll' => ['salary', 'salaries', 'payroll', 'payslip', 'pay slip', 'wages', 'tankhwah', 'vetan', 'वेतन', 'तनख्वाह', 'सैलरी', 'पगार'],
        'add-student' => ['new student', 'add student', 'add child', 'enrol', 'नया छात्र'],
        'syllabus' => ['syllabus', 'lesson plan', 'chapter', 'lesson', 'curriculum', 'pathyakram', 'पाठ्यक्रम', 'सिलेबस', 'पाठ', 'अध्याय'],
        'students' => ['student', 'students', 'children', 'child', 'kids', 'chhatra', 'छात्र', 'बच्चे', 'विद्यार्थी'],
        'staff' => ['staff', 'teacher', 'teachers', 'employee', 'shikshak', 'शिक्षक', 'कर्मचारी', 'स्टाफ'],
        'setup' => ['setup', 'class', 'section', 'subject', 'year', 'logo', 'कक्षा', 'विषय', 'सेटअप'],
    ];

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'home'; }
    public function permissions(): array { return []; }
    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $r->get('/schools/{schoolId}/home', $this->home(...));
        $r->post('/schools/{schoolId}/assistant', $this->assistant(...));
    }

    /** Actions this person can take, in the order the home screen shows them. */
    public static function actionsFor(Request $req): array
    {
        $t = $req->tenant;
        $a = [];
        if ($t->can('attendance.mark')) $a[] = 'attendance';
        if ($t->can('homework.give')) $a[] = 'homework';
        if ($t->can('notices.send_class')) $a[] = 'notice';
        if ($t->can('admissions.view')) $a[] = 'admissions';
        if ($t->can('fees.collect')) $a[] = 'fees';
        if ($t->can('payroll.manage')) $a[] = 'payroll';
        if ($t->can('students.manage')) $a[] = 'add-student';
        if ($t->can('syllabus.teach')) $a[] = 'syllabus';
        if ($t->can('students.view')) $a[] = 'students';
        if ($t->can('staff.view')) $a[] = 'staff';
        if ($t->can('setup.manage')) $a[] = 'setup';
        if ($t->hasRole(Roles::PARENT)) array_unshift($a, 'children');
        return array_values(array_unique($a));
    }

    private function home(Request $req): array
    {
        $t = $req->tenant;
        $db = $this->s->db;
        $access = new Access($db);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $out = ['school' => PlatformModule::schoolOut($t->school), 'roles' => $t->roles, 'actions' => self::actionsFor($req), 'today' => $today];

        if ($t->hasRole(Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD)) {
            $yearId = $access->currentYearId($t->schoolId);
            $out['school_stats'] = [
                'students' => (int)$db->value('SELECT COUNT(*) FROM students WHERE school_id = ? AND status = "active"', [$t->schoolId]),
                'staff' => (int)$db->value('SELECT COUNT(*) FROM staff WHERE school_id = ? AND status = "active"', [$t->schoolId]),
                'classes' => (int)$db->value('SELECT COUNT(*) FROM divisions WHERE school_id = ? AND academic_year_id = ?', [$t->schoolId, $yearId]),
                'presentToday' => (int)$db->value('SELECT COUNT(*) FROM student_attendance WHERE school_id = ? AND on_date = ? AND status IN ("present","late")', [$t->schoolId, $today]),
                'absentToday' => (int)$db->value('SELECT COUNT(*) FROM student_attendance WHERE school_id = ? AND on_date = ? AND status = "absent"', [$t->schoolId, $today]),
                'classesNotMarked' => (int)$db->value(
                    'SELECT COUNT(*) FROM divisions d WHERE d.school_id = ? AND d.academic_year_id = ?
                       AND EXISTS (SELECT 1 FROM enrolments e WHERE e.division_id = d.id)
                       AND NOT EXISTS (SELECT 1 FROM student_attendance a WHERE a.division_id = d.id AND a.on_date = ?)',
                    [$t->schoolId, $yearId, $today],
                ),
            ];
            $out['school_stats']['openAdmissions'] = (int)$db->value('SELECT COUNT(*) FROM admissions WHERE school_id = ? AND status IN ("enquiry","registered")', [$t->schoolId]);
            $out['setup_needed'] = $out['school_stats']['students'] === 0;
        }

        if ($t->hasRole(Roles::TEACHER)) {
            $ids = $access->teacherDivisionIds($req);
            $out['my_classes'] = $ids ? array_map(fn($d) => [
                'id' => (int)$d['id'], 'label' => $d['label'], 'students' => (int)$d['students'], 'markedToday' => (bool)$d['marked'],
            ], $db->all(
                'SELECT d.id, CONCAT(g.name, " ", d.name) AS label,
                        (SELECT COUNT(*) FROM enrolments e WHERE e.division_id = d.id) AS students,
                        EXISTS (SELECT 1 FROM student_attendance a WHERE a.division_id = d.id AND a.on_date = ?) AS marked
                   FROM divisions d JOIN grades g ON g.id = d.grade_id WHERE d.id IN (' . implode(',', $ids) . ') ORDER BY g.sort_order, d.name',
                [$today],
            )) : [];
        }

        if ($t->hasRole(Roles::PARENT)) {
            $childIds = $access->childIds($req);
            $out['children'] = $childIds ? array_map(fn($c) => [
                'id' => (int)$c['id'], 'name' => trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')), 'division' => $c['division_label'],
                'today' => $c['today'],
                'homeworkThisWeek' => (int)$c['homework'],
            ], $db->all(
                'SELECT s.id, s.first_name, s.last_name, CONCAT(g.name, " ", d.name) AS division_label,
                        (SELECT a.status FROM student_attendance a WHERE a.student_id = s.id AND a.on_date = ?) AS today,
                        (SELECT COUNT(*) FROM homework h WHERE h.division_id = e.division_id AND h.deleted_at IS NULL AND h.created_at > NOW() - INTERVAL 7 DAY) AS homework
                   FROM students s
                   LEFT JOIN enrolments e ON e.student_id = s.id AND e.academic_year_id = (SELECT id FROM academic_years WHERE school_id = s.school_id AND is_current = 1)
                   LEFT JOIN divisions d ON d.id = e.division_id LEFT JOIN grades g ON g.id = d.grade_id
                  WHERE s.school_id = ? AND s.id IN (' . implode(',', $childIds) . ') ORDER BY s.first_name',
                [$today, $t->schoolId],
            )) : [];
        }

        $out['notices'] = (new NoticesModule($this->s))->visibleTo($req, 5);
        return $out;
    }

    /** { text } -> { action, reply }. The app opens the action's screen. */
    private function assistant(Request $req): array
    {
        $text = (string)$req->input()->str('text', 300);
        $allowed = self::actionsFor($req);
        $action = self::intent($text, $allowed);
        $hindi = (bool)preg_match('/\p{Devanagari}/u', $text);
        if ($action === null) {
            return ['action' => null, 'reply' => $hindi
                ? 'माफ़ कीजिए, मैं समझ नहीं पाया। नीचे दिए बटन में से चुनें।'
                : "Sorry, I didn't get that. Please pick one of the buttons below.", 'suggestions' => $allowed];
        }
        return ['action' => $action, 'reply' => null, 'suggestions' => $allowed];
    }

    public static function intent(string $text, array $allowed): ?string
    {
        $t = mb_strtolower(trim($text));
        $best = null;
        $bestLen = 0;
        foreach (self::INTENTS as $action => $words) {
            if (!in_array($action, $allowed, true)) continue;
            foreach ($words as $w) {
                // Longest matching phrase wins ("add student" beats "student")
                if (mb_strlen($w) > $bestLen && str_contains($t, $w)) {
                    $best = $action;
                    $bestLen = mb_strlen($w);
                }
            }
        }
        return $best;
    }
}
