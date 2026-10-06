<?php
namespace Hue\Core;
final class Mailer {
  /** mail_driver=log writes to storage/logs/mail.log (development). Anything else uses PHP mail(); configure sendmail/SMTP on the server. */
  public static function send(string $to, string $subject, string $body): void {
    if (Config::get('mail_driver', 'mail') === 'log') {
      $dir = Config::get('storage') . '/logs'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
      file_put_contents("$dir/mail.log", "To: $to\nSubject: $subject\n$body\n---\n", FILE_APPEND | LOCK_EX); return;
    }
    $from = Config::get('mail_from', 'no-reply@localhost');
    @mail($to, $subject, $body, "From: Hue <$from>\r\nContent-Type: text/plain; charset=utf-8");
  }
}
