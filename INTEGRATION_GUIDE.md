# ForsaDrive — Web + Mobile Unified Backend Integration

This document describes how the **Web (PHP)** browser app and the **Mobile/Web
(Flutter)** app share **one database and one set of users**, and how to run the
whole system locally.

> ⚠️ **Read this if you last saw an older version of this file.** The earlier
> revision described the mobile app talking to the PHP API at
> `http://10.0.2.2/ForsaDrive/api` with the single database at
> `web/Database/DB.db`. **That is no longer how it works.** The active mobile
> backend is the **Dart API on `:8080`**, and the single shared database is
> `ForsaDrive_PFE/forsa_drive_api/database/DB.db`. The sections below reflect
> the current, verified setup.

---

## Architecture (current)

```
┌─────────────────────┐          ┌──────────────────────────┐
│  Web browser        │          │  Flutter app             │
│  PHP-rendered       │          │  (Android emulator, real │
│  Pages/*.php        │          │   phone, or Chrome web)  │
└──────────┬──────────┘          └────────────┬─────────────┘
           │ PHP $_SESSION                     │ Bearer <token>  (HTTP+JSON)
           │ (server-side, direct DB)          │
           ▼                                   ▼
┌────────────────────────────┐     ┌───────────────────────────────┐
│  Apache (WAMP)             │     │  Dart Shelf API               │
│  Alias /ForsaDrive → web/  │     │  bin/server.dart  on :8080    │
│  • Pages/*.php  (browser)  │     │  routes: /api/auth, /api/rides│
│  • Src/*        (images)   │     │          /api/bookings, ...   │
└─────────────┬──────────────┘     └───────────────┬───────────────┘
              │                                     │
              │   both open the SAME file (WAL)     │
              └──────────────┬──────────────────────┘
                             ▼
            ┌────────────────────────────────────────────┐
            │  SQLite (single source of truth)           │
            │  ForsaDrive_PFE/forsa_drive_api/           │
            │      database/DB.db   (journal_mode=WAL)   │
            └────────────────────────────────────────────┘
```

**Two independent code paths, one database file:**

- The **browser UI** is server-rendered PHP (`web/Pages/*.php`). It reads/writes
  the database **directly** through the `Database` class — not over HTTP. Auth
  is a PHP `$_SESSION`.
- The **Flutter app** never touches the DB directly. It calls the **Dart REST
  API** on `http://localhost:8080/api` with a `Bearer` token.
- Both processes open the **same SQLite file** with `PRAGMA journal_mode=WAL`,
  which lets PHP and Dart read/write it concurrently. **There is no second
  database.**

> A parallel PHP REST API also exists under `web/api/*`. It is **not used by the
> Flutter app** (the app targets the Dart API). Treat `web/api/*` as a legacy /
> browser-side fetch layer, not the canonical mobile backend.

---

## How PHP was pointed at the shared database

`web/classes/database.php` opens the Dart API's database when it exists, and
falls back to the old web-only file otherwise:

```php
$shared = __DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db';
$legacy = __DIR__ . '/../Database/DB.db';
$dbPath = file_exists($shared) ? $shared : $legacy;
```

On every connection it runs an **idempotent auto-migration** (`autoMigrate()`)
that `ALTER`s in the extra columns and helper tables the web side needs but the
Dart schema doesn't ship (e.g. `users.student_status`, `vehicles.verified`,
`payments.ref_id`, `feed_posts.boost_tier`, plus tables like `password_resets`,
`audit_logs`, `announcements`, `promo_codes`, `user_sanctions`). Each `ALTER`
is wrapped in try/catch, so re-running is a no-op.

**Why it's done this way:** the original two-stack setup left real data (users,
feed posts) in the Dart database while the web read a separate, empty SQLite —
so the browser said *"account not found"* for users that clearly existed on
mobile. Pointing PHP at the Dart database resolved it without migrating data.

---

## Running the system locally

You need **three** things up: Apache (for the browser UI + images), the Dart
API (for the mobile app), and the Flutter app itself.

### 1. Apache / WAMP (browser UI + uploaded images)

The alias is already written to `C:\wamp64\alias\forsadrive.conf`:

```apache
Alias /ForsaDrive "c:/Users/GIGABYTE/Desktop/ForsaDrive_integration/web/"
```

Start WAMP and make sure the tray icon is green. Verify:

```powershell
curl http://localhost/ForsaDrive/Pages/login.php   # → login HTML (HTTP 200)
```

> If you edit the alias, Apache must be restarted (WAMP tray → green, or
> `Restart-Service wampapache64` from an **admin** PowerShell).

### 2. Dart API (mobile backend, port 8080)

```powershell
cd ForsaDrive_PFE
.\start_api.bat        # → dart run bin/server.dart
```

Verify it's up:

```powershell
curl http://localhost:8080/api/health        # → {"status":"ok"}
```

### 3. Flutter app

