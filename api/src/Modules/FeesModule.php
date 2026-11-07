<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Module, Request, Response, Roles, Router, Services};

/**
 * Fees. The office sets what each class pays this year (head + amount + due date, one row per
 * instalment, set for many classes at once), adds per-child changes (concession, transport,
 * late fee) and takes payments with a receipt. A child's balance is all that is due minus all
 * that is paid, so part payments need no special handling. Parents see their own child's fees.
 */
final class FeesModule implements Module
{
    public const MODES = ['cash', 'upi', 'cheque', 'bank', 'card', 'online'];
    public const HEADS = ['Admission fee', 'Tuition fee', 'Annual charges', 'Exam fee', 'Transport', 'Books and uniform', 'Activity fee', 'Computer fee', 'Late fee'];

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'fees'; }

    public function permissions(): array
    {
        return [
            'fees.view' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS],
            'fees.collect' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS],
            'fees.manage' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS],
            'fees.cancel' => [Roles::ADMIN, Roles::PRINCIPAL],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/fees';
        $r->get("$b/structure", $this->structure(...), ['perm' => 'fees.view']);
        $r->post("$b/items", $this->addItems(...), ['perm' => 'fees.manage']);
        $r->patch("$b/items/{itemId}", $this->updateItem(...), ['perm' => 'fees.manage']);
        $r->delete("$b/items/{itemId}", $this->deleteItem(...), ['perm' => 'fees.manage']);
        $r->get("$b/students", $this->students(...), ['perm' => 'fees.view']);
        $r->get("$b/students/{studentId}", $this->account(...)); // staff with fees.view, or the child's parent
        $r->post("$b/students/{studentId}/payments", $this->pay(...), ['perm' => 'fees.collect']);
        $r->post("$b/students/{studentId}/adjustments", $this->addAdjustment(...), ['perm' => 'fees.manage']);
        $r->delete("$b/adjustments/{adjustmentId}", $this->deleteAdjustment(...), ['perm' => 'fees.manage']);
        $r->get("$b/payments", $this->payments(...), ['perm' => 'fees.view']);
        $r->get("$b/payments/{paymentId}", $this->receipt(...));
        $r->post("$b/payments/{paymentId}/cancel", $this->cancel(...), ['perm' => 'fees.cancel']);
        $r->get("$b/summary", $this->summary(...), ['perm' => 'fees.view']);
    }

    // ---------- structure (what each class pays) ----------

    private function yearId(Request $req): int
    {
        $schoolId = $req->tenant->schoolId;
        if (!empty($req->query['yearId'])) {
            $id = (int)$req->query['yearId'];
            $this->s->db->one('SELECT id FROM academic_years WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?? throw HttpError::notFound('Year not found.');
            return $id;
        }
        return (new Access($this->s->db))->currentYearId($schoolId) ?? throw HttpError::badRequest('Please set the current school year in Setup first.');
    }

    private function structure(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $yearId = $this->yearId($req);
        $grades = $this->s->db->all('SELECT id, name FROM grades WHERE school_id = ? ORDER BY sort_order, name', [$schoolId]);
        $items = $this->s->db->all('SELECT * FROM fee_items WHERE school_id = ? AND academic_year_id = ? ORDER BY due_on, head', [$schoolId, $yearId]);
        $byGrade = [];
        foreach ($items as $i) $byGrade[(int)$i['grade_id']][] = self::itemOut($i);
        return [
            'yearId' => $yearId,
            'heads' => self::HEADS,
            'grades' => array_map(fn($g) => [
                'id' => (int)$g['id'], 'name' => $g['name'],
                'items' => $byGrade[(int)$g['id']] ?? [],
                'total' => array_sum(array_column($byGrade[(int)$g['id']] ?? [], 'amount')),
            ], $grades),
        ];
    }

    /**
     * Body: { gradeIds: [..], head, amount, dueOn } for one fee, or
     * { gradeIds, head, amount (per instalment), dueOn, instalments: 4, everyMonths: 3 } for a fee in parts.
     */
    private function addItems(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $yearId = $in->int('yearId', false) ?? $this->yearId($req);
        $this->s->db->one('SELECT id FROM academic_years WHERE school_id = ? AND id = ?', [$schoolId, $yearId]) ?? throw HttpError::notFound('Year not found.');
        $gradeIds = array_values(array_unique(array_map('intval', $in->list('gradeIds'))));
        if (!$gradeIds) throw HttpError::badRequest('Please choose at least one class.', ['field' => 'gradeIds']);
        foreach ($gradeIds as $g) $this->s->db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $g]) ?? throw HttpError::notFound('Class not found.');
        $head = $in->str('head', 60);
        $amount = $in->int('amount', true, 1);
        if ($amount > 10000000) throw HttpError::badRequest('Amount looks too large.', ['field' => 'amount']);
        $first = new \DateTimeImmutable($in->date('dueOn'));
        $parts = $in->int('instalments', false, 1) ?? 1;
        $every = $in->int('everyMonths', false, 1) ?? 1;
        if ($parts > 12) throw HttpError::badRequest('At most 12 instalments.', ['field' => 'instalments']);
        $ids = $this->s->db->tx(function () use ($schoolId, $yearId, $gradeIds, $head, $amount, $first, $parts, $every) {
            $ids = [];
            foreach ($gradeIds as $g) {
                for ($p = 0; $p < $parts; $p++) {
                    $ids[] = $this->s->db->insert('fee_items', [
                        'school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_id' => $g,
                        'head' => $parts > 1 ? sprintf('%s (%d of %d)', $head, $p + 1, $parts) : $head,
                        'amount' => $amount, 'due_on' => $first->modify('+' . ($p * $every) . ' months')->format('Y-m-d'),
                    ]);
                }
            }
            return $ids;
        });
        $this->s->events->emit('fees.items_added', ['count' => count($ids), 'head' => $head], $req);
        return Response::created(['ids' => $ids]);
    }

    private function item(Request $req): array
    {
        return $this->s->db->one('SELECT * FROM fee_items WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $req->intParam('itemId')])
            ?? throw HttpError::notFound('Fee not found.');
    }

    private function updateItem(Request $req): array
    {
        $item = $this->item($req);
        $in = $req->input();
        $row = [];
        if ($in->has('head')) $row['head'] = $in->str('head', 60);
        if ($in->has('amount')) $row['amount'] = $in->int('amount', true, 1);
        if ($in->has('dueOn')) $row['due_on'] = $in->date('dueOn');
        if ($row) $this->s->db->update('fee_items', $req->tenant->schoolId, (int)$item['id'], $row);
        $this->s->events->emit('fees.item_changed', ['id' => (int)$item['id']] + $row, $req);
        return ['item' => self::itemOut($this->item($req))];
    }

    private function deleteItem(Request $req): array
    {
        $item = $this->item($req);
        $this->s->db->exec('DELETE FROM fee_items WHERE id = ?', [$item['id']]);
        $this->s->events->emit('fees.item_removed', ['id' => (int)$item['id'], 'head' => $item['head'], 'amount' => (int)$item['amount']], $req);
        return ['ok' => true];
    }

    // ---------- children and balances ----------

    /**
     * Every enrolled child with total, paid and balance. ?divisionId= or ?gradeId= to narrow,
     * ?q= name/admission no, ?only=due (balance due today or earlier) or ?only=pending (any balance).
     */
    private function students(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $yearId = $this->yearId($req);
        $where = ['e.school_id = ?', 'e.academic_year_id = ?', "st.status = 'active'"];
        $args = [$schoolId, $yearId];
        if (!empty($req->query['divisionId'])) { $where[] = 'e.division_id = ?'; $args[] = (int)$req->query['divisionId']; }
        if (!empty($req->query['gradeId'])) { $where[] = 'd.grade_id = ?'; $args[] = (int)$req->query['gradeId']; }
        if (($q = trim((string)($req->query['q'] ?? ''))) !== '') {
            $where[] = '(st.first_name LIKE ? OR st.last_name LIKE ? OR st.admission_no = ?)';
            array_push($args, "$q%", "$q%", $q);
        }
        $today = date('Y-m-d');
        $rows = $this->s->db->all(
            "SELECT st.id, st.first_name, st.last_name, st.admission_no, e.roll_no, g.name AS grade, d.name AS division, d.grade_id,
                    COALESCE((SELECT SUM(fi.amount) FROM fee_items fi WHERE fi.academic_year_id = e.academic_year_id AND fi.grade_id = d.grade_id), 0)
                  + COALESCE((SELECT SUM(fa.amount) FROM fee_adjustments fa WHERE fa.academic_year_id = e.academic_year_id AND fa.student_id = st.id), 0) AS total,
                    COALESCE((SELECT SUM(fi.amount) FROM fee_items fi WHERE fi.academic_year_id = e.academic_year_id AND fi.grade_id = d.grade_id AND fi.due_on <= ?), 0)
                  + COALESCE((SELECT SUM(fa.amount) FROM fee_adjustments fa WHERE fa.academic_year_id = e.academic_year_id AND fa.student_id = st.id AND fa.due_on <= ?), 0) AS due_now,
                    COALESCE((SELECT SUM(fp.amount) FROM fee_payments fp WHERE fp.academic_year_id = e.academic_year_id AND fp.student_id = st.id AND fp.cancelled_at IS NULL), 0) AS paid
               FROM enrolments e JOIN students st ON st.id = e.student_id
               JOIN divisions d ON d.id = e.division_id JOIN grades g ON g.id = d.grade_id
              WHERE " . implode(' AND ', $where) . ' ORDER BY g.sort_order, d.name, e.roll_no, st.first_name LIMIT 2000',
            [$today, $today, ...$args],
        );
        $out = array_map(fn($r) => self::balanceOut($r) + [
            'studentId' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
            'admissionNo' => $r['admission_no'], 'rollNo' => $r['roll_no'] === null ? null : (int)$r['roll_no'],
            'class' => $r['grade'] . ' ' . $r['division'],
        ], $rows);
        $only = $req->query['only'] ?? '';
        if ($only === 'due') $out = array_values(array_filter($out, fn($s) => $s['overdue'] > 0));
        if ($only === 'pending') $out = array_values(array_filter($out, fn($s) => $s['balance'] > 0));
        return [
            'students' => $out,
            'totals' => [
                'total' => array_sum(array_column($out, 'total')),
                'paid' => array_sum(array_column($out, 'paid')),
                'balance' => array_sum(array_column($out, 'balance')),
                'overdue' => array_sum(array_column($out, 'overdue')),
                'overdueCount' => count(array_filter($out, fn($s) => $s['overdue'] > 0)),
            ],
        ];
    }

    private static function balanceOut(array $r): array
    {
        $total = (int)$r['total'];
        $paid = (int)$r['paid'];
        return ['total' => $total, 'paid' => $paid, 'balance' => $total - $paid, 'overdue' => max(0, (int)$r['due_now'] - $paid)];
    }

    /** Staff with fees.view see any child; a parent sees only their own children. */
    private function requireChild(Request $req, int $studentId): array
    {
        $t = $req->tenant;
        $student = $this->s->db->one('SELECT * FROM students WHERE school_id = ? AND id = ?', [$t->schoolId, $studentId])
            ?? throw HttpError::notFound('Student not found.');
        if ($t->can('fees.view')) return $student;
        if (in_array($studentId, (new Access($this->s->db))->childIds($req), true)) return $student;
        throw HttpError::forbidden();
    }

    /** One child's fee account: what is due (class fees + changes), payments and the balance. */
    private function account(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $studentId = $req->intParam('studentId');
        $student = $this->requireChild($req, $studentId);
        $yearId = $this->yearId($req);
        $enrol = $this->s->db->one(
            'SELECT d.grade_id, g.name AS grade, d.name AS division, e.roll_no FROM enrolments e
               JOIN divisions d ON d.id = e.division_id JOIN grades g ON g.id = d.grade_id
              WHERE e.academic_year_id = ? AND e.student_id = ?',
            [$yearId, $studentId],
        );
        $items = $enrol ? $this->s->db->all('SELECT * FROM fee_items WHERE academic_year_id = ? AND grade_id = ? ORDER BY due_on, head', [$yearId, $enrol['grade_id']]) : [];
        $adjustments = $this->s->db->all('SELECT * FROM fee_adjustments WHERE academic_year_id = ? AND student_id = ? ORDER BY due_on, id', [$yearId, $studentId]);
        $payments = $this->s->db->all(
            'SELECT p.*, u.name AS received_by_name FROM fee_payments p LEFT JOIN users u ON u.id = p.received_by
              WHERE p.academic_year_id = ? AND p.student_id = ? ORDER BY p.paid_on DESC, p.id DESC',
            [$yearId, $studentId],
        );
        $today = date('Y-m-d');
        $lines = [
            ...array_map(fn($i) => ['kind' => 'fee', 'id' => (int)$i['id'], 'label' => $i['head'], 'amount' => (int)$i['amount'], 'dueOn' => $i['due_on']], $items),
            ...array_map(fn($a) => ['kind' => 'adjustment', 'id' => (int)$a['id'], 'label' => $a['label'], 'amount' => (int)$a['amount'], 'dueOn' => $a['due_on']], $adjustments),
        ];
        usort($lines, fn($a, $b) => [$a['dueOn'], $a['kind']] <=> [$b['dueOn'], $b['kind']]);
        $paid = array_sum(array_map(fn($p) => $p['cancelled_at'] ? 0 : (int)$p['amount'], $payments));
        $balance = self::balanceOut([
            'total' => array_sum(array_column($lines, 'amount')),
            'due_now' => array_sum(array_map(fn($l) => $l['dueOn'] <= $today ? $l['amount'] : 0, $lines)),
            'paid' => $paid,
        ]);
        // Mark each line paid / part paid / due, oldest first, from the money paid so far
        $left = $paid;
        foreach ($lines as &$l) {
            if ($l['amount'] <= 0) { $l['status'] = 'concession'; continue; }
            $covered = min($left, $l['amount']);
            $left -= $covered;
            $l['paid'] = $covered;
            $l['status'] = $covered >= $l['amount'] ? 'paid' : ($l['dueOn'] <= $today ? 'overdue' : ($covered > 0 ? 'part' : 'upcoming'));
        }
        unset($l);
        $next = null;
        foreach ($lines as $l) if (in_array($l['status'], ['overdue', 'part', 'upcoming'], true)) { $next = $l; break; }
        return [
            'student' => [
                'id' => (int)$student['id'], 'name' => trim($student['first_name'] . ' ' . ($student['last_name'] ?? '')),
                'admissionNo' => $student['admission_no'], 'class' => $enrol ? $enrol['grade'] . ' ' . $enrol['division'] : null,
            ],
            'yearId' => $yearId,
            'lines' => $lines,
            'payments' => array_map(self::paymentOut(...), $payments),
            'next' => $next,
        ] + $balance;
    }

    /** Body: { amount, mode, reference?, paidOn?, note? }. Gives the receipt number. */
    private function pay(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $studentId = $req->intParam('studentId');
        $this->requireChild($req, $studentId);
        $yearId = $this->yearId($req);
        $in = $req->input();
        $amount = $in->int('amount', true, 1);
        if ($amount > 10000000) throw HttpError::badRequest('Amount looks too large.', ['field' => 'amount']);
        $mode = $in->oneOf('mode', self::MODES, false) ?? 'cash';
        $reference = $in->str('reference', 80, false);
        if (in_array($mode, ['cheque', 'upi', 'bank'], true) && !$reference) {
            throw HttpError::badRequest($mode === 'cheque' ? 'Please enter the cheque number.' : 'Please enter the transaction ID.', ['field' => 'reference']);
        }
        $paidOn = $in->date('paidOn', false) ?? date('Y-m-d');
        if ($paidOn > date('Y-m-d')) throw HttpError::badRequest('Payment date cannot be in the future.', ['field' => 'paidOn']);
        $db = $this->s->db;
        $id = $db->tx(function () use ($db, $schoolId, $yearId, $studentId, $amount, $mode, $reference, $paidOn, $in, $req) {
            $db->one('SELECT id FROM schools WHERE id = ? FOR UPDATE', [$schoolId]); // one receipt number at a time
            $prefix = 'R' . date('y') . '-';
            $last = (string)$db->value('SELECT MAX(receipt_no) FROM fee_payments WHERE school_id = ? AND receipt_no LIKE ?', [$schoolId, "$prefix%"]);
            $next = $last ? (int)substr($last, strlen($prefix)) + 1 : 1;
            return $db->insert('fee_payments', [
                'school_id' => $schoolId, 'academic_year_id' => $yearId, 'student_id' => $studentId,
                'receipt_no' => $prefix . str_pad((string)$next, 5, '0', STR_PAD_LEFT),
                'amount' => $amount, 'mode' => $mode, 'reference' => $reference, 'paid_on' => $paidOn,
                'note' => $in->str('note', 200, false), 'received_by' => $req->userId(),
            ]);
        });
        $this->s->events->emit('fees.paid', ['id' => $id, 'student_id' => $studentId, 'amount' => $amount, 'mode' => $mode], $req);
        return Response::created(['payment' => $this->loadPayment($schoolId, $id)]);
    }

    private function loadPayment(int $schoolId, int $id): array
    {
        $p = $this->s->db->one(
            "SELECT p.*, u.name AS received_by_name, st.first_name, st.last_name, st.admission_no,
                    (SELECT CONCAT(g.name, ' ', d.name) FROM enrolments e JOIN divisions d ON d.id = e.division_id JOIN grades g ON g.id = d.grade_id
                      WHERE e.student_id = p.student_id AND e.academic_year_id = p.academic_year_id LIMIT 1) AS class_label,
                    y.name AS year_name
               FROM fee_payments p JOIN students st ON st.id = p.student_id
               JOIN academic_years y ON y.id = p.academic_year_id LEFT JOIN users u ON u.id = p.received_by
              WHERE p.school_id = ? AND p.id = ?",
            [$schoolId, $id],
        ) ?? throw HttpError::notFound('Receipt not found.');
        return self::paymentOut($p) + [
            'studentId' => (int)$p['student_id'],
            'student' => trim($p['first_name'] . ' ' . ($p['last_name'] ?? '')),
            'admissionNo' => $p['admission_no'], 'class' => $p['class_label'], 'year' => $p['year_name'],
        ];
    }

    /** A receipt (to print or share). Staff, or the parent of that child. */
    private function receipt(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $p = $this->loadPayment($schoolId, $req->intParam('paymentId'));
        $this->requireChild($req, $p['studentId']);
        $school = $req->tenant->school;
        return ['payment' => $p, 'school' => ['name' => $school['name'], 'city' => $school['city'] ?? null, 'phone' => $school['phone'] ?? null, 'logoUrl' => $school['logo_url'] ?? null]];
    }

    private function cancel(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('paymentId');
        $p = $this->s->db->one('SELECT * FROM fee_payments WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?? throw HttpError::notFound('Receipt not found.');
        if ($p['cancelled_at']) throw HttpError::conflict('This receipt is already cancelled.');
        $reason = $req->input()->str('reason', 200);
        $this->s->db->exec('UPDATE fee_payments SET cancelled_at = NOW(), cancel_reason = ? WHERE id = ?', [$reason, $id]);
        $this->s->events->emit('fees.cancelled', ['id' => $id, 'receipt' => $p['receipt_no'], 'amount' => (int)$p['amount'], 'reason' => $reason], $req);
        return ['payment' => $this->loadPayment($schoolId, $id)];
    }

    /** Body: { label, amount (minus for a concession), dueOn? }. */
    private function addAdjustment(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $studentId = $req->intParam('studentId');
        $this->requireChild($req, $studentId);
        $yearId = $this->yearId($req);
        $in = $req->input();
        $raw = $req->input()->str('amount', 12);
        if (!preg_match('/^-?\d+$/', $raw) || (int)$raw === 0 || abs((int)$raw) > 10000000) {
            throw HttpError::badRequest('Amount must be a whole number of rupees (minus for a concession).', ['field' => 'amount']);
        }
        $id = $this->s->db->insert('fee_adjustments', [
            'school_id' => $schoolId, 'academic_year_id' => $yearId, 'student_id' => $studentId,
            'label' => $in->str('label', 80), 'amount' => (int)$raw,
            'due_on' => $in->date('dueOn', false) ?? date('Y-m-d'), 'created_by' => $req->userId(),
        ]);
        $this->s->events->emit('fees.adjusted', ['id' => $id, 'student_id' => $studentId, 'amount' => (int)$raw], $req);
        return Response::created(['id' => $id]);
    }

    private function deleteAdjustment(Request $req): array
    {
        $id = $req->intParam('adjustmentId');
        $a = $this->s->db->one('SELECT * FROM fee_adjustments WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $id]) ?? throw HttpError::notFound();
        $this->s->db->exec('DELETE FROM fee_adjustments WHERE id = ?', [$id]);
        $this->s->events->emit('fees.adjustment_removed', ['id' => $id, 'student_id' => (int)$a['student_id'], 'amount' => (int)$a['amount']], $req);
        return ['ok' => true];
    }

    // ---------- collection ----------

    /** Payments between ?from= and ?to= (default today), newest first, with totals by mode. */
    private function payments(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        [$from, $to] = $this->range($req);
        $rows = $this->s->db->all(
            'SELECT p.*, u.name AS received_by_name, st.first_name, st.last_name, st.admission_no
               FROM fee_payments p JOIN students st ON st.id = p.student_id LEFT JOIN users u ON u.id = p.received_by
              WHERE p.school_id = ? AND p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on DESC, p.id DESC LIMIT 1000',
            [$schoolId, $from, $to],
        );
        $byMode = array_fill_keys(self::MODES, 0);
        foreach ($rows as $r) if (!$r['cancelled_at']) $byMode[$r['mode']] += (int)$r['amount'];
        return [
            'from' => $from, 'to' => $to,
            'payments' => array_map(fn($r) => self::paymentOut($r) + [
                'studentId' => (int)$r['student_id'], 'student' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')), 'admissionNo' => $r['admission_no'],
            ], $rows),
            'byMode' => array_filter($byMode),
            'total' => array_sum($byMode),
        ];
    }

    private function range(Request $req): array
    {
        $from = (string)($req->query['from'] ?? date('Y-m-d'));
        $to = (string)($req->query['to'] ?? $from);
        foreach ([$from, $to] as $d) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) throw HttpError::badRequest('Dates look like 2026-06-01.');
        }
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    /** For the fees home: collected today and this month, what is pending and overdue. */
    private function summary(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $today = date('Y-m-d');
        $sum = fn(string $from) => (int)$this->s->db->value(
            'SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE school_id = ? AND cancelled_at IS NULL AND paid_on BETWEEN ? AND ?',
            [$schoolId, $from, $today],
        );
        $students = $this->students($req);
        $hasStructure = (bool)$this->s->db->value('SELECT 1 FROM fee_items WHERE school_id = ? AND academic_year_id = ? LIMIT 1', [$schoolId, $this->yearId($req)]);
        return [
            'today' => $sum($today),
            'month' => $sum(date('Y-m-01')),
            'hasStructure' => $hasStructure,
        ] + $students['totals'];
    }

    // ---------- shapes ----------

    private static function itemOut(array $i): array
    {
        return ['id' => (int)$i['id'], 'gradeId' => (int)$i['grade_id'], 'head' => $i['head'], 'amount' => (int)$i['amount'], 'dueOn' => $i['due_on']];
    }

    private static function paymentOut(array $p): array
    {
        return [
            'id' => (int)$p['id'], 'receiptNo' => $p['receipt_no'], 'amount' => (int)$p['amount'], 'mode' => $p['mode'],
            'reference' => $p['reference'], 'paidOn' => $p['paid_on'], 'note' => $p['note'],
            'receivedBy' => $p['received_by_name'] ?? null,
            'cancelled' => $p['cancelled_at'] !== null, 'cancelReason' => $p['cancel_reason'],
        ];
    }
}
