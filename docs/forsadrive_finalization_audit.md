# ForsaDrive — Finalization Audit (Phase 1, read-only)

**Role:** PFE supervisor + IT consultant review, for defense readiness.
**Goal:** polish, not rebuild. Every finding cites a specific `file:line` or report §.
**Date:** 2026-06-01. **Scope reviewed:** `web/` (PHP/SQLite), `ForsaDrive_PFE/forsa_drive_api/` (Dart Shelf API), `ForsaDrive_PFE/forsa_drive_flutter/` (Flutter), `ForsaDrive_PFE/plantUML/*.puml`, `ForsaDrive_Full_Report.docx`, live DB `forsa_drive_api/database/DB.db`.

> **Phase 2 status (in progress, 2026-06).** A `phase2-defense-fixes` checkpoint is staged (web on the branch; Dart edits in the `ForsaDrive_PFE` working tree) — **not yet committed** — containing **F-09, F-11, F-37, F-44, F-25, F-29** (F-29 includes an admin-cancel integrity guard). **F-31 was reverted** (it only shifts money online→cash; see **F-50**). **Held for a later bundled step** (needs a verified student account): the complete discount fix = F-31 upfront split **+** F-50 cash-side **+** F-49 (gate on `is_student_verified`) **+** F-13 (verification flows must set `is_student_verified=1`). **Also held:** F-26/F-27 (web payment rework), F-10 (org status vocab), web-side F-25.

---

## 0. Ground truth (architecture & how the pieces actually fit)

This underpins almost every finding, so it is stated once here.

- **One shared SQLite file, two backends.** The PHP web app (`web/Pages/*.php` + `web/classes/*.php`, server-rendered, direct DB) and the Dart REST API (`forsa_drive_api`, used by the Flutter app) both open the **same** file: `ForsaDrive_PFE/forsa_drive_api/database/DB.db`. Confirmed in `web/classes/database.php:10-12` and `INTEGRATION_GUIDE.md:45-58`.
- **The live schema was authored by the Dart side.** I dumped the live DB: `rides`, `bookings`, `payments`, `organizations` all match `forsa_drive_api/lib/db.dart` (no `CHECK` constraints), with PHP's alias columns (`payments.ref_id`, `organizations.contact_name/email_domain`, `users.is_student_verified`, …) added by `Database::autoMigrate()` (`web/classes/database.php:44-339`). The PHP `setup_db.php` schema is **not** what is in the file.
- **There is a third, legacy booking code path:** `web/api/*.php`. `INTEGRATION_GUIDE.md:56-58,184` says it is "legacy / not used by the Flutter app." It is inconsistent with the live data (see [F-12]) and is effectively dead.

**Canonical terminology used in this document** (enforce everywhere): Passenger, Driver, Admin, HelpDesk Agent, Organization, Student Verification, Booking, Payment, SupportTicket, Notification.

---

## How to read the finding tables

Each finding: **[ID]** · evidence · **Severity** (Blocker / Major / Minor / Cosmetic) · **Category** (Code / DB / Flow / Report-text / Diagram) · proposed fix · **Verdict** (MUST-FIX-BEFORE-DEFENSE / CAN-STAY-AS-IS / PRESENT-AS-FUTURE-WORK).

---

## Area 1 — Code structure & organization

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-01** | Booking/ride logic is implemented **three times**, divergently: Dart (`forsa_drive_api/lib/routes/bookings.dart`, `rides.dart`), canonical web (`web/classes/rides.php`, `web/Pages/book_ride.php`), and legacy web (`web/api/bookings.php`, `web/api/rides.php`). The three disagree on charge amount, refunds, and ride status (see Area 6/10). | Major | Code | Treat the **Dart API as the single source of payment truth**; delete or clearly quarantine `web/api/*.php`; keep the web `classes/` path but align its money rules (Area 6). | MUST-FIX (decide ownership) |
| **F-02** | `web/api/*.php` is dead/legacy (`INTEGRATION_GUIDE.md:56-58`), yet still filters rides by `status='open'` (`web/api/bookings.php:49`) — a value no live ride has (live values: `active/completed/cancelled`). It would 404/“unavailable” on every real ride. | Minor | Code | Remove `web/api/` or mark it `DEPRECATED` in a header; do not demo it. | CAN-STAY-AS-IS (but hide) |
| **F-03** | `forsa_drive_api/lib/routes/misc.dart` is a **1,463-line grab-bag** of unrelated routers (vehicles, payments, notifications, messages, ratings, complaints, profile, admin, student, helpdesk). | Minor | Code | Split into `payments.dart`, `notifications.dart`, `admin.dart`, `helpdesk.dart`, `student.dart`. Cosmetic for the jury but improves the walkthrough. | CAN-STAY-AS-IS |
| **F-04** | Committed `node_modules/` (Firebase JS) + `package.json` inside a Flutter project (`forsa_drive_flutter/node_modules/`, `package.json`). | Minor | Code | Add to `.gitignore`, `git rm -r --cached node_modules`. (Already noted in prior audit, still unfixed.) | MUST-FIX (repo hygiene) |
| **F-05** | Misleading dead comments in `forsa_drive_api/lib/helpers.dart:101-110` describe a “SHA-256 fallback” that does not exist — the code uses bcrypt (`verifyPassword`/`hashPassword`, lines 112-122). | Cosmetic | Code | Delete the stale comment block. | CAN-STAY-AS-IS |
| **F-06** | Machine-specific absolute path hardcoded as the uploads default: `r'C:\Users\GIGABYTE\...\web\Src'` (`misc.dart:560`). An env override exists (`FORSADRIVE_UPLOADS_DIR`), but the default will break on any other machine. | Minor | Code | Resolve relative to the repo, or document the env var in the run instructions. | CAN-STAY-AS-IS (document) |
| **F-07** | Two overlapping “report a user” subsystems coexist: `complaints` (table + `complaintsRouter`) and `reports` (`reports` table + `reports.dart`, by `public_id`). Both are wired in the mobile app (`complaints_screen.dart`, `report_user_screen.dart`). | Minor | Code/DB | Pick one for the narrative: present `reports` (public-ID based) as the user-facing “Report a user”, and `complaints` as the legacy/admin dispute log — or merge. | PRESENT-AS-FUTURE-WORK (consolidate) |
| **F-08** | Vestigial `driver_profiles` table (PHP) duplicates the role of `driver_applications` (Dart). Both exist in the live DB; `driver_profiles` is backfilled but barely read. | Minor | DB/Code | Note in the report that driver onboarding is `driver_applications` → admin approval → `vehicles`; treat `driver_profiles` as legacy. | CAN-STAY-AS-IS |

**Positives:** clean separation of `Pages`/`classes`/`api` on the web; consistent route/handler shape in Dart; bcrypt password hashing shared across both stacks; guarded idempotent migrations.

---

