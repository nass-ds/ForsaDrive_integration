# ForsaDrive — Architecture

## The big picture: two front-ends, two back-ends, ONE database

```
        MOBILE CHANNEL                          WEB CHANNEL
   ┌─────────────────────┐              ┌──────────────────────────┐
   │   Flutter App        │              │   PHP Web App (WAMP)      │
   │  (Dart, Material UI)  │              │  server-rendered pages    │
   │  screens→providers   │              │  Pages/ + classes/        │
   │  →services→models    │              │  (view + logic together)  │
   └──────────┬───────────┘              └────────────┬─────────────┘
              │ HTTP/JSON (Bearer token)              │ direct PDO calls
              │                                        │ (PHP sessions)
              ▼                                        │
   ┌─────────────────────┐                            │
   │  Dart REST API       │                            │
   │  Shelf + shelf_router │                            │
   │  :8080  /api/...     │                            │
   │  routers per domain  │                            │
   └──────────┬───────────┘                            │
              │ sqlite3.dll                            │ PDO sqlite
              ▼                                        ▼
        ┌───────────────────────────────────────────────────┐
        │   ONE shared SQLite file:                          │
        │   ForsaDrive_PFE/forsa_drive_api/database/DB.db    │
        │   (WAL mode + busy_timeout so both can write)      │
        └───────────────────────────────────────────────────┘

        ┌───────────────────────────────────────────────────┐
        │  Apache/WAMP static files (/ForsaDrive alias)      │
        │  web/Src/  → profile pics, vehicle photos          │
        │  (the Dart API does NOT serve images)              │
        └───────────────────────────────────────────────────┘
```

## The three tiers

**1. Presentation (two clients)**
- **Mobile:** Flutter app. Talks to the world only over HTTP/JSON.
- **Web:** PHP server-rendered pages (`web/Pages/*.php`). The browser gets finished
  HTML — no separate JS front-end framework.

**2. Application / business logic (two back-ends)**
- **Dart REST API** (`forsa_drive_api`, Shelf framework, port **8080**). One router per
  domain: `auth`, `rides`, `bookings`, `feed`, `ratings`, plus a large `misc.dart`
  (payments, notifications, admin, helpdesk, student). Serves the Flutter app.
- **PHP classes** (`web/classes/*.php`) — e.g. `rides.php`, `bookings.php`,
  `sanctions.php`. The PHP pages call these directly; logic and data access are bundled
  in the web tier (no separate API hop).

**3. Data (one shared store)**
- A single **SQLite** file. Both back-ends open the *same* file:
  - PHP: `web/classes/database.php:10` → `../../ForsaDrive_PFE/forsa_drive_api/database/DB.db`
  - Dart: `db.dart` → `database/DB.db`

## How two runtimes safely share one SQLite file (key design point)

SQLite is a single-file DB, so two processes writing it could collide. Solved with:
- **WAL journal mode** (`PRAGMA journal_mode = WAL`) — PHP can read while Dart writes and
  vice versa.
- **`busy_timeout = 5000`** — instead of failing with `SQLITE_BUSY`, a writer waits up to
  5s for the lock (matters for the HelpDesk cross-runtime guarded updates).
- **`foreign_keys = ON`** in both stacks — referential integrity enforced no matter which
  side writes.

**Schema ownership:** the Dart side (`db.dart`) is the authority that creates the tables.
The PHP side runs an **idempotent `autoMigrate()`** that adds the extra alias columns the
web pages need (every `ALTER` wrapped in try/catch so re-running is safe).
⚠️ `setup_db.php` is NOT the real schema — never present it; show the live DB / `db.dart`.

## Authentication (different per channel, same hashing)
- **Mobile/Dart API:** stateless **Bearer tokens** stored in an `api_tokens` table with
  expiry; sent as `Authorization: Bearer <token>` (`helpers.dart:49-59`).
- **Web/PHP:** classic **server-side sessions**.
- **Both:** **bcrypt** password hashing — shared algorithm across stacks.

## Static files come from a third place
Flutter gets **JSON from the Dart API (:8080)** but **images from Apache (:80)**.
Uploaded files physically live in `web/Src/` and Apache serves them via the
`/ForsaDrive` alias (`api_config.dart` `uploadsUrl`). The Dart API deliberately does not
serve static files.

## Internal Flutter architecture (layered)
```
screens/   UI per feature (feed, rides, admin, profile…)
  ↓ uses
providers/ app-wide state (auth_provider, locale_provider) — Provider pattern
  ↓ calls
services/  one per domain (ride_service, message_service, face_check_service…) — wrap HTTP
  ↓ returns
models/    typed data (ride_model, user_model, payment_model…)
```
Routing is `go_router`; theming is centralized in `app_theme.dart`.

## Tech stack summary
- **Mobile:** Flutter / Dart — Provider, go_router, flutter_map + geolocator (GPS),
  Firebase Messaging, Google ML Kit (face detection), intl/l10n.
- **API:** Dart Shelf REST API — shelf_router, sqlite3, bcrypt.
- **Web:** PHP (server-rendered), PDO/SQLite, trilingual lang/ (ar/fr/en).
- **Data:** one shared SQLite file in WAL mode.

## One-line defense answer
"ForsaDrive is a three-tier system with two presentation channels — a Flutter mobile app
talking to a Dart REST API, and a server-rendered PHP web console — both operating on a
single shared SQLite database in WAL mode. The Dart side owns the schema; the PHP side
migrates in the columns it needs. One source of truth, two specialized front-ends: mobile
for end users, web for administration."
