<?php
// 관리자 관리: 같은 화면에서 등록 / 수정 / 삭제
// 최고 관리자는 모든 계정을, 일반 관리자는 자기 계정(이름·이메일·비밀번호)만 고칠 수 있음
require __DIR__ . '/../inc/admin.php';
$me = require_login();
$super = is_super($me);
$tbl = '`' . T('admin') . '`';

function other_active_supers($exceptId) {
    $r = q1('SELECT COUNT(*) AS n FROM `' . T('admin') . "` WHERE role = 'super' AND is_active = 1 AND id <> ?", array((int)$exceptId));
    return (int)$r['n'];
}

$errors = array();
$form = null;   // 오류가 났을 때 입력값을 다시 보여주기 위함

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)g($_POST, 'action', '');
    $id = (int)g($_POST, 'id', 0);

    if ($action === 'delete') {
        $target = q1("SELECT * FROM $tbl WHERE id = ?", array($id));
        if (!$super) {
            $errors[] = '삭제 권한이 없습니다.';
        } elseif (!$target) {
            $errors[] = '계정을 찾을 수 없습니다.';
        } elseif ((int)$target['id'] === (int)$me['id']) {
            $errors[] = '로그인한 본인 계정은 삭제할 수 없습니다.';
        } elseif ($target['role'] === 'super' && (int)$target['is_active'] === 1 && other_active_supers($target['id']) === 0) {
            $errors[] = '마지막 최고 관리자는 삭제할 수 없습니다.';
        } else {
            q("DELETE FROM $tbl WHERE id = ?", array($id));
            flash("'" . $target['username'] . "' 계정을 삭제했습니다.");
            redirect('admins.php');
        }
    }

    if ($action === 'save') {
        $isNew = $id === 0;
        $target = $isNew ? null : q1("SELECT * FROM $tbl WHERE id = ?", array($id));
        $self = $target && (int)$target['id'] === (int)$me['id'];
        $form = array(
            'id'        => $id,
            'username'  => trim((string)g($_POST, 'username', $target ? $target['username'] : '')),
            'name'      => trim((string)g($_POST, 'name', '')),
            'email'     => trim((string)g($_POST, 'email', '')),
            'role'      => g($_POST, 'role') === 'super' ? 'super' : 'admin',
            'is_active' => g($_POST, 'is_active') === '1' ? 1 : 0,
        );
        $pw = (string)g($_POST, 'password', '');
        $pw2 = (string)g($_POST, 'password2', '');

        if (!$isNew && !$target) {
            $errors[] = '계정을 찾을 수 없습니다.';
        } elseif (!$super && ($isNew || !$self)) {
            $errors[] = '권한이 없습니다.';
        } else {
            if (!$super) {   // 일반 관리자는 아이디·권한·사용 여부를 바꿀 수 없음
                $form['username'] = $target['username'];
                $form['role'] = $target['role'];
                $form['is_active'] = (int)$target['is_active'];
            }
            if ($self) {     // 자기 자신을 중지시키는 실수 방지
                $form['is_active'] = 1;
            }
            if (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $form['username'])) {
                $errors[] = '아이디는 영문·숫자·_ . - 로 3~30자여야 합니다.';
            } elseif (q1("SELECT id FROM $tbl WHERE username = ? AND id <> ?", array($form['username'], $id))) {
                $errors[] = "'" . $form['username'] . "' 아이디는 이미 사용 중입니다.";
            }
            if (mb_strlen($form['name']) > 50) {
                $errors[] = '이름은 50자 이내로 적어 주세요.';
            }
            if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = '이메일 형식이 맞지 않습니다.';
            }
            if ($isNew && $pw === '') {
                $errors[] = '비밀번호를 입력해 주세요.';
            }
            if ($pw !== '') {
                if (strlen($pw) < 6) {
                    $errors[] = '비밀번호는 6자 이상이어야 합니다.';
                } elseif ($pw !== $pw2) {
                    $errors[] = '비밀번호 확인이 일치하지 않습니다.';
                }
                if ($self && !password_verify((string)g($_POST, 'current_password', ''), $me['password_hash'])) {
                    $errors[] = '본인 비밀번호를 바꾸려면 현재 비밀번호를 정확히 입력해 주세요.';
                }
            }
            if ($target && $target['role'] === 'super' && ($form['role'] !== 'super' || !$form['is_active'])
                && (int)$target['is_active'] === 1 && other_active_supers($target['id']) === 0) {
                $errors[] = '마지막 최고 관리자의 권한을 낮추거나 사용 중지할 수 없습니다.';
            }
        }

        if (!$errors) {
            if ($isNew) {
                q("INSERT INTO $tbl (username, password_hash, name, email, role, is_active, created_by, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    array($form['username'], password_hash($pw, PASSWORD_DEFAULT), $form['name'], $form['email'],
                        $form['role'], $form['is_active'], $me['username'], now(), now()));
                flash("'" . $form['username'] . "' 관리자를 등록했습니다.");
            } else {
                $sets = 'username = ?, name = ?, email = ?, role = ?, is_active = ?, updated_at = ?';
                $params = array($form['username'], $form['name'], $form['email'], $form['role'], $form['is_active'], now());
                if ($super) {   // 최고 관리자가 저장하면 로그인 잠금도 풀림
                    $sets .= ', fail_count = 0, locked_until = NULL';
                }
                if ($pw !== '') {
                    $sets .= ', password_hash = ?';
                    $params[] = password_hash($pw, PASSWORD_DEFAULT);
                }
                $params[] = $id;
                q("UPDATE $tbl SET $sets WHERE id = ?", $params);
                flash("'" . $form['username'] . "' 계정을 수정했습니다." . ($pw !== '' ? ' (비밀번호 변경됨)' : ''));
            }
            redirect('admins.php');
        }
    }
}