## Area 2 — Database consistency

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-09** | **`setup_db.php` describes a schema that contradicts the live DB and would corrupt it if run.** Its `CHECK` constraints reject values the app actually writes: `rides.status` allows only `open/full/cancelled/completed/pending` (`setup_db.php:206`) but the app writes `active`/`in_progress`; `payments.type` allows only `deposit/charge/refund/earning` (`:227`) but the app writes `ride_earning/boost/admin_adjustment/promo`; `organizations.status` allows only `pending/active/suspended` (`:332-333`) but live data is `approved/rejected`. It also rebuilds `users` with `first_name/last_name/role` columns the live schema does not have. `INTEGRATION_GUIDE.md:222-229` already warns "never run it against the shared DB." | Major (latent Blocker) | DB | **Do not present `setup_db.php` as the schema.** Either (a) rewrite its DDL to mirror `db.dart` (no contradicting CHECKs), or (b) add a hard guard so it cannot target the shared file, and stamp it `DEPRECATED — see db.dart`. | MUST-FIX (guard + de-canonicalize) |
| **F-10** | **Organization status vocabulary is split → discount codes only work on the platform that approved the org.** Mobile approves as `status='approved'` (`organizations.dart:112`) and books/validates against `status='approved'` (`bookings.dart:46`, `organizations.dart:153`). Web approves as `status='active'` (`web/Pages/admin.php:152`) and the legacy web API books against `status='active'` (`web/api/bookings.php:20,72`). Live data is `approved`. `admin.php:1490` doesn’t even render `approved`/`rejected` badges. | Major | DB/Flow | Standardise on **`approved`/`rejected`/`pending`** everywhere; change `admin.php:152` to write `approved` and its badge `match()` accordingly. | MUST-FIX |
| **F-11** | **Missing performance indexes on the live DB.** Live DB has only 10 indexes (verified by query): `audit_logs`, `password_resets`, `reports×5`, `ride_locations`, `user_sanctions`, `users.public_id`. The `idx_rides_*`, `idx_bookings_*`, `idx_notif_user`, `idx_msg_conv`, `idx_ratings_to`, `idx_feed_*` from `setup_db.php:413-431` were never created (Dart built the tables; `autoMigrate()` doesn’t add them). Report §2.4 claims “queries… optimized with appropriate indexes.” | Minor | DB | Add the booking/ride/notification/message/rating indexes inside `db.dart _createSchema()` (idempotent `CREATE INDEX IF NOT EXISTS`). | MUST-FIX (cheap, supports the report claim) |
| **F-12** | Internal PHP inconsistency in legacy API: listing uses `r.status='active'` (`web/classes/rides.php:57`) but booking requires `r.status='open'` (`web/api/bookings.php:49`). | Minor | DB/Flow | Resolved by removing `web/api/` (F-02). | CAN-STAY-AS-IS |
| **F-13** | `is_student_verified` column exists (live) but **no code path sets it to 1**; the Dart limited-profile “verified” badge keys on it (`profile_access.dart:75`), while Student Verification only sets `is_student=1` (`misc.dart:1141`; web `admin.php:240`). Net effect: verified students never get the badge on mobile. | Minor | DB/Code | Key the badge on `is_student` (and/or set `is_student_verified=1` in both approval paths). | MUST-FIX (small) |
| **F-14** | Duplicate tables for one concept: `org_members` (PHP) **and** `organization_members` (Dart); both live. Org code reads `organization_members` (`organizations.dart:173,195,223`); `org_members` is only created by PHP migration and unused by features. | Minor | DB | Pick `organization_members`; drop/ignore `org_members`. | CAN-STAY-AS-IS |
| **F-15** | Two sources of truth for student domains: hardcoded `_allowedDomainSuffixes` (`misc.dart:1000-1004`, used by the OTP gate) vs the seeded `student_domains` table (`setup_db.php:456-468`). They differ (e.g. `tek-up.tn`, `uvt.rnu.tn` only in code; `esprit.com`, `ucar.tn` only in table). | Minor | DB/Code | Make the OTP check read `student_domains`, or document the table as web-only. | CAN-STAY-AS-IS |

**Positives (verify-and-claim these):** status strings are **consistently lowercase** — a grep for uppercase/mixed status comparisons (`'Active'`, `'Pending'`, …) returned **zero** matches; recent columns all present and correct: `helpdesk_conversations.assignment_method`/`assigned_at` (`db.dart:381-382`, `database.php:189-190`), `users.public_id`, `reports.reported_public_id`, the `ratings` table.

---

## Area 3 — Entity / table relations

**FK integrity (from live `PRAGMA foreign_key_list` + schema):**

- `rides.driver_id → users(id)` CASCADE; `rides.vehicle_id → vehicles(id)`. ✓
- `bookings.ride_id → rides(id)` CASCADE; `bookings.passenger_id → users(id)` CASCADE. ✓
- `ratings.ride_id → rides(id)` SET NULL; `from_user_id/to_user_id → users(id)` CASCADE. ✓
- `notifications.user_id → users(id)` CASCADE; `related_user_id → users(id)` SET NULL. ✓
- `helpdesk_conversations.user_id → users` CASCADE; `agent_id → users` SET NULL (SupportTicket). ✓
- `reports.reporter_id/reported_user_id/ride_id` → users/rides. ✓
- `PRAGMA foreign_keys=ON` in both stacks (`db.dart:34`, `database.php:26`). ✓

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-16** | **Payment ↔ Booking is not a real relation.** `payments` has only `user_id → users(id)` (live `PRAGMA foreign_key_list(payments)` shows one FK). There is **no** `booking_id`/`ride_id` FK. The web `ref_id` column exists but is **NULL for all 38 payment rows** (verified). Linkage is free-text only (`description = "Booking #N - 50% upfront"`, `bookings.dart:75`). | Minor | DB | For the report: describe `payments` honestly as a **per-user wallet ledger**, not a per-booking record. Optionally populate `ref_id` with `booking_id` going forward. | CAN-STAY-AS-IS (re-describe) |
| **F-17** | **Rating UNIQUE constraint exists and is correct**, but the column names differ from the brief. Live `ratings` has `UNIQUE(ride_id, from_user_id, to_user_id)` (`db.dart:176`, `setup_db.php:242`) — i.e. `from_user_id` = rater, `to_user_id` = rated user. The names `rater_id`/`rated_user_id` used in conversation do **not** exist; if the report names them that way, fix the report, not the DB. | Cosmetic | DB/Report-text | In the report/ER diagram, label the columns `from_user_id (rater)` and `to_user_id (rated)`. | CAN-STAY-AS-IS (report wording) |
| **F-18** | Class diagram draws relations the DB doesn’t implement: `Booking "1"--"0..*" Payment : generates` (`forsadrive_class_diagram.puml:214`) — no such FK (see F-16); `Message.receiver_id` (`:123`) — `messages` has no `receiver_id`, recipient is derived from `conversation_id`; the rating relations omit **User receives Rating** (`to_user_id`), which is what reputation is built on. | Minor | Diagram | Remove/relabel the Booking→Payment edge (or footnote it as logical-only), drop `receiver_id`, add `User "1"--"0..*" Rating : receives`. | MUST-FIX (diagram) |

---

## Area 4 — Use-case & UML logic vs code

