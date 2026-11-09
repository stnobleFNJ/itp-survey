<?php
require __DIR__ . '/../inc/admin.php';
$me = require_login();

$tbl = '`' . T('survey_response') . '`';
$id = (int)g($_GET, 'id', g($_POST, 'id', 0));
$popup = g($_GET, 'popup') === '1';   // 목록 화면의 팝업 안에서 열림
$back = (string)g($_GET, 'back', g($_POST, 'back', 'index.php'));
if (!preg_match('/^index\.php(\?[^\s<>"]*)?$/', $back)) {
    $back = 'index.php';
}

// 응답 삭제 (최고 관리자만): 시험 응답 정리용
if ($_SERVER['REQUEST_METHOD'] === 'POST' && g($_POST, 'action') === 'delete') {
    csrf_check();
    if (!is_super()) {
        http_response_code(403);
        exit('권한이 없습니다.');
    }
    q("DELETE FROM $tbl WHERE id = ?", array($id));
    flash($id . '번 응답을 삭제했습니다.');
    redirect($back);
}

$r = q1("SELECT * FROM $tbl WHERE id = ?", array($id));
if (!$r) {
    flash('응답을 찾을 수 없습니다.', 'err');
    redirect($back);
}

$def = survey_def();
$answers = json_decode((string)$r['answers'], true);
$answers = is_array($answers) ? $answers : array();
$route = json_decode((string)$r['route'], true);
$route = is_array($route) ? $route : array();

function dur($from, $to) {
    if (!$from || !$to) return '';
    $s = max(0, strtotime($to) - strtotime($from));
    if ($s < 60) return $s . '초';
    if ($s < 3600) return floor($s / 60) . '분 ' . ($s % 60) . '초';
    return floor($s / 3600) . '시간 ' . floor(($s % 3600) / 60) . '분';
}

$industries = survey_options('A6');
admin_header('응답 #' . $r['id'], 'results', $popup);
?>
<div class="page-head">
  <h1>응답 #<?= (int)$r['id'] ?> <span class="badge badge-<?= h($r['status']) ?>"><?= h(status_label($r['status'])) ?></span></h1>
  <div class="head-actions">
    <?php if ($popup): ?>
    <a class="btn btn-ghost" href="view.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">새 창에서 열기 ↗</a>
    <?php else: ?>
    <a class="btn btn-ghost" href="<?= h($back) ?>">목록으로</a>
    <?php if (is_super()): ?>
    <form method="post" onsubmit="return confirm('이 응답을 삭제할까요? 되돌릴 수 없습니다.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <button type="submit" class="btn btn-danger">응답 삭제</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<section class="card">
  <h2>응답 정보</h2>
  <dl class="meta">
    <div><dt>응답자 유형</dt><dd><?= h(rtype_label($r['respondent_type'])) ?: '-' ?></dd></div>
    <div><dt>기업명</dt><dd><?= h($r['company']) ?: '-' ?></dd></div>
    <div><dt>성명</dt><dd><?= h($r['name']) ?: '-' ?><?= $r['gender'] !== '' ? ' (' . h($r['gender']) . ')' : '' ?></dd></div>
    <div><dt>직위</dt><dd><?= h($r['position']) ?: '-' ?></dd></div>
    <div><dt>연락처</dt><dd><?= h($r['phone']) ?: '-' ?></dd></div>
    <div><dt>이메일</dt><dd><?= h($r['email']) ?: '-' ?></dd></div>
    <div><dt>분야</dt><dd><?= $r['industry'] !== null ? h(g($industries, (int)$r['industry'], $r['industry'])) : '-' ?></dd></div>
    <div><dt>개인정보 동의</dt><dd><?= $r['consent'] === null ? '-' : ((int)$r['consent'] === 1 ? '동의' : '동의하지 않음') ?></dd></div>
    <div><dt>진행</dt><dd><?= (int)$r['answered_count'] ?> / <?= (int)$r['route_count'] ?> 문항<?= $r['status'] !== 'complete' && $r['current_q'] !== '' ? ' · 마지막 문항 ' . h($r['current_q']) : '' ?></dd></div>
    <div><dt>시작 일시</dt><dd><?= h($r['started_at']) ?></dd></div>
    <div><dt>제출 일시</dt><dd><?= h($r['completed_at']) ?: '-' ?></dd></div>
    <div><dt>최종 저장</dt><dd><?= h($r['updated_at']) ?> <small class="muted">(저장 <?= (int)$r['save_count'] ?>회)</small></dd></div>
    <div><dt>소요 시간</dt><dd><?= h(dur($r['started_at'], $r['completed_at'] ? $r['completed_at'] : $r['updated_at'])) ?: '-' ?></dd></div>
    <div><dt>IP</dt><dd class="mono"><?= h($r['ip']) ?><?= $r['last_ip'] !== $r['ip'] ? ' → ' . h($r['last_ip']) . ' <small class="muted">(마지막 저장)</small>' : '' ?></dd></div>
    <?php if ($r['forwarded_for'] !== ''): ?><div><dt>X-Forwarded-For</dt><dd class="mono"><?= h($r['forwarded_for']) ?></dd></div><?php endif; ?>
    <div class="wide"><dt>브라우저</dt><dd class="small"><?= h($r['user_agent']) ?: '-' ?></dd></div>
    <div class="wide"><dt>유입 경로</dt><dd class="small"><?= h($r['referer']) ?: '-' ?></dd></div>
    <div class="wide"><dt>응답 고유번호</dt><dd class="mono small"><?= h($r['submission_id']) ?> · 설문 버전 <?= h($r['survey_version']) ?></dd></div>
  </dl>
</section>

<section class="card">
  <h2>응답 내용</h2>
  <?php
  $curSec = null;
  $shown = 0;
  foreach ($def['q'] as $qid => $q):
      $inRoute = in_array($qid, $route, true);
      $has = array_key_exists($qid, $answers);
      if (!$inRoute && !$has) continue;
      if ($q['sec'] !== $curSec):
          if ($curSec !== null) echo '</div>';
          $curSec = $q['sec']; ?>
      <h3 class="sec"><?= h($curSec) ?>. <?= h(g($def['sections'], $curSec, '')) ?></h3><div class="qa-list">
  <?php endif;
      $lines = $has ? format_answer($q, $answers[$qid]) : array();
      $shown++; ?>
    <div class="qa<?= $has ? '' : ' qa-empty' ?>">
      <div class="qa-q"><span class="qno"><?= h($qid) ?></span><?= h($q['title']) ?></div>
      <div class="qa-a"><?php
        if (!$has) {
            echo '<span class="muted">' . ($r['status'] === 'complete' ? '건너뜀' : '아직 응답하지 않음') . '</span>';
        } else {
            echo nl2br(h(implode("\n", $lines)));
        } ?></div>
    </div>
  <?php endforeach;
  if ($curSec !== null) echo '</div>';
  if (!$shown) echo '<p class="muted">저장된 응답이 없습니다.</p>'; ?>
</section>

<details class="card raw">
  <summary>원본 데이터(JSON) 보기</summary>
  <pre><?= h(json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
</details>
<?php admin_footer($popup);
