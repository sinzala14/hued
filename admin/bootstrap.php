<?php
declare(strict_types=1);
ini_set('display_errors', '0'); ini_set('log_errors', '1');
// Reuses the backend's config + PDO wrapper so the dashboard talks to the same MySQL database.
require __DIR__ . '/../backend/src/Core/Response.php';
require __DIR__ . '/../backend/src/Core/Config.php';
require __DIR__ . '/../backend/src/Core/Database.php';
require __DIR__ . '/../backend/src/Core/RateLimiter.php';
use Hue\Core\Database as DB;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com");

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Session expired. Reload the page.'); } }
function back(string $to): never { header('Location: ' . $to); exit; }
function admin(): ?array { return isset($_SESSION['admin_id']) ? DB::one('SELECT id, display_name FROM users WHERE id = ? AND is_admin = 1 AND is_banned = 0', [$_SESSION['admin_id']]) : null; }

const HUE_COLORS = ['green'=>'#2FA36B','red'=>'#D64545','purple'=>'#8B5CF6','black'=>'#2B2B33','pink'=>'#EC6FA8','yellow'=>'#E8B931','blue'=>'#3B82F6','orange'=>'#F28C28','white'=>'#E5E7EB','gray'=>'#8A8D96','cyan'=>'#22B8CF','brown'=>'#8B6B4A'];
