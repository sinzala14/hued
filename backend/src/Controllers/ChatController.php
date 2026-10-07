<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator};

final class ChatController {
  /** Returns the conversation (plus my membership row) or 404. A blocked pair behaves as if the conversation does not exist. */
  public static function membership(int $convId, int $userId, bool $allowBlocked = false): array {
    $c = Database::one('SELECT c.*, cm.muted, cm.hidden, cm.cleared_before_id FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
                        WHERE c.id = ?', [$userId, $convId]);
    if (!$c) throw new ApiException('Conversation not found', 404);
    $c['other_id'] = (int)($c['user_a'] == $userId ? $c['user_b'] : $c['user_a']);
    if (!$allowBlocked && UserController::blocked($userId, $c['other_id'])) throw new ApiException('Conversation not found', 404);
    return $c;
  }

  public function list(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    $rows = Database::all("
      SELECT c.id, o.id AS other_id, o.code, o.username, o.display_name, o.personal_color, o.personal_colors, o.color_visibility, o.profile_visibility, o.avatar_path, o.bio, cm.muted,
             (SELECT CASE WHEN m.body <> '' THEN m.body WHEN EXISTS (SELECT 1 FROM message_media mm WHERE mm.message_id = m.id) THEN 'Photo' ELSE '' END
                FROM messages m WHERE m.conversation_id = c.id AND m.deleted_at IS NULL AND m.id > cm.cleared_before_id ORDER BY m.id DESC LIMIT 1) AS last_body,
             (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id AND m.deleted_at IS NULL AND m.id > cm.cleared_before_id ORDER BY m.id DESC LIMIT 1) AS last_at,
             (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id <> ? AND m.deleted_at IS NULL AND m.id > GREATEST(cm.last_read_message_id, cm.cleared_before_id)) AS unread,
             mine.color AS my_chat_color, mine.revealed AS my_chat_color_revealed, IF(theirs.revealed = 1, theirs.color, NULL) AS their_chat_color
      FROM conversations c
      JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
      JOIN users o ON o.id = IF(c.user_a = ?, c.user_b, c.user_a)
      LEFT JOIN chat_colors mine ON mine.conversation_id = c.id AND mine.user_id = ?
      LEFT JOIN chat_colors theirs ON theirs.conversation_id = c.id AND theirs.user_id = o.id
      WHERE o.is_banned = 0 AND cm.hidden = 0
        AND NOT EXISTS (SELECT 1 FROM blocked_users b WHERE (b.user_id = ? AND b.blocked_id = o.id) OR (b.user_id = o.id AND b.blocked_id = ?))
      ORDER BY COALESCE(last_at, c.created_at) DESC", [$me, $me, $me, $me, $me, $me]);
    foreach ($rows as &$c) {
      $p = Auth::publicProfile($c, $me);
      $c['personal_color'] = $p['personal_color']; $c['personal_colors'] = $p['personal_colors']; $c['has_avatar'] = $p['has_avatar']; $c['bio'] = $p['bio'];
      $c['muted'] = (int)$c['muted']; unset($c['color_visibility'], $c['profile_visibility'], $c['avatar_path']);
    }
    Response::ok(['chats' => $rows]);
  }

  public function open(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    $o = Database::one('SELECT id FROM users WHERE code = ? AND is_banned = 0', [strtoupper((string)$r->input('code', ''))]);
    if (!$o) throw new ApiException('User not found', 404);
    $other = (int)$o['id'];
    if (FriendController::statusBetween($me, $other) !== 'friends' || UserController::blocked($me, $other)) throw new ApiException('You can only message friends', 403);
    [$a, $b] = $me < $other ? [$me, $other] : [$other, $me];
    Database::run('INSERT IGNORE INTO conversations (user_a, user_b) VALUES (?,?)', [$a, $b]);
    $c = Database::one('SELECT id FROM conversations WHERE user_a = ? AND user_b = ?', [$a, $b]);
    Database::run('INSERT IGNORE INTO conversation_members (conversation_id, user_id) VALUES (?,?),(?,?)', [$c['id'], $a, $c['id'], $b]);
    Database::run('UPDATE conversation_members SET hidden = 0 WHERE conversation_id = ? AND user_id = ?', [$c['id'], $me]);
    Response::ok(['conversation_id' => (int)$c['id']]);
  }

  /** GET /chats/{id}/messages?before=ID | after=ID & limit */
  public function messages(Request $r, array $p): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    $c = self::membership((int)$p['id'], $me);
    $limit = min(max((int)$r->input('limit', 30), 1), 100);
    $before = (int)$r->input('before', 0); $after = (int)$r->input('after', 0);
    $sql = 'SELECT m.id, m.client_id, m.sender_id, m.body, m.reply_to_id, m.status, DATE_FORMAT(m.created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at,
                   (m.edited_at IS NOT NULL) AS edited,
                   (m.deleted_at IS NOT NULL) AS deleted, (SELECT mm.message_id FROM message_media mm WHERE mm.message_id = m.id) AS media_id
            FROM messages m WHERE m.conversation_id = ? AND m.id > ?'; $args = [$c['id'], $c['cleared_before_id']];
    if ($before) { $sql .= ' AND m.id < ?'; $args[] = $before; }
    if ($after)  { $sql .= ' AND m.id > ?'; $args[] = $after; }
    $sql .= $after ? ' ORDER BY m.id ASC LIMIT ' . $limit : ' ORDER BY m.id DESC LIMIT ' . $limit;
    $rows = Database::all($sql, $args);
    if (!$after) $rows = array_reverse($rows);
    foreach ($rows as &$m) {
      if ($m['deleted']) $m['body'] = '';
      $m['id'] = (int)$m['id']; $m['sender_id'] = (int)$m['sender_id'];
      $m['deleted'] = (bool)$m['deleted']; $m['edited'] = (bool)$m['edited'];
      $m['media_id'] = $m['media_id'] === null ? null : (int)$m['media_id'];
    }
    // "delivered" is set only here: the server has handed the message to the recipient's device.
    Database::run("UPDATE messages SET status = 'delivered' WHERE conversation_id = ? AND sender_id <> ? AND status = 'sent' AND id > ?", [$c['id'], $me, $c['cleared_before_id']]);
    $mine = Database::all('SELECT id, status FROM messages WHERE conversation_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 100', [$c['id'], $me]);
    Response::ok(['messages' => $rows, 'my_statuses' => $mine]);
  }

  public function search(Request $r, array $p): void {
    $u = Auth::user($r); $c = self::membership((int)$p['id'], (int)$u['id']);
    $q = Validator::str($r->input('q', ''), 'Search', 2, 60);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $rows = Database::all('SELECT m.id, m.client_id, m.sender_id, m.body, m.reply_to_id, m.status, DATE_FORMAT(m.created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at, 0 AS deleted, (m.edited_at IS NOT NULL) AS edited,
        (SELECT mm.message_id FROM message_media mm WHERE mm.message_id = m.id) AS media_id
        FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND m.deleted_at IS NULL AND m.body LIKE ? ORDER BY m.id DESC LIMIT 50', [$c['id'], $c['cleared_before_id'], $like]);
    foreach ($rows as &$m) { $m['id'] = (int)$m['id']; $m['sender_id'] = (int)$m['sender_id']; $m['deleted'] = false; $m['edited'] = (bool)$m['edited']; $m['media_id'] = $m['media_id'] === null ? null : (int)$m['media_id']; }
    Response::ok(['messages' => $rows]);
  }

  public function mute(Request $r): void {
    $u = Auth::user($r); $c = self::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), (int)$u['id']);
    Database::run('UPDATE conversation_members SET muted = ? WHERE conversation_id = ? AND user_id = ?', [$r->input('muted') ? 1 : 0, $c['id'], $u['id']]);
    Response::ok();
  }

  /** Clear chat / delete conversation: affects ONLY the caller's view. The other person keeps everything. */
  public function clear(Request $r): void {
    $u = Auth::user($r); $c = self::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), (int)$u['id'], true);
    $max = (int)(Database::one('SELECT COALESCE(MAX(id),0) m FROM messages WHERE conversation_id = ?', [$c['id']])['m']);
    Database::run('UPDATE conversation_members SET cleared_before_id = ?, hidden = ? WHERE conversation_id = ? AND user_id = ?', [$max, $r->input('hide') ? 1 : 0, $c['id'], $u['id']]);
    Response::ok(['cleared_before_id' => $max]);
  }
}
