<?php
require __DIR__ . '/bootstrap.php';
use Hue\Core\{Database as DB, RateLimiter, ApiException};

$page = in_array($_GET['p'] ?? '', ['overview', 'users', 'user', 'reports', 'spaces', 'space'], true) ? $_GET['p'] : 'overview';
$error = null;

// ---------- login / logout ----------
if (($_POST['do'] ?? '') === 'login') {
  check_csrf();
  try {
    RateLimiter::hit('admin-login:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 8, 600);
    $login = strtolower(trim($_POST['login'] ?? ''));
    $u = DB::one('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_admin = 1 AND is_banned = 0', [$login, $login]);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) { session_regenerate_id(true); $_SESSION['admin_id'] = (int)$u['id']; back('index.php'); }
    $error = 'User does not exist or password is incorrect.';
  } catch (ApiException $e) { $error = 'Too many attempts. Try again in a few minutes.'; }
    catch (Throwable $e) {
      error_log('Hue admin login unavailable: ' . $e->getMessage());
      $error = 'Admin sign-in is temporarily unavailable. Check the database configuration.';
    }
}
if (($_POST['do'] ?? '') === 'logout') { check_csrf(); session_destroy(); back('index.php'); }

$me = admin();
if (!$me) { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hue admin</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;800&display=swap"><link rel="stylesheet" href="assets/admin.css"></head>
<body><div class="login"><form method="post"><div class="brand" style="padding:0">Hue admin</div>
<?php if ($error) { echo '<div class="err" role="alert">' . e($error) . '</div>'; } ?>
<input type="hidden" name="do" value="login"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<label for="l" class="sr">Username or email</label><input id="l" type="text" name="login" placeholder="Username or email" autocomplete="username" required>
<label for="pw" class="sr">Password</label><div class="password-field"><input id="pw" type="password" name="password" placeholder="Password" autocomplete="current-password" required>
<button class="password-toggle" type="button" id="toggle-password" aria-controls="pw" aria-pressed="false">Show</button></div>
<button class="btn primary">Sign in</button></form></div>
<script src="assets/admin.js" defer></script></body></html>
<?php exit; }

