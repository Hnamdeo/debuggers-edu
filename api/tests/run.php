<?php
declare(strict_types=1);

/**
 * Integration tests: run the real app against a real MySQL/MariaDB database.
 *   SCHOOL_TEST_DB=mysql://user:pass@127.0.0.1:3306/school_test php tests/run.php
 * The database is wiped first, so never point this at a live database.
 */
require dirname(__DIR__) . '/src/autoload.php';

use School\Core\{App, Config, Db, Migrator, Request};

$url = parse_url(getenv('SCHOOL_TEST_DB') ?: 'mysql://school:school@127.0.0.1:3306/school_test');
$dbName = ltrim($url['path'] ?? '', '/');
if (!str_contains($dbName, 'test')) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(1); }
Config::set([
    'db_host' => ($url['host'] ?? '127.0.0.1') . (isset($url['port']) ? ';port=' . $url['port'] : ''),
    'db_name' => $dbName, 'db_user' => $url['user'] ?? 'root', 'db_pass' => $url['pass'] ?? '',
    'deploy_key' => str_repeat('k', 32), 'app_url' => 'https://school.test',
]);

$db = Db::get();
$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($db->all('SHOW TABLES') as $t) $db->pdo->exec('DROP TABLE `' . array_values($t)[0] . '`');
$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$app = new App($db, require dirname(__DIR__) . '/src/modules.php');

$passed = 0;
$failed = 0;
function check(bool $ok, string $what, mixed $got = null): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: $what" . ($got !== null ? ' -> ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . PHP_EOL;
}
function call(string $method, string $path, array $body = [], ?string $token = null, array $query = [], array $headers = []): array
{
    global $app;
    if ($token) $headers['authorization'] = "Bearer $token";
    $res = $app->handle(new Request($method, $path, $query, $body, $headers));
    return [$res->status, $res->body];
}
function inviteToken(?string $link): string
{
    parse_str((string)parse_url((string)$link, PHP_URL_QUERY), $q);
    return $q['token'] ?? '';
}
$key = ['x-deploy-key' => str_repeat('k', 32)];

// --- deploy + first platform admin
[$st, $b] = call('POST', '/system/migrate', [], null, [], ['x-deploy-key' => 'wrong']);
check($st === 403, 'migrate needs the deploy key', $st);
[$st, $b] = call('POST', '/system/migrate', [], null, [], $key);
check($st === 200 && count($b['applied']) === 6, 'migrations apply', $b);
[$st, $b] = call('POST', '/system/migrate', [], null, [], $key);
check($b['applied'] === [], 'migrations run once', $b);
check(count(Migrator::statements("-- c\nSELECT 1;\nSELECT 2;\n")) === 2, 'migration splitter');

[$st, $b] = call('POST', '/system/bootstrap', ['name' => 'Team', 'email' => 'team@thinkletots.com', 'password' => 'short'], null, [], $key);
check($st === 400, 'bootstrap rejects short password', $b);
[$st, $b] = call('POST', '/system/bootstrap', ['name' => 'Team', 'email' => 'team@thinkletots.com', 'password' => 'team-password-1'], null, [], $key);
check($st === 201, 'bootstrap creates platform admin', $b);
[$st] = call('POST', '/system/bootstrap', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'xxxxxxxxxx'], null, [], $key);
check($st === 409, 'bootstrap only once');

[$st, $b] = call('POST', '/auth/login', ['login' => 'team@thinkletots.com', 'password' => 'wrong-pass']);
check($st === 401, 'wrong password refused');
[$st, $b] = call('POST', '/auth/login', ['login' => 'TEAM@thinkletots.com', 'password' => 'team-password-1']);
check($st === 200 && $b['user']['isPlatformAdmin'] === true, 'platform admin signs in', $b);
$team = $b['token'];

// --- create two schools (tenants)
[$st, $b] = call('POST', '/platform/schools', ['slug' => 'Bad Slug!', 'name' => 'X'], $team);
check($st === 400, 'bad link name refused', $b);
[$st, $b] = call('POST', '/platform/schools', ['slug' => 'bharatmata', 'name' => 'Bharat Mata School', 'board' => 'CGBSE', 'state' => 'Chhattisgarh', 'city' => 'Raipur', 'template' => 'primary'], $team);
check($st === 201, 'school created', $b);
$s1 = $b['school']['id'];
[$st, $b] = call('POST', '/platform/schools', ['slug' => 'dps-bhopal', 'name' => 'DPS Bhopal', 'board' => 'CBSE', 'state' => 'Madhya Pradesh', 'template' => 'k10'], $team);
$s2 = $b['school']['id'];
[$st, $b] = call('POST', '/platform/schools', ['slug' => 'bharatmata', 'name' => 'Copy'], $team);
check($st === 409, 'duplicate link name refused', $st);
[$st, $b] = call('GET', '/public/schools/bharatmata');
check($st === 200 && $b['name'] === 'Bharat Mata School', 'public school page', $b);

