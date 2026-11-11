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
    'deploy_key' => str_repeat('k', 32), 'app_url' => 'https://school.test', 'starter_schools' => '',
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
check($st === 200 && count($b['applied']) === 11, 'migrations apply', $b);
[$st, $b] = call('POST', '/system/migrate', [], null, [], $key);
check($b['applied'] === [], 'migrations run once', $b);
$tokenFile = dirname(__DIR__) . '/.deploy-token';
file_put_contents($tokenFile, str_repeat('t', 48) . "\n");
[$st] = call('POST', '/system/migrate', [], null, [], ['x-deploy-token' => str_repeat('x', 48)]);
check($st === 403, 'wrong deploy token refused');
[$st] = call('POST', '/system/migrate', [], null, [], ['x-deploy-token' => str_repeat('t', 48)]);
check($st === 200 && !is_file($tokenFile), 'deploy token works once and is deleted', $st);
[$st] = call('POST', '/system/migrate', [], null, [], ['x-deploy-token' => str_repeat('t', 48)]);
check($st === 403, 'used deploy token refused');
@unlink($tokenFile);
// starter schools from the seed file are added once, by link name
$seedFile = tempnam(sys_get_temp_dir(), 'seed');
file_put_contents($seedFile, json_encode([['slug' => 'seed-one', 'name' => 'Seed One School', 'template' => 'primary'], ['slug' => 'Bad slug!', 'name' => 'x']]));
Config::set(['starter_schools' => $seedFile] + Config::load());
[$st, $b] = call('POST', '/system/migrate', [], null, [], $key);
check($st === 200 && $b['schoolsAdded'] === ['seed-one'], 'starter school added', $b);
check((int)$db->value('SELECT COUNT(*) FROM grades g JOIN schools s ON s.id = g.school_id WHERE s.slug = ?', ['seed-one']) > 0, 'starter school gets classes');
[$st, $b] = call('POST', '/system/migrate', [], null, [], $key);
check($b['schoolsAdded'] === [], 'starter school added only once', $b);
$db->exec('DELETE FROM schools WHERE slug = ?', ['seed-one']);
Config::set(['starter_schools' => ''] + Config::load());
unlink($seedFile);
check(count(Migrator::statements("-- c\nSELECT 1;\nSELECT 2;\n")) === 2, 'migration splitter');

[$st, $b] = call('POST', '/system/bootstrap', ['name' => 'Team', 'email' => 'team@debuggersspace.com', 'password' => 'short'], null, [], $key);
check($st === 400, 'bootstrap rejects short password', $b);
[$st, $b] = call('POST', '/system/bootstrap', ['name' => 'Team', 'email' => 'team@debuggersspace.com', 'password' => 'team-password-1'], null, [], $key);
check($st === 201, 'bootstrap creates platform admin', $b);
[$st] = call('POST', '/system/bootstrap', ['name' => 'X', 'email' => 'x@x.com', 'password' => 'xxxxxxxxxx'], null, [], $key);
check($st === 409, 'bootstrap only once');

[$st, $b] = call('POST', '/auth/login', ['login' => 'team@debuggersspace.com', 'password' => 'wrong-pass']);
check($st === 401, 'wrong password refused');
[$st, $b] = call('POST', '/auth/login', ['login' => 'TEAM@debuggersspace.com', 'password' => 'team-password-1']);
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