**Diagrams I can read (`.puml` text) and cross-checked:** `forsadrive_use_case.puml`, `forsadrive_class_diagram.puml`, `forsadrive_sequence_1_book_ride.puml`, `forsadrive_activity_1_booking_flow.puml`.

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-19** | Use-case diagram claims features that are **not implemented**: “Generate Referral Code on Signup”, “Share Referral Code”, “Redeem Referral Code” (`forsadrive_use_case.puml:67-68,93,311`). Neither signup path generates a referral code (`auth.dart:54-92` does not; web `signup.php` does not), and there is no redeem-referral endpoint. Only the columns `referral_code`/`referred_by` exist. | Major | Diagram/Flow | Remove referral use-cases from the diagram **or** present referral as “Future Work.” Don’t demo it. | PRESENT-AS-FUTURE-WORK |
| **F-20** | Use-case diagram shows System “Auto-Refund on Cancellation” (`:309`) as a clean automated process. In code, refunds fire only on **mobile** driver-cancel and passenger-cancel; they **don’t** fire on driver-reject (mobile + web), admin cancel, or any web path (see Area 6). | Major | Diagram | Either fix the code (preferred, see F-21) or scope the diagram note to “refund on cancellation (mobile).” | MUST-FIX (via Area 6) |
| **F-21** | Class diagram enum/labels don’t match the implementation: `User.role : Enum{passenger,driver,student,agent,admin}` but there is **no `role` column** (boolean flags `is_driver/is_student/is_admin/is_helpdesk_agent`, live PRAGMA confirms no `role`); `Ride.status{open,full,cancelled,completed}` vs actual `active/in_progress/completed/cancelled`; `Payment.type{...earning}` vs `ride_earning`; `Organization.status{pending,active,suspended}` vs `pending/approved/rejected`. The header annotation “Full schema lives in `web/setup_db.php`” (`:5`) points at the **wrong/stale** file. | Major | Diagram | Update enums to the real values; change the annotation to `db.dart`; either model role as flags or footnote that roles are non-exclusive. | MUST-FIX (diagram) |
| **F-22** | The **book-ride sequence diagram is faithful** to the Dart 50% flow (`forsadrive_sequence_1_book_ride.puml:82-105` ↔ `bookings.dart:55-95`). Note it (correctly, for now) omits the student discount during booking — matching the mobile bug in F-31, but contradicting the report text. | — (positive, with caveat) | Diagram | Keep; revisit if F-31 is fixed. | CAN-STAY-AS-IS |
| **F-23** | The **booking activity diagram explicitly specifies “Process refund of 50% prepayment” on driver reject** (`forsadrive_activity_1_booking_flow.puml:137-149`). This is the team’s own design — and the code violates it (F-24). The same diagram shows “Apply student discount if verified” during booking (`:17-18`), which the mobile code does not do for single bookings. | Major | Diagram/Flow | Use the diagram as the spec: implement refund-on-reject and the student discount. | MUST-FIX (via Area 6) |
| **F-24** | Report text vs its own figures: text uses **“Support Agent”** (§2.2.6, §2.3.6) while the diagrams use the canonical **“HelpDesk Agent”** (`forsadrive_use_case.puml:175`); text calls GPS “future work” (§1.2.3) while the diagrams include “Track Driver Live / Share Live GPS Location / Manage Driver GPS Tracking” and the code implements it (`ride_locations`, `rides.dart:613-763`). | Minor | Report-text/Diagram | Standardise on “HelpDesk Agent”; upgrade GPS from “future” to “implemented (basic live tracking).” | MUST-FIX (report) |

**Diagrams I CANNOT validate (rendered images/PDFs) — verify by eye before printing:**
1. `plantUML/ForsaDrive_Class_Diagram_HQ (1).pdf` / `ForsaDrive_ClassDiagram_Full.pdf` — confirm they were re-rendered from the **current** `forsadrive_class_diagram.puml` (the `.puml` was edited Jun 1 18:02; the older `ForsaDrive_Class_Diagram.pdf` is May 17). Apply F-21 first, then re-render.
2. `plantUML/ForsaDrive_SequenceDiagram_*.pdf` (PassengerBooksRide, DriverCreatesRide_v2, AdminVerifiesStudentID, AdminReviewsDriver) — confirm they match the current `.puml` and the implemented flows.
3. `plantUML/ForsaDrive_UseCaseDiagram.pdf`, `use case 1.png`, `use case 2.png`, `admin_helpdesk Agent.png`, `Organization View.png`, `System.png` — confirm they reflect F-19/F-20 fixes (no referral; refund scope).
4. The figures actually **inserted** in `ForsaDrive_Full_Report.docx` (all are placeholders `[ Insert Figure … ]` in the text — confirm the embedded images, terminology, and that “Support Agent”→“HelpDesk Agent” in any rendered legend).

---

## Area 5 — User flows traced end-to-end, per actor

### Passenger
**Works:** signup (`auth.dart:54`) → Student Verification by university-email OTP (`misc.dart:1036-1153`) → wallet deposit (`misc.dart:119-131`) → search (`rides.dart:10`) → book (`bookings.dart:10`) → confirmed → ride completes → rate driver (`misc.dart:432`). Public/limited driver profile pre-booking, full profile after confirmation (`book_ride.php:56-65`, `public_profile.dart`).
**Dead-ends / gaps:** student discount silently **not applied** on mobile single bookings (F-31); driver-reject **forfeits** the passenger’s money (F-25); web charges 100% (F-26).

### Driver
**Works:** apply (`driver_applications`, `misc.dart:671-705`) → Admin approves, auto-creating a `vehicle` (`misc.dart:955-974`) → offer ride (`rides.dart:93`) → accept/reject requests (`bookings.dart:123`) → depart → arrive (mobile, `rides.dart:244,420`) → driver credited online portion at arrival (`rides.dart:454-461`).
**Gaps:** on **web**, the ride lifecycle is only “Mark Complete” at booking level (`booking_requests.php:72`, `classes/rides.php:239`) — no depart/arrive, no driver payout (F-27); web `createRide` hardcodes `vehicle_id=1` (`classes/rides.php:304`).

### Admin
**Works (rich):** web console `Pages/admin.php` (1,800 lines) + Dart `adminRouter` (`misc.dart:722-997`): users, progressive sanctions warn/suspend/ban/lift (`misc.dart:829-880`), Student/Driver verification, Organizations, complaints, promo codes, announcements, audit log.
**Gap:** admin `cancel_ride` cancels ride + bookings but **issues no refund** (`misc.dart:927-932`), unlike driver-cancel (F-29).

### HelpDesk Agent
**Works:** ticket creation → bot FAQ answer or escalation (`misc.dart:1182-1206`, `bot.dart`); auto-assignment, one active ticket per agent, guarded cross-runtime UPDATE (`misc.dart:1294-1366`); agent notified (`misc.dart:1342`).
**Gaps:** the **Dart API has no resolve/close endpoint and no agent console** — `helpdeskRouter` only returns the *user’s own* tickets (`misc.dart:1167-1177`); the queue-drain helper is defined but “not currently called from a Dart route” (author’s own note, `misc.dart:1373-1378`). **Agent resolution is web-only** (`Pages/helpdesk.php`). Present mobile HelpDesk as user-side (ask/escalate); agent workflow as web.

### Organization / Student Verification
**Works:** Organization applies (`organizations.dart:32`) → Admin reviews (`organizations.dart:91`) → discount code generated → members managed. Student Verification: OTP email (mobile) + document/admin (web) — matches report §2.8.4.
**Gap:** the `active`/`approved` split breaks org discount codes cross-platform (F-10).

---

## Area 6 — PAYMENT FLOW (highest scrutiny)