// 편집 대상: 일반 관리자는 항상 자기 계정
$editId = $super ? (int)g($_GET, 'edit', 0) : (int)$me['id'];
if ($form === null) {
    $editRow = $editId ? q1("SELECT * FROM $tbl WHERE id = ?", array($editId)) : null;
    $form = $editRow
        ? array('id' => (int)$editRow['id'], 'username' => $editRow['username'], 'name' => $editRow['name'], 'email' => $editRow['email'], 'role' => $editRow['role'], 'is_active' => (int)$editRow['is_active'])
        : array('id' => 0, 'username' => '', 'name' => '', 'email' => '', 'role' => 'admin', 'is_active' => 1);
}
$editing = $form['id'] > 0;
$editingSelf = $form['id'] === (int)$me['id'];
$list = $super ? q("SELECT * FROM $tbl ORDER BY (role = 'super') DESC, id ASC") : array();

admin_header($super ? '관리자 관리' : '내 계정', 'admins');
?>
<div class="page-head">
  <h1><?= $super ? '관리자 관리' : '내 계정' ?></h1>
</div>

<?php if ($errors): ?>
<div class="alert alert-err" role="alert"><?php foreach ($errors as $e) echo '<div>' . h($e) . '</div>'; ?></div>
<?php endif; ?>

