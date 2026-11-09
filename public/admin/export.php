<?php
// 검색 조건에 맞는 응답을 CSV 로 내려받기 (엑셀에서 바로 열리도록 UTF-8 BOM 포함)
require __DIR__ . '/../inc/admin.php';
require __DIR__ . '/../inc/responses.php';
$me = require_login();

$tbl = '`' . T('survey_response') . '`';
$f = result_filter($_GET);
list($where, $params, $order) = result_sql($f);
$rows = q("SELECT * FROM $tbl $where $order", $params);

$def = survey_def();
$industries = survey_options('A6');

// 엑셀이 수식으로 해석하지 않도록
function cell($v) {
    $v = (string)$v;
    if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
        $v = "'" . $v;
    }
    return $v;
}

set_time_limit(0);
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"itp_survey_" . date('Ymd_His') . ".csv\"; filename*=UTF-8''" . rawurlencode('ICT수요조사_응답_' . date('Ymd_His') . '.csv'));
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

$head = array('번호', '완료 여부', '응답자 유형', '기업명', '성명', '성별', '직위', '연락처', '이메일', '분야',
    '진행(응답/경로)', '마지막 문항', 'IP', '마지막 저장 IP', '시작 일시', '제출 일시', '최종 저장 일시', '브라우저', '응답 고유번호');
foreach ($def['q'] as $qid => $q) {
    $head[] = $qid . ' ' . $q['title'];
}
fputcsv($out, $head);

foreach ($rows as $r) {
    $answers = json_decode((string)$r['answers'], true);
    $answers = is_array($answers) ? $answers : array();
    $line = array(
        $r['id'], status_label($r['status']), rtype_label($r['respondent_type']),
        cell($r['company']), cell($r['name']), cell($r['gender']), cell($r['position']), cell($r['phone']), cell($r['email']),
        $r['industry'] !== null ? g($industries, (int)$r['industry'], $r['industry']) : '',
        $r['answered_count'] . '/' . $r['route_count'], $r['current_q'],
        $r['ip'], $r['last_ip'], $r['started_at'], $r['completed_at'], $r['updated_at'], cell($r['user_agent']), $r['submission_id'],
    );
    foreach ($def['q'] as $qid => $q) {
        $line[] = isset($answers[$qid]) ? cell(implode(' / ', format_answer($q, $answers[$qid]))) : '';
    }
    fputcsv($out, $line);
}
fclose($out);