// --- admissions
[$st] = call('POST', '/public/schools/bharatmata/admissions', ['firstName' => 'Riya', 'fatherPhone' => '9000000010']);
check($st === 403, 'online admission closed by default', $st);
[$st, $b] = call('PATCH', "/schools/$s1/settings", ['admissionsOpen' => true, 'loginBoard' => ['headline' => 'Admissions open 2027-28', 'lines' => ['Smart classes', '', 'Bus service'], 'imageUrl' => 'https://example.com/banner.jpg']], $principal);
check($st === 200 && $b['school']['admissionsOpen'] === true && $b['school']['loginBoard']['lines'] === ['Smart classes', 'Bus service'], 'school opens admissions + login board', $b['school'] ?? $b);
[$st] = call('PATCH', "/schools/$s1/settings", ['loginBoard' => ['imageUrl' => 'http://bad']], $principal);
check($st === 400, 'login picture must be https');
[$st, $b] = call('GET', '/public/schools/bharatmata');
check($b['admissionsOpen'] === true && $b['loginBoard']['headline'] === 'Admissions open 2027-28', 'login page gets board', $b);
[$st, $b] = call('GET', '/public/schools/bharatmata/admission-form');
check($b['open'] && count($b['grades']) === 8 && count($b['years']) === 2, 'public admission form', $b);
$nurseryId = $b['grades'][0]['id'];
[$st] = call('POST', '/public/schools/bharatmata/admissions', ['firstName' => 'Riya', 'gradeId' => $nurseryId]);
check($st === 400, 'online form needs a parent mobile', $st);
[$st, $b] = call('POST', '/public/schools/bharatmata/admissions', [
    'firstName' => 'Riya', 'lastName' => 'Sahu', 'gender' => 'girl', 'dob' => '2023-05-10', 'gradeId' => $nurseryId, 'academicYear' => '2027-28',
    'fatherName' => 'Mohan Sahu', 'fatherPhone' => '+91 90000 00010', 'motherName' => 'Sita Sahu', 'motherPhone' => '9000000011',
    'address' => 'Shankar Nagar, Raipur', 'bloodGroup' => 'B+', 'category' => 'OBC', 'transport' => 'yes',
], null, [], ['x-test-ip' => '5.5.5.5']);
check($st === 201 && str_starts_with((string)$b['applicationNo'], 'ADM-'), 'parent applies online', $b);
[$st, $b] = call('POST', '/public/schools/bharatmata/admissions', ['firstName' => 'Bot', 'fatherPhone' => '9000000012', 'website' => 'spam']);
check($st === 201 && $b['applicationNo'] === 'ok', 'bot form quietly ignored', $b);
[$st, $b] = call('POST', "/schools/$s1/admissions", ['firstName' => 'Kunal', 'gradeId' => $nurseryId, 'motherPhone' => '9000000013', 'registrationFee' => 500, 'receiptNo' => 'R-1'], $principal);
check($st === 201 && $b['admission']['source'] === 'office' && $b['admission']['registrationFee'] === 500, 'office adds walk-in', $b);
[$st, $b] = call('GET', "/schools/$s1/admissions", [], $principal);
check(count($b['admissions']) === 2 && $b['counts']['enquiry'] === 2, 'admissions list', $b['counts'] ?? $b);
$riya = array_values(array_filter($b['admissions'], fn($a) => $a['firstName'] === 'Riya'))[0];
check($riya['details']['bloodGroup'] === 'B+' && $riya['fatherPhone'] === '9000000010' && $riya['source'] === 'online', 'online details kept', $riya);
[$st] = call('GET', "/schools/$s1/admissions", [], $teacher);
check($st === 403, 'teacher cannot see admissions');
[$st, $b] = call('PATCH', "/schools/$s1/admissions/{$riya['id']}", ['status' => 'registered', 'registrationFee' => 1000, 'receiptNo' => 'R-2', 'religion' => 'Hindu'], $principal);
check($b['admission']['status'] === 'registered' && $b['admission']['details']['religion'] === 'Hindu' && $b['admission']['details']['bloodGroup'] === 'B+', 'application registered, details merged', $b);
$nurseryA = $divisions['Nursery A'];
[$st, $b] = call('POST', "/schools/$s1/admissions/{$riya['id']}/admit", ['divisionId' => $nurseryA], $principal);
check($st === 201 && count($b['parents']) === 2 && $b['parents'][0]['inviteLink'] && $b['admissionNo'] !== '', 'admit creates student + parent links', $b);
$riyaStudent = $b['studentId'];
[$st] = call('POST', "/schools/$s1/admissions/{$riya['id']}/admit", ['divisionId' => $nurseryA], $principal);
check($st === 409, 'cannot admit twice');
[$st, $b] = call('GET', "/schools/$s1/students/$riyaStudent", [], $principal);
check($b['student']['division'] === 'Nursery A' && count($b['guardians']) === 2, 'admitted child is in class with both parents', $b);

