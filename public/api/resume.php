<?php
// 휴대전화 번호 + 성명으로 중단된(작성 중) 설문을 찾아 이어서 하기
// 브라우저 임시 저장이 없어진 경우(다른 기기, 브라우저 기록 삭제 등)에도 이어서 할 수 있도록 함.
// 요청: POST, 본문 JSON (Content-Type: text/plain)
//   action=find  : {phone, name, channel, submissionId(지금 작성 중인 번호)} → 가장 최근 작성 중 응답
//   action=merge : {from(지금 번호), to(이어서 할 번호), phone, name} → 새로 생긴 빈 응답을 삭제 처리
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
$d = json_decode((string)file_get_contents('php://input', false, null, 0, 10000), true);
if (!is_array($d)) {
    reply(400, array('ok' => false, 'error' => 'invalid json'));
}

$tbl = '`' . T('survey_response') . '`';
$sidOk = '/^[A-Za-z0-9\-]{8,64}$/';

// action=check : {sid} → 브라우저에 남은 임시 저장 응답이 서버에서 삭제 처리·제출 완료됐는지 확인
if ((string)g($d, 'action', '') === 'check') {
    $sid = (string)g($d, 'sid', '');
    $state = 'none';
    if (preg_match($sidOk, $sid)) {
        try {
            $r = q1("SELECT status, deleted_at FROM $tbl WHERE submission_id = ?", array($sid));
            if ($r) {
                $state = $r['deleted_at'] ? 'deleted' : $r['status'];
            }
        } catch (Exception $e) {
            $state = 'unknown';
        }
    }
    reply(200, array('ok' => true, 'state' => $state));
}

$tel = preg_replace('/\D/', '', (string)g($d, 'phone', ''));
$name = mb_substr(trim((string)g($d, 'name', '')), 0, 50);
if (strlen($tel) < 10 || strlen($tel) > 11 || $name === '') {
    reply(200, array('ok' => true, 'found' => false));
}
// 저장된 연락처는 '010-1234-5678' 형식 → 숫자만 비교
$telSql = "REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '.', '')";
try {
    $action = (string)g($d, 'action', 'find');

    if ($action === 'merge') {
        $from = (string)g($d, 'from', '');
        $to = (string)g($d, 'to', '');
        if (!preg_match($sidOk, $from) || !preg_match($sidOk, $to) || $from === $to) {
            reply(400, array('ok' => false, 'error' => 'invalid id'));
        }
        // 이어서 할 응답이 같은 번호·성명인지 확인한 뒤, 방금 새로 생긴 작성 중 응답만 삭제 처리
        $own = q1("SELECT id FROM $tbl WHERE submission_id = ? AND deleted_at IS NULL AND name = ? AND $telSql = ?", array($to, $name, $tel));
        if ($own) {
            q("UPDATE $tbl SET deleted_at = ?, deleted_by = ? WHERE submission_id = ? AND status = 'progress' AND deleted_at IS NULL",
                array(now(), '이어하기(자동)', $from));
        }
        reply(200, array('ok' => true));
    }

    $channel = strtoupper((string)g($d, 'channel', ''));
    if (!array_key_exists($channel, channel_list())) {
        $channel = '';
    }
    $cur = (string)g($d, 'submissionId', '');
    $r = q1("SELECT submission_id, answers, current_q, answered_count, route_count, updated_at
             FROM $tbl
             WHERE status = 'progress' AND deleted_at IS NULL AND channel = ? AND name = ? AND $telSql = ?
               AND answered_count >= 3 AND submission_id <> ? AND updated_at >= ?   -- P1·P2 외에 실제 문항 응답이 있는 것만
             ORDER BY updated_at DESC LIMIT 1",
        array($channel, $name, $tel, $cur, date('Y-m-d H:i:s', time() - 60 * 86400)));
    if (!$r) {
        reply(200, array('ok' => true, 'found' => false));
    }
    $answers = json_decode((string)$r['answers'], true);
    if (!is_array($answers) || count($answers) < 3) {   // P1·P2 만 있는 응답은 이어서 할 내용이 없음
        reply(200, array('ok' => true, 'found' => false));
    }
    $t = strtotime($r['updated_at']);
    reply(200, array(
        'ok' => true,
        'found' => true,
        'sid' => $r['submission_id'],
        'current' => $r['current_q'],
        'answered' => (int)$r['answered_count'],
        'total' => (int)$r['route_count'],
        'updated' => date('n', $t) . '월 ' . date('j', $t) . '일 ' . date('H:i', $t),
        'answers' => $answers,
    ));
} catch (Exception $e) {
    error_log('[itp-survey resume] ' . $e->getMessage());
    reply(200, array('ok' => true, 'found' => false));   // 찾기 실패는 새로 작성으로 진행
}
