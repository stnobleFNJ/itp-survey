<?php
// 설문 결과 검색·정렬 조건 (목록 화면과 CSV 내려받기가 함께 사용)
if (!defined('ITP_APP')) { http_response_code(403); exit; }

function result_sorts() {
    return array(
        'started'   => array('시작 일시', 'started_at'),
        'completed' => array('제출 일시', 'completed_at'),
        'updated'   => array('최종 저장 일시', 'updated_at'),
        'status'    => array('완료 여부', 'status'),
        'industry'  => array('분야', 'industry'),
        'rtype'     => array('응답자 유형', 'respondent_type'),
        'company'   => array('기업명', "NULLIF(company, '')"),
        'name'      => array('성명', "NULLIF(name, '')"),
        'progress'  => array('진행률', 'answered_count / GREATEST(route_count, 1)'),
        'ip'        => array('IP', 'ip'),
        'id'        => array('번호', 'id'),
    );
}

function result_date_fields() {
    return array('started' => '시작일', 'completed' => '제출일', 'updated' => '최종 저장일');
}

function result_status_filters() {
    return array(
        ''           => '전체',
        'complete'   => '제출 완료',
        'incomplete' => '미완료 (작성 중 + 대상외)',
        'progress'   => '작성 중',
        'out'        => '대상외 종료 (인천 외)',
    );
}

/** GET 값 → 정리된 검색 조건 */
function result_filter($src) {
    $f = array(
        'status'   => (string)g($src, 'status', ''),
        'industry' => (string)g($src, 'industry', ''),
        'rtype'    => (string)g($src, 'rtype', ''),
        'dfield'   => (string)g($src, 'dfield', 'started'),
        'from'     => (string)g($src, 'from', ''),
        'to'       => (string)g($src, 'to', ''),
        'kw'       => trim((string)g($src, 'kw', '')),
        'sort'     => (string)g($src, 'sort', 'started'),
        'dir'      => strtolower((string)g($src, 'dir', 'desc')) === 'asc' ? 'asc' : 'desc',
    );
    if (!array_key_exists($f['status'], result_status_filters())) $f['status'] = '';
    if (!preg_match('/^(\d{1,3}|none)$/', $f['industry'])) $f['industry'] = '';
    if (!in_array($f['rtype'], array('1', '2', 'none'), true)) $f['rtype'] = '';
    if (!array_key_exists($f['dfield'], result_date_fields())) $f['dfield'] = 'started';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) $f['from'] = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) $f['to'] = '';
    if (!array_key_exists($f['sort'], result_sorts())) $f['sort'] = 'started';
    $f['kw'] = mb_substr($f['kw'], 0, 100);
    return $f;
}

/** 검색 조건 → [WHERE 절, 파라미터, ORDER BY 절] */
function result_sql($f) {
    $w = array();
    $p = array();
    if ($f['status'] === 'incomplete') {
        $w[] = "status <> 'complete'";
    } elseif ($f['status'] !== '') {
        $w[] = 'status = ?';
        $p[] = $f['status'];
    }
    if ($f['industry'] === 'none') {
        $w[] = 'industry IS NULL';
    } elseif ($f['industry'] !== '') {
        $w[] = 'industry = ?';
        $p[] = (int)$f['industry'];
    }
    if ($f['rtype'] === 'none') {
        $w[] = 'respondent_type IS NULL';
    } elseif ($f['rtype'] !== '') {
        $w[] = 'respondent_type = ?';
        $p[] = (int)$f['rtype'];
    }
    $sorts = result_sorts();
    $dcol = $sorts[$f['dfield']][1];
    if ($f['from'] !== '') {
        $w[] = "$dcol >= ?";
        $p[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $w[] = "$dcol <= ?";
        $p[] = $f['to'] . ' 23:59:59';
    }
    if ($f['kw'] !== '') {
        $like = '%' . addcslashes($f['kw'], '%_\\') . '%';
        $w[] = '(company LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ? OR ip LIKE ? OR last_ip LIKE ? OR submission_id LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $p[] = $like;
        }
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $dir = $f['dir'] === 'asc' ? 'ASC' : 'DESC';
    $col = $sorts[$f['sort']][1];
    // 빈 값(NULL)은 오름·내림차순 모두 맨 뒤로
    $order = "ORDER BY ($col IS NULL) ASC, $col $dir, id $dir";
    return array($where, $p, $order);
}

/** 현재 조건을 유지한 채 일부 값만 바꾼 주소 */
function result_url($page, $f, $change = array()) {
    $q = array_merge($f, $change);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return $page . ($q ? '?' . http_build_query($q) : '');
}
