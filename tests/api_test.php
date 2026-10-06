<?php
/**
 * End-to-end API tests. Needs: a MySQL database with schema.sql + 002 migration, and the API running:
 *   HUE_MAIL_DRIVER=log php -S 127.0.0.1:8080 -t backend/public backend/public/index.php
 *   HUE_TEST_DB="mysql:host=127.0.0.1;dbname=hue" HUE_TEST_USER=hue HUE_TEST_PASS=... php tests/api_test.php
 * WARNING: wipes the users table. Use a throwaway database.
 */
$base = getenv('HUE_TEST_URL') ?: 'http://127.0.0.1:8080/api';
$pdo = new PDO(getenv('HUE_TEST_DB') ?: 'mysql:host=127.0.0.1;dbname=hue', getenv('HUE_TEST_USER') ?: 'hue', getenv('HUE_TEST_PASS') ?: 'testpw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE users; TRUNCATE sessions; TRUNCATE friendships; TRUNCATE conversations; TRUNCATE conversation_members; TRUNCATE messages; TRUNCATE notifications; TRUNCATE reports; TRUNCATE spaces; TRUNCATE space_media; TRUNCATE space_access_keys; TRUNCATE space_grants; TRUNCATE space_relay; TRUNCATE space_impressions; TRUNCATE blocked_users; TRUNCATE chat_colors; TRUNCATE notification_settings; TRUNCATE password_resets; TRUNCATE device_tokens; SET FOREIGN_KEY_CHECKS=1;');
@unlink(__DIR__ . '/../backend/storage/logs/mail.log');
$pass = 0; $fail = 0;