```powershell
cd ForsaDrive_PFE
.\start_flutter.bat    # → flutter run -d chrome --web-port 3000
```

…or run it on an Android emulator / device from your IDE.

---

## Mobile app configuration

`ForsaDrive_PFE/forsa_drive_flutter/lib/config/api_config.dart`:

```dart
static const String baseUrl   = 'http://localhost:8080/api';  // Dart API
static const String uploadsUrl = 'http://localhost/ForsaDrive/Src'; // Apache
```

- `baseUrl` → the Dart API. `localhost`/`127.0.0.1` work for Chrome web and
  (via `10.0.2.2`) Android emulators that loop back to the host.
- `uploadsUrl` → uploaded images live in `web/Src/` and are served by **Apache**
  through the `/ForsaDrive` alias (the Dart API serves no static files).
- **Physical phone:** replace `localhost` in *both* URLs with your PC's LAN IP,
  e.g. `http://192.168.100.146:8080/api` and `http://192.168.100.146/ForsaDrive/Src`.
  The `forsadrive.conf` alias already allows the `192.168.0.0/16`,
  `10.0.2.0/24`, and `172.16.0.0/12` ranges.

---

## Verifying cross-platform sync

The point of the integration is that a change on one platform is visible on the
other because they share `users`, `rides`, `bookings`, etc. in one file.

1. **Create a user on the web:** `http://localhost/ForsaDrive/Pages/signup.php`.
2. **Log in on mobile** with the same email/password. The Dart API authenticates
   against the same `users` row (`POST /api/auth/login` → token; `GET /api/auth/me`).
3. **Top up on the web** (`Pages/payments.php`) → re-fetch on mobile; the
   `users.balance` is the same value.
4. **Offer a ride on mobile** (driver) → it appears in the web dashboard.
5. **Book / cancel on either side** → the other reflects it after a refresh.

> Stale values after a change are a **refresh** issue (PHP re-reads the user row
> on full page load; the Flutter screens re-fetch on `initState` /
> pull-to-refresh), **not** a split data source.

---

## What lives where (cheat-sheet)

| Concern | Location |
|---|---|
| SQLite file (single source of truth) | `ForsaDrive_PFE/forsa_drive_api/database/DB.db` (WAL) |
| Dart API entrypoint + route mounts | `ForsaDrive_PFE/forsa_drive_api/bin/server.dart` |
| Dart API route handlers | `ForsaDrive_PFE/forsa_drive_api/lib/routes/*.dart` |
| Dart DB access + schema/migrations | `ForsaDrive_PFE/forsa_drive_api/lib/db.dart` |
| Web → shared-DB connection + auto-migrate | `web/classes/database.php` |
| Web browser UI | `web/Pages/*.php` (+ `include/`, `server/`) |
| Web data-access classes | `web/classes/*.php` |
| Uploaded images (profile/vehicle photos) | `web/Src/` → served at `/ForsaDrive/Src/` |
| Legacy PHP REST API (not used by mobile) | `web/api/*.php` |
| Mobile API base URL + uploads URL | `forsa_drive_flutter/lib/config/api_config.dart` |
| Mobile API client / auth state | `forsa_drive_flutter/lib/services/api_service.dart`, `providers/auth_provider.dart` |
| Boost-tier prices (kept in sync, §3.2) | `forsa_drive_api/lib/boost_tiers.dart`, `web/server/boost_tiers.php`, `forsa_drive_flutter/lib/widgets/boost_tier_picker.dart` |

---

## Dart API endpoints (mounted in `server.dart`)

`/api/health` plus these route groups (all under `/api/…/`):

```
auth   rides   bookings   vehicles   payments   notifications   messages
ratings   complaints   profile   admin   student   helpdesk   feed
recommendations   analytics   organizations   saved-searches
```

Routes are mounted **with a trailing slash**, so the list/root of a group is
e.g. `GET /api/rides/` (a request to `/api/rides` without the slash returns 404
— this is normal `shelf_router` behaviour). Protected endpoints return
`401 {"message":"Unauthorized"}` without a valid `Bearer` token.

---

## Accounts

The database ships with real seeded accounts (no fixed demo password is
published here). The **admin** account is the user `noss`
(`anasyns25@gmail.com`, `is_admin = 1`).

If you don't know a password, use the **forgot-password** flow (spec §2.2): it
issues a hashed, single-use, 60-minute token. There is no SMTP in this demo, so
the reset link/token is shown on screen rather than emailed
(`POST /api/auth/forgot-password` on mobile, or `Pages/forgot_password.php` on
the web).

---

## Rebuilding the database from scratch

> ⚠️ **Destructive** — `web/setup_db.php` drops every table. **Never** run it
> against the shared DB if you want to keep the current data the Dart API and
> mobile app rely on.

If you genuinely need a clean DB, stop the Dart API first, then rebuild and let
`autoMigrate()` re-add the web columns on the next PHP connection.