// ---------- actions (POST + CSRF only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
  check_csrf();
  $id = (int)($_POST['id'] ?? 0); $ret = $_POST['return'] ?? 'index.php';
  if (!preg_match('/^index\.php(\?[A-Za-z0-9_=&%-]*)?$/', $ret)) $ret = 'index.php';
  switch ($_POST['act']) {
    case 'ban':
      $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 200);
      DB::run('UPDATE users SET is_banned = 1, suspension_reason = ? WHERE id = ? AND is_admin = 0', [$reason ?: null, $id]);
      DB::run('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
      DB::run('DELETE FROM device_tokens WHERE user_id = ?', [$id]);
      DB::run('UPDATE space_grants g JOIN spaces s ON s.id = g.space_id SET g.revoked_at = NOW() WHERE s.owner_id = ? AND g.revoked_at IS NULL', [$id]);
      break;
    case 'unban': DB::run('UPDATE users SET is_banned = 0, suspension_reason = NULL WHERE id = ?', [$id]); break;
    case 'report':
      $st = in_array($_POST['status'] ?? '', ['reviewed', 'resolved', 'rejected', 'open'], true) ? $_POST['status'] : 'reviewed';
      DB::run('UPDATE reports SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$st, mb_substr(trim($_POST['note'] ?? ''), 0, 500) ?: null, $me['id'], $id]); break;
    case 'hide':   DB::run('UPDATE spaces SET is_hidden = 1 WHERE id = ?', [$id]); break;
    case 'unhide': DB::run('UPDATE spaces SET is_hidden = 0 WHERE id = ?', [$id]); break;
    case 'revokeall':
      DB::run('UPDATE space_access_keys SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$id]);
      DB::run('UPDATE space_grants SET revoked_at = NOW() WHERE space_id = ? AND revoked_at IS NULL', [$id]); break;
    case 'rmmedia':
      $m = DB::one('SELECT path FROM space_media WHERE id = ?', [$id]);
      if ($m) { @unlink(\Hue\Core\Config::get('storage') . '/public_media/' . basename($m['path'])); DB::run('DELETE FROM space_media WHERE id = ?', [$id]); } break;
  }
  back($ret);
}

function form_btn(string $act, int $id, string $label, string $cls = '', array $extra = []): string {
  $h = '<form class="inline" method="post"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="id" value="' . $id . '">';
  $h .= '<input type="hidden" name="return" value="' . e('index.php?' . http_build_query($_GET)) . '">';
  foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
  return $h . '<button class="btn ' . e($cls) . '">' . e($label) . '</button></form>';
}
function dot(string $c): string { return '<span class="dot" style="background:' . e(HUE_COLORS[$c] ?? '#999') . '" aria-hidden="true"></span>'; }
$nav = ['overview' => 'Overview', 'users' => 'People', 'reports' => 'Reports', 'spaces' => 'Spaces'];
$active = ['user' => 'users', 'space' => 'spaces'][$page] ?? $page;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hue admin</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;800&display=swap"><link rel="stylesheet" href="assets/admin.css"></head><body>
<aside><div class="brand">Hue</div>
<?php foreach ($nav as $k => $label) { echo '<a href="?p=' . $k . '" class="' . ($active === $k ? 'on' : '') . '"' . ($active === $k ? ' aria-current="page"' : '') . '>' . e($label) . '</a>'; } ?>
<div class="who"><?= e($me['display_name']) ?><form method="post"><input type="hidden" name="do" value="logout"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="btn" style="margin-top:8px">Sign out</button></form></div></aside>
<main>
<?php
if ($page === 'overview') {
  $s = DB::one("SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM users WHERE last_seen_at > NOW() - INTERVAL 1 DAY) active, (SELECT COUNT(*) FROM users WHERE is_banned = 1) suspended,
    (SELECT COUNT(*) FROM messages WHERE created_at > NOW() - INTERVAL 1 DAY) msgs, (SELECT COUNT(*) FROM messages) allmsgs, (SELECT COUNT(*) FROM spaces WHERE type='public' AND deleted_at IS NULL) pub,
    (SELECT COUNT(*) FROM space_access_keys WHERE revoked_at IS NULL AND expires_at > NOW()) live_keys, (SELECT COUNT(*) FROM reports WHERE status='open') reports");
  $dist = DB::all('SELECT personal_color c, COUNT(*) n FROM users GROUP BY personal_color ORDER BY n DESC'); $total = max(1, array_sum(array_column($dist, 'n')));
  echo '<h1>Overview</h1><p class="sub">What is happening on Hue right now. Message contents are never shown here.</p><div class="stats">';
  foreach ([['users', 'People'], ['active', 'Active in 24 hours'], ['suspended', 'Suspended'], ['msgs', 'Messages in 24 hours'], ['allmsgs', 'Messages in total'], ['pub', 'Public Spaces'], ['live_keys', 'Live Space Keys'], ['reports', 'Open reports']] as [$k, $l])
    echo '<div class="stat"><b>' . (int)$s[$k] . '</b><span>' . e($l) . '</span></div>';
  echo '</div><section class="card"><b>Personal Colors across Hue</b><div class="spectrum" role="img" aria-label="Distribution of personal colors">';
  foreach ($dist as $d) echo '<i style="flex:' . (int)$d['n'] . ';background:' . e(HUE_COLORS[$d['c']]) . '" title="' . e($d['c']) . ': ' . (int)$d['n'] . '"></i>';
  echo '</div><div class="legend">';
  foreach ($dist as $d) echo '<span style="--c:' . e(HUE_COLORS[$d['c']]) . '">' . e(ucfirst($d['c'])) . ' ' . round($d['n'] / $total * 100) . '% (' . (int)$d['n'] . ')</span>';
  echo '</div></section>';
}

elseif ($page === 'users') {
  $q = trim($_GET['q'] ?? '');
  $rows = DB::all('SELECT id, code, username, display_name, email, personal_color, is_banned, is_admin, last_seen_at FROM users WHERE ? = "" OR code LIKE ? OR username LIKE ? OR email LIKE ? OR display_name LIKE ? ORDER BY id DESC LIMIT 100', [$q, "%$q%", "%$q%", "%$q%", "%$q%"]);
  echo '<h1>People</h1><p class="sub">Search by name, code, username or email.</p><form class="bar"><input type="hidden" name="p" value="users"><label class="sr" for="q">Search</label><input id="q" type="search" name="q" value="' . e($q) . '" placeholder="Search people"><button class="btn primary">Search</button></form>';
  echo '<section class="card"><table><tr><th>Name</th><th>Code</th><th>Last seen</th><th>Status</th></tr>';
  foreach ($rows as $u) {
    $status = $u['is_banned'] ? '<span class="tag bad">Suspended</span>' : ($u['is_admin'] ? '<span class="tag">Admin</span>' : 'Active');
    echo '<tr><td>' . dot($u['personal_color']) . '<a href="?p=user&id=' . (int)$u['id'] . '">' . e($u['display_name']) . '</a> <span class="muted">@' . e($u['username']) . '</span></td><td>' . e($u['code']) . '</td><td>' . e($u['last_seen_at'] ?? 'Never') . '</td><td>' . $status . '</td></tr>';
  }
  echo '</table></section>';
}

elseif ($page === 'user') {
  $uid = (int)($_GET['id'] ?? 0);
  $u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
  if (!$u) { echo '<h1>Not found</h1>'; }
  else {
    $st = DB::one('SELECT (SELECT COUNT(*) FROM sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()) sessions,
        (SELECT COUNT(*) FROM messages WHERE sender_id = ?) msgs, (SELECT COUNT(*) FROM spaces WHERE owner_id = ? AND deleted_at IS NULL) spaces,
        (SELECT COUNT(*) FROM reports WHERE reported_user_id = ?) against, (SELECT COUNT(*) FROM reports WHERE reporter_id = ?) filed', [$uid, $uid, $uid, $uid, $uid]);
    echo '<h1>' . dot($u['personal_color']) . e($u['display_name']) . '</h1><p class="sub">@' . e($u['username']) . ' &middot; ' . e($u['code']) . ' &middot; ' . ($u['is_banned'] ? '<span class="tag bad">Suspended</span>' : 'Active') . '</p>';
    echo '<div class="stats"><div class="stat"><b>' . (int)$st['sessions'] . '</b><span>Active sessions</span></div><div class="stat"><b>' . (int)$st['msgs'] . '</b><span>Messages sent</span></div><div class="stat"><b>' . (int)$st['spaces'] . '</b><span>Spaces</span></div><div class="stat"><b>' . (int)$st['against'] . '</b><span>Reports against</span></div><div class="stat"><b>' . (int)$st['filed'] . '</b><span>Reports filed</span></div></div>';
    echo '<section class="card"><table><tr><th>Email</th><td>' . e($u['email']) . '</td></tr><tr><th>Joined</th><td>' . e($u['created_at']) . '</td></tr><tr><th>Last seen</th><td>' . e($u['last_seen_at'] ?? 'Never') . '</td></tr><tr><th>Personal Color</th><td>' . e(ucfirst($u['personal_color'])) . '</td></tr>';
    if ($u['is_banned']) echo '<tr><th>Suspension reason</th><td>' . e($u['suspension_reason'] ?? 'None given') . '</td></tr>';
    echo '</table></section>';
    if (!$u['is_admin']) {
      if ($u['is_banned']) echo '<section class="card">' . form_btn('unban', $uid, 'Restore account', 'primary') . '</section>';
      else echo '<section class="card"><form method="post" class="bar"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="act" value="ban"><input type="hidden" name="id" value="' . $uid . '"><input type="hidden" name="return" value="' . e('index.php?p=user&id=' . $uid) . '"><label class="sr" for="rs">Reason</label><input id="rs" type="text" name="reason" placeholder="Reason shown to the user (optional)" maxlength="200"><button class="btn danger">Suspend account</button></form><p class="muted">Suspending revokes every session, blocks login and API access, and hides their Spaces.</p></section>';
    }
    $rep = DB::all('SELECT r.id, r.category, r.status, r.created_at, r.reported_user_id, rep.display_name reporter FROM reports r LEFT JOIN users rep ON rep.id = r.reporter_id WHERE r.reported_user_id = ? OR r.reporter_id = ? ORDER BY r.id DESC LIMIT 30', [$uid, $uid]);
    echo '<h2>Report history</h2><section class="card">';
    if (!$rep) echo '<p class="muted">No reports involve this person.</p>';
    else { echo '<table><tr><th>When</th><th>Role</th><th>Category</th><th>Status</th></tr>'; foreach ($rep as $r) echo '<tr><td>' . e($r['created_at']) . '</td><td>' . ((int)$r['reported_user_id'] === $uid ? 'Reported' : 'Reporter') . '</td><td>' . e($r['category']) . '</td><td><a href="?p=reports&status=' . e($r['status']) . '">' . e($r['status']) . '</a></td></tr>'; echo '</table>'; }
    echo '</section>';
  }
}

elseif ($page === 'reports') {
  $f = $_GET['status'] ?? 'open'; $cat = $_GET['category'] ?? '';
  $f = in_array($f, ['open', 'reviewed', 'resolved', 'rejected', 'all'], true) ? $f : 'open';
  $cat = in_array($cat, ['spam', 'harassment', 'inappropriate', 'other'], true) ? $cat : '';
  $rows = DB::all("SELECT r.*, rep.display_name reporter, tgt.display_name target, tgt.is_banned target_banned, m.body msg_body, m.deleted_at msg_deleted, s.title space_title, s.id sp_id
    FROM reports r LEFT JOIN users rep ON rep.id = r.reporter_id LEFT JOIN users tgt ON tgt.id = r.reported_user_id LEFT JOIN messages m ON m.id = r.message_id LEFT JOIN spaces s ON s.id = r.space_id
    WHERE (? = 'all' OR r.status = ?) AND (? = '' OR r.category = ?) ORDER BY r.id DESC LIMIT 100", [$f, $f, $cat, $cat]);
  echo '<h1>Reports</h1><p class="sub">Only reported messages are visible to you, never whole conversations.</p><form class="bar"><input type="hidden" name="p" value="reports"><label class="sr" for="st">Status</label><select id="st" name="status" class="btn">';
  foreach (['open', 'reviewed', 'resolved', 'rejected', 'all'] as $o) echo '<option value="' . $o . '"' . ($f === $o ? ' selected' : '') . '>' . ucfirst($o) . '</option>';
  echo '</select><label class="sr" for="ct">Category</label><select id="ct" name="category" class="btn"><option value="">Any category</option>';
  foreach (['spam', 'harassment', 'inappropriate', 'other'] as $o) echo '<option value="' . $o . '"' . ($cat === $o ? ' selected' : '') . '>' . ucfirst($o) . '</option>';
  echo '</select><button class="btn primary">Filter</button></form>';
  if (!$rows) echo '<section class="card"><p class="muted">No reports match.</p></section>';
  foreach ($rows as $r) {
    $what = $r['message_id'] ? 'Message: ' . ($r['msg_deleted'] ? '(deleted by sender)' : mb_strimwidth((string)$r['msg_body'], 0, 160, '...')) : ($r['space_id'] ? 'Space: ' . ($r['space_title'] ?? '(removed)') : 'User profile');
    echo '<section class="card"><div class="row"><b>' . e(ucfirst($r['category'])) . '</b><span class="tag' . ($r['status'] === 'open' ? ' bad' : '') . '">' . e($r['status']) . '</span><span class="muted">' . e($r['created_at']) . '</span></div>';
    echo '<p>' . e($what) . '</p><p class="muted">Reported by ' . e($r['reporter'] ?? 'unknown') . ' &middot; About ' . ($r['reported_user_id'] ? '<a href="?p=user&id=' . (int)$r['reported_user_id'] . '">' . e($r['target']) . '</a>' : 'n/a') . ($r['details'] ? ' &middot; "' . e($r['details']) . '"' : '') . '</p>';
    if ($r['space_id']) echo '<p><a href="?p=space&id=' . (int)$r['space_id'] . '">Review Space</a></p>';
    echo '<form method="post" class="bar"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="act" value="report"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="return" value="' . e('index.php?' . http_build_query($_GET)) . '">';
    echo '<label class="sr" for="n' . (int)$r['id'] . '">Moderation note</label><input id="n' . (int)$r['id'] . '" type="text" name="note" value="' . e($r['admin_note'] ?? '') . '" placeholder="Moderation note" maxlength="500">';
    foreach (['reviewed' => 'Mark reviewed', 'resolved' => 'Resolve', 'rejected' => 'Reject'] as $v => $l) echo '<button class="btn' . ($v === 'resolved' ? ' primary' : '') . '" name="status" value="' . $v . '">' . $l . '</button>';
    echo '</form>';
    if ($r['reported_user_id'] && !$r['target_banned']) echo form_btn('ban', (int)$r['reported_user_id'], 'Suspend ' . $r['target'], 'danger', ['reason' => 'Violation of community rules']);
    echo '</section>';
  }
}

elseif ($page === 'spaces') {
  $rows = DB::all("SELECT s.id, s.type, s.title, s.is_hidden, s.created_at, o.display_name owner, o.id owner_id,
     (SELECT COUNT(*) FROM space_media WHERE space_id = s.id) media, (SELECT COUNT(*) FROM space_access_keys WHERE space_id = s.id AND revoked_at IS NULL AND expires_at > NOW()) live_keys,
     (SELECT COUNT(*) FROM reports WHERE space_id = s.id AND status = 'open') open_reports
     FROM spaces s JOIN users o ON o.id = s.owner_id WHERE s.deleted_at IS NULL ORDER BY open_reports DESC, s.id DESC LIMIT 100");
  echo '<h1>Spaces</h1><p class="sub">Review public Spaces. Private pictures are never stored on the server, so only access can be revoked.</p><section class="card"><table><tr><th>Title</th><th>Owner</th><th>Type</th><th>Content</th><th>Reports</th><th></th></tr>';
  foreach ($rows as $s) {
    $content = $s['type'] === 'public' ? (int)$s['media'] . ' pictures' : (int)$s['live_keys'] . ' live keys';
    $act = $s['type'] === 'public' ? ($s['is_hidden'] ? form_btn('unhide', (int)$s['id'], 'Show') : form_btn('hide', (int)$s['id'], 'Suspend', 'danger')) : form_btn('revokeall', (int)$s['id'], 'Revoke access', 'danger');
    $title = $s['type'] === 'public' ? '<a href="?p=space&id=' . (int)$s['id'] . '">' . e($s['title']) . '</a>' : e($s['title']);
    echo '<tr><td>' . $title . ($s['is_hidden'] ? ' <span class="tag bad">Suspended</span>' : '') . '</td><td><a href="?p=user&id=' . (int)$s['owner_id'] . '">' . e($s['owner']) . '</a></td><td>' . e($s['type']) . '</td><td>' . $content . '</td><td>' . (int)$s['open_reports'] . '</td><td>' . $act . '</td></tr>';
  }
  echo '</table></section>';
}

elseif ($page === 'space') {
  $sp = DB::one("SELECT s.*, o.display_name owner FROM spaces s JOIN users o ON o.id = s.owner_id WHERE s.id = ? AND s.type = 'public'", [(int)($_GET['id'] ?? 0)]);
  if (!$sp) { echo '<h1>Not found</h1>'; }
  else {
    echo '<h1>' . e($sp['title']) . '</h1><p class="sub">By ' . e($sp['owner']) . ($sp['is_hidden'] ? ' &middot; <span class="tag bad">Suspended</span>' : '') . '</p><section class="card">' . ($sp['is_hidden'] ? form_btn('unhide', (int)$sp['id'], 'Show Space') : form_btn('hide', (int)$sp['id'], 'Suspend Space', 'danger')) . '</section><div class="grid">';
    foreach (DB::all('SELECT id FROM space_media WHERE space_id = ? ORDER BY position', [$sp['id']]) as $m)
      echo '<figure class="card"><img src="media.php?id=' . (int)$m['id'] . '" alt="Space picture ' . (int)$m['id'] . '" loading="lazy">' . form_btn('rmmedia', (int)$m['id'], 'Remove picture', 'danger') . '</figure>';
    echo '</div>';
  }
}
?>
</main></body></html>