// --- template created year, classes, subjects
[$st, $b] = call('GET', "/schools/$s1/setup", [], $team);
check(count($b['grades']) === 8 && $b['grades'][0]['name'] === 'Nursery', 'primary template classes', array_column($b['grades'], 'name'));
check(count($b['divisions']) === 8 && count($b['years']) === 1 && $b['years'][0]['isCurrent'], 'sections and year from template', $b['years']);
check(in_array('Hindi', array_column($b['subjects'], 'name'), true), 'subjects from template');
check(array_column($b['grades'], 'stage', 'name') === ['Nursery' => 'pre_primary', 'Jr KG' => 'pre_primary', 'Sr KG' => 'pre_primary', 'Class 1' => 'primary', 'Class 2' => 'primary', 'Class 3' => 'primary', 'Class 4' => 'primary', 'Class 5' => 'primary'], 'class groups from template', array_column($b['grades'], 'stage', 'name'));
check(School\Modules\SetupModule::stageFor('Class 7') === 'middle' && School\Modules\SetupModule::stageFor('Class 10') === 'secondary' && School\Modules\SetupModule::stageFor('XII 12') === 'higher_secondary', 'class group guessing');
[$st] = call('POST', "/schools/$s1/grades", ['name' => 'Class 6'], $team);
[, $b2] = call('GET', "/schools/$s1/setup", [], $team);
check(end($b2['grades'])['stage'] === 'middle', 'new class gets its group', end($b2['grades']));
$c6 = end($b2['grades'])['id'];
[$st] = call('PATCH', "/schools/$s1/grades/$c6", ['name' => 'Class VI'], $team);
check($st === 200, 'class renamed');
call('DELETE', "/schools/$s1/grades/$c6", [], $team);
$divisions = array_column($b['divisions'], 'id', 'label');
$class1 = $divisions['Class 1 A'];

// --- school admin via invite link
[$st, $b] = call('POST', "/platform/schools/$s1/admins", ['name' => 'Principal Sharma', 'phone' => '+91 98765 43210', 'role' => 'principal'], $team);
check($st === 201 && str_contains((string)$b['inviteLink'], 'https://school.test/bharatmata/welcome?token='), 'admin invite link', $b);
$tok = inviteToken($b['inviteLink']);
[$st, $b] = call('GET', '/auth/invite', [], null, ['token' => $tok]);
check($st === 200 && $b['school']['slug'] === 'bharatmata' && $b['login'] === '9876543210', 'invite peek', $b);
[$st, $b] = call('POST', '/auth/welcome', ['token' => $tok, 'password' => 'principal-pass']);
check($st === 200 && $b['schools'][0]['roles'] === ['principal'], 'invite accepted', $b);
[$st] = call('POST', '/auth/welcome', ['token' => $tok, 'password' => 'principal-pass']);
check($st === 409, 'invite used once');
[$st, $b] = call('POST', '/auth/login', ['login' => '98765 43210', 'password' => 'principal-pass']);
check($st === 200, 'principal signs in with mobile', $b);
$principal = $b['token'];

// --- tenant isolation
[$st] = call('GET', "/schools/$s2/setup", [], $principal);
check($st === 403, 'principal cannot open another school');
[$st] = call('GET', '/platform/schools', [], $principal);
check($st === 403, 'principal is not platform admin');

// --- staff
[$st, $b] = call('POST', "/schools/$s1/staff", ['name' => 'Asha Verma', 'phone' => '9123456780', 'role' => 'teacher', 'designation' => 'Class teacher'], $principal);
check($st === 201 && $b['inviteLink'], 'teacher added with link', $b);
$ashaStaff = $b['id'];
call('POST', '/auth/welcome', ['token' => inviteToken($b['inviteLink']), 'password' => 'teacher-pass']);
[, $b] = call('POST', '/auth/login', ['login' => '9123456780', 'password' => 'teacher-pass']);
$teacher = $b['token'];
[$st, $b] = call('POST', "/schools/$s1/staff", ['name' => 'Ramesh (peon)', 'role' => 'staff'], $principal);
check($st === 201 && $b['inviteLink'] === null, 'staff without phone cannot sign in', $b);
[$st] = call('PATCH', "/schools/$s1/divisions/$class1", ['classTeacherId' => $ashaStaff], $principal);
check($st === 200, 'class teacher set');
[$st] = call('PATCH', "/schools/$s1/divisions/$class1", ['classTeacherId' => $ashaStaff], $teacher);
check($st === 403, 'teacher cannot change setup');

