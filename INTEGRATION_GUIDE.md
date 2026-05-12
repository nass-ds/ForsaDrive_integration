# ForsaDrive — Web + Mobile Unified Backend Integration

This document describes how the Web (PHP) and Mobile (Flutter) apps share **one
backend, one database, and one set of users**, and what was changed to complete
the integration.

---

## Architecture (after integration)

```
┌─────────────────────┐     ┌──────────────────────┐
│  Web browser        │     │  Flutter mobile app  │
│  (PHP-rendered      │     │  (Dart, HTTP+token)  │
│   Pages/*.php)      │     │                      │
└──────────┬──────────┘     └───────────┬──────────┘
           │ PHP $_SESSION              │ Bearer <token>
           │                            │
           ▼                            ▼
   ┌────────────────────────────────────────────┐
   │  Apache (WAMP) – serves /ForsaDrive        │
   │   ├─ /ForsaDrive/Pages/*.php   (browser)   │
   │   └─ /ForsaDrive/api/*         (mobile)    │
   └────────────────────┬───────────────────────┘
                        │
                        ▼
            ┌─────────────────────────┐
            │  SQLite                 │
            │  web/Database/DB.db     │
            │  (single source)        │
            └─────────────────────────┘
```

Both code paths (`Pages/` and `api/`) instantiate the same `Database` class and
open the same `web/Database/DB.db` file. There is **no second database**.

---

## What was changed

| # | Change | Why |
|---|--------|-----|
| 1 | Added missing columns to [setup_db.php](web/setup_db.php) for the `users` table: `student_email`, `student_status` (with CHECK), `student_verified_at`, `referral_code`, `referred_by`. | Several existing pages (`payments.php`, `settings.php`, `admin.php`) read/write these fields. Without the columns, the SQL throws and PHP shows *"A server error occurred"* on login because the post-login redirect blows up. |
| 2 | Made [setup_db.php](web/setup_db.php) runnable from CLI (`php setup_db.php --confirm`). | So the DB can be rebuilt without going through the browser. |
| 3 | Initialized `web/Database/DB.db` (was 0 bytes — empty file with no tables → root cause of the *server error*). | The DB now has all 19 tables + the seeded admin account. |
| 4 | Added WAMP Apache alias at `C:\wamp64\alias\forsadrive.conf` mapping `/ForsaDrive` → the Desktop project folder. | The Mobile app's `api_config.dart` already points at `http://10.0.2.2/ForsaDrive/api`. Before this alias, WAMP only served an older copy at `/webPFE/ForsaDrive/ForsaDrive/` (which has no `api/` folder). Now both browser and emulator hit the same URL prefix → the same code → the same DB. |

No source files in `web/Pages/`, `web/classes/`, `web/api/`, or anywhere under
`mobile/lib/` were modified. Auditing them confirmed:

- **Web Pages** already load everything via `getDB()` + `$_SESSION['user_id']`.
  No hardcoded/mock user data was found.
- **Mobile screens** already go through `ApiService` with `Bearer <token>` and
  scope every list to the authenticated user (`AuthProvider.user`). No
  hardcoded/mock user data was found.

The two `_kRoles` / `_orgTypes` / `routeLabels` constants in
`mobile/lib/screens/organization/organization_screen.dart` and
`mobile/lib/screens/landing/landing_screen.dart` are not user data — they are
enum-like UI labels (role pickers, popular-route chips). They stay as-is.

---

## One-time setup steps you need to run

### 1. Restart Apache so the new alias is loaded

Claude wrote `C:\wamp64\alias\forsadrive.conf` but cannot restart the
`wampapache64` service (no admin rights from this session). You need to:

- **Option A (recommended):** left-click the WAMP tray icon → green → wait for
  green. (WAMP auto-restarts Apache after any alias change.)
- **Option B:** left-click WAMP tray icon → `Apache` → `Service` → `Restart Service`.
- **Option C (PowerShell as Admin):** `Restart-Service wampapache64`.

