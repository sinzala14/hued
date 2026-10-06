<?php
namespace Hue\Core;
final class RateLimiter {
  /** Fixed-window limiter backed by MySQL. */
  public static function hit(string $bucket, int $max, int $windowSec): void {
    $now = time(); $start = $now - ($now % $windowSec);
    $key = substr($bucket, 0, 100) . ':' . $start;
    Database::run('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$key, $start]);
    $row = Database::one('SELECT hits FROM rate_limits WHERE bucket = ?', [$key]);
    if ($row && $row['hits'] > $max) throw new ApiException('Too many requests. Try again shortly.', 429);
    if (random_int(1, 200) === 1) Database::run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 7200]);
  }
}
