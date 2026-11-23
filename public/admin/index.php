<?php
require __DIR__ . '/../inc/admin.php';
require __DIR__ . '/../inc/responses.php';
$me = require_login();

$tbl = '`' . T('survey_response') . '`';

// 선택한 응답 삭제 처리 / 복구 (DB 에서 지우지 않고 deleted_at 만 표시)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = (string)g($_POST, 'back', 'index.php');
    if (!preg_match('/^index\.php(\?[^\s<>"]*)?$/', $back)) {
        $back = 'index.php';
    }
    $ids = array();
    foreach ((array)g($_POST, 'ids', array()) as $v) {
        if ((int)$v > 0) $ids[] = (int)$v;
    }
    $ids = array_values(array_unique($ids));
    $act = (string)g($_POST, 'action');
    if (!$ids) {
        flash('선택한 응답이 없습니다.', 'err');
    } elseif ($act === 'soft_delete' || $act === 'restore') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($act === 'soft_delete') {
            q("UPDATE $tbl SET deleted_at = ?, deleted_by = ? WHERE deleted_at IS NULL AND id IN ($in)", array_merge(array(now(), $me['username']), $ids));
            flash(count($ids) . '건을 삭제 처리했습니다. ([삭제된 응답]에서 복구할 수 있습니다)');
        } else {
            q("UPDATE $tbl SET deleted_at = NULL, deleted_by = '' WHERE deleted_at IS NOT NULL AND id IN ($in)", $ids);
            flash(count($ids) . '건을 복구했습니다.');
        }
    }
    redirect($back);
}

$f = result_filter($_GET);
list($where, $params, $order) = result_sql($f);

$perOptions = array(20, 50, 100, 200);
$per = (int)g($_GET, 'per', 50);
if (!in_array($per, $perOptions, true)) {
    $per = 50;
}
$page = max(1, (int)g($_GET, 'page', 1));