// --- syllabus
$subjects = array_column(call('GET', "/schools/$s1/setup", [], $principal)[1]['subjects'], 'id', 'name');
$english = $subjects['English'];
$grade1 = call('GET', "/schools/$s1/setup", [], $principal)[1]['divisions'];
$grade1 = array_values(array_filter($grade1, fn($d) => $d['label'] === 'Class 1 A'))[0]['gradeId'];
[$st] = call('POST', "/schools/$s1/syllabus/units", ['gradeId' => $grade1, 'subjectId' => $english, 'title' => 'Letters'], $teacher);
check($st === 403, 'teacher cannot edit syllabus');
[$st, $b] = call('POST', "/schools/$s1/syllabus/units", ['gradeId' => $grade1, 'subjectId' => $english, 'title' => 'Letters', 'month' => 'June'], $principal);
check($st === 201, 'chapter added', $b);
$unit = $b['id'];
[$st, $b] = call('POST', "/schools/$s1/syllabus/units/$unit/lessons", ['lines' => ['Letter A | abc-s1-e01', 'Letter B | https://youtu.be/b', '', 'Revision A-B']], $principal);
check($st === 201 && count($b['ids']) === 3, 'lessons pasted one per line', $b);
$lessonA = $b['ids'][0];
[$st, $b] = call('POST', "/schools/$s1/syllabus/units/$unit/lessons", ['lines' => ['Bad | javascript:x']], $principal);
check($st === 400 && str_contains($b['error'] ?? '', 'Line 1'), 'bad line named', $b);
[$st, $b] = call('POST', "/schools/$s1/syllabus/plan", ['gradeId' => $grade1, 'subjectId' => $english, 'startOn' => '2026-11-14', 'skip' => ['2026-11-16']], $principal);
check($st === 200 && $b['lessons'] === 3 && $b['to'] === '2026-11-18', 'day-wise plan skips Sunday and holiday', $b);
[$st, $b] = call('GET', "/schools/$s1/syllabus", [], $teacher, ['divisionId' => $class1, 'subjectId' => $english]);
check($st === 200 && $b['total'] === 3 && $b['units'][0]['lessons'][0]['lessonCode'] === 'ABC-S1-E01' && $b['units'][0]['lessons'][1]['plannedOn'] === '2026-11-17', 'teacher sees plan', $b);
[$st] = call('PUT', "/schools/$s1/syllabus/lessons/$lessonA/done", ['divisionId' => $class1], $teacher);
check($st === 200, 'teacher ticks a lesson');
[$st] = call('PUT', "/schools/$s1/syllabus/lessons/$lessonA/done", ['divisionId' => $nurseryA], $principal);
check($st === 400, 'lesson of another class refused');
[$st, $b] = call('GET', "/schools/$s1/syllabus", [], $parent, ['divisionId' => $class1, 'subjectId' => $english]);
check($st === 200 && $b['done'] === 1, 'parent sees progress for own child class', $b);
[$st] = call('GET', "/schools/$s1/syllabus", [], $parent, ['gradeId' => $grade1, 'subjectId' => $english]);
check($st === 400, 'parent must use own class');
[$st] = call('GET', "/schools/$s1/syllabus", [], $parent, ['divisionId' => $class2, 'subjectId' => $english]);
check($st === 403, 'parent cannot see other class');
[$st, $b] = call('GET', "/schools/$s1/syllabus/day", [], $teacher, ['divisionId' => $class1, 'date' => '2026-11-14']);
check(count($b['lessons']) === 1 && $b['lessons'][0]['subject'] === 'English' && $b['lessons'][0]['doneOn'] !== null, 'lessons for a day', $b);
[$st, $b] = call('GET', "/schools/$s1/syllabus/overview", [], $principal);
check($b['classes'][0]['lessons'] === 3 && $b['classes'][0]['planned'] === 3, 'syllabus overview', $b);
[, $b] = call('POST', "/schools/$s1/assistant", ['text' => 'प्रवेश के आवेदन दिखाओ'], $principal);
check($b['action'] === 'admissions', 'assistant: admissions in Hindi', $b);
[, $b] = call('POST', "/schools/$s1/assistant", ['text' => 'open syllabus'], $teacher);
check($b['action'] === 'syllabus', 'assistant: syllabus', $b);