function api(string $m, string $path, array $body = [], ?string $tok = null, array $files = []): array {
  global $base;
  $ch = curl_init($base . $path . ($m === 'GET' && $body ? '?' . http_build_query($body) : ''));
  $h = $tok ? ["Authorization: Bearer $tok"] : [];
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
  if ($m === 'POST') {
    if ($files) { foreach ($files as $k => $f) $body[$k] = $f; curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    else { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
  $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = json_decode((string)$raw, true);
  return [$code, $j ?? ['_raw' => $raw]];
}
function t(string $name, bool $ok, $detail = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name " . (is_string($detail) ? $detail : json_encode($detail)) . "\n"; } }
function limits(): void { global $pdo; $pdo->exec('TRUNCATE rate_limits'); }
function uuid(): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function reg(string $n, string $color = 'white'): array { [$c, $j] = api('POST', '/auth/register', ['display_name' => ucfirst($n), 'username' => $n, 'email' => "$n@example.com", 'password' => 'Passw0rdOK', 'confirm_password' => 'Passw0rdOK', 'color' => $color]); return [$j['token'] ?? '', $j['user'] ?? []]; }
function q(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }

echo "AUTH\n";
[$c, $j] = api('POST', '/auth/register', ['display_name' => 'X', 'username' => 'xx', 'email' => 'bad', 'password' => 'short', 'confirm_password' => 'short']); t('register rejects invalid input', $c === 422, $j);
[$c, $j] = api('POST', '/auth/register', ['display_name' => 'Kim', 'username' => 'kim', 'email' => 'kim@example.com', 'password' => 'Passw0rdOK', 'confirm_password' => 'Different1']); t('register rejects mismatched confirm', $c === 422 && str_contains($j['error'], 'match'), $j);
[$c, $j] = api('POST', '/auth/register', ['display_name' => 'Kim', 'username' => 'kim', 'email' => 'kim@example.com', 'password' => 'onlyletters', 'confirm_password' => 'onlyletters']); t('register rejects weak password', $c === 422, $j);
[$ta, $A] = reg('kim', 'green'); t('register succeeds with color + code', $ta !== '' && preg_match('/^[A-Z]{3}-[A-Z0-9]{5}$/', $A['code'] ?? '') && $A['personal_color'] === 'green', $A);
[$c, $j] = api('POST', '/auth/register', ['display_name' => 'Kim2', 'username' => 'kim', 'email' => 'other@example.com', 'password' => 'Passw0rdOK', 'confirm_password' => 'Passw0rdOK']); t('duplicate username blocked', $c === 409, $j);
t('notification defaults created at registration', count(q('SELECT 1 FROM notification_settings WHERE user_id = ?', [$A['id']])) === 1);
limits();
[$tb, $B] = reg('lee'); [$tc, $C] = reg('sam');
[$c, $j] = api('POST', '/auth/login', ['login' => 'kim', 'password' => 'wrongpass1']); t('invalid password -> generic 401', $c === 401 && $j['error'] === 'Incorrect login or password', $j);
[$c, $j] = api('POST', '/auth/login', ['login' => 'nobody', 'password' => 'wrongpass1']); t('unknown user -> same message', $c === 401 && $j['error'] === 'Incorrect login or password', $j);
[$c, $j] = api('POST', '/auth/login', ['login' => 'kim', 'password' => 'Passw0rdOK']); $ta2 = $j['token'] ?? ''; t('login ok', $c === 200 && $ta2 !== '');
t('session restore (token still works)', api('GET', '/users/profile', [], $ta2)[0] === 200);
t('tokens stored hashed', count(q('SELECT 1 FROM sessions WHERE token_hash = ?', [$ta2])) === 0 && count(q('SELECT 1 FROM sessions WHERE token_hash = ?', [hash('sha256', $ta2)])) === 1);
api('POST', '/auth/logout', [], $ta2); t('logout revokes session', api('GET', '/users/profile', [], $ta2)[0] === 401);
t('no token -> 401', api('GET', '/chats')[0] === 401);
for ($i = 0; $i < 11; $i++) $last = api('POST', '/auth/login', ['login' => 'rapid', 'password' => 'x'])[0]; t('rapid login attempts rate-limited', $last === 429, $last); limits();

echo "PASSWORD RESET\n";
[$c, $j] = api('POST', '/auth/forgot', ['email' => 'ghost@example.com']); $m1 = $j['message'] ?? '';
[$c, $j] = api('POST', '/auth/forgot', ['email' => 'sam@example.com']); t('forgot gives identical response for known/unknown email', $c === 200 && $j['message'] === $m1);
preg_match('/code is: ([A-Z0-9]{5}-[A-Z0-9]{5})/', file_get_contents(__DIR__ . '/../backend/storage/logs/mail.log'), $mm); $code = $mm[1] ?? ''; t('reset code emailed (only for the real account)', $code !== '' && substr_count(file_get_contents(__DIR__ . '/../backend/storage/logs/mail.log'), 'To:') === 1);
t('reset rejects weak password', api('POST', '/auth/reset', ['email' => 'sam@example.com', 'code' => $code, 'password' => 'weak', 'confirm_password' => 'weak'])[0] === 422);
t('reset rejects wrong code', api('POST', '/auth/reset', ['email' => 'sam@example.com', 'code' => 'AAAAA-BBBBB', 'password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'])[0] === 400);
t('reset rejects code for a different email', api('POST', '/auth/reset', ['email' => 'lee@example.com', 'code' => $code, 'password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'])[0] === 400);
t('reset succeeds', api('POST', '/auth/reset', ['email' => 'sam@example.com', 'code' => $code, 'password' => 'NewPassw0rd', 'confirm_password' => 'NewPassw0rd'])[0] === 200);
t('reset code is single-use', api('POST', '/auth/reset', ['email' => 'sam@example.com', 'code' => $code, 'password' => 'Another1Pass', 'confirm_password' => 'Another1Pass'])[0] === 400);
t('old sessions revoked after reset', api('GET', '/users/profile', [], $tc)[0] === 401);
[$c, $j] = api('POST', '/auth/login', ['login' => 'sam', 'password' => 'NewPassw0rd']); $tc = $j['token'] ?? ''; t('login with new password', $c === 200);
$q = q('SELECT id FROM password_resets'); q('UPDATE password_resets SET used_at = NULL, expires_at = NOW() - INTERVAL 1 MINUTE'); t('expired reset code rejected', api('POST', '/auth/reset', ['email' => 'sam@example.com', 'code' => $code, 'password' => 'Another1Pass', 'confirm_password' => 'Another1Pass'])[0] === 400);

echo "SECURITY SETTINGS\n";
[$c, $j] = api('POST', '/auth/login', ['login' => 'lee', 'password' => 'Passw0rdOK']); $tb2 = $j['token'];
t('change password needs current password', api('POST', '/auth/change-password', ['current_password' => 'nope', 'new_password' => 'Newer1Pass', 'confirm_password' => 'Newer1Pass'], $tb)[0] === 403);
t('change password + revoke others', api('POST', '/auth/change-password', ['current_password' => 'Passw0rdOK', 'new_password' => 'Newer1Pass', 'confirm_password' => 'Newer1Pass', 'revoke_others' => true], $tb)[0] === 200 && api('GET', '/users/profile', [], $tb2)[0] === 401 && api('GET', '/users/profile', [], $tb)[0] === 200);
[$c, $j] = api('POST', '/auth/login', ['login' => 'lee', 'password' => 'Newer1Pass']); $tb3 = $j['token'];
[$c, $j] = api('GET', '/auth/sessions', [], $tb); t('active sessions listed with current flag', count($j['sessions']) === 2 && count(array_filter($j['sessions'], fn($s) => $s['current'])) === 1, $j);
api('POST', '/auth/logout-others', [], $tb); t('log out all other sessions', api('GET', '/users/profile', [], $tb3)[0] === 401);

echo "FRIENDS\n";
$kimCode = $A['code']; $leeCode = $B['code']; $samCode = $C['code'];
t('cannot friend yourself', api('POST', '/friends/request', ['code' => $kimCode], $ta)[0] === 404);
t('unknown code -> 404', api('POST', '/friends/request', ['code' => 'ZZZ-00000'], $ta)[0] === 404);
t('send request', api('POST', '/friends/request', ['code' => $leeCode], $ta)[0] === 200);
t('duplicate request refused', api('POST', '/friends/request', ['code' => $leeCode], $ta)[0] === 409);
t('reverse request tells you to accept', api('POST', '/friends/request', ['code' => $kimCode], $tb)[0] === 409);
t('recipient got friend-request notification', count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'friend_request'", [$B['id']])) === 1);
[$c, $j] = api('GET', '/friends', [], $tb); $rid = $j['incoming'][0]['request_id'] ?? 0; t('incoming request visible', $rid > 0, $j);
t('only addressee can accept', api('POST', '/friends/accept', ['request_id' => $rid], $tc)[0] === 404);
t('accept request', api('POST', '/friends/accept', ['request_id' => $rid], $tb)[0] === 200);
t('requester notified of acceptance', count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'friend_accepted'", [$A['id']])) === 1);
api('POST', '/friends/request', ['code' => $leeCode], $tc); [$c, $j] = api('GET', '/friends', [], $tb); $rid2 = $j['incoming'][0]['request_id'] ?? 0;
t('reject request', api('POST', '/friends/reject', ['request_id' => $rid2], $tb)[0] === 200 && count(api('GET', '/friends', [], $tb)[1]['incoming']) === 0);
api('POST', '/friends/request', ['code' => $leeCode], $tc); t('cancel own request', api('POST', '/friends/cancel', ['user_id' => $B['id']], $tc)[0] === 200);
api('POST', '/users/privacy', ['who_can_request' => 'nobody'], $tb); limits(); t('"nobody can request" is enforced', api('POST', '/friends/request', ['code' => $leeCode], $tc)[0] === 403); api('POST', '/users/privacy', ['who_can_request' => 'everyone'], $tb);

echo "MESSAGING\n";
[$c, $j] = api('POST', '/chats', ['code' => $leeCode], $ta); $conv = $j['conversation_id'] ?? 0; t('open conversation with friend', $conv > 0);
t('cannot open chat with non-friend', api('POST', '/chats', ['code' => $samCode], $ta)[0] === 403);
$cid = uuid(); [$c, $j] = api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => $cid, 'body' => 'hello'], $ta); $mid = $j['message']['id'] ?? 0; t('send message', $c === 200 && $mid > 0 && $j['message']['status'] === 'sent', $j);
[$c, $j] = api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => $cid, 'body' => 'hello'], $ta); t('duplicate client_id -> same message, no duplicate row', $j['message']['id'] === $mid && count(q('SELECT 1 FROM messages WHERE client_id = ?', [$cid])) === 1, $j);
t('empty message rejected', api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => '   '], $ta)[0] === 422);
t('4001-char message rejected', api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => str_repeat('a', 4001)], $ta)[0] === 422);
t('bad client id rejected', api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => 'abc', 'body' => 'x'], $ta)[0] === 422);
t('outsider cannot read conversation', api('GET', "/chats/$conv/messages", [], $tc)[0] === 404);
t('outsider cannot send into conversation', api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'x'], $tc)[0] === 404);
t('message is "sent" until recipient fetches', q('SELECT status FROM messages WHERE id = ?', [$mid])[0]['status'] === 'sent');
t('client cannot mark its own message read', api('POST', '/messages/read', ['conversation_id' => $conv, 'up_to_id' => $mid], $ta)[0] === 200 && q('SELECT status FROM messages WHERE id = ?', [$mid])[0]['status'] === 'sent');
[$c, $j] = api('GET', "/chats/$conv/messages", [], $tb); t('recipient receives message', count($j['messages']) === 1 && $j['messages'][0]['body'] === 'hello');
[$c, $j] = api('GET', "/chats/$conv/messages", [], $ta); $st = array_column($j['my_statuses'], 'status', 'id'); t('sender sees "delivered" only after recipient fetched', ($st[$mid] ?? '') === 'delivered', $j);
api('POST', '/messages/read', ['conversation_id' => $conv, 'up_to_id' => $mid], $tb); [$c, $j] = api('GET', "/chats/$conv/messages", [], $ta); t('read receipt reaches sender', array_column($j['my_statuses'], 'status', 'id')[$mid] === 'read');
t('message notification created for recipient', count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'message' AND title = 'Lee'", [$A['id']])) === 0 && count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'message' AND title = 'Kim' AND body = 'hello'", [$B['id']])) === 1);
api('POST', '/notifications/settings', ['show_previews' => false], $tb); api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'secret text'], $ta);
t('previews off -> notification body is generic', q("SELECT body FROM notifications WHERE user_id = ? AND type = 'message' ORDER BY id DESC LIMIT 1", [$B['id']])[0]['body'] === 'New message');
api('POST', '/notifications/settings', ['messages' => false], $tb); $n0 = count(q('SELECT 1 FROM notifications WHERE user_id = ?', [$B['id']])); api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'muted by setting'], $ta);
t('category switch off -> no notification', count(q('SELECT 1 FROM notifications WHERE user_id = ?', [$B['id']])) === $n0); api('POST', '/notifications/settings', ['messages' => true], $tb);
api('POST', '/chats/mute', ['conversation_id' => $conv, 'muted' => true], $tb); $n0 = count(q('SELECT 1 FROM notifications WHERE user_id = ?', [$B['id']])); api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'muted chat'], $ta);
t('muted conversation -> no notification', count(q('SELECT 1 FROM notifications WHERE user_id = ?', [$B['id']])) === $n0);
t('only sender can delete for everyone', api('POST', '/messages/delete', ['message_id' => $mid], $tb)[0] === 403);
api('POST', '/messages/delete', ['message_id' => $mid], $ta); [$c, $j] = api('GET', "/chats/$conv/messages", [], $tb); t('deleted message body is hidden from recipient', $j['messages'][0]['deleted'] === true && $j['messages'][0]['body'] === '');
[$c, $j] = api('GET', "/chats/$conv/search", ['q' => 'muted'], $ta); t('server-side message search', count($j['messages']) === 2, $j);
api('POST', '/chats/clear', ['conversation_id' => $conv, 'hide' => true], $tb); [$c, $j] = api('GET', '/chats', [], $tb); t('delete conversation (for me) hides it', count($j['chats']) === 0);
t('...other person still has everything', count(api('GET', "/chats/$conv/messages", [], $ta)[1]['messages']) >= 4);
api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'back again'], $ta); [$c, $j] = api('GET', '/chats', [], $tb); t('new message resurfaces hidden conversation, old history stays cleared', count($j['chats']) === 1 && count(api('GET', "/chats/$conv/messages", [], $tb)[1]['messages']) === 1);