Verify:

```powershell
curl http://localhost/ForsaDrive/Pages/login.php
# Should return the login HTML (HTTP 200), not 404.
```

### 2. Verify the API responds (after step 1)

```powershell
curl -X POST http://localhost/ForsaDrive/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"email":"admin@forsadrive.tn","password":"Admin@1234"}'
# Should return: {"token":"...","user":{...}}
```

### 3. Mobile app config

`mobile/lib/config/api_config.dart` is already correct:

```dart
static const String baseUrl = 'http://10.0.2.2/ForsaDrive/api';
```

- `10.0.2.2` is the Android emulator's loopback to the host PC.
- For a **physical phone**, replace it with your PC's LAN IP, e.g.
  `http://192.168.1.42/ForsaDrive/api`. The `forsadrive.conf` alias already
  allows the `192.168.0.0/16`, `10.0.2.0/24`, and `172.16.0.0/12` ranges
  (`Require ip` lines).

---

## How to test the cross-platform sync (the "noss" scenario)

1. Open the Web app: `http://localhost/ForsaDrive/Pages/signup.php`. Create an
   account named **noss** with `noss@example.com` / a password / `is_driver=1`.
2. After signup, the browser session shows the dashboard with **noss's** name,
   balance `0.00 TND`, rating `5.0`, and 0 upcoming rides — all read from the
   same `users` row.
3. Start the mobile app. Log in with the same email/password. The mobile
   `Dashboard` greets **noss** and pulls the same balance / rating via
   `GET /auth/me`.
4. On the **Web**, go to `Pages/payments.php` and use the *Simulated top-up*
   form to add 25 TND. Log out and back into the mobile app → balance is
   25 TND. (Same `users.balance` column.)
5. On the **Mobile**, go to `Offer a Ride` (driver only) and create one. Refresh
   the Web `Pages/interface.php` dashboard → the ride is in *Upcoming*.
6. On the **Mobile**, book a ride. Web `Pages/my_rides.php` shows it.
7. On the **Web**, cancel the booking from `my_rides.php`. Pull-to-refresh the
   mobile My Rides screen → it is cancelled.
8. Log out from both, log back in, verify the data is still tied to **noss**.

If any of those steps shows a stale value, it is a **refresh** issue (PHP
sessions only re-fetch the user row on full reload; mobile screens re-fetch on
`initState` / pull-to-refresh), not a data-source split.

---

## What lives where (cheat-sheet)

| Concern | Location |
|---|---|
| SQLite file (single source of truth) | `web/Database/DB.db` |
| Schema definition | `web/setup_db.php` |
| Web auth (browser sessions) | `web/server/session.php` + `web/Pages/login.php` |
| Web data access classes | `web/classes/*.php` (User, Ride, Booking, …) |
| API front controller | `web/api/index.php` |
| API auth + token middleware | `web/api/helpers.php`  (`auth_user()`) |
| API endpoints | `web/api/{auth,rides,bookings,users,payments,notifications,complaints,ratings,admin_api}.php` |
| API clean-URL rewrite | `web/api/.htaccess` (already present, all-to-index.php) |
| Mobile API base URL | `mobile/lib/config/api_config.dart` |
| Mobile auth state | `mobile/lib/providers/auth_provider.dart` + `auth_service.dart` |
| Mobile API client | `mobile/lib/services/api_service.dart` |

---

## Credentials

After `setup_db.php` runs (already done by Claude), only the admin exists:

```
Email:    admin@forsadrive.tn
Password: Admin@1234
```

Change this password immediately after the first login.

---

## To rebuild the DB from scratch later

> ⚠️  This drops every user, ride, booking, message — everything.

```powershell
cd c:\Users\GIGABYTE\Desktop\ForsaDrive_integration\web
C:\wamp64\bin\php\php8.2.26\php.exe setup_db.php --confirm
```

Or via browser: `http://localhost/ForsaDrive/setup_db.php?confirm=yes`.