**The money model, as actually implemented (Dart/mobile — the canonical one):**
1. **Deposit:** wallet top-up adds to `users.balance`, logs `payments(type='deposit')`, capped 5000 TND (`misc.dart:119-131`).
2. **Booking (upfront 50%):** `paidNow = price * seats * 0.5`, deduct from balance, `payments(type='charge')`, booking `status='pending'` (`bookings.dart:55-76`). Notify Driver.
3. **Acceptance:** Driver accept → `status='confirmed'` (`bookings.dart:153`). No money moves (already charged at step 2 ⇒ the “confirmed ⇒ paid” invariant holds; see F-39).
4. **Completion / payout:** Driver `/arrive` → booking `status='completed'`, ride `completed_at` stamped, **Driver credited the summed online 50%** as `payments(type='ride_earning')` (`rides.dart:440-461`).
5. **Remaining 50% (cash):** Driver marks cash received → `bookings.payment_status='cash_collected'` (`bookings.dart:320-322`). Receipt computes `cash_on_arrival = total − paid_online` (`rides.dart:338-339`).

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-25** | **Driver reject does not refund the passenger.** Mobile: `bookings.dart:155-161` sets `status='cancelled'` and returns — no balance refund, no `payments(refund)`. Web canonical `classes/rides.php:211-223` (`rejectBooking`) — same, no refund. Yet the passenger was charged at booking. The team’s **own activity diagram** mandates a refund here (`forsadrive_activity_1_booking_flow.puml:141`), and the legacy web API does it correctly (`web/api/bookings.php:148-152`). | **Blocker** | Code/Flow | On reject, refund `paid_amount` to `users.balance`, insert `payments(type='refund')`, notify. Mirror the passenger-cancel block (`bookings.dart:357-364`). | **MUST-FIX** |
| **F-26** | **Web charges 100% upfront, not 50%.** `web/Pages/book_ride.php:88-106`: `totalAmount = (student?price*0.5:price) * seats`; balance −= totalAmount; `paid_amount = totalAmount`. No 50% split. (Legacy `web/api/bookings.php:63-86` also full.) Mobile charges 50% (`bookings.dart:55`). Contradicts report §2.3.2 (“pay 50% online, 50% cash”) and §2.4 Portability (“same business rules”). | **Blocker** | Code/Flow | Either implement the 50% split on the web (`paid = total*0.5`, show cash-on-arrival) **or** explicitly scope the web as an admin/console channel and demo payment on mobile. | **MUST-FIX (decide)** |
| **F-27** | **Web never pays the driver.** `classes/rides.php:239-262` (`completeBooking`) marks completed + stamps `completed_at` but does not credit the driver. Combined with F-26, web money leaves the Passenger and reaches no one. Mobile pays out at `/arrive` (`rides.dart:454-461`). | Major | Code/Flow | If the web keeps real payments, credit the driver on completion; otherwise document web payments as non-settling. | MUST-FIX (decide with F-26) |
| **F-28** | **Web passenger-cancel issues no refund.** `classes/rides.php:226-235` (`cancelBooking`) just sets `cancelled`. Mobile refunds (`bookings.dart:357-364`). | Major | Code/Flow | Add the refund to `cancelBooking()` if it’s reachable from the UI; otherwise remove the dead method. | MUST-FIX (or remove) |
| **F-29** | **Admin `cancel_ride` issues no refund** (`misc.dart:927-932`), unlike driver-cancel which refunds every affected booking (`rides.dart:580-589`). | Major | Code/Flow | Reuse the driver-cancel refund loop in the admin path. | MUST-FIX |
| **F-30** | **Driver-cancel & passenger-cancel refunds are correct (mobile).** `rides.dart:573-607`, `bookings.dart:328-383` (cannot cancel after departure; refund + notify). | — (positive) | — | Keep; cite in defense. | CAN-STAY-AS-IS |
| **F-31** | **Student 50% discount not applied on mobile single bookings.** `bookings.dart:25` selects `is_student` but `paidNow` (`:55`) never uses it; group booking does (`:199-202`). Web applies it (`book_ride.php:88`). | Major | Code/Flow | Apply `price *= 0.5` for `is_student` in the single-booking handler (before the 50% split). | MUST-FIX |
| **F-32** | **No-show is not modelled anywhere** (no state, no penalty, no partial capture). | Minor | Flow | Present as Future Work; optionally a `no_show` booking status + forfeit rule. | PRESENT-AS-FUTURE-WORK |
| **F-33** | The web/legacy cash-collected path inserts `payments(type='earning', amount=paid_amount)` (`web/api/bookings.php:206`) even though the web already charged 100% online — double-counting. Dart’s cash-collected only flips `payment_status` (no ledger row). | Minor | Code | Resolved by removing `web/api/` (F-02) and aligning on the Dart model. | CAN-STAY-AS-IS |
| **F-50** | **Student AND organization discounts are illusory end-to-end — the discount is clawed back in cash.** The discount reduces only the online half (`bookings.dart` adjusts `price` → smaller `paid_amount`), but the remaining is computed from the **full** ride price: receipt `cash_on_arrival = r.price*seats − paid_amount` (`rides.dart:338-339`) and driver list `cash_due = r.price*seats − paid_amount` (`rides.dart:399`). A discounted Passenger pays the **full fare** overall (a student: ¼ online + ¾ cash). Confirmed during Phase 2 while validating F-31. | **Major** | Code/Report-text | Base the remainder on the discounted total: `cash_on_arrival = paid_amount`, `total_price = paid_amount*2` — single bookings only (valid because mobile always charges exactly 50% upfront; group/split has separate math). Bundle with F-31 + F-49 + F-13. | MUST-FIX (bundled discount step) |

**Defense one-liner (accurate):** “Payments are a **simulated wallet**. On mobile, the Passenger pre-pays **50%** at booking (held by the platform), the Driver is credited that 50% on arrival, and the remaining **50% is cash**, recorded via `payment_status`. Real payment-gateway settlement is Future Work.” *(Do not claim the 50% model for the web until F-26 is resolved.)*

> ⚠️ **Discount caveat (F-50).** Until the cash-side fix lands, **do not claim real discount savings** (student or organization) in the report or demo: the discount currently only changes the online/cash split, not the total the Passenger pays — they are billed the full fare in cash on arrival.

---

## Area 7 — Booking state machine (as found in code)

**Booking.status (both stacks):**
```
            (create, charged)            (driver accept)            (driver /arrive | web Mark Complete)
   ──────▶ pending ───────────────▶ confirmed ───────────────────▶ completed
              │                         │
              │ reject / cancel /        │ passenger cancel / ride cancel / admin cancel
              ▼                         ▼
           cancelled  ◀───────────────────
```
**Booking.payment_status:** `pending → cash_collected` (mobile only, `bookings.dart:320`). The online 50% never updates `payment_status`; it tracks only the cash half. Live values: `pending(9)`, `cash_collected(6)`.