// --- fees
$grades1 = array_column(call('GET', "/schools/$s1/setup", [], $principal)[1]['grades'], 'id', 'name');
$g1 = $grades1['Class 1'];
$past = date('Y-m-d', strtotime('-10 days'));
$future = date('Y-m-d', strtotime('+20 days'));
[$st, $b] = call('POST', "/schools/$s1/fees/items", ['gradeIds' => [$g1, $grades1['Class 2']], 'head' => 'Admission fee', 'amount' => 5000, 'dueOn' => $past], $principal);
check($st === 201 && count($b['ids']) === 2, 'fee set for two classes at once', $b);
[$st, $b] = call('POST', "/schools/$s1/fees/items", ['gradeIds' => [$g1], 'head' => 'Tuition fee', 'amount' => 3000, 'dueOn' => $future, 'instalments' => 4, 'everyMonths' => 3], $principal);
check($st === 201 && count($b['ids']) === 4, 'fee in 4 instalments', $b);
[$st] = call('POST', "/schools/$s1/fees/items", ['gradeIds' => [$g1], 'head' => 'X', 'amount' => 100, 'dueOn' => $past], $teacher);
check($st === 403, 'teacher cannot set fees');
[$st, $b] = call('GET', "/schools/$s1/fees/structure", [], $principal);
$c1 = array_values(array_filter($b['grades'], fn($g) => $g['id'] === $g1))[0];
check(count($c1['items']) === 5 && $c1['total'] === 17000 && $c1['items'][1]['head'] === 'Tuition fee (1 of 4)', 'fee structure per class', $c1);
[$st, $b] = call('GET', "/schools/$s1/fees/students", [], $principal, ['gradeId' => $g1]);
check($st === 200 && count($b['students']) === 3 && $b['totals']['total'] === 51000 && $b['totals']['overdue'] === 15000 && $b['totals']['overdueCount'] === 3, 'class fee list with overdue', $b['totals']);
[$st, $b] = call('POST', "/schools/$s1/fees/students/$shivi/payments", ['amount' => 2000, 'mode' => 'upi'], $principal);
check($st === 400, 'UPI needs a transaction ID', $b);
[$st, $b] = call('POST', "/schools/$s1/fees/students/$shivi/payments", ['amount' => 6000, 'mode' => 'cash'], $principal);
check($st === 201 && $b['payment']['receiptNo'] === 'R' . date('y') . '-00001' && $b['payment']['class'] === 'Class 1 A', 'payment gives receipt', $b);
$receipt1 = $b['payment']['id'];
[$st, $b] = call('POST', "/schools/$s1/fees/students/$shivi/payments", ['amount' => 500, 'mode' => 'upi', 'reference' => 'UPI123'], $principal);
check($b['payment']['receiptNo'] === 'R' . date('y') . '-00002', 'receipt numbers go up', $b);
$receipt2 = $b['payment']['id'];
[$st, $b] = call('POST', "/schools/$s1/fees/students/$shivi/adjustments", ['label' => 'Sibling concession', 'amount' => '-1000'], $principal);
check($st === 201, 'concession added', $b);
[$st, $b] = call('GET', "/schools/$s1/fees/students/$shivi", [], $parent);
check($st === 200 && $b['total'] === 16000 && $b['paid'] === 6500 && $b['balance'] === 9500 && $b['overdue'] === 0, 'parent sees own child fees', [$b['total'], $b['paid'], $b['balance'], $b['overdue']]);
$statuses = array_column($b['lines'], 'status', 'label');
check($statuses['Admission fee'] === 'paid' && $statuses['Sibling concession'] === 'concession' && $statuses['Tuition fee (1 of 4)'] === 'part', 'fee lines marked paid / part', $statuses);
check($b['next']['label'] === 'Tuition fee (1 of 4)', 'next fee to pay', $b['next']);
[$st] = call('GET', "/schools/$s1/fees/students/$aarav", [], $parent);
check($st === 403, 'parent cannot see another child fees');
[$st, $b] = call('GET', "/schools/$s1/fees/payments/$receipt1", [], $parent);
check($st === 200 && $b['payment']['amount'] === 6000 && $b['school']['name'] === 'Bharat Mata School', 'parent opens receipt', $b);
[$st] = call('POST', "/schools/$s1/fees/students/$shivi/payments", ['amount' => 100], $parent);
check($st === 403, 'parent cannot record a payment');
[$st, $b] = call('GET', "/schools/$s1/fees/payments", [], $principal);
check($b['total'] === 6500 && $b['byMode'] === ['cash' => 6000, 'upi' => 500], 'today collection by mode', $b);
[$st] = call('POST', "/schools/$s1/fees/payments/$receipt2/cancel", ['reason' => 'Wrong child'], $principal);
check($st === 200, 'receipt cancelled');
[$st] = call('POST', "/schools/$s1/fees/payments/$receipt2/cancel", ['reason' => 'again'], $principal);
check($st === 409, 'cancel only once');
[$st, $b] = call('GET', "/schools/$s1/fees/summary", [], $principal);
check($b['today'] === 6000 && $b['hasStructure'] && $b['overdueCount'] === 3 && $b['balance'] === 49000, 'fees summary', $b);
[$st, $b] = call('GET', "/schools/$s1/fees/students", [], $principal, ['only' => 'due']);
check(count($b['students']) === 3 && !in_array('Shivi N', array_column($b['students'], 'name'), true), 'only children with overdue fees', array_column($b['students'], 'name'));
[$st] = call('GET', "/schools/$s2/fees/students/$shivi", [], $principal);
check($st === 403 || $st === 404, 'fees stay inside their school', $st);
check(School\Modules\HomeModule::intent('fees baki hai', ['fees', 'students']) === 'fees', 'assistant understands fees');