echo "COLORS + PRIVACY\n";
t('set personal color', api('POST', '/colors/profile', ['color' => 'cyan'], $ta)[0] === 200 && api('POST', '/colors/profile', ['color' => 'neon'], $ta)[0] === 422);
api('POST', '/colors/chat', ['conversation_id' => $conv, 'color' => 'pink', 'reveal' => false], $ta); [$c, $j] = api('GET', '/chats', [], $tb); t('chat color is private by default', $j['chats'][0]['their_chat_color'] === null, $j);
api('POST', '/colors/chat', ['conversation_id' => $conv, 'color' => 'pink', 'reveal' => true], $ta); [$c, $j] = api('GET', '/chats', [], $tb); t('chat color visible once revealed', $j['chats'][0]['their_chat_color'] === 'pink');
api('POST', '/users/privacy', ['color_visibility' => 'nobody'], $ta); [$c, $j] = api('GET', '/friends', [], $tb); t('personal color hidden when visibility = nobody', $j['friends'][0]['personal_color'] === null, $j);
api('POST', '/users/privacy', ['color_visibility' => 'everyone'], $ta);

echo "BLOCKING\n";
[$c, $j] = api('POST', '/spaces/private', ['title' => 'P', 'item_count' => 2], $ta); $psid = $j['space_id'];
[$c, $j] = api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 10], $ta); $key = $j['key'] ?? ''; t('private key created (8 chars from 32-char alphabet)', strlen(str_replace('-', '', $key)) === 8);
[$c, $j] = api('POST', '/spaces/access', ['key' => $key], $tb); $grant = $j['grant_id'] ?? 0; t('friend redeems key', $c === 200 && $grant > 0, $j);
t('owner notified of space access', count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'space_access'", [$A['id']])) === 1);
t('block user', api('POST', '/users/block', ['user_id' => $B['id']], $ta)[0] === 200);
t('blocked user cannot send', api('POST', '/messages/send', ['conversation_id' => $conv, 'client_id' => uuid(), 'body' => 'hi'], $tb)[0] === 404);
t('blocked user cannot read the chat', api('GET', "/chats/$conv/messages", [], $tb)[0] === 404);
t('blocker no longer sees the conversation', count(api('GET', '/chats', [], $ta)[1]['chats']) === 0);
t('blocked user cannot open a chat', api('POST', '/chats', ['code' => $kimCode], $tb)[0] === 403);
t('blocked user cannot find blocker', api('GET', '/users/search', ['code' => $kimCode], $tb)[0] === 404);
t('blocked user cannot send a friend request', api('POST', '/friends/request', ['code' => $kimCode], $tb)[0] === 404);
t('private access revoked by block', api('POST', '/spaces/private/request', ['grant_id' => $grant, 'item_index' => 0], $tb)[0] === 410);
[$c, $j] = api('GET', '/users/blocked', [], $ta); t('blocked list shows user', count($j['blocked']) === 1 && $j['blocked'][0]['code'] === $leeCode);
t('blocked user cannot redeem keys', api('POST', '/spaces/access', ['key' => $key], $tb)[0] === 404);
api('POST', '/users/block', ['user_id' => $B['id'], 'unblock' => true], $ta); t('unblock clears the list', count(api('GET', '/users/blocked', [], $ta)[1]['blocked']) === 0);
t('after unblock, friendship must be re-established (documented)', api('POST', '/chats', ['code' => $leeCode], $ta)[0] === 403);
api('POST', '/friends/request', ['code' => $leeCode], $ta); [$c, $j] = api('GET', '/friends', [], $tb); api('POST', '/friends/accept', ['request_id' => $j['incoming'][0]['request_id']], $tb);
t('...and then history is back', count(api('GET', '/chats', [], $ta)[1]['chats']) === 1);

echo "SPACES\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents('/tmp/t.png', $png); file_put_contents('/tmp/t.php.png', '<?php echo 1;');
[$c, $j] = api('POST', '/spaces/public', ['title' => 'Pics'], $ta, ['images[0]' => new CURLFile('/tmp/t.png', 'image/png', 'a.png'), 'images[1]' => new CURLFile('/tmp/t.php.png', 'image/png', 'evil.png')]); $pub = $j['space_id'] ?? 0;
t('public space created; fake image rejected by content check', $c === 200 && (int)q('SELECT COUNT(*) n FROM space_media WHERE space_id = ?', [$pub])[0]['n'] === 1, $j);
[$c, $j] = api('GET', '/spaces/public', [], $tb); t('discover lists it', count($j['spaces']) === 1); $media = $j['spaces'][0]['media'][0] ?? 0;
t('media requires auth', api('GET', "/spaces/media/$media")[0] === 401);
t('impression recorded + owner notified', api('POST', '/spaces/impressions', ['space_id' => $pub, 'kind' => 'love'], $tb)[0] === 200 && count(q("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'impression'", [$A['id']])) === 1);
t('invalid impression rejected', api('POST', '/spaces/impressions', ['space_id' => $pub, 'kind' => 'meh'], $tb)[0] === 422);
api('POST', '/users/privacy', ['space_visibility' => 'friends'], $ta); t('space visibility "friends" hides it from strangers', count(api('GET', '/spaces/public', [], $tc)[1]['spaces']) === 0 && api('GET', "/spaces/media/$media", [], $tc)[0] === 404 && count(api('GET', '/spaces/public', [], $tb)[1]['spaces']) === 1);
api('POST', '/users/privacy', ['space_visibility' => 'everyone'], $ta);
[$c, $j] = api('POST', '/reports', ['target_type' => 'space', 'target_id' => $pub, 'category' => 'inappropriate', 'details' => 'test'], $tb); t('report a space', $c === 200 && count(q('SELECT 1 FROM reports WHERE space_id = ?', [$pub])) === 1);
api('POST', '/reports', ['target_type' => 'space', 'target_id' => $pub, 'category' => 'inappropriate'], $tb); t('duplicate report not stored twice', count(q('SELECT 1 FROM reports WHERE space_id = ?', [$pub])) === 1);
t('report a user', api('POST', '/reports', ['target_type' => 'user', 'target_id' => $C['id'], 'category' => 'spam'], $tb)[0] === 200);
$m2 = q('SELECT id FROM messages WHERE sender_id = ? LIMIT 1', [$A['id']])[0]['id']; t('report a message', api('POST', '/reports', ['target_type' => 'message', 'target_id' => $m2, 'category' => 'harassment'], $tb)[0] === 200);
t('cannot report a message from a conversation you are not in', api('POST', '/reports', ['target_type' => 'message', 'target_id' => $m2, 'category' => 'spam'], $tc)[0] === 404);

// Private Space lifecycle
[$c, $j] = api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 7], $ta); t('only 5/10/30 minute keys allowed', $c === 422);
t('non-owner cannot create keys', api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 5], $tb)[0] === 404);
[$c, $j] = api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 5], $ta); $key = $j['key']; $sl = $j['seconds_left']; t('server returns remaining seconds (client clock not trusted)', $sl > 290 && $sl <= 300, $j);
t('wrong key rejected', api('POST', '/spaces/access', ['key' => 'AAAA-AAAA'], $tb)[0] === 404);
[$c, $j] = api('POST', '/spaces/access', ['key' => $key], $tb); $grant = $j['grant_id'];
t('picture index outside the Space rejected', api('POST', '/spaces/private/request', ['grant_id' => $grant, 'item_index' => 5], $tb)[0] === 422);
t('another user cannot use my grant', api('POST', '/spaces/private/request', ['grant_id' => $grant, 'item_index' => 0], $tc)[0] === 410);
[$c, $j] = api('POST', '/spaces/private/request', ['grant_id' => $grant, 'item_index' => 0], $tb); $rq = $j['request_id'];
t('picture not available until the owner delivers it', api('GET', "/spaces/private/fetch/$rq", [], $tb)[0] === 202);
[$c, $j] = api('GET', '/spaces/private/pending', [], $ta); t('owner sees pending request', count($j['requests']) === 1);
t('only the owner can fulfil', api('POST', '/spaces/private/fulfil', ['request_id' => $rq], $tb, ['image' => new CURLFile('/tmp/t.png')])[0] === 404);
t('owner fulfils', api('POST', '/spaces/private/fulfil', ['request_id' => $rq], $ta, ['image' => new CURLFile('/tmp/t.png')])[0] === 200);
$ch = curl_init("$base/spaces/private/fetch/$rq"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $tb"]]); $body1 = curl_exec($ch); $c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
t('viewer receives the picture bytes', $c1 === 200 && strlen($body1) > 50);
t('one-time: second fetch returns nothing', api('GET', "/spaces/private/fetch/$rq", [], $tb)[0] === 202 && count(glob(__DIR__ . '/../backend/storage/relay/*.bin')) === 0);
q('UPDATE space_access_keys SET expires_at = NOW() - INTERVAL 1 SECOND'); q('UPDATE space_grants SET expires_at = NOW() - INTERVAL 1 SECOND');
t('expired grant rejected server-side', api('POST', '/spaces/private/request', ['grant_id' => $grant, 'item_index' => 0], $tb)[0] === 410);
t('expired key cannot be redeemed', api('POST', '/spaces/access', ['key' => $key], $tc)[0] === 404);
[$c, $j] = api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 30], $ta); [$c, $j2] = api('POST', '/spaces/access', ['key' => $j['key']], $tb); $g2 = $j2['grant_id'];
api('POST', '/spaces/revoke', ['space_id' => $psid], $ta); t('owner revoke ends access immediately', api('POST', '/spaces/private/request', ['grant_id' => $g2, 'item_index' => 0], $tb)[0] === 410);
[$c, $j] = api('POST', '/spaces/private/key', ['space_id' => $psid, 'minutes' => 30], $ta); [$c, $j2] = api('POST', '/spaces/access', ['key' => $j['key']], $tb); $g3 = $j2['grant_id'];
api('POST', '/spaces/delete', ['space_id' => $psid], $ta); t('deleted Space cannot be accessed', api('POST', '/spaces/private/request', ['grant_id' => $g3, 'item_index' => 0], $tb)[0] === 410);