**Ride.status (mobile):** `active ──/depart──▶ in_progress ──/arrive──▶ completed` ; `active ──/cancel──▶ cancelled`. `completed_at` stamped on `/arrive` (`rides.dart:440`).

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-34** | **Web rides never reach a terminal status.** Web completes at the *booking* level only and stamps `rides.completed_at` while leaving `rides.status='active'` (`classes/rides.php:239-262`). There is no `in_progress` on web. A web-completed ride can still appear bookable (`getAvailableRides` filters `status='active'`, `:57`). | Minor | Flow | On full completion, set `rides.status='completed'` on the web too; or document that the web omits the depart/arrive sub-states. | CAN-STAY-AS-IS (document) |
| **F-35** | UI offers an action the backend partially handles: the mobile booking *accept* gives no Passenger Notification (F-37); the activity diagram’s “Driver En Route” sub-state isn’t a real ride state (only depart→in_progress). | Minor | Flow/Diagram | Add accept-notification; collapse “En Route”/“Trip Started” to the single `in_progress` in diagrams. | MUST-FIX (notif) / CAN-STAY (diagram) |
| **F-36** | Impossible/legacy state: legacy web API only books `status='open'` rides (`web/api/bookings.php:49`) which no live ride is. | Minor | Flow | Removed with F-02. | CAN-STAY-AS-IS |

No truly orphaned states were found; `pending/confirmed/completed/cancelled` are all reachable and terminal-correct.

---

## Area 8 — Notifications: fired vs claimed

**Actually fired (Dart):** `booking_request` (`bookings.dart:79`), group booking request (`:257`), `booking_cancelled` (`:366`), ride `ride_update` departed (`rides.dart:277`) & arrived (`:465`), `ride_updated` on edit (`:537`), `ride_cancelled` + refund note (`:590`), `new_ride_match` from saved searches (`:131`), `message` (`misc.dart:325`), `org_approved`/`org_rejected` (`organizations.dart:116,131`), sanctions `warning`/`danger`/`success` (`misc.dart:836,854,866,877`), `driver_approved`/`driver_rejected` (`misc.dart:968,984`), HelpDesk auto-assign (`misc.dart:1342`).
**Actually fired (web):** new booking → driver (`book_ride.php:111`); accept/reject/complete → passenger (`booking_requests.php:46-83`); admin actions.

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-37** | **Mobile booking *accept* sends no Notification to the Passenger.** `bookings.dart:136-154` updates status only. The web does notify (`api/bookings.php:145`, `booking_requests.php:46-52`). | Major | Code/Flow | Insert a `booking_confirmed` Notification in the Dart accept branch. | MUST-FIX |
| **F-38** | **FCM push is scaffolded but non-functional end-to-end.** The Flutter client fully integrates `firebase_messaging` and posts its token to `POST /users/fcm-token` (`notification_service.dart:155`) — **but that endpoint does not exist** on the Dart API (`publicProfileRouter` has only `/search`, `/<id>/limited-profile`, `/<id>/full-profile`). No backend ever *sends* a push; both stacks only INSERT into `notifications`. Report lists FCM as delivered tech (§2.9.3, acronyms). | Major | Code/Report-text | Present notifications as **in-app (polled)**; mark FCM push “scaffolded, server push = Future Work.” (Or add the token endpoint + a server send.) | PRESENT-AS-FUTURE-WORK |

**Claimed-but-thin:** report mentions “PHPMailer for email notifications and verification codes” (§2.9.3) — there is **no SMTP**: OTP is printed to the server console (`misc.dart:1025-1029`) and reset tokens are shown on screen / returned in the API (`auth.dart:135-137`, `INTEGRATION_GUIDE.md:215-219`). See F-46.

---

## Area 9 — Security & privacy

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-39** | **Public-profile gating is enforced server-side, correctly.** `canViewFull` grants full profile only to self / Admin / HelpDesk Agent / a counterpart on a `confirmed`/`completed` booking, within a 48-h post-completion window (`profile_access.dart:9-49`); the endpoint returns **403 + limited payload** when not allowed (`public_profile.dart:65-76`). The **“confirmed ⇒ paid” invariant holds**: payment occurs at booking creation (`status='pending'`), so any `confirmed`/`completed` booking is necessarily paid. | — (positive) | — | Cite as a strength in defense. | CAN-STAY-AS-IS |
| **F-40** | **Third-party identity is shielded behind `public_id`.** Limited/full profiles, ratings-received, and reports all key on `public_id` and never expose the target’s internal `id` (`public_profile.dart`, `misc.dart:378-411`, `reports.dart`). Reviewer shown by first name only (`misc.dart:407`). | — (positive) | — | Cite as a strength. | CAN-STAY-AS-IS |
| **F-41** | **“No internal IDs leak anywhere” is overstated.** `userToJson` returns the caller’s own `id` (`helpers.dart:163`), and operational endpoints return `ride_id`, `booking_id`, `driver_id`, `passenger_id` (e.g. `bookings.dart:103-113`, `rides.dart:170-182`) because the app addresses resources by numeric id. | Minor | Report-text | Scope the claim: “**third-party user identity** is referenced only by `public_id`; resource handles (ride/booking) use numeric ids.” | MUST-FIX (report wording) |
| **F-42** | CORS is fully open: `Access-Control-Allow-Origin: *` (`helpers.dart:35`, `server.dart:32`). Acceptable for a local demo, but note it. | Minor | Code | Restrict to known origins for any non-local deployment; mention in the security section. | CAN-STAY-AS-IS |
| **F-43** | Password policy inconsistency: Dart signup enforces only length ≥ 8 (`auth.dart:65`), while reset enforces upper+digit (`auth.dart:10-15`) and the web signup enforces the strong policy. | Minor | Code | Apply `_passwordPolicyError` in `auth.dart` signup too. | CAN-STAY-AS-IS |

**Positives:** bcrypt password hashing shared across stacks; hashed, single-use, 60-minute reset tokens (`auth.dart:114-162`, `classes/password_reset.php`); progressive sanctions block at the auth layer on every request (`helpers.dart:64-99`); server-side CAPTCHA on web signup (`signup.php:56-62`); ratings rules fully server-enforced (ride completed, opposite-side participants, one-per-ride) with a UNIQUE hard guard (`misc.dart:441-495`).

---

## Area 10 — Web vs mobile parity & terminology

| Capability | Mobile (Dart/Flutter) | Web (PHP) | Finding |
|---|---|---|---|
| Booking charge | **50% upfront** (`bookings.dart:55`) | **100% upfront** (`book_ride.php:89`) | **F-26** (Blocker) |
| Refund on driver reject | ✗ (`bookings.dart:155`) | ✗ (`classes/rides.php:211`) | **F-25** (Blocker) |
| Driver payout | ✓ at arrival (`rides.dart:454`) | ✗ (`classes/rides.php:239`) | **F-27** |
| Student discount | group only (`:202`); single ✗ | single + group ✓ (`book_ride.php:88`) | **F-31** |
| Org discount code at booking | ✓ (`bookings.dart:44`) | canonical `book_ride.php` has **no promo field**; only legacy API | F-10 / parity |
| Org approval value | `approved` | `active` | **F-10** |
| Group booking | ✓ rich (members/split, `bookings.dart:164`) | ✗ in `Pages/` (legacy API only) | parity gap |
| Ride lifecycle | depart→arrive (`in_progress`) | Mark Complete only | F-34 |
| Live GPS tracking | ✓ (`ride_locations`, `live_tracking_screen.dart`) | ✗ (Leaflet static map) | parity (mobile-only) |
| HelpDesk resolution | ✗ (no resolve/close, `misc.dart:1373`) | ✓ (`Pages/helpdesk.php`) | F (agent web-only) |
| Notifications | in-app; accept-notif missing (F-37) | in-app; accept-notif present | F-37 |
| Currency label | TND | **DZD** on `booking_requests.php:259,336` | **F-44** |

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-44** | Wrong currency label on the web: `… DZD` (Algerian dinar) in `web/Pages/booking_requests.php:259,336`, while the rest of the app uses **TND** (Tunisian dinar). Embarrassing for a Tunisia-focused PFE. | Cosmetic | Code | Replace `DZD` → `TND`. | MUST-FIX (trivial) |
| **F-45** | Terminology drift: report uses “Support Agent” (§2.2.6); code/diagrams use **HelpDesk Agent** (`is_helpdesk_agent`, `forsadrive_use_case.puml:175`). “Administrator” vs canonical “Admin” is acceptable but standardise. SupportTicket = `helpdesk_conversations` (the report’s “support request”). | Minor | Report-text | Global replace “Support Agent” → “HelpDesk Agent”; note SupportTicket = HelpDesk ticket. | MUST-FIX (report) |

