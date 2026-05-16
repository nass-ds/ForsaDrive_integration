# ForsaDrive — Defense Presentation Notes

Companion doc for `ForsaDrive_Defense.pptx`. Use this to prepare what to
say on each slide, run the demo, and survive the Q&A.

---

## Time budget (~17 min total + 5–10 min Q&A)

| Slides | Section | Target time |
|---|---|---|
| 1 | Title (intro yourselves) | 30 s |
| 2 | Outline | 30 s |
| 3–4 | Context + company | 1 min 30 s |
| 5–6 | Existing solutions + ForsaDrive | 1 min 30 s |
| 7 | Methodology (Scrum) | 1 min |
| 8–10 | Architecture + class + use case | 2 min 30 s |
| 11–14 | Four sprints (1 min each) | 4 min |
| 15–17 | Screens + mobile features + stack | 2 min |
| 18 | Tests | 45 s |
| 19 | **LIVE DEMO** | 3 min |
| 20 | Limitations + roadmap | 1 min |
| 21 | Thanks | 15 s |

If you run long, the safest cut is slides 16 (mobile features) and 17
(stack) — the jury can read them; you don't need to narrate them.

---

## Per-slide talking points

### Slide 1 — Title
> "Bonjour, je suis Youssef, et voici Anas. Nous allons vous présenter
> ForsaDrive, notre projet de fin d'études réalisé chez ATOMIC IT
> sous la supervision de Mr. Khalil Selmi et Mme Ines Ben Nasr."

### Slide 2 — Outline
Just walk it: "Nous allons d'abord poser le contexte, puis présenter
les solutions existantes, notre proposition, la méthodologie Scrum,
la conception, la réalisation sprint par sprint, les tests et la
démonstration, et enfin la conclusion."

### Slide 3 — Mobility problem
Two anchors: students/workers travel a lot but have small budgets, and
private cars travel half empty. The combination is a market gap that
nobody is filling well in Tunisia.

### Slide 4 — ATOMIC IT
Brief. The point is: they helped you with both technical supervision
and the agile process. Don't dwell — the jury cares about the project,
not the company.

### Slide 5 — Existing solutions
**Key sentence**: "We don't claim carpooling is a new idea — we claim
that no existing option combines local fit with structured trust."

Walk one row of the table: "Look at student discount — none of these
have it. Wallet-based balance — none of these have it. That's where
we positioned ForsaDrive."

### Slide 6 — Proposed solution
The three pillars (Local fit, Trust, Smart features) are the structure
of every claim you make later. The KPIs at the bottom are your numbers
to memorize: **3 apps, 4 sprints, 20+ entities, 3 languages**.

### Slide 7 — Scrum
**This is the slide your supervisor cares about** (remember the email).
Walk the timeline: Sprint 0 was preparatory, then each sprint produced
a working increment, grouped into two releases. Mention daily stand-ups
and weekly reviews.

### Slide 8 — Architecture
"Two clients, one backend, one database. The business rules live in
the backend, so they can't be bypassed by editing the mobile app."

### Slide 9 — Class diagram
Don't read every class. Say: "Twenty-something entities organized into
five logical groups — users and profiles, trips and bookings, payments
and ratings, verification and discounts, and communication and
moderation. The diagram is detailed in the report."

### Slide 10 — Use case
Same idea: don't enumerate. Say: "Four actors — passenger, driver,
admin, and the system itself for automated operations like discount
calculation and reliability scoring."

