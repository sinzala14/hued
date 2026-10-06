# Hue: Chat. Colors. Spaces. Connections.

Flutter (Android-first) + PHP REST API + MySQL + encrypted SQLite (offline-first) + admin dashboard.

```
database/schema.sql                  base MySQL schema
database/migrations/002_phase2.sql   Phase 2 additions (run after schema.sql)
backend/                             PHP API (public/index.php, src/Core, src/Controllers, cron/cleanup.php)
admin/                               admin dashboard
app/                                 Flutter app
tests/api_test.php                   119 end-to-end API checks
docs/QA_CHECKLIST.md                 manual test script for a real phone
```

## 1. Database
```sql
CREATE USER 'hue'@'localhost' IDENTIFIED BY 'a-long-random-password';
```
```bash
mysql -u root -p < database/schema.sql
mysql -u root -p hue < database/migrations/002_phase2.sql
mysql -u root -p -e "GRANT ALL ON hue.* TO 'hue'@'localhost'; FLUSH PRIVILEGES;"
```
Requires MySQL 8+ or MariaDB 10.5+. Run the migration exactly once.

## 2. PHP backend
Needs PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`, and Argon2 support.

**Configuration** (pick one; never commit secrets)
- Production: environment variables `HUE_DB_DSN`, `HUE_DB_USER`, `HUE_DB_PASS`, `HUE_FORCE_HTTPS=1`, `HUE_MAIL_DRIVER=mail`, `HUE_MAIL_FROM`, and for push `HUE_FCM_PROJECT_ID`, `HUE_FCM_SERVICE_ACCOUNT`.
- Development: `cp backend/config/config.example.php backend/config/config.php` and edit (git-ignored).

**Development server**
```bash
cd backend/public && HUE_MAIL_DRIVER=log php -S 127.0.0.1:8080 index.php
```
Reset codes are written to `backend/storage/logs/mail.log` when `mail_driver` is `log`.

**Production server** (nginx example)
```nginx
server {
  listen 443 ssl http2;  server_name api.example.com;
  ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
  root /var/www/hue/backend/public;
  client_max_body_size 30m;
  location /api/ { try_files $uri /index.php?$query_string; }
  location = /index.php { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_param HTTP_AUTHORIZATION $http_authorization; }
  location / { return 404; }
}
server { listen 80; server_name api.example.com; return 301 https://$host$request_uri; }
```
Get a certificate with `certbot --nginx -d api.example.com`. Keep `backend/storage/` outside the web root or denied (an `.htaccess` is included for Apache). Make it writable by PHP. Set `display_errors=Off` in php.ini.

**Cron (every minute)**: `* * * * * php /var/www/hue/backend/cron/cleanup.php`. It deletes uncollected private-Space relay files, sends "Space Key expired" notifications, and removes stale sessions and rate-limit rows.

**Email**: password reset needs working outbound mail. Use `HUE_MAIL_DRIVER=mail` with a configured sendmail/Postfix, or replace `src/Core/Mailer.php` with your SMTP provider.

## 3. Admin dashboard
1. Register a normal account in the app, then: `php admin/make_admin.php yourusername 'a-strong-password'` (this also sets that account's password).
2. Serve `admin/` on a separate hostname or path, over HTTPS, ideally restricted by IP or VPN. It reads the same config as the backend.

## 4. Push notifications (Firebase)
1. Create a Firebase project, add an Android app with your package name, download `google-services.json` into `app/android/app/`.
2. In `app/android/settings.gradle` / `build.gradle` add the `com.google.gms.google-services` plugin per the FlutterFire docs. Also enable core library desugaring (required by flutter_local_notifications).
3. Firebase console > Project settings > Service accounts > generate a private key. Save it **outside the web root**, and set `HUE_FCM_SERVICE_ACCOUNT` to its path and `HUE_FCM_PROJECT_ID`.
4. AndroidManifest: `INTERNET`, `ACCESS_NETWORK_STATE`, `POST_NOTIFICATIONS`, `USE_BIOMETRIC`; set `minSdkVersion 23`.

Without Firebase configured the app still works and uses the 4-second polling fallback.

## 5. Flutter app
```bash
cd app
flutter create . --platform android --org com.yourname --project-name hue
# copy android_snippets/MainActivity.kt over the generated MainActivity.kt (keep your package line)
flutter pub get
flutter test
flutter run --dart-define=HUE_API=https://api.example.com/api
flutter build apk --release --dart-define=HUE_API=https://api.example.com/api
```
The API URL is supplied at build time; no production URL is hardcoded. For Play Store, create a signing key and use `flutter build appbundle`.

## 6. Tests
```bash
# throwaway database only: this script TRUNCATES tables
HUE_MAIL_DRIVER=log php -S 127.0.0.1:8080 -t backend/public backend/public/index.php &
HUE_TEST_DB="mysql:host=127.0.0.1;dbname=hue" HUE_TEST_USER=hue HUE_TEST_PASS=... php tests/api_test.php
```
Then work through `docs/QA_CHECKLIST.md` on a phone.

## What changed in Phase 2
Registration with confirm password and initial color; login/logout/session restore; forgot/reset password (emailed single-use 30-minute code, generic responses, sessions revoked); change password; active sessions and "log out others"; multi-account switching with a separate encrypted SQLCipher database per account; profile editing with picture; privacy settings (friend requests, messages, color, profile, Space visibility); blocking UI and server-side effects (hides chats, ends friendship, revokes private access); friend reject/cancel/remove; notification settings, FCM push and deep links; mute / clear chat / delete conversation (for me); local-then-server message search; user, message and Space reports with admin review, notes and filters; account suspension with reason; error, loading, empty and retry states; accessibility labels; biometric app lock; test suite.

## Honest limits
- **The Flutter code has not been compiled or run.** I had no Flutter toolchain. Expect small fixes on first `flutter pub get` / `flutter analyze`. The PHP backend and admin were run against MariaDB and tested.
- Push notifications are written but untested: they need your Firebase project and a device.
- Not end-to-end encrypted. Chats use HTTPS only; Hue's server can read them. SQLite on the phone is encrypted.
- Private Spaces need the owner's app open, and each picture passes through the server once for up to 60 s before deletion. Not peer-to-peer.
- Blocking removes the friendship; after unblocking, both people must add each other again.
- Not built: phone-number sign-in, account deletion, push for "Space expiring soon" (only "expired", via cron), avatars in the chat list (they show on profiles), online/last-seen status, iOS.
- Dark mode and text-scaling were reviewed in code, not visually on a device.