$total = (int)q1("SELECT COUNT(*) AS n FROM $tbl $where", $params)['n'];
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = q("SELECT id, submission_id, status, channel, respondent_type, consent, company, name, phone, industry, current_q,
                  answered_count, route_count, ip, started_at, updated_at, completed_at, deleted_at, deleted_by
           FROM $tbl $where $order LIMIT " . (($page - 1) * $per) . ", $per", $params);

// 상단 요약 (검색 조건과 관계없이 전체 기준)
$sum = array('all' => 0, 'complete' => 0, 'progress' => 0, 'out' => 0);
foreach (q("SELECT status, COUNT(*) AS n FROM $tbl WHERE deleted_at IS NULL GROUP BY status") as $r) {
    $sum[$r['status']] = (int)$r['n'];
    $sum['all'] += (int)$r['n'];
}
// 유입 경로별 응답 수 (전체 / 제출 완료)
$byChannel = array();
foreach (channel_list() as $k => $v) {
    $byChannel[$k] = array('all' => 0, 'complete' => 0);
}
foreach (q("SELECT channel, COUNT(*) AS n, SUM(status = 'complete') AS c FROM $tbl WHERE deleted_at IS NULL GROUP BY channel") as $r) {
    $byChannel[$r['channel']] = array('all' => (int)$r['n'], 'complete' => (int)$r['c']);
}
$deletedCount = (int)q1("SELECT COUNT(*) AS n FROM $tbl WHERE deleted_at IS NOT NULL")['n'];
$today = (int)q1("SELECT COUNT(*) AS n FROM $tbl WHERE deleted_at IS NULL AND completed_at >= ?", array(date('Y-m-d 00:00:00')))['n'];

$industries = survey_options('A6');
$sorts = result_sorts();
$fq = $f + array('per' => $per);

function sort_th($key, $label, $f, $per) {
    $on = $f['sort'] === $key;
    $dir = ($on && $f['dir'] === 'desc') ? 'asc' : 'desc';
    $arrow = $on ? ($f['dir'] === 'asc' ? '▲' : '▼') : '';
    $aria = $on ? ($f['dir'] === 'asc' ? 'ascending' : 'descending') : 'none';
    return '<th aria-sort="' . $aria . '"><a href="' . h(result_url('index.php', $f + array('per' => $per), array('sort' => $key, 'dir' => $dir, 'page' => ''))) . '">'
        . h($label) . ' <span class="arr">' . $arrow . '</span></a></th>';
}

function fmt_dt($s) {
    return $s ? date('Y-m-d H:i', strtotime($s)) : '';
}

admin_header('설문조사 결과', 'results');
?>
<div class="page-head">
  <h1>설문조사 결과</h1>
  <a class="btn btn-ghost" href="<?= h(result_url('export.php', $f)) ?>">검색 결과 CSV 내려받기</a>
</div>

<section class="stats" aria-label="응답 현황">
  <a class="stat" href="index.php"><span>전체 응답</span><b><?= number_format($sum['all']) ?></b></a>
  <a class="stat stat-ok" href="index.php?status=complete"><span>제출 완료</span><b><?= number_format($sum['complete']) ?></b></a>
  <a class="stat" href="index.php?status=progress"><span>작성 중</span><b><?= number_format($sum['progress']) ?></b></a>
  <a class="stat" href="index.php?status=out"><span>대상외 종료</span><b><?= number_format($sum['out']) ?></b></a>
  <div class="stat"><span>오늘 제출</span><b><?= number_format($today) ?></b></div>
  <a class="stat stat-del<?= $f['del'] === '1' ? ' on' : '' ?>" href="index.php?del=1"><span>삭제된 응답</span><b><?= number_format($deletedCount) ?></b></a>
</section>

<section class="channels" aria-label="유입 경로별 응답">
  <span class="channels-title">유입 경로별</span>
  <?php foreach ($byChannel as $k => $c):
      $key = $k === '' ? 'base' : $k; ?>
  <a class="chip-ch<?= $f['channel'] === $key ? ' on' : '' ?>" href="<?= h(result_url('index.php', $fq, array('channel' => $f['channel'] === $key ? '' : $key, 'page' => ''))) ?>">
    <span class="ch ch-<?= h(strtolower($key)) ?>"><?= h(channel_label($k)) ?></span>
    <b><?= number_format($c['all']) ?></b><small>완료 <?= number_format($c['complete']) ?></small>
  </a>
  <?php endforeach; ?>
</section>

<form class="filter" method="get" action="index.php">
  <div class="filter-grid">
    <label class="fld"><span>설문 완료 여부</span>
      <select name="status">
        <?php foreach (result_status_filters() as $k => $v): ?>
        <option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="fld"><span>유입 경로</span>
      <select name="channel">
        <option value="">전체</option>
        <?php foreach (channel_list() as $k => $v): $key = $k === '' ? 'base' : $k; ?>
        <option value="<?= h($key) ?>"<?= $f['channel'] === $key ? ' selected' : '' ?>><?= h($k === '' ? '기본 (/itp/)' : $v . ' (/' . $k . '/)') ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="fld"><span>분야 (주요 산업)</span>
      <select name="industry">
        <option value="">전체</option>
        <?php foreach ($industries as $k => $v): ?>
        <option value="<?= h($k) ?>"<?= $f['industry'] === (string)$k ? ' selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
        <option value="none"<?= $f['industry'] === 'none' ? ' selected' : '' ?>>(미응답)</option>
      </select></label>
    <label class="fld"><span>응답자 유형</span>
      <select name="rtype">
        <option value="">전체</option>
        <option value="1"<?= $f['rtype'] === '1' ? ' selected' : '' ?>>기업 대표응답자</option>
        <option value="2"<?= $f['rtype'] === '2' ? ' selected' : '' ?>>일반 재직자</option>
        <option value="3"<?= $f['rtype'] === '3' ? ' selected' : '' ?>>인천 ICT IS 교육 기수강생</option>
        <option value="none"<?= $f['rtype'] === 'none' ? ' selected' : '' ?>>(미응답)</option>
      </select></label>
    <div class="fld fld-date"><span>기간</span>
      <div class="date-row">
        <select name="dfield" aria-label="기간 기준">
          <?php foreach (result_date_fields() as $k => $v): ?>
          <option value="<?= h($k) ?>"<?= $f['dfield'] === $k ? ' selected' : '' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= h($f['from']) ?>" aria-label="시작 날짜">
        <span aria-hidden="true">~</span>
        <input type="date" name="to" value="<?= h($f['to']) ?>" aria-label="끝 날짜">
      </div></div>
    <label class="fld fld-kw"><span>검색어</span>
      <input type="search" name="kw" value="<?= h($f['kw']) ?>" placeholder="기업명·성명·연락처·이메일·IP"></label>
    <label class="fld"><span>정렬</span>
      <div class="date-row">
        <select name="sort">
          <?php foreach ($sorts as $k => $v): ?>
          <option value="<?= h($k) ?>"<?= $f['sort'] === $k ? ' selected' : '' ?>><?= h($v[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="dir" aria-label="정렬 방향">
          <option value="desc"<?= $f['dir'] === 'desc' ? ' selected' : '' ?>>내림차순</option>
          <option value="asc"<?= $f['dir'] === 'asc' ? ' selected' : '' ?>>오름차순</option>
        </select>
      </div></label>
  </div>
  <div class="filter-actions">
    <input type="hidden" name="per" value="<?= $per ?>">
    <button type="submit" class="btn btn-primary">검색</button>
    <a href="index.php" class="btn btn-ghost">초기화</a>
  </div>
</form>

<?php if ($f['del'] === '1'): ?>
<p class="del-note">삭제 처리된 응답만 보고 있습니다. 통계·CSV 기본 목록에는 포함되지 않으며, 선택해서 복구할 수 있습니다. <a href="index.php">정상 응답 목록으로</a></p>
<?php endif; ?>
<?php $backUrl = result_url('index.php', $fq, array('page' => $page > 1 ? $page : '')); ?>
<form id="bulk" method="post" action="index.php" onsubmit="return bulkCheck(this, event)">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= h($backUrl) ?>">
</form>
<div class="list-head">
  <p>검색 결과 <b><?= number_format($total) ?></b>건 · <?= h($sorts[$f['sort']][0]) ?> <?= $f['dir'] === 'asc' ? '오름차순' : '내림차순' ?></p>
  <div class="bulk-actions">
    <?php if ($f['del'] === '1'): ?>
    <button type="submit" form="bulk" name="action" value="restore" class="btn btn-ghost btn-sm">선택 복구</button>
    <?php else: ?>
    <button type="submit" form="bulk" name="action" value="soft_delete" class="btn btn-danger btn-sm">선택 삭제</button>
    <?php endif; ?>
  </div>
  <form method="get" action="index.php" class="per">
    <?php foreach ($f as $k => $v): if ($v === '') continue; ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
    <label>한 화면에
      <select name="per" onchange="this.form.submit()">
        <?php foreach ($perOptions as $n): ?><option value="<?= $n ?>"<?= $per === $n ? ' selected' : '' ?>><?= $n ?>건</option><?php endforeach; ?>
      </select></label>
  </form>
</div>

<div class="table-wrap">
<table class="tbl">
  <thead><tr>
    <th class="chk"><input type="checkbox" id="chkAll" aria-label="이 화면 전체 선택"></th>
    <?= sort_th('id', '번호', $f, $per) ?>
    <?= sort_th('status', '완료 여부', $f, $per) ?>
    <?= sort_th('channel', '유입 경로', $f, $per) ?>
    <?= sort_th('rtype', '응답자 유형', $f, $per) ?>
    <?= sort_th('company', '기업명', $f, $per) ?>
    <?= sort_th('name', '성명', $f, $per) ?>
    <th>연락처</th>
    <?= sort_th('industry', '분야', $f, $per) ?>
    <?= sort_th('progress', '진행', $f, $per) ?>
    <?= sort_th('ip', 'IP', $f, $per) ?>
    <?= sort_th('started', '시작', $f, $per) ?>
    <?= sort_th('completed', '제출', $f, $per) ?>
    <?= sort_th('updated', '최종 저장', $f, $per) ?>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="14" class="empty">조건에 맞는 응답이 없습니다.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
      $url = 'view.php?id=' . (int)$r['id'] . '&back=' . rawurlencode(result_url('index.php', $fq, array('page' => $page > 1 ? $page : '')));
      $pct = progress_pct($r); ?>
    <tr<?= $r['deleted_at'] ? ' class="row-del"' : '' ?>>
      <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulk" aria-label="<?= (int)$r['id'] ?>번 선택"></td>
      <td class="num"><a href="<?= h($url) ?>"><?= (int)$r['id'] ?></a></td>
      <td><span class="badge badge-<?= h($r['status']) ?>"><?= h(status_label($r['status'])) ?></span><?php if ($r['deleted_at']): ?><br><small class="del-by" title="<?= h($r['deleted_at']) ?>">삭제 <?= h(fmt_dt($r['deleted_at'])) ?> · <?= h($r['deleted_by']) ?></small><?php endif; ?></td>
      <td><span class="ch ch-<?= h($r['channel'] === '' ? 'base' : strtolower($r['channel'])) ?>"><?= h(channel_label($r['channel'])) ?></span></td>
      <td class="w-rt"><?= h(rtype_label($r['respondent_type'])) ?></td>
      <td class="strong co"><a href="<?= h($url) ?>"><?= $r['company'] !== '' ? h($r['company']) : blank_label($r) ?></a></td>
      <td class="nowrap"><a class="name-pop" href="<?= h($url) ?>" data-pop="view.php?id=<?= (int)$r['id'] ?>&amp;popup=1" title="설문 내용 팝업으로 보기"><?= $r['name'] !== '' ? h($r['name']) : blank_label($r) ?></a></td>
      <td class="nowrap"><?= h($r['phone']) ?></td>
      <td class="w-ind"><?= $r['industry'] !== null ? h(g($industries, (int)$r['industry'], $r['industry'])) : '' ?></td>
      <td class="nowrap" title="<?= (int)$r['answered_count'] ?> / <?= (int)$r['route_count'] ?> 문항<?= $r['status'] !== 'complete' && $r['current_q'] !== '' ? ' · 마지막 문항 ' . h($r['current_q']) : '' ?>">
        <span class="bar"><i style="width:<?= $pct ?>%"></i></span> <?= $pct ?>%</td>
      <td class="mono"><?= h($r['ip']) ?></td>
      <td class="nowrap"><?= h(fmt_dt($r['started_at'])) ?></td>
      <td class="nowrap"><?= h(fmt_dt($r['completed_at'])) ?></td>
      <td class="nowrap"><?= h(fmt_dt($r['updated_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="페이지">
  <?php
  $start = max(1, $page - 4);
  $end = min($pages, $start + 9);
  $start = max(1, $end - 9);
  if ($page > 1) echo '<a href="' . h(result_url('index.php', $fq, array('page' => $page - 1))) . '">이전</a>';
  for ($i = $start; $i <= $end; $i++) {
      echo $i === $page ? '<b aria-current="page">' . $i . '</b>' : '<a href="' . h(result_url('index.php', $fq, array('page' => $i))) . '">' . $i . '</a>';
  }
  if ($page < $pages) echo '<a href="' . h(result_url('index.php', $fq, array('page' => $page + 1))) . '">다음</a>';
  ?>
</nav>
<?php endif; ?>
<dialog id="popup" class="popup" aria-labelledby="popupTitle">
  <div class="popup-head">
    <b id="popupTitle">설문 내용</b>
    <button type="button" class="btn btn-ghost btn-sm" id="popupClose">닫기</button>
  </div>
  <iframe id="popupFrame" title="설문 내용" src="about:blank"></iframe>
</dialog>
<script>
function bulkCheck(form, ev) {
  var n = document.querySelectorAll('input[name="ids[]"]:checked').length;
  var b = (ev && ev.submitter) || document.activeElement, act = b && b.value;
  if (!n) { alert('삭제(복구)할 응답을 선택해 주세요.'); return false; }
  return confirm(act === 'restore' ? n + '건을 복구할까요?' : n + '건을 삭제 처리할까요?\n(DB 에서 지워지지 않으며 [삭제된 응답]에서 복구할 수 있습니다)');
}
(function () {
  var all = document.getElementById('chkAll');
  if (all) all.addEventListener('change', function () {
    var c = document.querySelectorAll('input[name="ids[]"]');
    for (var i = 0; i < c.length; i++) c[i].checked = all.checked;
  });
})();
(function () {
  var dlg = document.getElementById('popup'), frame = document.getElementById('popupFrame');
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a.name-pop') : null;
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || !dlg.showModal) return;   // Ctrl+클릭은 새 탭
    e.preventDefault();
    document.getElementById('popupTitle').textContent = a.textContent.trim() + ' 님의 설문 내용';
    frame.src = a.getAttribute('data-pop');
    dlg.showModal();
  });
  function close() { dlg.close(); }
  document.getElementById('popupClose').addEventListener('click', close);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });   // 바깥 클릭
  dlg.addEventListener('close', function () { frame.src = 'about:blank'; });
})();
</script>
<?php admin_footer();