### Slides 11–14 — Sprints
Same template each time:
1. Read the **mission** (left rail).
2. Pick **two backlog rows** to highlight (don't read all 8).
3. Read the **deliverables** bullets (those are the takeaways).

**Sprint 3 talking point**: mention the price-pipeline bug caught at
the sprint review — it shows you actually do reviews and don't just
ship blindly.

**Sprint 4 talking point**: chat is **3-second polling, no WebSocket**
— a deliberate trade-off, not a limitation.

### Slide 15 — Selected screens
Walk left to right: auth → search with match score → trip detail with
map → driver dashboard with reliability gauge.

### Slide 16 — Mobile-specific
Quickly: push (FCM), interactive map (OSM + OSRM), in-app chat,
multilingual with automatic RTL for Arabic.

### Slide 17 — Stack
**One sentence per column**:
- Web: PHP + Bootstrap, server-rendered.
- Backend: PHP REST API + bearer tokens + SQLite WAL.
- Mobile: Flutter with Provider + go_router + FCM.

### Slide 18 — Tests
"Tests were continuous, not deferred. Every scenario in the table was
verified end-to-end."

### Slide 19 — LIVE DEMO
See the demo script below.

### Slide 20 — Limitations & roadmap
**Be honest**. Jurors test honesty here. Acknowledge:
- Wallet uses manual top-ups.
- SQLite is fine for dev, not production.
- iOS not verified.

Then pivot to the roadmap immediately.

### Slide 21 — Thank you
"Nous vous remercions pour votre attention. Nous sommes prêts pour vos
questions."

---

## Demo script (slide 19, 3 minutes max)

Have **two browser windows** open before you start:
1. The web app (admin or driver session)
2. The mobile app on the emulator (passenger session)

**Script** — exactly this order, no exploration:

1. **Register a new passenger** on mobile (10s).
2. **Show the student email banner** turning green when you type a
   recognized university domain (5s).
3. **Trigger student verification** with the OTP. Have the OTP code
   ready in the backend logs or use a hard-coded test value (10s).
4. **Switch to web**, log in as a driver, **publish a ride** Tunis →
   Sfax (15s).
5. **Switch back to mobile**, **search "Tunis Sfax"**, show the ride
   appearing with the **match-score badge** (15s).
6. **Open the ride detail** — show the **map**, the **price breakdown**
   with the student discount applied automatically (15s).
7. **Book one seat**. Show the **50% prepayment** confirmation. Show
   wallet balance dropping (15s).
8. **Switch to web**, in the driver session, **accept the booking**
   (10s).
9. **Switch to mobile**, show the booking now **confirmed** in My Rides
   and the **notification** (10s).
10. **Open the chat** for that booking, send "On arrive dans 5 minutes"
    (10s).
11. **Show the driver dashboard** on mobile (analytics tab) with the
    reliability gauge (15s).

End with: "Voilà le flux complet, de l'inscription jusqu'à la
confirmation de la réservation."

### If the demo crashes
- Have the **PDF of the report screenshots** open as fallback.
- Switch to: "Vu le temps imparti, je vais vous montrer les captures
  d'écran préparées."
- Then narrate the same flow over the static screenshots.

---

## Anticipated jury questions (with model answers)

### Methodology

**Q: Pourquoi Scrum et pas une autre méthode agile ?**
A: Scrum gives a clear cadence (sprints + reviews) which fit a 4-month
internship. XP would have asked for pair programming we couldn't do
remotely. Kanban has no time-boxed reviews, which is exactly what we
needed for the weekly sync with the supervising engineer.

**Q: Combien de sprints, et combien de temps par sprint ?**
A: One Sprint 0 of about 2 weeks for the architecture and conception,
then 4 functional sprints of about 3 weeks each.

### Technical

**Q: Pourquoi SQLite et pas MySQL/PostgreSQL ?**
A: For development: zero configuration, file-based, easy to share
between web and mobile. We're explicit in the report that SQLite is
**not** suited for production — the migration path to PostgreSQL or
MySQL is in the roadmap.

**Q: SQLite en concurrence ?**
A: We enabled WAL (Write-Ahead Logging) mode, which allows concurrent
reads while a write is in progress. Sufficient for the development
load. Production would need a real DBMS.

**Q: Pourquoi PHP au lieu de Node ou Python ?**
A: ATOMIC IT operates a PHP infrastructure, the team had prior
experience with it, and PHP is natively supported by XAMPP without
extra runtime. The architecture (REST API, JSON contract) is
language-agnostic — we could swap PHP for any other backend without
touching the mobile client.

**Q: Pourquoi Flutter et pas React Native ou natif ?**
A: Single Dart codebase, native rendering via Skia (not a JS bridge),
hot-reload accelerated the iterative work. Native would have doubled
the effort with no extra value for our use case.

**Q: Comment gérez-vous la sécurité des paiements ?**
A: For now the wallet is internal: we don't process card data, so we
don't fall under PCI-DSS. The 50% prepayment is deducted from the
internal balance. For a real deployment, we'd integrate Konnect or
Paymee, both of which handle PCI compliance themselves.

**Q: L'authentification — comment ça marche ?**
A: Bearer tokens. Login returns a 32-byte hex token stored in
`api_tokens`. Every protected endpoint reads the `Authorization`
header, validates the token, and checks the user role. Passwords are
hashed with bcrypt via `password_hash`.

**Q: Pourquoi pas de WebSocket pour le chat ?**
A: 3-second polling is sufficient for our scale and avoids adding a
WebSocket server as an operational dependency. The interface stays
responsive, and switching to WebSockets later is a contained change
in the chat service — the rest of the API doesn't move.

### Business / functional

**Q: Comment vérifiez-vous l'identité des conducteurs ?**
A: Two layers. (1) The driver application form collects identity and
vehicle details, reviewed by an administrator before the driver role
is granted. (2) After every trip, ratings and complaints feed back
into the reliability score. A driver who consistently gets bad
ratings or attracts complaints can be suspended.

**Q: Comment sont sélectionnées les universités pour la réduction
étudiante ?**
A: There's an `student_domains` table managed by the administrator.
A passenger gets the discount only if their email domain is in that
table AND they've validated the OTP sent to that address.

**Q: Que se passe-t-il si le passager ne se présente pas ?**
A: The 50% prepayment is non-refundable in this case — it's the
mechanism that protects the driver from no-shows. The cancellation
rules are documented in the report.

**Q: Pourquoi 50 % et pas 100 % ?**
A: To keep the option of cash settlement, which is how Tunisians
already pay in informal carpooling. 100% online would have been
inconsistent with the local context — that was a design choice we
discussed early in the project.

### Limitations

**Q: Avez-vous testé sur iOS ?**
A: No, we only tested on Android. iOS would require an Apple
developer account and a Mac for the build. It's in the roadmap.

**Q: Avez-vous mesuré la performance sous charge ?**
A: No. Performance under concurrent load was not measured during the
internship. We acknowledge this as a limitation in the report. The
WAL mode and the indexes on the search columns should help, but
this needs real load testing before production.

**Q: Et la conformité RGPD / loi 2004-63 ?**
A: We hash passwords, we use HTTPS in production, we store identity
documents in a separate file storage with signed URLs, and the
audit log records sensitive operations. A full compliance audit was
out of scope for the internship.

### Open-ended / strategic

**Q: Quel est votre plus grand apprentissage technique ?**
A: Coordinating two clients (web + mobile) against a single backend.
Designing the API contract on day 1 of Sprint 0 instead of letting
it emerge. We mention in the report that, with hindsight, we would
have agreed on the error response format earlier.

**Q: Si vous deviez recommencer, qu'est-ce que vous changeriez ?**
A: Three things from the retrospective: (1) agree on the API error
format on day 1, (2) write the unit tests of the price pipeline
**before** the implementation, (3) take screenshots of each screen as
soon as it's done — we ended up taking them all at once at the end.

---

## Pre-defense checklist (the night before)

- [ ] Open the .pptx in PowerPoint at presentation resolution to
      verify all images render correctly
- [ ] Print the speaker notes (this file) on 2-3 pages
- [ ] Capture the missing mobile screenshots and re-run
      `python3 generate_presentation.py`
- [ ] Test the demo flow end-to-end on the actual machine you'll use
- [ ] Have the **PDF fallback** of the deck on a USB stick
- [ ] Start the backend (XAMPP) and Flutter emulator BEFORE the
      defense begins — don't do it live
- [ ] Have a test user pre-registered with student status verified
- [ ] Have a test driver pre-registered with a published ride
- [ ] Pre-fund the test wallet so the prepayment doesn't fail
- [ ] Charge your laptop. Bring the charger.
- [ ] Bring a printed copy of the report

## Defense day — order of operations

1. Arrive 30 min early.
2. Test the projector / HDMI. Confirm 16:9 is displayed correctly.
3. Open the .pptx. Press F5. Make sure presenter view works.
4. Open the demo windows in the background (web + emulator).
5. Have a glass of water within reach.
6. **Don't read the slides.** Look at the jury, talk, glance at the
   slide for the next bullet.
7. Time signal: when you reach slide 14 (Sprint 4), you should be at
   roughly 12 minutes in.
8. After the demo, take a breath before slide 20.

Good luck.