// --- payroll
$calc = School\Modules\PayrollModule::calculate(['basic' => 20000, 'allowances' => [['name' => 'HRA', 'amount' => 6000]], 'deductions' => [['name' => 'Professional tax', 'amount' => 200]], 'pf' => true], 30, 3, 1000, 500);
check($calc['gross'] === 18000 + 5400 + 1000 && $calc['deductions'][0]['amount'] === 1800 && $calc['net'] === 24400 - 1800 - 200 - 500, 'salary sum with unpaid days, PF cap, bonus, advance', $calc);
[$st, $b] = call('GET', "/schools/$s1/payroll/salaries", [], $principal);
check($st === 200 && count($b['staff']) >= 1 && !$b['staff'][0]['set'], 'staff list for salaries', $b);
[$st] = call('GET', "/schools/$s1/payroll/salaries", [], $teacher);
check($st === 403, 'teacher cannot see salaries');
[$st, $b] = call('POST', "/schools/$s1/payroll/runs", ['month' => '2026-11'], $principal);
check($st === 400, 'salary sheet needs salaries first', $b);
[$st, $b] = call('PUT', "/schools/$s1/payroll/salaries/$ashaStaff", ['basic' => 15000, 'allowances' => [['name' => 'HRA', 'amount' => 3000], ['name' => '', 'amount' => '']], 'deductions' => [['name' => 'Professional tax', 'amount' => 200]], 'pf' => true, 'accountLast4' => '4321'], $principal);
check($st === 200 && $b['gross'] === 18000 && $b['net'] === 18000 - 1800 - 200, 'salary set', $b);
[$st] = call('PUT', "/schools/$s1/payroll/salaries/$ashaStaff", ['basic' => 15000, 'accountLast4' => '123456789012'], $principal);
check($st === 400, 'only last 4 account digits kept');
[$st, $b] = call('POST', "/schools/$s1/payroll/runs", ['month' => '2026-11'], $principal);
check($st === 201 && count($b['slips']) === 1 && $b['slips'][0]['daysInMonth'] === 30 && $b['totals']['net'] === 16000, 'salary sheet made for the month', $b['totals'] ?? $b);
$run = $b['run']['id'];
$slip = $b['slips'][0]['id'];
[$st] = call('POST', "/schools/$s1/payroll/runs", ['month' => '2026-11'], $principal);
check($st === 409, 'one sheet per month');
[$st, $b] = call('PATCH', "/schools/$s1/payroll/runs/$run/slips/$slip", ['unpaidDays' => '1.5', 'bonus' => 500], $principal);
check($st === 200 && $b['slips'][0]['gross'] === 17100 + 500 && $b['slips'][0]['unpaidDays'] === 1.5, 'unpaid days and bonus', $b['slips'][0] ?? $b);
[$st, $b] = call('GET', "/schools/$s1/payroll/my-payslips", [], $teacher);
check($st === 200 && $b['payslips'] === [], 'draft payslip not shown to staff yet', $b);
[$st] = call('POST', "/schools/$s1/payroll/runs/$run/pay", ['mode' => 'bank'], $principal);
check($st === 409, 'pay only after finalising');
[$st] = call('POST', "/schools/$s1/payroll/runs/$run/finalise", [], $principal);
check($st === 200, 'sheet finalised');
[$st] = call('PATCH', "/schools/$s1/payroll/runs/$run/slips/$slip", ['bonus' => 1], $principal);
check($st === 409, 'final sheet cannot change');
[$st] = call('DELETE', "/schools/$s1/payroll/runs/$run", [], $principal);
check($st === 409, 'final sheet cannot be deleted');
[$st, $b] = call('POST', "/schools/$s1/payroll/runs/$run/pay", ['mode' => 'bank', 'reference' => 'NEFT1', 'paidOn' => '2026-11-30'], $principal);
check($b['totals']['paid'] === 1 && $b['slips'][0]['paidOn'] === '2026-11-30', 'salaries marked paid', $b['totals']);
[$st, $b] = call('GET', "/schools/$s1/payroll/my-payslips", [], $teacher);
check(count($b['payslips']) === 1 && $b['payslips'][0]['month'] === '2026-11', 'teacher sees own payslip', $b);
[$st, $b] = call('GET', "/schools/$s1/payroll/payslips/$slip", [], $teacher);
check($st === 200 && $b['payslip']['accountLast4'] === '4321', 'teacher opens own payslip', $b);
[$st] = call('GET', "/schools/$s1/payroll/payslips/$slip", [], $parent);
check($st === 403, 'others cannot open a payslip');
check(School\Modules\HomeModule::intent('salary banana hai', ['payroll', 'staff']) === 'payroll', 'assistant understands salary');