---

## Area 11 — Report-vs-reality consistency (the key defense table)

> The report **scopes itself to Sprint 1** (Abstract; §General Conclusion) and presents rides/payment/community as later sprints. The **code implements all four sprints.** So the main risk is the report *under-claiming* implemented features and a few *over-claims*. Marks: **CONFIRMED** (cite) / **PARTIAL** / **NOT IMPLEMENTED**.

| Report claim | Status | Evidence |
|---|---|---|
| Account creation, login, secure session | **CONFIRMED** | `auth.dart:23-92`; `web/Pages/login.php`, `signup.php` |
| Password hashing (secure) | **CONFIRMED** | bcrypt `helpers.dart:112-122`; PHP `password_hash` |
| Forgot/reset password (§2.2) | **CONFIRMED** (no SMTP) | `auth.dart:114-162`; token shown on screen |
| Server-side form validation, CAPTCHA | **CONFIRMED (web)** | `signup.php:56-62`; mobile has none |
| Student Verification + 50% discount | **PARTIAL / illusory savings** | OTP `misc.dart:1036-1153`; discount only shifts online↔cash, **full fare still paid** (**F-50**); also self-declarable at web signup (**F-49**) |
| Driver profile + vehicle + admin approval | **CONFIRMED** | `misc.dart:671-705,955-974` |
| Publish ride / search / filters | **CONFIRMED** | `rides.dart:10-145`; `classes/rides.php:55` |
| Booking + group booking | **CONFIRMED** (group mobile-only) | `bookings.dart:10,164` |
| **Pay 50% online + 50% cash** | **CONFIRMED (mobile) / NOT on web** | `bookings.dart:55` vs `book_ride.php:89` (**F-26**) |
| Refund on cancellation | **PARTIAL** | mobile cancel ✓; reject/admin/web ✗ (**F-25/F-28/F-29**) |
| Wallet recharge / balance | **CONFIRMED (simulated)** | `misc.dart:119-131` |
| “Same backend, **same business rules**” (Portability, §2.4) | **NOT TRUE** | payment rules diverge web vs mobile (**F-25/26/27/31**) |
| Ratings + reputation | **CONFIRMED** | `misc.dart:432-504`; `UNIQUE(ride_id,from_user_id,to_user_id)` |
| Complaints / report a user | **CONFIRMED (two systems)** | `complaints` + `reports` (**F-07**) |
| Admin: users, sanctions, orgs, promos, announcements, audit log | **CONFIRMED** | `Pages/admin.php`; `misc.dart:722-997` |
| Progressive sanctions (warn→suspend→ban, auto-lift) | **CONFIRMED** | `misc.dart:829-880`; `helpers.dart:64-99` |
| Organizations + discount codes | **CONFIRMED (codes work; savings illusory)** | `organizations.dart`; cross-platform vocab **F-10**; discount clawed back in cash **F-50** |
| HelpDesk chatbot + escalation + agent | **PARTIAL** | bot+escalate+auto-assign ✓; agent resolution **web-only** (`misc.dart:1373`) |
| Notifications | **CONFIRMED (in-app)** | tables + inserts; accept-notif missing on mobile (**F-37**) |
| **FCM push notifications** | **PARTIAL (scaffold only)** | `notification_service.dart`; no server send, endpoint missing (**F-38**) |
| **PHPMailer email / email codes** | **NOT IMPLEMENTED** | OTP to console `misc.dart:1028`; no SMTP (**F-46**) |
| Leaflet maps (web) | **CONFIRMED** | Leaflet referenced in web pages |
| Multilingual EN/FR/AR + RTL | **CONFIRMED** | `web/lang/{en,fr,ar}.php`; Flutter `l10n/`, `locale_provider.dart` |
| Real-time GPS tracking (report: “future”) | **IMPLEMENTED (basic)** | `ride_locations`, `rides.dart:613-763`, `live_tracking_screen.dart` — upgrade the claim |
| Ride boosting (tiered) | **CONFIRMED** | `feed_posts.boost_*`, `boost_tiers.dart` |
| Driver analytics / reliability | **CONFIRMED** | `analytics.dart`; match score `rides.dart:770-815` |
| Recommendations / smart matching | **CONFIRMED (heuristic)** | `recommendations.dart`; `_computeMatchScore` |
| ML face validation (profile photo) | **PARTIAL (mock default)** | `face_validation.dart:94-109` filename-based mock; real ML needs `FACE_API_URL` (**F-47**) |
| Referral codes | **NOT IMPLEMENTED** | columns only; no generation/redemption (**F-19**) |
| DB relational model as in §3.7 | **DOES NOT MATCH** | report `users(first_name,last_name,role,language,status)`, `vehicles→driver_profiles`, `student_verifications(method,university_email,verification_code…)`, `notifications(content)`, `audit_logs(details)` — none match live (**F-48**) |
| Role model `role ∈ {passenger,driver,admin,organization}` (§3.7 SQL) | **DOES NOT MATCH** | no `role` column; boolean flags; omits HelpDesk Agent & Student (**F-21/F-48**) |

| ID | Evidence | Sev | Cat | Proposed fix | Verdict |
|----|----------|-----|-----|--------------|---------|
| **F-46** | PHPMailer/email is claimed (§2.9.3) but not wired: OTP printed to console (`misc.dart:1025-1029`); reset token shown on screen (`auth.dart:135-137`). | Major | Report-text | Describe email/SMS as **simulated** (codes shown in-app/console); real delivery = Future Work. | MUST-FIX (report) |
| **F-47** | “ML face validation” is a **mock by default** — `FaceValidationService.fromEnv()` returns `MockFaceDetector` unless `FACE_API_URL` is set; the mock infers face count from the **filename** (`face_validation.dart:94-109,157-164`). The code is honest (clearly labelled), but a demo/report must not call it real ML. | Major | Report-text/Code | Present as “a pluggable face-validation pipeline with a mock detector; production plugs in a real ML provider.” | PRESENT-AS-FUTURE-WORK |
| **F-48** | Report §3.7 relational model & SQL extract **do not match the implemented schema** (column names, `role` enum, `vehicles → driver_profiles` FK, `student_verifications` fields). An examiner who opens the DB will see the mismatch. | **Major** | Report-text/DB | Replace §3.7 with the real schema (see Section C). | **MUST-FIX (report)** |

