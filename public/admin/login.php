<?php
require __DIR__ . '/../inc/admin.php';
ensure_schema();

if (current_admin()) {
    redirect('index.php');
}

$err = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)g($_POST, 'username', ''));
    $password = (string)g($_POST, 'password', '');
    $tbl = '`' . T('admin') . '`';
    $a = $username !== '' ? q1("SELECT * FROM $tbl WHERE username = ?", array($username)) : null;

    if ($a && $a['locked_until'] && strtotime($a['locked_until']) > time()) {
        $err = '비밀번호를 여러 번 틀려 잠시 잠겼습니다. ' . date('H:i', strtotime($a['locked_until'])) . ' 이후에 다시 시도해 주세요.';
    } elseif ($a && (int)$a['is_active'] === 1 && password_verify($password, $a['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$a['id'];
        $_SESSION['csrf'] = random_hex(16);
        $sets = 'fail_count = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?';
        $params = array(now(), client_ip());
        if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
            $sets .= ', password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $params[] = (int)$a['id'];
        q("UPDATE $tbl SET $sets WHERE id = ?", $params);
        redirect('index.php');
    } else {
        if ($a && (int)$a['is_active'] === 1) {
            $fails = (int)$a['fail_count'] + 1;
            $lock = $fails >= LOCK_FAILS ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
            q("UPDATE $tbl SET fail_count = ?, locked_until = ? WHERE id = ?", array($lock ? 0 : $fails, $lock, (int)$a['id']));
        }
        $err = '아이디 또는 비밀번호가 맞지 않습니다.';
    }
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>로그인 · ICT 수요조사 관리자</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=3">
</head>
<body class="login-body">
<main class="login-card">
  <div class="login-logos">
    <img src="assets/ictis_logo.svg" alt="ICT 이노베이션스퀘어" class="logo-ictis">
    <span class="brand-sep" aria-hidden="true"></span>
    <img src="assets/fnj_logo.png" alt="에프엔제이" class="logo-fnj">
  </div>
  <h1>수요조사 관리자</h1>
  <p class="login-sub">2026년 디지털신기술 교육 및 기업협력 수요조사</p>
  <?php if ($err): ?><div class="alert alert-err" role="alert"><?= h($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <label class="fld"><span>아이디</span><input type="text" name="username" value="<?= h($username) ?>" required autofocus autocomplete="username" maxlength="30"></label>
    <label class="fld"><span>비밀번호</span><input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit" class="btn btn-primary btn-block">로그인</button>
  </form>
</main>
</body>
</html>