// --- students + parents
[$st, $b] = call('POST', "/schools/$s1/students", [
    'admissionNo' => 'A001', 'firstName' => 'Shivi', 'lastName' => 'N', 'gender' => 'girl', 'divisionId' => $class1, 'rollNo' => 1,
    'parentName' => 'Niharika', 'parentPhone' => '9000000001', 'relation' => 'mother',
], $principal);
check($st === 201 && $b['inviteLink'], 'student admitted with parent link', $b);
$shivi = $b['id'];
call('POST', '/auth/welcome', ['token' => inviteToken($b['inviteLink']), 'password' => 'parent-pass']);
[, $b] = call('POST', '/auth/login', ['login' => '9000000001', 'password' => 'parent-pass']);
$parent = $b['token'];
[$st] = call('POST', "/schools/$s1/students", ['admissionNo' => 'A001', 'firstName' => 'Dup'], $principal);
check($st === 409, 'duplicate admission number refused');

[$st, $b] = call('POST', "/schools/$s1/students/import", ['rows' => [
    ['admissionNo' => 'A002', 'firstName' => 'Aarav', 'class' => '1', 'section' => 'a', 'rollNo' => 2, 'parentName' => 'Ravi', 'parentPhone' => '9000000002', 'relation' => 'father'],
    ['admissionNo' => 'A003', 'firstName' => 'Diya', 'class' => 'Class 1', 'rollNo' => 3],
    ['admissionNo' => 'A004', 'firstName' => 'Kabir', 'class' => 'Class 9'],
    ['admissionNo' => 'A001', 'firstName' => 'Dup'],
    ['firstName' => 'No number'],
]], $principal);
check($st === 200 && $b['added'] === 2 && count($b['errors']) === 3, 'import adds good rows, reports bad ones', $b);
check(($b['errors'][0]['row'] ?? 0) === 4, 'import error row numbers match the sheet', $b['errors']);

[$st, $b] = call('GET', "/schools/$s1/students", [], $teacher, ['divisionId' => $class1]);
check(count($b['students']) === 3 && $b['students'][0]['name'] === 'Shivi N', 'teacher sees class list in roll order', $b);
[$st] = call('GET', "/schools/$s1/students", [], $parent);
check($st === 403, 'parent cannot list all students');
[$st, $b] = call('GET', "/schools/$s1/students/$shivi", [], $parent);
check($st === 200 && $b['guardians'][0]['phone'] === null, 'parent sees own child, not contact list', $b);
[$st, $b] = call('GET', "/schools/$s1/students", [], $principal, ['q' => 'Aar']);
$aarav = $b['students'][0]['id'] ?? 0;
[$st] = call('GET', "/schools/$s1/students/$aarav", [], $parent);
check($st === 403, 'parent cannot see another child');

// --- attendance
[$st, $b] = call('GET', "/schools/$s1/attendance/divisions/$class1", [], $teacher);
check($st === 200 && !$b['marked'] && $b['canEdit'] && count($b['students']) === 3, 'attendance sheet', $b);
[$st, $b] = call('PUT', "/schools/$s1/attendance/divisions/$class1", ['marks' => [['studentId' => $aarav, 'status' => 'absent']]], $teacher);
check($st === 200 && $b['counts']['present'] === 2 && $b['counts']['absent'] === 1, 'marking: others default to present', $b);
[$st, $b] = call('GET', "/schools/$s1/attendance/divisions/$class1", [], $teacher);
check($b['marked'] && $b['students'][1]['status'] === 'absent', 'marks saved', $b['students']);
[$st] = call('PUT', "/schools/$s1/attendance/divisions/$class1", ['marks' => [['studentId' => $aarav, 'status' => 'absent']]], $parent);
check($st === 403, 'parent cannot mark');
[$st] = call('PUT', "/schools/$s1/attendance/divisions/$class1", ['date' => '2099-01-01', 'marks' => []], $teacher);
check($st === 400, 'no future attendance');
$class2 = $divisions['Class 2 A'];
call('POST', "/schools/$s1/students", ['admissionNo' => 'B001', 'firstName' => 'Meera', 'divisionId' => $class2], $principal);
[$st] = call('PUT', "/schools/$s1/attendance/divisions/$class2", ['marks' => []], $teacher);
check($st === 403, 'teacher cannot mark another class');
[$st, $b] = call('GET', "/schools/$s1/attendance/summary", [], $principal);
check($b['classesMarked'] === 1 && $b['classesToMark'] === 1 && $b['presentPercent'] === 67, 'principal summary', $b);
[$st, $b] = call('GET', "/schools/$s1/attendance/students/$shivi", [], $parent);
check($st === 200 && $b['counts']['present'] === 1 && $b['presentPercent'] === 100, 'parent sees child month', $b);

