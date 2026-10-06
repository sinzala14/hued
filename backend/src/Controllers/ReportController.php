<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter};

final class ReportController {
  public function create(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    RateLimiter::hit('report:' . $me, 10, 3600);
    $type = Validator::oneOf($r->input('target_type'), ['user', 'message', 'space'], 'target_type');
    $tid  = Validator::int($r->input('target_id'), 'target_id');
    $cat  = Validator::oneOf($r->input('category', 'other'), ['spam', 'harassment', 'inappropriate', 'other'], 'category');
    $details = $r->input('details') ? mb_substr(trim((string)$r->input('details')), 0, 500) : null;
    $msgId = $spaceId = null; $reported = null;

    if ($type === 'message') {
      $m = Database::one('SELECT id, sender_id, conversation_id FROM messages WHERE id = ?', [$tid]);
      if (!$m) throw new ApiException('Message not found', 404);
      ChatController::membership((int)$m['conversation_id'], $me, true);
      if ((int)$m['sender_id'] === $me) throw new ApiException('You cannot report your own message', 422);
      $msgId = $tid; $reported = (int)$m['sender_id'];
    } elseif ($type === 'space') {
      $s = Database::one("SELECT id, owner_id FROM spaces WHERE id = ? AND type = 'public' AND deleted_at IS NULL", [$tid]);
      if (!$s || (int)$s['owner_id'] === $me) throw new ApiException('Space not found', 404);
      $spaceId = $tid; $reported = (int)$s['owner_id'];
    } else {
      if ($tid === $me || !Database::one('SELECT 1 FROM users WHERE id = ?', [$tid])) throw new ApiException('User not found', 404);
      $reported = $tid;
    }
    // Repeat reports of the same thing within 24 hours are accepted silently but not stored again.
    $dup = Database::one('SELECT 1 FROM reports WHERE reporter_id = ? AND created_at > NOW() - INTERVAL 24 HOUR AND ((? IS NOT NULL AND message_id = ?) OR (? IS NOT NULL AND space_id = ?) OR (message_id IS NULL AND space_id IS NULL AND reported_user_id = ?))',
      [$me, $msgId, $msgId, $spaceId, $spaceId, $type === 'user' ? $reported : 0]);
    if (!$dup) Database::insert('INSERT INTO reports (reporter_id, message_id, space_id, reported_user_id, category, reason, details) VALUES (?,?,?,?,?,?,?)',
      [$me, $msgId, $spaceId, $reported, $cat, $cat, $details]);
    Response::ok();
  }
}
