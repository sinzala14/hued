<?php
// Copy this file to config.php and fill in your values.
// config.php is git-ignored so your credentials stay safe.
//
// Production tip: you can also set these as environment variables instead:
//   HUE_DB_DSN, HUE_DB_USER, HUE_DB_PASS, HUE_FORCE_HTTPS=1,
//   HUE_MAIL_DRIVER, HUE_MAIL_FROM,
//   HUE_FCM_PROJECT_ID, HUE_FCM_SERVICE_ACCOUNT

return [

  // --- Database ---
  'db' => [
    'dsn'  => 'mysql:host=127.0.0.1;dbname=hue;charset=utf8mb3',
    'user' => 'root',
    'pass' => '',
  ],

  // --- Sessions ---
  'session_days' => 90,

  // --- File storage (must be writable, outside web root in production) ---
  'storage' => __DIR__ . '/../storage',

  // --- Upload size limit (bytes) ---
  // 10 MB covers images and voice notes comfortably.
  'max_upload_bytes' => 10 * 1024 * 1024,

  // --- HTTPS ---
  // Set to true in production so the app rejects plain HTTP.
  'force_https' => false,

  // --- Mail ---
  // 'log'  → writes reset codes to storage/logs/mail.log (good for local dev)
  // 'mail' → uses PHP mail() (configure your server's MTA for production)
  'mail_driver' => 'log',
  'mail_from'   => 'no-reply@example.com',

  // --- Push Notifications (Firebase Cloud Messaging) ---
  // 1. Go to Firebase Console → Project Settings → Service Accounts
  // 2. Click "Generate new private key" → save the JSON file OUTSIDE your web root
  // 3. Fill in the two values below and uncomment them.
  //
  // Without these, the app still works — notifications are silently skipped.
  //
  // 'fcm_service_account' => '/home/youruser/secrets/hue-firebase-adminsdk.json',
  // 'fcm_project_id'      => 'your-firebase-project-id',

];