// --- homework
[$st, $b] = call('POST', "/schools/$s1/homework", ['divisionIds' => [$class1], 'title' => 'Letter A', 'kind' => 'lesson', 'lessonCode' => 'abc-s1-e01', 'linkUrl' => 'https://youtu.be/x'], $teacher);
check($st === 201, 'teacher gives a lesson as homework', $b);
[$st] = call('POST', "/schools/$s1/homework", ['divisionIds' => [$class1], 'title' => 'x', 'linkUrl' => 'javascript:alert(1)'], $teacher);
check($st === 400, 'unsafe link refused');
[$st, $b] = call('GET', "/schools/$s1/homework", [], $parent);
check(count($b['homework']) === 1 && $b['homework'][0]['lessonCode'] === 'ABC-S1-E01', 'parent sees child homework', $b);
[$st] = call('POST', "/schools/$s1/homework", ['divisionIds' => [$class1], 'title' => 'x'], $parent);
check($st === 403, 'parent cannot give homework');

// --- notices
[$st] = call('POST', "/schools/$s1/notices", ['title' => 'Holiday', 'audience' => 'everyone'], $teacher);
check($st === 403, 'teacher cannot send school-wide notice');
[$st] = call('POST', "/schools/$s1/notices", ['title' => 'Bring crayons', 'audience' => 'division', 'divisionId' => $class1], $teacher);
check($st === 201, 'teacher sends class notice');
call('POST', "/schools/$s1/notices", ['title' => 'Staff meeting', 'audience' => 'staff'], $principal);
call('POST', "/schools/$s1/notices", ['title' => 'Diwali holiday', 'audience' => 'everyone'], $principal);
[, $b] = call('GET', "/schools/$s1/notices", [], $parent);
$titles = array_column($b['notices'], 'title');
check(in_array('Bring crayons', $titles, true) && in_array('Diwali holiday', $titles, true) && !in_array('Staff meeting', $titles, true), 'parent sees right notices', $titles);

// --- home + assistant
[$st, $b] = call('GET', "/schools/$s1/home", [], $parent);
check($st === 200 && $b['actions'][0] === 'children' && $b['children'][0]['today'] === 'present', 'parent home', $b);
[$st, $b] = call('GET', "/schools/$s1/home", [], $teacher);
check($b['my_classes'][0]['markedToday'] === true && in_array('attendance', $b['actions'], true), 'teacher home', $b);
[$st, $b] = call('GET', "/schools/$s1/home", [], $principal);
check($b['school_stats']['students'] === 4 && $b['school_stats']['classesNotMarked'] === 1, 'principal home', $b['school_stats']);
[, $b] = call('POST', "/schools/$s1/assistant", ['text' => 'आज की हाज़िरी लगानी है'], $teacher);
check($b['action'] === 'attendance', 'assistant understands Hindi', $b);
[, $b] = call('POST', "/schools/$s1/assistant", ['text' => 'I want to add student'], $principal);
check($b['action'] === 'add-student', 'assistant picks longest phrase', $b);
[, $b] = call('POST', "/schools/$s1/assistant", ['text' => 'add student'], $teacher);
check($b['action'] === 'students', 'assistant only offers allowed actions', $b);

// --- staff leaving loses access; pause school
[$st] = call('PATCH', "/schools/$s1/staff/$ashaStaff", ['status' => 'left'], $principal);
[$st] = call('GET', "/schools/$s1/home", [], $teacher);
check($st === 403, 'staff who left loses access', $st);
call('PATCH', "/platform/schools/$s1", ['status' => 'paused'], $team);
[$st] = call('GET', "/schools/$s1/home", [], $principal);
check($st === 403, 'paused school blocks users');

// --- sessions + misc
[$st] = call('GET', '/me', [], null);
check($st === 401, 'me needs sign in');
call('POST', '/auth/logout', [], $parent);
[$st] = call('GET', '/me', [], $parent);
check($st === 401, 'logout ends session');
for ($i = 0; $i < 8; $i++) call('POST', '/auth/login', ['login' => '9999999999', 'password' => 'nope-nope'], null, [], ['x-test-ip' => '1.2.3.4']);
[$st] = call('POST', '/auth/login', ['login' => '9999999999', 'password' => 'nope-nope']);
check($st === 429, 'login throttled after many fails', $st);
[$st] = call('GET', '/nope');
check($st === 404, 'unknown route 404');
check((int)$db->value('SELECT COUNT(*) FROM audit_log WHERE school_id = ?', [$s1]) > 10, 'changes are audited');

echo PHP_EOL . "$passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