---

## Area 12 — Writing quality (report)

Issues found (drop-in rewrites in **Section D**):
- **Contradiction:** §2.4 “same backend and the same business rules” vs the payment divergence — must be softened/qualified (the single most dangerous sentence in the report).
- **Over-claim:** §2.9.3 lists FCM + PHPMailer as delivered technologies; both are simulated/scaffolded.
- **Stale schema prose:** §3.7 relational model uses fields the system doesn’t have.
- **Terminology:** “Support Agent” (use HelpDesk Agent); “Administrator” (standardise to Admin); GPS described as “future” though implemented.
- **Vague scenarios:** §2.8.3 “Book a Ride” says “pays the online part” without stating the 50% rule or what happens on reject; §3.4.1 mentions CAPTCHA + age check that exist only on web — qualify “(web)”.
- Minor: the report mixes “trip/route/ride” — standardise on **Ride**; “reservation/booking” — standardise on **Booking**.

---

# A. Detected problems — triaged summary

**Blockers (3):** F-25 (reject no refund), F-26 (web 100% not 50%), and the latent F-09 (`setup_db.php` would corrupt the DB).
**Majors (~16):** F-01, F-10, F-13, F-19, F-20, F-21, F-23, F-27, F-28, F-29, F-31, F-37, F-38, F-46, F-47, F-48.
**Minors (~15):** F-02, F-03, F-04, F-06, F-07, F-08, F-11, F-12, F-14, F-15, F-16, F-32, F-34, F-41, F-42, F-43, F-45.
**Cosmetic (4):** F-05, F-17, F-44, (report wording items).

Full detail and evidence in Areas 1–12 above.

---

# B. Correction for each problem (code/DB — for Phase 2 approval)

Grouped; each ties to a finding ID. *Surgical edits only — no redesign.*

1. **F-25 (Blocker):** In `bookings.dart` reject branch (155-161), before/after setting `cancelled`, refund `paid_amount` to `users.balance`, insert `payments(type='refund', 'Refund for rejected Booking #N')`, and add a `booking_cancelled`/`booking_rejected` Notification. Mirror lines 357-375. Apply the same to `classes/rides.php::rejectBooking` (211-223) if the web keeps real payments.
2. **F-26/F-27 (Blocker/Major):** Decide ownership. *Recommended:* keep payments **mobile-canonical**; in `web/Pages/book_ride.php:88-106` change `paid_amount`/deduction to `total*0.5` and show “50% now / 50% cash on arrival”, and credit the driver in `completeBooking()`. *Alternative:* document the web as a non-settling admin/console channel and demo payment on mobile.
3. **F-28:** Add the refund to `classes/rides.php::cancelBooking` (226-235), or delete it if unreachable.
4. **F-29:** In `misc.dart` `cancel_ride` (927-932), reuse the driver-cancel refund loop from `rides.dart:580-589`.
5. **F-31:** In `bookings.dart` single-booking handler, apply `if (user['is_student']==1) price *= 0.5;` before the `*0.5` upfront split.
6. **F-37:** Add a `booking_confirmed` Notification to the Dart accept branch (`bookings.dart:153`).
7. **F-09:** Rewrite `setup_db.php` DDL to mirror `db.dart` (drop the contradicting CHECKs) **or** add a guard that refuses to run against the shared file; stamp `DEPRECATED`.
8. **F-10:** Standardise org status on `approved`/`rejected`/`pending`: change `web/Pages/admin.php:152` to write `approved` and fix the badge `match()` (`:1490`); the legacy `web/api/bookings.php:20,72` becomes moot once F-02 removes it.
9. **F-11:** Add `CREATE INDEX IF NOT EXISTS` for `bookings(ride_id,status)`, `bookings(passenger_id)`, `rides(driver_id,status)`, `rides(status)`, `notifications(user_id,is_read)`, `messages(conversation_id,created_at)`, `ratings(to_user_id)` in `db.dart _createSchema()`.
10. **F-13:** Key the verified-student badge on `is_student` in `profile_access.dart:75` (or set `is_student_verified=1` in `misc.dart:1141` and `admin.php:240`).
11. **F-44:** `web/Pages/booking_requests.php:259,336` `DZD` → `TND`.
12. **F-02/F-04:** Remove/quarantine `web/api/*.php`; gitignore + untrack `forsa_drive_flutter/node_modules`.
13. **F-18/F-21 (Diagram):** Update `forsadrive_class_diagram.puml` enums/relations and the schema annotation; re-render the PDFs.

*(Report-text corrections F-24/F-41/F-45/F-46/F-47/F-48 are in Sections C/D — paste into the report yourself.)*

---

# C. Improved final scenarios (clean, canonical terminology)

### C.1 Use Case “Book a Ride” (replaces report §2.8.3 nominal/alternative)
> **Nominal scenario.** The Passenger searches for a Ride, opens its details, and confirms the number of seats. The system computes the fare (applying the Student Verification 50% discount and any Organization discount code), charges **50% to the Passenger’s wallet** as a deposit, and creates a Booking with status *pending*. The Driver receives a Notification. When the Driver accepts, the Booking becomes *confirmed* and the Passenger is notified. On the trip day the Driver marks departure and arrival; on arrival the Booking becomes *completed*, the Driver is credited the deposit, and the Passenger pays the **remaining 50% in cash**, which the Driver confirms.
> **Alternative scenarios.** If the wallet balance is below the 50% deposit, the system asks the Passenger to top up. If seats are no longer available, the Booking is refused. **If the Driver rejects the request, the 50% deposit is refunded to the Passenger’s wallet** and the Passenger is notified.

### C.2 Payment lifecycle (new, accurate subsection)
> ForsaDrive uses a **simulated wallet** (no external gateway in this version). A Booking charges **50%** of the fare upfront, held by the platform; the Driver is credited this amount upon arrival; the remaining **50% is paid in cash** and recorded as the Booking’s payment status. Cancellation by either party before departure, and rejection by the Driver, refund the deposit. Real gateway settlement and a formal escrow account are **Future Work**.

### C.3 Corrected relational model (replaces report §3.7 — matches the live schema)
> `users(id, username, email, password, Region, is_driver, is_student, is_admin, is_helpdesk_agent, is_student_verified, balance, score, picture, phone, bio, gender, governorate, public_id, suspended, suspended_until, ban_reason, warnings_count, created_at)`
> `vehicles(id, user_id→users, type, make, model, year, color, plate_number, seats, has_ac, has_wifi, luggage, verified, created_at)`
> `rides(id, driver_id→users, vehicle_id→vehicles, from_location, to_location, departure_time, price, available_seats, status, completed_at, created_at)`
> `bookings(id, ride_id→rides, passenger_id→users, seats, paid_amount, status, payment_status, created_at)`
> `payments(id, user_id→users, amount, type, description, created_at)` — *per-user wallet ledger (no per-booking FK)*
> `ratings(id, ride_id→rides, from_user_id→users, to_user_id→users, score, comment, created_at, UNIQUE(ride_id, from_user_id, to_user_id))`
> `student_verifications(id, user_id→users, document_path, status, created_at)` + `student_otp_codes(...)` for email OTP
> `helpdesk_conversations(id, user_id→users, agent_id→users, subject, status, assignment_method, assigned_at, created_at)` (**SupportTicket**)
> `organizations(id, user_id→users, name, type, contact_person, contact_email, staff_email_domain, discount_percent, discount_code, status∈{pending,approved,rejected}, created_at)`
> Note: roles are modelled as **boolean flags**, not a single `role` enum (a Driver is also a Passenger; a user may be a Student and an Admin).