echo "SUSPENSION\n";
q("UPDATE users SET is_banned = 1, suspension_reason = 'Spam' WHERE id = ?", [$B['id']]);
[$c, $j] = api('GET', '/chats', [], $tb); t('suspended user is blocked at the API with a clear message', $c === 403 && ($j['code'] ?? '') === 'suspended' && str_contains($j['error'], 'suspended'), $j);
[$c, $j] = api('POST', '/auth/login', ['login' => 'lee', 'password' => 'Newer1Pass']); t('suspended user cannot log in', $c === 403 && ($j['code'] ?? '') === 'suspended');
t('suspended user cannot create a Space', api('POST', '/spaces/public', ['title' => 'x'], $tb)[0] === 403);
t('suspended user disappears from others\' chat list', count(api('GET', '/chats', [], $ta)[1]['chats']) === 0);
q('UPDATE users SET is_banned = 0, suspension_reason = NULL WHERE id = ?', [$B['id']]); t('restored user can log in again', api('POST', '/auth/login', ['login' => 'lee', 'password' => 'Newer1Pass'])[0] === 200);

echo "ERRORS\n";
[$c, $j] = api('GET', '/nope', [], $ta); t('unknown route -> clean JSON 404', $c === 404 && isset($j['error']) && !str_contains(json_encode($j), 'Stack'));
$ch = curl_init("$base/auth/login"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{bad json', CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true]); $raw = curl_exec($ch); curl_close($ch); t('malformed JSON -> clean error', json_decode($raw, true)['ok'] === false);

echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
