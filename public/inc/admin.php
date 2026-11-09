<?php
// 관리자 화면 공통: 세션, 로그인 확인, CSRF, 화면 머리·꼬리
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/survey.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// cafe24 처럼 session.auto_start 가 켜진 서버에서는 이미 시작된 세션을 그대로 사용
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('ITPADMIN');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'));
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_start();
}

set_exception_handler(function ($e) {
    error_log('[itp-survey admin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = $e instanceof mysqli_sql_exception ? '데이터베이스 연결 또는 조회 중 오류가 발생했습니다.' : '서버 오류가 발생했습니다.';
    echo '<!doctype html><meta charset="utf-8"><title>오류</title><div style="font-family:sans-serif;max-width:480px;margin:80px auto;padding:24px;border-radius:12px;background:#fdecea;color:#7a1c14">'
        . '<b>' . h($msg) . '</b><br><small>잠시 후 다시 시도하고, 계속되면 담당자에게 문의해 주세요.</small></div>';
});

const LOCK_FAILS = 5;       // 비밀번호를 이만큼 틀리면
const LOCK_MINUTES = 10;    // 이 시간 동안 로그인 잠금

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_hex(16);
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check() {
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($t === '' || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('요청이 만료되었습니다. 페이지를 새로 고친 뒤 다시 시도해 주세요.');
    }
}

function flash($msg = null, $type = 'ok') {
    if ($msg !== null) {
        $_SESSION['flash'] = array($msg, $type);
        return null;
    }
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/** 로그인한 관리자 (매 요청마다 DB 에서 다시 읽어 삭제·중지가 바로 반영되게 함) */
function current_admin() {
    static $me = false;
    if ($me !== false) {
        return $me;
    }
    $me = null;
    if (!empty($_SESSION['admin_id'])) {
        $me = q1('SELECT * FROM `' . T('admin') . '` WHERE id = ? AND is_active = 1', array((int)$_SESSION['admin_id']));
        if (!$me) {
            unset($_SESSION['admin_id']);
        }
    }
    return $me;
}

function require_login() {
    ensure_schema();
    if (!current_admin()) {
        redirect('login.php');
    }
    return current_admin();
}

function is_super($a = null) {
    $a = $a ? $a : current_admin();
    return $a && $a['role'] === 'super';
}

function role_label($r) {
    return $r === 'super' ? '최고 관리자' : '관리자';
}

// $bare = true 이면 머리·메뉴 없이 내용만 (목록 화면의 팝업 안에서 사용)
function admin_header($title, $active = '', $bare = false) {
    $me = current_admin();
    $f = flash();
    ?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> · ICT 수요조사 관리자</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=3">
</head>
<body<?= $bare ? ' class="bare"' : '' ?>>
<?php if (!$bare): ?>
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="index.php" aria-label="관리자 첫 화면">
      <img src="assets/ictis_logo.svg" alt="ICT 이노베이션스퀘어" class="logo-ictis">
      <span class="brand-sep" aria-hidden="true"></span>
      <img src="assets/fnj_logo.png" alt="에프엔제이" class="logo-fnj">
    </a>
    <div class="brand-title">2026 디지털신기술 교육·기업협력 수요조사 <b>관리자</b></div>
    <?php if ($me): ?>
    <div class="me">
      <?php $nm = $me['name'] !== '' ? $me['name'] : $me['username']; ?>
      <span><?= h($nm) ?><?= $nm !== role_label($me['role']) ? ' <small>' . h(role_label($me['role'])) . '</small>' : '' ?></span>
      <a href="logout.php" class="btn btn-ghost btn-sm">로그아웃</a>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($me): ?>
  <nav class="tabs"><div class="tabs-in">
    <a href="index.php" class="<?= $active === 'results' ? 'on' : '' ?>">설문조사 결과</a>
    <a href="admins.php" class="<?= $active === 'admins' ? 'on' : '' ?>"><?= is_super() ? '관리자 관리' : '내 계정' ?></a>
    <a href="../" target="_blank" rel="noopener">설문 화면 열기 ↗</a>
  </div></nav>
  <?php endif; ?>
</header>
<?php endif; ?>
<main class="page">
<?php if ($f): ?><div class="alert alert-<?= h($f[1]) ?>" role="status"><?= h($f[0]) ?></div><?php endif; ?>
<?php if (!$bare && $me && $me['username'] === cfg('default_admin_id', 'fnj') && password_verify(cfg('default_admin_pw', 'fnj1234'), $me['password_hash'])): ?>
<div class="alert alert-warn">초기 비밀번호를 사용하고 있습니다. <a href="admins.php?edit=<?= (int)$me['id'] ?>">비밀번호를 바꿔 주세요.</a></div>
<?php endif; ?>
<?php
}

function admin_footer($bare = false) {
    ?>
</main>
<?php if (!$bare): ?><footer class="foot">2026 인천 ICT이노베이션스퀘어 수요조사 관리자</footer><?php endif; ?>
</body>
</html>
<?php
}