<div class="admin-grid<?= $super ? '' : ' single' ?>">
  <section class="card" id="form">
    <h2><?= $editing ? ($editingSelf ? '내 계정 수정' : "'" . h($form['username']) . "' 수정") : '신규 관리자 등록' ?></h2>
    <form method="post" action="admins.php<?= $editing && $super ? '?edit=' . (int)$form['id'] : '' ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <label class="fld"><span>아이디 <em class="req">필수</em></span>
        <input type="text" name="username" value="<?= h($form['username']) ?>" maxlength="30" pattern="[A-Za-z0-9_.\-]{3,30}" required<?= $super ? '' : ' readonly' ?>></label>
      <label class="fld"><span>이름</span>
        <input type="text" name="name" value="<?= h($form['name']) ?>" maxlength="50"></label>
      <label class="fld"><span>이메일</span>
        <input type="email" name="email" value="<?= h($form['email']) ?>" maxlength="100"></label>
      <?php if ($super): ?>
      <div class="fld-row">
        <label class="fld"><span>권한</span>
          <select name="role">
            <option value="admin"<?= $form['role'] === 'admin' ? ' selected' : '' ?>>관리자 (결과 조회)</option>
            <option value="super"<?= $form['role'] === 'super' ? ' selected' : '' ?>>최고 관리자 (관리자 관리·응답 삭제)</option>
          </select></label>
        <label class="fld"><span>사용 여부</span>
          <select name="is_active"<?= $editingSelf ? ' disabled' : '' ?>>
            <option value="1"<?= $form['is_active'] ? ' selected' : '' ?>>사용</option>
            <option value="0"<?= !$form['is_active'] ? ' selected' : '' ?>>중지</option>
          </select>
          <?php if ($editingSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?></label>
      </div>
      <?php endif; ?>
      <?php if ($editingSelf): ?>
      <label class="fld"><span>현재 비밀번호 <small class="muted">비밀번호를 바꿀 때만</small></span>
        <input type="password" name="current_password" autocomplete="current-password"></label>
      <?php endif; ?>
      <div class="fld-row">
        <label class="fld"><span><?= $editing ? '새 비밀번호' : '비밀번호' ?> <?= $editing ? '<small class="muted">바꿀 때만</small>' : '<em class="req">필수</em>' ?></span>
          <input type="password" name="password" minlength="6" autocomplete="new-password"<?= $editing ? '' : ' required' ?>></label>
        <label class="fld"><span>비밀번호 확인</span>
          <input type="password" name="password2" minlength="6" autocomplete="new-password"<?= $editing ? '' : ' required' ?>></label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editing ? '저장' : '등록' ?></button>
        <?php if ($editing && $super): ?><a href="admins.php" class="btn btn-ghost">취소 (신규 등록으로)</a><?php endif; ?>
      </div>
    </form>
  </section>

  <?php if ($super): ?>
  <section class="card">
    <h2>관리자 목록 <small class="muted"><?= count($list) ?>명</small></h2>
    <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>아이디</th><th>이름</th><th>권한</th><th>상태</th><th>최근 로그인</th><th>등록</th><th class="right">관리</th></tr></thead>
      <tbody>
      <?php foreach ($list as $a):
          $locked = $a['locked_until'] && strtotime($a['locked_until']) > time(); ?>
        <tr class="<?= (int)$a['id'] === $form['id'] ? 'sel' : '' ?>">
          <td class="strong"><?= h($a['username']) ?><?= (int)$a['id'] === (int)$me['id'] ? ' <small class="muted">(나)</small>' : '' ?></td>
          <td><?= h($a['name']) ?><?= $a['email'] !== '' ? '<br><small class="muted">' . h($a['email']) . '</small>' : '' ?></td>
          <td><span class="badge <?= $a['role'] === 'super' ? 'badge-super' : 'badge-admin' ?>"><?= h(role_label($a['role'])) ?></span></td>
          <td><?= (int)$a['is_active'] ? '사용' : '<span class="muted">중지</span>' ?><?= $locked ? ' <span class="badge badge-out" title="저장하면 잠금이 풀립니다">잠김</span>' : '' ?></td>
          <td class="nowrap small"><?= $a['last_login_at'] ? h(date('Y-m-d H:i', strtotime($a['last_login_at']))) . '<br><span class="mono muted">' . h($a['last_login_ip']) . '</span>' : '<span class="muted">-</span>' ?></td>
          <td class="nowrap small"><?= h(date('Y-m-d', strtotime($a['created_at']))) ?><br><span class="muted"><?= h($a['created_by']) ?></span></td>
          <td class="right nowrap">
            <a href="admins.php?edit=<?= (int)$a['id'] ?>#form" class="btn btn-ghost btn-sm">수정</a>
            <?php if ((int)$a['id'] !== (int)$me['id']): ?>
            <form method="post" class="inline" onsubmit="return confirm('<?= h($a['username']) ?> 계정을 삭제할까요?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">삭제</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php admin_footer();