### C.4 HelpDesk (clarify channel split)
> Users open SupportTickets from web or mobile; a chatbot answers common questions and escalates to a human on request or on no-match. Tickets are **auto-assigned** to the least-loaded HelpDesk Agent (one active ticket per agent). **Agents resolve tickets from the web console**; the mobile app is the user-facing channel (ask, escalate, follow status).

---

# D. Better report paragraphs (drop-in replacements)

**D.1 — §2.4 “Portability” (remove the false equivalence):**
> *Current:* “the platform must be accessible through web and mobile interfaces relying on the same backend and the same business rules.”
> *Replace with:* “The platform is accessible through a web interface and a mobile application that **share a single database and user base**. Core domain rules (authentication, verification, booking states, ratings) are common; the **payment workflow is currently most complete on the mobile channel** (50% online + 50% cash with refunds), and aligning the web channel to the identical money rules is part of finalization.”

**D.2 — §2.9.3 (technologies — stop over-claiming):**
> *Replace the FCM/PHPMailer sentence with:* “Notifications are delivered **in-app** (stored per user and retrieved by the client). Push delivery via Firebase Cloud Messaging is **scaffolded on the client** and planned for production. Email/SMS delivery (verification codes, password-reset links) is **simulated** in this version — codes are surfaced in-app rather than sent over SMTP; integrating PHPMailer/an SMS gateway is Future Work.”

**D.3 — §3.7 intro (point at the real schema):**
> *Replace:* “Full schema lives in `web/setup_db.php`.” / §3.7 model → use **Section C.3** above. Add: “The authoritative schema is created by the shared backend (`forsa_drive_api/lib/db.dart`); the web layer adds compatibility columns at runtime.”

**D.4 — Actor list (§2.2.6) and global replace:**
> Rename “Support Agent” → **“HelpDesk Agent”** throughout. “A **HelpDesk Agent** handles SupportTickets escalated from the chatbot: viewing the ticket queue, replying to users, resolving or reopening tickets.”

**D.5 — Face validation (if mentioned / for the demo):**
> “Profile photos pass a **face-validation pipeline** that accepts an image only when exactly one face is detected. The detector is pluggable: a deterministic mock is used in development, and a real ML face-detection service can be enabled in production via configuration.”

**D.6 — GPS (upgrade from future to done):**
> “**Basic real-time GPS tracking is implemented**: the Driver streams location during an in-progress Ride and confirmed Passengers can follow it live. A production-grade tracking service (battery optimization, map-matching) remains Future Work.”

---

# E. Pre-defense recommendations (likely examiner questions + answers)

1. **“Walk me through the money. Where does the 50% go, and what about the other 50%?”**
   → Mobile: 50% deducted from the Passenger wallet at booking (held), Driver credited it on arrival, 50% cash recorded via `payment_status`. **Before defense, fix F-25/F-26** so you can say this consistently for both channels — or state plainly “payment is demonstrated on mobile; the web is the admin/console channel.” Never claim identical payment rules until F-26 is done.
2. **“What happens if the Driver rejects my booking — do I get my money back?”** → This is the trap. **Fix F-25 first**; then: “Yes, the deposit is auto-refunded to the wallet.” Your own activity diagram already specifies this.
3. **“Do students actually get the discount?”** → **Currently no.** The discount only changes the online/cash split; the **full fare is still collected** (¾ in cash for a student) because the remainder is computed from the undiscounted ride price (F-50). The bundled discount step (F-31 upfront + F-50 cash-side + F-49 verification-gating + F-13) makes the saving real and verified. **Do not claim discount savings until then** — the same clawback affects organization discounts.
4. **“Show me your database schema.”** → Open the **live DB** / `db.dart`, not `setup_db.php`. Use the Section C.3 model in the report so they match. Be ready to explain roles are flags, not an enum.
5. **“How do you protect user privacy / identity?”** → Strong story (F-39/F-40): server-side limited-vs-full gating, 48-h window, `public_id` for third parties, ratings by first name only. Just **scope the “no internal IDs” claim** (F-41).
6. **“Is the face check / push notification / email real?”** → Be honest (F-38/F-46/F-47): in-app notifications are real; FCM push and email are scaffolded/simulated; face validation is a pluggable pipeline with a mock detector. Academic integrity scores better than a claim that collapses under one question.
7. **“Web and mobile — one system or two?”** → One shared SQLite DB, two channels (PHP web + Dart API). Show `database.php:10-12`. Acknowledge payment alignment is the finalization item.
8. **“Indexes / performance?”** → Apply F-11 so the claim in §2.4 is backed by real indexes on the live DB.

---

# F. MUST-CHANGE vs CAN-STAY summary

| Verdict | Findings | One-line reason |
|---|---|---|
| **MUST-FIX — code/DB** | **F-25** (reject refund), **F-26/F-27** (web 50% + payout, or scope it), **F-29** (admin-cancel refund), **F-31** (student discount mobile), **F-37** (accept notification), **F-09** (guard `setup_db.php`), **F-10** (org status vocab), **F-11** (indexes), **F-13** (verified badge), **F-44** (DZD→TND), **F-04** (untrack node_modules) | These break under examiner questions or corrupt the DB |
| **MUST-FIX — report/diagram** | **F-48** (real schema in §3.7), **F-46** (email simulated), **F-21/F-18** (class diagram enums/relations + annotation), **F-24/F-45** (HelpDesk Agent; GPS implemented), **F-41** (scope the ID-privacy claim) | Report/diagrams must match reality |
| **PRESENT-AS-FUTURE-WORK** | **F-19** (referral), **F-32** (no-show), **F-38** (FCM push send), **F-47** (real ML face), **F-07** (merge complaints/reports), web payment settlement if not aligned | Honest scoping beats fake completeness |
| **CAN-STAY-AS-IS** | **F-02/F-03/F-05/F-06/F-08/F-12/F-14/F-15/F-16/F-17/F-28(if unreachable)/F-30/F-33/F-34/F-42/F-43** | Cosmetic, dead, or correct-as-designed |

---

## Appendix — what I could not verify (needs your eyes)
- The **rendered** diagram images/PDFs and the figures embedded in the `.docx` (all figure slots in the report are `[ Insert Figure … ]` placeholders). Re-render after F-18/F-21/F-19/F-20 and confirm legends say **HelpDesk Agent**.
- Whether `web/Pages/my_rides.php` exposes a passenger “cancel” button that reaches the no-refund `cancelBooking()` (F-28) — confirm the UI path before deciding fix-vs-delete.
- The Flutter UI strings for currency/terminology (I audited backend + web; spot-check `forsa_drive_flutter/lib/screens/**` for any `DZD`/“Support Agent”).

*End of Phase 1 audit. No code changed. Tell me which finding IDs to apply in Phase 2.*
