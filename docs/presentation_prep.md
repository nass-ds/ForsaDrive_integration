# ForsaDrive — PFE Defense Prep (20 min)

## Pitch (one sentence)
ForsaDrive is a student-focused carpooling SOCIAL NETWORK with two channels sharing one
database: a Flutter mobile app (passengers & drivers) and a PHP web admin console,
backed by a Dart REST API. Beyond ride booking it has a ranked community feed,
monetized boosting, and group rides.

**Real stack:** Flutter/Dart · Dart Shelf API (bcrypt, sqlite3) · PHP web · SQLite.
(Ignore the README's React/Node/PostgreSQL — that was the old plan.)

---

## Part A — Examiner Q&A (honest, defensible answers)

**Q1 — Walk me through the money (the 50%).**
Mobile: 50% of fare deducted from passenger wallet at booking (held); driver credited
it on arrival; remaining 50% settled as cash via booking payment status. Payment is
demonstrated on mobile; web is the admin/console channel. (Never claim identical rules
on both.)

**Q2 — If the driver rejects, do I get my money back?**
Yes — on the mobile flow the held deposit is auto-refunded to the wallet on
cancellation. (Verify in build first; if unsure, scope it to mobile driver-cancel /
passenger-cancel paths.)

**Q3 — Do students actually get a discount?**
Student verification by university-email OTP; discount via verified-student/org codes.
⚠️ Test before claiming real savings — audit flagged the discount only shifted
online/cash split. If unconfirmed, present as the verification + code mechanism.

**Q4 — Show me the schema.**
Open the LIVE DB / db.dart (NOT setup_db.php — it's stale). Roles are boolean flags
(is_driver/is_student/is_admin/is_helpdesk_agent), not an enum — a user can hold
several roles.

**Q5 — How do you protect privacy/identity?**
Server-side. Limited driver profile before booking; full profile after confirmation
(48h window). Third parties referenced by public_id, never internal IDs. Ratings show
first name only.

**Q6 — Is the face check / push / email real?**
In-app notifications are real (stored in DB). FCM push and email are scaffolded/
simulated for the demo. Face validation is a pluggable ML Kit pipeline with a mock
detector — architecture real, trained model is future work.

**Q7 — Web and mobile: one system or two?**
One system, two channels. Single shared SQLite DB; PHP web and Dart API both open the
same file (show database.php + db.dart). ← strongest answer.

**Q8 — Indexes / performance?**
High-traffic tables (rides, bookings, notifications, messages, ratings) have indexes
on lookup columns, created idempotently in schema setup.

**Q9 — Hardest part / what you learned?**
Keeping two backends (PHP + Dart) consistent on one shared DB → one canonical source
of truth per concern (Dart API owns payment logic), aligned status vocab & migrations.

**Q10 — Future work?**
Referral codes, trained ML face model, real FCM push, full web payment settlement,
consolidate report/complaint subsystems.

---

## Part B — Slide text (paste-ready)

**1. Title** — ForsaDrive — Student Carpooling Platform · [name] · [supervisor] · 2026

**2. Problem** — Students need affordable, trusted transport · existing apps expensive,
no student focus, weak trust/safety · need low-cost rides + verification + moderation.

**3. Solution** — Student-focused carpooling · two channels one system (Flutter app +
PHP web admin) · built-in trust: verification, ratings, sanctions, privacy gating.

**4. Architecture (WOW)** — Flutter ⟶ Dart REST API ⟶ shared SQLite DB ⟵ PHP web ·
one DB, two backends, real-time consistency · stack: Flutter/Dart, Dart API, PHP, SQLite.

**5. Actors** — Passenger · Driver · Admin · HelpDesk Agent · Organization · roles
non-exclusive.

**6. Core flow** — Search → Book (50% held) → Driver accepts → Live GPS → Arrive →
Driver credited → Rate.

**7. Demo (Passenger)** — search & book · wallet deduction · live GPS on map.

**8. Demo (Driver)** — accept → depart → arrive → credited.

**9. Demo (Admin web)** — same user/ride in console · verify student / apply sanction ·
audit log.

**10. Standout features** — privacy gating (limited→full, public_id, first-name ratings)
· progressive sanctions (warn → auto-expiring suspend → ban) · smart HelpDesk (bot FAQ →
escalation → auto-assign) · live GPS · face validation (ML Kit) · trilingual AR/FR/EN + RTL.

**10b. Community / social layer (WOW)** — ranked community feed (3 post types: driver
offer / passenger request / group ride) with likes & comments · feed RANKING algorithm
(boosted +200, same-governorate +50, reliability score×10, recency decay −3/hr) ·
tiered BOOST monetization (paid, time-limited, auto-expires) · group booking with split
payment · saved searches / ride alerts · trip receipts · advanced search filters
(price/rating/date/seats/amenities).

**11. Data model** — Users, Rides, Bookings, Payments (wallet ledger), Ratings,
Organizations, Notifications, SupportTickets · FK integrity + indexes.

**12. Challenges/lessons** — two backends one DB → single source of truth per concern ·
aligned status vocab & migrations · server-side privacy/permissions.

**13. Future work** — referral · trained ML face · real FCM push & email · web payment
settlement.

**14. Conclusion** — trust-first dual-channel student carpooling platform · thank you · Q&A.

---

## Timing (20 min)
Title 0:30 · Problem 1:30 · Solution 1:00 · Architecture 2:00 · Actors 1:30 ·
Core flow 2:00 · DEMO 6:00 · Standout 2:00 · Data model 1:30 · Challenges 1:00 ·
Future 0:30 · Conclusion 0:30.

## Demo = one connected story (rehearse + record backup video)
Passenger books → Driver accepts/arrives → Passenger rates → Admin sees same data,
applies sanction/verification, shows audit log. Proves shared-DB dual-channel live.
Seed data first with web/seed_demo.php.
