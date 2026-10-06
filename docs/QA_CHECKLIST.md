# Manual QA checklist (on a real Android device, two accounts A and B)

Automated: `php tests/api_test.php` (119 API checks) and `cd app && flutter test`.
The items below need a phone, because they involve the OS, Firebase or the screen.

## Lifecycle 1: messaging
1. Register A (pick a color), register B on a second phone/emulator. Each shows a code like KIM-7F29X.
2. A searches B's code, sends a request. B gets a push; tapping it opens People with the request.
3. B accepts; A gets "request accepted". A opens the chat from People and sends a message.
4. Turn on airplane mode on A, send a message: it shows the clock icon and stays in the chat. Force-close Hue, reopen: still queued.
5. Turn airplane mode off: the message becomes "sent" within seconds, B gets a push (preview text per B's setting).
6. B taps the push: the correct chat opens. A's ticks change to delivered then read.

## Lifecycle 2: private Space
1. A: Spaces > Private > choose pictures > 5 minute key. B: enter the key. B sees the pictures (A's app must be open).
2. Screenshot attempt on either phone is blocked. Revoke on A: B's next picture fails with "Access has expired".
3. Wait for expiry: B is thrown out; reusing the key is rejected. Change B's phone clock: access is unaffected (server decides).

## Lifecycle 3: blocking
A blocks B from Chat > profile > Block. B cannot send/find A; the chat disappears for A. A unblocks in Privacy > Blocked users; both must re-friend.

## Lifecycle 4: moderation
B reports A's message and A's public Space. Admin dashboard shows both. Admin suspends A: A's phone returns to the login screen with the suspension message; login is refused; restore works.

## Accounts and security
- Forgot password: code arrives by email, works once, old sessions are logged out.
- Switch account: A's chats never appear under B; logging out of one keeps the other.
- Security > App lock: lock after 30 s in background; unlock with biometrics.
- Active sessions list; "Log out of all other sessions" kicks the second phone.

## Appearance and accessibility
- Light, Dark and System: check Chats, Chat, People, Spaces, Profile, every settings screen, dialogs, error and empty states.
- Settings > Display > Font size = largest, and Display size = largest: no clipped text or overlapping buttons.
- TalkBack on: every icon button announces a label; message ticks are announced (Sending, Sent, Delivered, Read, Failed).
