<?php
namespace Hue\Controllers;
use Hue\Core\{Request, Response, ApiException, Database, Auth, Validator, RateLimiter, Notifier, Config};

final class MessageController {
  public function send(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    RateLimiter::hit('send:' . $me, 120, 60);
    $conv = ChatController::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), $me);
    $cid  = Validator::uuid($r->input('client_id'), 'client_id');
    $body = Validator::str($r->input('body'), 'Message', 1, 4000);
    $reply = $r->input('reply_to_id') ? Validator::int($r->input('reply_to_id'), 'reply_to_id') : null;

    // Idempotent: a retry with the same client_id returns the original row, never a duplicate.
    $ex = Database::one('SELECT id, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at, status FROM messages WHERE sender_id = ? AND client_id = ?', [$me, $cid]);
    if ($ex) Response::ok(['message' => ['id' => (int)$ex['id'], 'created_at' => $ex['created_at'], 'status' => $ex['status'], 'duplicate' => true]]);

    $other = Database::one('SELECT id, who_can_message, is_banned FROM users WHERE id = ?', [$conv['other_id']]);
    if (!$other || $other['is_banned'] || $other['who_can_message'] === 'nobody' || FriendController::statusBetween($me, (int)$other['id']) !== 'friends')
      throw new ApiException('You cannot message this person', 403);
    if ($reply && !Database::one('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?', [$reply, $conv['id']])) $reply = null;

    $id = Database::insert('INSERT INTO messages (conversation_id, sender_id, client_id, body, reply_to_id) VALUES (?,?,?,?,?)', [$conv['id'], $me, $cid, $body, $reply]);
    Database::run('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$conv['id']]);
    Database::run('UPDATE conversation_members SET hidden = 0 WHERE conversation_id = ?', [$conv['id']]);
    $theirs = Database::one('SELECT muted FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $other['id']]);
    if (!$theirs || !$theirs['muted']) Notifier::send((int)$other['id'], 'message', $u['display_name'], Notifier::preview($body),
      ['conversation_id' => (int)$conv['id'], 'message_id' => (int)$id, 'sender_id' => $me]);
    $row = Database::one('SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at FROM messages WHERE id = ?', [$id]);
    Response::ok(['message' => ['id' => $id, 'created_at' => $row['created_at'], 'status' => 'sent']]);
  }

  public function sendImage(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    RateLimiter::hit('send:' . $me, 120, 60);
    $conv = ChatController::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), $me);
    $cid = Validator::uuid($r->input('client_id'), 'client_id');
    $other = Database::one('SELECT id, who_can_message, is_banned FROM users WHERE id = ?', [$conv['other_id']]);
    if (!$other || $other['is_banned'] || $other['who_can_message'] === 'nobody' || FriendController::statusBetween($me, (int)$other['id']) !== 'friends')
      throw new ApiException('You cannot message this person', 403);
    if (Database::one('SELECT id FROM messages WHERE sender_id = ? AND client_id = ?', [$me, $cid]))
      throw new ApiException('This image was already sent', 409);

    $f = $_FILES['image'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new ApiException('Choose an image to send', 422);
    $max = (int)Config::get('max_upload_bytes', 5 * 1024 * 1024);
    if ($f['size'] < 1 || $f['size'] > $max) throw new ApiException('Image is too large', 422);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $dim = @getimagesize($f['tmp_name']);
    if (!isset($allowed[$mime]) || !$dim || $dim[0] > 4096 || $dim[1] > 4096) throw new ApiException('Use a JPEG, PNG or WebP image', 422);

    $reply = $r->input('reply_to_id') ? Validator::int($r->input('reply_to_id'), 'reply_to_id') : null;
    if ($reply && !Database::one('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ? AND deleted_at IS NULL', [$reply, $conv['id']])) $reply = null;
    $body = trim((string)$r->input('caption', ''));
    if (mb_strlen($body) > 4000) throw new ApiException('Caption must be 4000 characters or fewer', 422);

    $dir = Config::get('storage') . '/message_media';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new ApiException('Could not save image', 500);
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new ApiException('Could not save image', 500);
    $pdo = Database::pdo();
    try {
      $pdo->beginTransaction();
      $id = Database::insert('INSERT INTO messages (conversation_id, sender_id, client_id, body, reply_to_id) VALUES (?,?,?,?,?)', [$conv['id'], $me, $cid, $body, $reply]);
      Database::run('INSERT INTO message_media (message_id, path, mime, size) VALUES (?,?,?,?)', [$id, $name, $mime, $f['size']]);
      Database::run('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$conv['id']]);
      Database::run('UPDATE conversation_members SET hidden = 0 WHERE conversation_id = ?', [$conv['id']]);
      $created = Database::one('SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at FROM messages WHERE id = ?', [$id])['created_at'];
      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      @unlink("$dir/$name");
      throw $e;
    }
    $theirs = Database::one('SELECT muted FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $other['id']]);
    if (!$theirs || !$theirs['muted']) Notifier::send((int)$other['id'], 'message', $u['display_name'], Notifier::preview($body ?: 'Photo'),
      ['conversation_id' => (int)$conv['id'], 'message_id' => (int)$id, 'sender_id' => $me]);
    Response::ok(['message' => ['id' => $id, 'client_id' => $cid, 'sender_id' => $me, 'body' => $body, 'reply_to_id' => $reply,
      'created_at' => $created, 'status' => 'sent', 'deleted' => false, 'media_id' => $id]]);
  }

  public function sendVoice(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    RateLimiter::hit('send:' . $me, 60, 60);
    $conv = ChatController::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), $me);
    $cid  = Validator::uuid($r->input('client_id'), 'client_id');
    $other = Database::one('SELECT id, who_can_message, is_banned FROM users WHERE id = ?', [$conv['other_id']]);
    if (!$other || $other['is_banned'] || $other['who_can_message'] === 'nobody' || FriendController::statusBetween($me, (int)$other['id']) !== 'friends')
      throw new ApiException('You cannot message this person', 403);
    if (Database::one('SELECT id FROM messages WHERE sender_id = ? AND client_id = ?', [$me, $cid]))
      throw new ApiException('This voice note was already sent', 409);

    $f = $_FILES['voice'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new ApiException('Choose a voice note to send', 422);
    if ($f['size'] < 1 || $f['size'] > 10 * 1024 * 1024) throw new ApiException('Voice note must be between 1 byte and 10 MB', 422);
    $allowed = [
      'audio/ogg'  => 'ogg',
      'audio/mpeg' => 'mp3',
      'audio/mp4'  => 'm4a',
      'audio/webm' => 'webm',
      'audio/aac'  => 'aac',
      'audio/wav'  => 'wav',
    ];
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) throw new ApiException('Unsupported audio format', 422);

    $reply = $r->input('reply_to_id') ? Validator::int($r->input('reply_to_id'), 'reply_to_id') : null;
    if ($reply && !Database::one('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ? AND deleted_at IS NULL', [$reply, $conv['id']])) $reply = null;

    $dir = Config::get('storage') . '/message_media';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new ApiException('Could not save voice note', 500);
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new ApiException('Could not save voice note', 500);
    $pdo = Database::pdo();
    try {
      $pdo->beginTransaction();
      $id = Database::insert('INSERT INTO messages (conversation_id, sender_id, client_id, body, reply_to_id) VALUES (?,?,?,?,?)', [$conv['id'], $me, $cid, '', $reply]);
      Database::run('INSERT INTO message_media (message_id, path, mime, size, is_voice) VALUES (?,?,?,?,1)', [$id, $name, $mime, $f['size']]);
      Database::run('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$conv['id']]);
      Database::run('UPDATE conversation_members SET hidden = 0 WHERE conversation_id = ?', [$conv['id']]);
      $created = Database::one('SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s.%f") AS created_at FROM messages WHERE id = ?', [$id])['created_at'];
      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      @unlink("$dir/$name");
      throw $e;
    }
    $theirs = Database::one('SELECT muted FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $other['id']]);
    if (!$theirs || !$theirs['muted']) Notifier::send((int)$other['id'], 'message', $u['display_name'], 'Voice note',
      ['conversation_id' => (int)$conv['id'], 'message_id' => (int)$id, 'sender_id' => $me]);
    Response::ok(['message' => ['id' => $id, 'client_id' => $cid, 'sender_id' => $me, 'body' => '', 'reply_to_id' => $reply,
      'created_at' => $created, 'status' => 'sent', 'deleted' => false, 'media_id' => $id]]);
  }

  private function visible(int $mid, int $me): array {
    $m = Database::one('SELECT * FROM messages WHERE id = ?', [$mid]);
    if (!$m) throw new ApiException('Message not found', 404);
    ChatController::membership((int)$m['conversation_id'], $me);
    return $m;
  }

  /** "Delete for everyone": only the sender can do this; the message is replaced by a deleted marker on both sides. */
  public function delete(Request $r): void {
    $u = Auth::user($r);
    $m = $this->visible(Validator::int($r->input('message_id'), 'message_id'), (int)$u['id']);
    if ((int)$m['sender_id'] !== (int)$u['id']) throw new ApiException('You can only delete your own messages', 403);
    $media = Database::one('SELECT path FROM message_media WHERE message_id = ?', [$m['id']]);
    Database::run('UPDATE messages SET deleted_at = NOW() WHERE id = ?', [$m['id']]);
    if ($media) {
      @unlink(Config::get('storage') . '/message_media/' . basename($media['path']));
      Database::run('DELETE FROM message_media WHERE message_id = ?', [$m['id']]);
    }
    Response::ok();
  }

  public function edit(Request $r): void {
    $u = Auth::user($r);
    $m = $this->visible(Validator::int($r->input('message_id'), 'message_id'), (int)$u['id']);
    if ((int)$m['sender_id'] !== (int)$u['id']) throw new ApiException('You can only edit your own messages', 403);
    if ($m['deleted_at'] !== null) throw new ApiException('Deleted messages cannot be edited', 409);
    $body = Validator::str($r->input('body'), 'Message', 1, 4000);
    Database::run('UPDATE messages SET body = ?, edited_at = NOW() WHERE id = ?', [$body, $m['id']]);
    Response::ok(['message_id' => (int)$m['id'], 'body' => $body, 'edited' => true]);
  }

  public function media(Request $r, array $p): void {
    $u = Auth::user($r);
    $m = Database::one('SELECT mm.path, mm.mime, msg.conversation_id, msg.id, msg.deleted_at FROM message_media mm JOIN messages msg ON msg.id = mm.message_id WHERE mm.message_id = ?', [(int)$p['id']]);
    if (!$m || $m['deleted_at'] !== null) throw new ApiException('Not found', 404);
    $conv = ChatController::membership((int)$m['conversation_id'], (int)$u['id']);
    if ((int)$m['id'] <= (int)$conv['cleared_before_id']) throw new ApiException('Not found', 404);
    $path = Config::get('storage') . '/message_media/' . basename($m['path']);
    if (!is_file($path)) throw new ApiException('Not found', 404);
    header('Content-Type: ' . $m['mime']); header('Content-Disposition: inline'); header('Cache-Control: private, max-age=300');
    readfile($path); exit;
  }

  public function react(Request $r): void {
    $u = Auth::user($r);
    $m = $this->visible(Validator::int($r->input('message_id'), 'message_id'), (int)$u['id']);
    $kind = $r->input('reaction');
    if ($kind === null) Database::run('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?', [$m['id'], $u['id']]);
    else Database::run('INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)', [$m['id'], $u['id'], Validator::oneOf($kind, Validator::REACTIONS, 'reaction')]);
    Response::ok();
  }

  /** Read receipts: only the recipient's own call can mark the sender's messages read. */
  public function read(Request $r): void {
    $u = Auth::user($r); $me = (int)$u['id'];
    $conv = ChatController::membership(Validator::int($r->input('conversation_id'), 'conversation_id'), $me);
    $upTo = Validator::int($r->input('up_to_id'), 'up_to_id');
    Database::run("UPDATE messages SET status = 'read' WHERE conversation_id = ? AND sender_id <> ? AND id <= ? AND status <> 'read'", [$conv['id'], $me, $upTo]);
    Database::run('UPDATE conversation_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE conversation_id = ? AND user_id = ?', [$upTo, $conv['id'], $me]);
    Response::ok();
  }
}