// --- exams and report cards
check(School\Modules\ExamsModule::gradeFor(91) === 'A1' && School\Modules\ExamsModule::gradeFor(90.9) === 'A2' && School\Modules\ExamsModule::gradeFor(32) === 'E', 'CBSE grade scale');
$subs = array_column(call('GET', "/schools/$s1/setup", [], $principal)[1]['subjects'], 'id', 'name');
[$st, $b] = call('POST', "/schools/$s1/exams", ['name' => 'Half-yearly', 'startsOn' => date('Y-m-d'), 'gradeIds' => [$g1], 'subjectIds' => [$subs['English'], $subs['Mathematics'] ?? $subs['Maths'] ?? array_values($subs)[1]], 'maxMarks' => 50], $principal);
check($st === 201 && count($b['classes']) === 1 && count($b['classes'][0]['papers']) === 2 && $b['classes'][0]['sections'][0]['students'] === 3, 'exam created with papers per class', $b);
$exam = $b['exam']['id'];
[$eng, $math] = array_map(fn($p) => $p['subjectId'], $b['classes'][0]['papers']);
[$st] = call('POST', "/schools/$s1/exams", ['name' => 'X', 'gradeIds' => [$g1], 'subjectIds' => [$eng]], $teacher);
check($st === 403, 'teacher cannot create exams');
[$st, $b] = call('GET', "/schools/$s1/exams/$exam/marks", [], $teacher, ['divisionId' => $class1, 'subjectId' => $eng]);
check($st === 200 && count($b['students']) === 3 && $b['paper']['maxMarks'] === 50, 'teacher opens marks sheet', $b);
$kids = array_column($b['students'], 'studentId');
[$st, $b] = call('PUT', "/schools/$s1/exams/$exam/marks", ['divisionId' => $class1, 'subjectId' => $eng, 'marks' => [['studentId' => $kids[0], 'marks' => 51]]], $teacher);
check($st === 400, 'marks above maximum refused', $b);
call('PUT', "/schools/$s1/exams/$exam/marks", ['divisionId' => $class1, 'subjectId' => $eng, 'marks' => [['studentId' => $kids[0], 'marks' => 46.5], ['studentId' => $kids[1], 'marks' => 30], ['studentId' => $kids[2], 'absent' => true]]], $teacher);
[$st, $b] = call('PUT', "/schools/$s1/exams/$exam/marks", ['divisionId' => $class1, 'subjectId' => $math, 'marks' => [['studentId' => $kids[0], 'marks' => 48], ['studentId' => $kids[1], 'marks' => 12]]], $teacher);
check($st === 200 && $b['saved'] === 2, 'marks saved');
[$st, $b] = call('GET', "/schools/$s1/exams/$exam/results", [], $teacher, ['divisionId' => $class1]);
$r = array_column($b['students'], null, 'studentId');
check($r[$kids[0]]['total'] === 94.5 && $r[$kids[0]]['percent'] === 94.5 && $r[$kids[0]]['grade'] === 'A1' && $r[$kids[0]]['rank'] === 1, 'topper result', $r[$kids[0]]);
check($r[$kids[1]]['result'] === 'needs_support' && $r[$kids[1]]['rank'] === 2 && $r[$kids[2]]['result'] === 'pending' && $r[$kids[2]]['rank'] === null, 'results: needs support, pending', [$r[$kids[1]], $r[$kids[2]]]);
[$st] = call('PUT', "/schools/$s1/exams/$exam/remarks", ['studentId' => $shivi, 'remark' => 'Excellent reader!'], $teacher);
check($st === 200, 'class teacher adds remark');
[$st] = call('GET', "/schools/$s1/exams/$exam/report/$shivi", [], $parent);
check($st === 403, 'parent waits until results are published');
[$st, $b] = call('GET', "/schools/$s1/report-cards/$shivi", [], $parent);
check($b['exams'] === [], 'unpublished exam hidden from parent', $b);
[$st] = call('POST', "/schools/$s1/exams/$exam/publish", ['published' => true], $principal);
check($st === 200, 'results published');
[$st] = call('PUT', "/schools/$s1/exams/$exam/marks", ['divisionId' => $class1, 'subjectId' => $eng, 'marks' => [['studentId' => $kids[0], 'marks' => 10]]], $teacher);
check($st === 409, 'marks locked once published');
[$st, $b] = call('GET', "/schools/$s1/exams/$exam/report/$shivi", [], $parent);
check($st === 200 && $b['remark'] === 'Excellent reader!' && $b['class'] === 'Class 1 A' && count($b['subjects']) === 2, 'parent sees report card', $b);
[$st, $b] = call('GET', "/schools/$s1/report-cards/$shivi", [], $parent);
check(count($b['exams']) === 1, 'published exam listed for parent');
[$st] = call('GET', "/schools/$s1/exams/$exam/report/$aarav", [], $parent);
check($st === 403, 'parent cannot see another child report');
[$st] = call('DELETE', "/schools/$s1/exams/$exam", [], $principal);
check($st === 409, 'published exam cannot be deleted');
check(School\Modules\HomeModule::intent('half yearly marks dalna hai', ['exams', 'students']) === 'exams', 'assistant understands marks');

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
