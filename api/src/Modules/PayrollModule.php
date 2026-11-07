<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Events, HttpError, Input, Module, Request, Response, Roles, Router, Services};

/**
 * Payroll. Accounts sets each employee's monthly salary once (basic, allowances, fixed
 * deductions, PF on or off). Each month: make the salary sheet, type unpaid leave days and any
 * bonus or advance recovery, finalise, mark paid. Staff see their own payslips.
 * Unpaid days reduce basic and allowances in proportion; fixed deductions stay as they are.
 */
final class PayrollModule implements Module
{
    public const PF_RATE = 0.12;
    public const PF_WAGE_CAP = 15000;
    public const PAY_MODES = ['bank', 'cash', 'upi', 'cheque'];

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'payroll'; }

    public function permissions(): array
    {
        return ['payroll.manage' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS]];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/payroll';
        $r->get("$b/salaries", $this->salaries(...), ['perm' => 'payroll.manage']);
        $r->put("$b/salaries/{staffId}", $this->setSalary(...), ['perm' => 'payroll.manage']);
        $r->get("$b/runs", $this->runs(...), ['perm' => 'payroll.manage']);
        $r->post("$b/runs", $this->createRun(...), ['perm' => 'payroll.manage']);
        $r->get("$b/runs/{runId}", $this->getRun(...), ['perm' => 'payroll.manage']);
        $r->delete("$b/runs/{runId}", $this->deleteRun(...), ['perm' => 'payroll.manage']);
        $r->patch("$b/runs/{runId}/slips/{slipId}", $this->updateSlip(...), ['perm' => 'payroll.manage']);
        $r->post("$b/runs/{runId}/finalise", $this->finalise(...), ['perm' => 'payroll.manage']);
        $r->post("$b/runs/{runId}/pay", $this->pay(...), ['perm' => 'payroll.manage']);
        $r->get("$b/my-payslips", $this->myPayslips(...));
        $r->get("$b/payslips/{slipId}", $this->payslip(...));
    }

    // ---------- the sum ----------

    /**
     * Earnings and deductions for one month.
     * @param array{basic:int, allowances:list<array{name:string,amount:int}>, deductions:list<array{name:string,amount:int}>, pf:bool} $salary
     */
    public static function calculate(array $salary, int $days, float $unpaidDays, int $bonus = 0, int $advance = 0): array
    {
        $factor = $days > 0 ? max(0.0, ($days - $unpaidDays) / $days) : 0.0;
        $basic = (int)round($salary['basic'] * $factor);
        $earnings = [['name' => 'Basic', 'amount' => $basic]];
        foreach ($salary['allowances'] as $a) $earnings[] = ['name' => $a['name'], 'amount' => (int)round($a['amount'] * $factor)];
        if ($bonus > 0) $earnings[] = ['name' => 'Bonus', 'amount' => $bonus];
        $deductions = [];
        if ($salary['pf']) $deductions[] = ['name' => 'PF (12%)', 'amount' => (int)round(min($basic, self::PF_WAGE_CAP) * self::PF_RATE)];
        foreach ($salary['deductions'] as $d) $deductions[] = ['name' => $d['name'], 'amount' => (int)$d['amount']];
        if ($advance > 0) $deductions[] = ['name' => 'Advance recovery', 'amount' => $advance];
        $gross = array_sum(array_column($earnings, 'amount'));
        $ded = array_sum(array_column($deductions, 'amount'));
        return ['earnings' => $earnings, 'deductions' => $deductions, 'gross' => $gross, 'totalDeductions' => $ded, 'net' => max(0, $gross - $ded)];
    }

    // ---------- salaries ----------

    private function salaryOf(?array $row): array
    {
        return [
            'basic' => (int)($row['basic'] ?? 0),
            'allowances' => $row && $row['allowances'] ? json_decode($row['allowances'], true) : [],
            'deductions' => $row && $row['deductions'] ? json_decode($row['deductions'], true) : [],
            'pf' => (bool)($row['pf'] ?? false),
            'bankName' => $row['bank_name'] ?? null,
            'accountLast4' => $row['account_last4'] ?? null,
        ];
    }

    private function salaries(Request $req): array
    {
        $rows = $this->s->db->all(
            "SELECT sf.id, sf.name, sf.designation, sf.employee_code, sf.role, ss.*
               FROM staff sf LEFT JOIN staff_salaries ss ON ss.staff_id = sf.id
              WHERE sf.school_id = ? AND sf.status = 'active' ORDER BY sf.name",
            [$req->tenant->schoolId],
        );
        $out = array_map(function ($r) {
            $sal = $this->salaryOf($r['basic'] === null ? null : $r);
            $month = self::calculate($sal, 30, 0);
            return [
                'staffId' => (int)$r['id'], 'name' => $r['name'], 'designation' => $r['designation'], 'employeeCode' => $r['employee_code'],
                'salary' => $sal, 'gross' => $month['gross'], 'net' => $month['net'], 'set' => $sal['basic'] > 0,
            ];
        }, $rows);
        return ['staff' => $out, 'totalGross' => array_sum(array_column($out, 'gross')), 'totalNet' => array_sum(array_column($out, 'net'))];
    }

    private static function parts(Input $in, string $key): array
    {
        $out = [];
        foreach ($in->list($key) as $i => $p) {
            if (!is_array($p)) throw HttpError::badRequest("$key is not valid.", ['field' => $key]);
            $name = trim((string)($p['name'] ?? ''));
            $amount = $p['amount'] ?? '';
            if ($name === '' && ($amount === '' || $amount === 0 || $amount === '0')) continue; // empty row on the form
            if ($name === '' || mb_strlen($name) > 40) throw HttpError::badRequest('Each line needs a short name.', ['field' => $key]);
            if (!is_numeric($amount) || (int)$amount != $amount || (int)$amount < 0 || (int)$amount > 10000000) throw HttpError::badRequest("Amount for $name must be whole rupees.", ['field' => $key]);
            $out[] = ['name' => $name, 'amount' => (int)$amount];
        }
        if (count($out) > 12) throw HttpError::badRequest('At most 12 lines.', ['field' => $key]);
        return $out;
    }

    /** Body: { basic, allowances: [{name, amount}], deductions: [{name, amount}], pf, bankName?, accountLast4? } */
    private function setSalary(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $staffId = $req->intParam('staffId');
        $this->s->db->one("SELECT id FROM staff WHERE school_id = ? AND id = ?", [$schoolId, $staffId]) ?? throw HttpError::notFound('Employee not found.');
        $in = $req->input();
        $basic = $in->int('basic', true, 0);
        if ($basic > 10000000) throw HttpError::badRequest('Basic looks too large.', ['field' => 'basic']);
        $last4 = $in->str('accountLast4', 4, false);
        if ($last4 !== null && !preg_match('/^\d{4}$/', $last4)) throw HttpError::badRequest('Only the last 4 digits of the account number.', ['field' => 'accountLast4']);
        $row = [
            'basic' => $basic,
            'allowances' => json_encode(self::parts($in, 'allowances'), JSON_UNESCAPED_UNICODE),
            'deductions' => json_encode(self::parts($in, 'deductions'), JSON_UNESCAPED_UNICODE),
            'pf' => $in->bool('pf') ? 1 : 0,
            'bank_name' => $in->str('bankName', 80, false),
            'account_last4' => $last4,
            'updated_by' => $req->userId(),
        ];
        $this->s->db->exec(
            'INSERT INTO staff_salaries (staff_id, school_id, basic, allowances, deductions, pf, bank_name, account_last4, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE basic = VALUES(basic), allowances = VALUES(allowances), deductions = VALUES(deductions),
                pf = VALUES(pf), bank_name = VALUES(bank_name), account_last4 = VALUES(account_last4), updated_by = VALUES(updated_by)',
            [$staffId, $schoolId, $row['basic'], $row['allowances'], $row['deductions'], $row['pf'], $row['bank_name'], $row['account_last4'], $row['updated_by']],
        );
        $this->s->events->emit('payroll.salary_set', ['id' => $staffId, 'basic' => $basic], $req);
        $sal = $this->salaryOf($this->s->db->one('SELECT * FROM staff_salaries WHERE staff_id = ?', [$staffId]));
        return ['salary' => $sal] + self::calculate($sal, 30, 0);
    }

    // ---------- monthly salary sheet ----------

    private function runs(Request $req): array
    {
        $rows = $this->s->db->all(
            'SELECT r.*, COUNT(p.id) AS people, COALESCE(SUM(p.gross), 0) AS gross, COALESCE(SUM(p.net), 0) AS net,
                    SUM(p.paid_on IS NOT NULL) AS paid
               FROM payroll_runs r LEFT JOIN payslips p ON p.run_id = r.id
              WHERE r.school_id = ? GROUP BY r.id ORDER BY r.month DESC LIMIT 36',
            [$req->tenant->schoolId],
        );
        return ['runs' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'month' => $r['month'], 'status' => $r['status'], 'people' => (int)$r['people'],
            'gross' => (int)$r['gross'], 'net' => (int)$r['net'], 'paid' => (int)$r['paid'],
        ], $rows)];
    }

    /** Body: { month: "2026-11" }. One sheet per month, for every active employee with a salary. */
    private function createRun(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $month = (string)$req->input()->str('month', 7);
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$d || $d->format('Y-m') !== $month) throw HttpError::badRequest('Month looks like 2026-11.', ['field' => 'month']);
        if ($this->s->db->value('SELECT id FROM payroll_runs WHERE school_id = ? AND month = ?', [$schoolId, $month])) {
            throw HttpError::conflict('The salary sheet for this month is already made.');
        }
        $staff = $this->s->db->all(
            "SELECT sf.id, ss.* FROM staff sf JOIN staff_salaries ss ON ss.staff_id = sf.id
              WHERE sf.school_id = ? AND sf.status = 'active' AND ss.basic > 0",
            [$schoolId],
        );
        if (!$staff) throw HttpError::badRequest('First set the salary of your staff.');
        $days = (int)$d->format('t');
        $id = $this->s->db->tx(function () use ($schoolId, $month, $staff, $days, $req) {
            $id = $this->s->db->insert('payroll_runs', ['school_id' => $schoolId, 'month' => $month, 'created_by' => $req->userId()]);
            foreach ($staff as $row) {
                $calc = self::calculate($this->salaryOf($row), $days, 0);
                $this->s->db->insert('payslips', [
                    'school_id' => $schoolId, 'run_id' => $id, 'staff_id' => (int)$row['id'], 'days_in_month' => $days,
                    'earnings' => json_encode($calc['earnings'], JSON_UNESCAPED_UNICODE), 'deductions' => json_encode($calc['deductions'], JSON_UNESCAPED_UNICODE),
                    'gross' => $calc['gross'], 'total_deductions' => $calc['totalDeductions'], 'net' => $calc['net'],
                ]);
            }
            return $id;
        });
        $this->s->events->emit('payroll.run_created', ['id' => $id, 'month' => $month, 'people' => count($staff)], $req);
        return Response::created($this->runOut($schoolId, $id));
    }

    private function run(Request $req): array
    {
        return $this->s->db->one('SELECT * FROM payroll_runs WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $req->intParam('runId')])
            ?? throw HttpError::notFound('Salary sheet not found.');
    }

    private function runOut(int $schoolId, int $runId): array
    {
        $run = $this->s->db->one('SELECT * FROM payroll_runs WHERE school_id = ? AND id = ?', [$schoolId, $runId]);
        $slips = $this->s->db->all(
            'SELECT p.*, sf.name, sf.designation, sf.employee_code FROM payslips p JOIN staff sf ON sf.id = p.staff_id
              WHERE p.run_id = ? ORDER BY sf.name',
            [$runId],
        );
        $out = array_map(self::slipOut(...), $slips);
        return [
            'run' => ['id' => (int)$run['id'], 'month' => $run['month'], 'status' => $run['status'], 'finalisedAt' => $run['finalised_at']],
            'slips' => $out,
            'totals' => [
                'gross' => array_sum(array_column($out, 'gross')), 'deductions' => array_sum(array_column($out, 'totalDeductions')),
                'net' => array_sum(array_column($out, 'net')), 'paid' => count(array_filter($out, fn($s) => $s['paidOn'] !== null)),
            ],
        ];
    }

    private function getRun(Request $req): array
    {
        $run = $this->run($req);
        return $this->runOut($req->tenant->schoolId, (int)$run['id']);
    }

    private function deleteRun(Request $req): array
    {
        $run = $this->run($req);
        if ($run['status'] !== 'draft') throw HttpError::conflict('A finalised salary sheet cannot be deleted.');
        $this->s->db->exec('DELETE FROM payroll_runs WHERE id = ?', [$run['id']]);
        $this->s->events->emit('payroll.run_deleted', ['id' => (int)$run['id'], 'month' => $run['month']], $req);
        return ['ok' => true];
    }

    /** Body: { unpaidDays?, bonus?, advance? }. Only while the sheet is a draft. */
    private function updateSlip(Request $req): array
    {
        $run = $this->run($req);
        if ($run['status'] !== 'draft') throw HttpError::conflict('This salary sheet is final. It cannot be changed.');
        $slip = $this->s->db->one('SELECT * FROM payslips WHERE run_id = ? AND id = ?', [$run['id'], $req->intParam('slipId')]) ?? throw HttpError::notFound();
        $in = $req->input();
        $unpaid = (float)$slip['unpaid_days'];
        if ($in->has('unpaidDays')) {
            $v = $in->str('unpaidDays', 5);
            if (!preg_match('/^\d{1,2}(\.[05])?$/', $v) || (float)$v > (int)$slip['days_in_month']) throw HttpError::badRequest('Unpaid days: a number like 2 or 1.5.', ['field' => 'unpaidDays']);
            $unpaid = (float)$v;
        }
        $bonus = $in->has('bonus') ? $in->int('bonus') : (int)$slip['bonus'];
        $advance = $in->has('advance') ? $in->int('advance') : (int)$slip['advance'];
        $salary = $this->salaryOf($this->s->db->one('SELECT * FROM staff_salaries WHERE staff_id = ?', [$slip['staff_id']]));
        $calc = self::calculate($salary, (int)$slip['days_in_month'], $unpaid, $bonus, $advance);
        $this->s->db->exec(
            'UPDATE payslips SET unpaid_days = ?, bonus = ?, advance = ?, earnings = ?, deductions = ?, gross = ?, total_deductions = ?, net = ? WHERE id = ?',
            [$unpaid, $bonus, $advance, json_encode($calc['earnings'], JSON_UNESCAPED_UNICODE), json_encode($calc['deductions'], JSON_UNESCAPED_UNICODE), $calc['gross'], $calc['totalDeductions'], $calc['net'], $slip['id']],
        );
        $this->s->events->emit('payroll.slip_changed', ['id' => (int)$slip['id'], 'unpaid_days' => $unpaid, 'bonus' => $bonus, 'advance' => $advance], $req);
        return $this->runOut($req->tenant->schoolId, (int)$run['id']);
    }

    private function finalise(Request $req): array
    {
        $run = $this->run($req);
        if ($run['status'] === 'final') throw HttpError::conflict('Already final.');
        $this->s->db->exec("UPDATE payroll_runs SET status = 'final', finalised_at = NOW() WHERE id = ?", [$run['id']]);
        $this->s->events->emit('payroll.finalised', ['id' => (int)$run['id'], 'month' => $run['month']], $req);
        return $this->runOut($req->tenant->schoolId, (int)$run['id']);
    }

    /** Body: { slipIds?: [..] (default: everyone not yet paid), paidOn?, mode, reference? }. Final sheets only. */
    private function pay(Request $req): array
    {
        $run = $this->run($req);
        if ($run['status'] !== 'final') throw HttpError::conflict('Finalise the salary sheet before marking salaries paid.');
        $in = $req->input();
        $mode = $in->oneOf('mode', self::PAY_MODES, false) ?? 'bank';
        $paidOn = $in->date('paidOn', false) ?? date('Y-m-d');
        $ids = array_values(array_unique(array_map('intval', $in->list('slipIds'))));
        $where = 'run_id = ? AND paid_on IS NULL';
        $args = [$paidOn, $mode, $in->str('reference', 80, false), $run['id']];
        if ($ids) { $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
        $n = $this->s->db->exec("UPDATE payslips SET paid_on = ?, pay_mode = ?, pay_reference = ? WHERE $where", $args);
        $this->s->events->emit('payroll.paid', ['id' => (int)$run['id'], 'count' => $n, 'mode' => $mode], $req);
        return $this->runOut($req->tenant->schoolId, (int)$run['id']);
    }

    // ---------- payslips ----------

    private function myPayslips(Request $req): array
    {
        $rows = $this->s->db->all(
            "SELECT p.*, r.month, sf.name, sf.designation, sf.employee_code FROM payslips p
               JOIN payroll_runs r ON r.id = p.run_id AND r.status = 'final'
               JOIN staff sf ON sf.id = p.staff_id
              WHERE p.school_id = ? AND sf.user_id = ? ORDER BY r.month DESC LIMIT 24",
            [$req->tenant->schoolId, $req->userId()],
        );
        return ['payslips' => array_map(fn($r) => self::slipOut($r) + ['month' => $r['month']], $rows)];
    }

    /** One payslip: payroll staff see any; an employee sees their own once the month is final. */
    private function payslip(Request $req): array
    {
        $t = $req->tenant;
        $p = $this->s->db->one(
            'SELECT p.*, r.month, r.status AS run_status, sf.name, sf.designation, sf.employee_code, sf.user_id, ss.bank_name, ss.account_last4
               FROM payslips p JOIN payroll_runs r ON r.id = p.run_id JOIN staff sf ON sf.id = p.staff_id
               LEFT JOIN staff_salaries ss ON ss.staff_id = p.staff_id
              WHERE p.school_id = ? AND p.id = ?',
            [$t->schoolId, $req->intParam('slipId')],
        ) ?? throw HttpError::notFound('Payslip not found.');
        $own = $p['user_id'] !== null && (int)$p['user_id'] === $req->userId() && $p['run_status'] === 'final';
        if (!$own && !$t->can('payroll.manage')) throw HttpError::forbidden();
        $school = $t->school;
        return [
            'payslip' => self::slipOut($p) + ['month' => $p['month'], 'final' => $p['run_status'] === 'final', 'bankName' => $p['bank_name'], 'accountLast4' => $p['account_last4']],
            'school' => ['name' => $school['name'], 'city' => $school['city'] ?? null, 'phone' => $school['phone'] ?? null],
        ];
    }

    private static function slipOut(array $p): array
    {
        return [
            'id' => (int)$p['id'], 'staffId' => (int)$p['staff_id'], 'name' => $p['name'], 'designation' => $p['designation'],
            'employeeCode' => $p['employee_code'], 'daysInMonth' => (int)$p['days_in_month'], 'unpaidDays' => (float)$p['unpaid_days'],
            'bonus' => (int)$p['bonus'], 'advance' => (int)$p['advance'],
            'earnings' => json_decode($p['earnings'], true), 'deductions' => json_decode($p['deductions'], true),
            'gross' => (int)$p['gross'], 'totalDeductions' => (int)$p['total_deductions'], 'net' => (int)$p['net'],
            'paidOn' => $p['paid_on'], 'payMode' => $p['pay_mode'], 'payReference' => $p['pay_reference'],
        ];
    }
}
