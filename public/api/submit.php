<?php
// 설문 응답 저장 (작성 중 자동 저장 + 최종 제출)
// 요청: POST, 본문은 JSON (Content-Type: text/plain)
require __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply($code, $body) {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, array('ok' => false, 'error' => 'POST only'));
}

$raw = file_get_contents('php://input', false, null, 0, 600000);
$d = json_decode((string)$raw, true);
if (!is_array($d)) {
    reply(400, array('ok' => false, 'error' => 'invalid json'));
}

$sid = (string)g($d, 'submissionId', '');
if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $sid)) {
    reply(400, array('ok' => false, 'error' => 'invalid submissionId'));
}
$status = (string)g($d, 'status', 'complete');
if (!in_array($status, array('progress', 'out', 'complete'), true)) {
    $status = 'progress';
}
$answers = g($d, 'answers', array());
$route = g($d, 'route', array());
if (!is_array($answers) || !is_array($route)) {
    reply(400, array('ok' => false, 'error' => 'invalid answers'));
}

// 검색·정렬용으로 따로 꺼내 두는 값
function av($answers, $id, $key = 'v') {
    return (isset($answers[$id]) && is_array($answers[$id]) && isset($answers[$id][$key])) ? $answers[$id][$key] : null;
}
function intv($v) {
    return ($v === null || $v === '' || !is_numeric($v)) ? null : (int)$v;
}
function cut($v, $len) {
    return mb_substr(trim((string)$v), 0, $len);
}

$p2 = (isset($answers['P2']) && is_array($answers['P2'])) ? $answers['P2'] : array();
$routeIds = array();
foreach ($route as $r) {
    if (is_string($r) && strlen($r) <= 10) {
        $routeIds[] = $r;
    }
}
$answered = 0;
foreach ($routeIds as $id) {
    if (isset($answers[$id])) {
        $answered++;
    }
}

$now = now();
$ip = client_ip();
$row = array(
    'status'          => $status,
    'survey_version'  => cut(g($d, 'surveyVersion', ''), 30),
    'respondent_type' => intv(av($answers, 'A1')),
    'consent'         => intv(av($answers, 'P1')),
    'company'         => cut(g($p2, 'company', ''), 100),
    'name'            => cut(g($p2, 'name', ''), 50),
    'gender'          => cut(g($p2, 'gender', ''), 10),
    'position'        => cut(g($p2, 'position', ''), 50),
    'phone'           => cut(g($p2, 'phone', ''), 50),
    'email'           => cut(g($p2, 'email', ''), 100),
    'region'          => intv(av($answers, 'A3')),
    'company_type'    => intv(av($answers, 'A4')),
    'industry'        => intv(av($answers, 'A6')),
    'job'             => intv(av($answers, 'A7')),
    'current_q'       => cut(g($d, 'current', ''), 10),
    'answered_count'  => $answered,
    'route_count'     => count($routeIds),
    'answers'         => json_encode($answers, JSON_UNESCAPED_UNICODE),
    'route'           => json_encode($routeIds),
    'last_ip'         => $ip,
    'forwarded_for'   => server_str('HTTP_X_FORWARDED_FOR', 255),
    'user_agent'      => server_str('HTTP_USER_AGENT', 500),
    'accept_lang'     => server_str('HTTP_ACCEPT_LANGUAGE', 100),
    'updated_at'      => $now,
);

function save_response($sid, $row, $status, $ip, $now) {
    $tbl = '`' . T('survey_response') . '`';
    $cur = q1("SELECT id, status FROM $tbl WHERE submission_id = ?", array($sid));

    if ($cur) {
        // 이미 제출 완료된 응답은 늦게 도착한 자동 저장이나 중복 제출로 덮어쓰지 않음
        if ($cur['status'] === 'complete') {
            return array('ok' => true, 'id' => (int)$cur['id'], 'status' => 'complete');
        }
        $sets = array();
        $params = array();
        foreach ($row as $k => $v) {
            $sets[] = "`$k` = ?";
            $params[] = $v;
        }
        $sets[] = 'save_count = save_count + 1';
        if ($status === 'complete') {
            $sets[] = 'completed_at = ?';
            $params[] = $now;
        }
        $params[] = (int)$cur['id'];
        q("UPDATE $tbl SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
        return array('ok' => true, 'id' => (int)$cur['id'], 'status' => $status);
    }

    // 새 응답: 같은 IP 에서 짧은 시간에 너무 많이 시작하면 거절
    $limit = (int)cfg('max_new_per_ip_hour', 200);
    if ($limit > 0) {
        $n = q1("SELECT COUNT(*) AS n FROM $tbl WHERE ip = ? AND started_at >= ?", array($ip, date('Y-m-d H:i:s', time() - 3600)));
        if ((int)$n['n'] >= $limit) {
            reply(429, array('ok' => false, 'error' => 'too many submissions'));
        }
    }
    $row['submission_id'] = $sid;
    $row['ip'] = $ip;
    $row['referer'] = server_str('HTTP_REFERER', 500);
    $row['started_at'] = $now;
    $row['completed_at'] = $status === 'complete' ? $now : null;
    $cols = array_keys($row);
    q("INSERT INTO $tbl (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return array('ok' => true, 'id' => (int)db()->insert_id, 'status' => $status);
}

try {
    try {
        $result = save_response($sid, $row, $status, $ip, $now);
    } catch (mysqli_sql_exception $e) {
        $code = (int)$e->getCode();
        if ($code === 1146) {          // 테이블 없음 → 만들고 다시 시도
            ensure_schema();
        } elseif ($code !== 1062) {    // 1062 = 같은 응답이 동시에 두 번 들어옴 → 다시 시도하면 수정으로 처리됨
            throw $e;
        }
        $result = save_response($sid, $row, $status, $ip, $now);
    }
    reply(200, $result);
} catch (Exception $e) {
    error_log('[itp-survey submit] ' . $e->getMessage());
    reply(500, array('ok' => false, 'error' => 'server error'));
}
