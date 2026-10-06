<?php
// Admin-only stream of a public Space picture for moderation review.
require __DIR__ . '/bootstrap.php';
use Hue\Core\{Database as DB, Config};
if (!admin()) { http_response_code(403); exit; }
$m = DB::one("SELECT path, mime FROM space_media WHERE id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$m) { http_response_code(404); exit; }
header('Content-Type: ' . $m['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
readfile(Config::get('storage') . '/public_media/' . basename($m['path']));
