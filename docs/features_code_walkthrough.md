# ForsaDrive — Features, with Code (jury defense reference)

For each feature: **what it does · the file · the key code · what to say if asked.**
Line numbers are from the current code; open the file live if the jury wants to see it.

---

## 1. Authentication (signup / login / token)

**File:** `forsa_drive_api/lib/routes/auth.dart`, `forsa_drive_api/lib/helpers.dart`

**Login** — verify bcrypt password, check sanctions, issue a 30-day Bearer token:
```dart
// auth.dart  (POST /auth/login)
final user = db.queryOne('SELECT * FROM users WHERE email = ?', [email]);
if (user == null) return jsonError('Invalid email or password', statusCode: 401);
if (!verifyPassword(password, user['password'] as String))      // bcrypt check
  return jsonError('Invalid email or password', statusCode: 401);
final lockout = sanctionLockout(user);                          // banned/suspended?
if (lockout != null) return jsonError(lockout, statusCode: 403);
final token = generateToken();                                 // 32 random bytes
db.execute("INSERT INTO api_tokens (user_id, token, expires_at) "
           "VALUES (?, ?, datetime('now', '+30 days'))", [user['id'], token]);
return jsonSuccess({'token': token, 'user': userToJson(user)}, 'Login successful');
```

**Token check on every protected request** — `helpers.dart`:
```dart
String? extractToken(Request request) {            // reads "Authorization: Bearer xxx"
  final auth = request.headers['authorization'] ?? request.headers['Authorization'];
  if (auth == null || !auth.startsWith('Bearer ')) return null;
  return auth.substring(7);
}
Map<String, dynamic>? requireAuth(Request request) {
  final token = extractToken(request);
  final row = db.queryOne(
    "SELECT user_id FROM api_tokens WHERE token = ? AND expires_at > datetime('now')", [token]);
  ...                                              // also re-checks sanctionLockout()
}
```

**Password hashing** (same algorithm both stacks):
```dart
bool verifyPassword(String pw, String hash) => BCrypt.checkpw(pw, hash);
String hashPassword(String pw) => BCrypt.hashpw(pw, BCrypt.gensalt());
```

**If asked:**
- *Stateless auth* — token in an `api_tokens` table, expires in 30 days, sent as a Bearer header. Web side uses PHP sessions instead, but **bcrypt is shared**.
- *Password policy:* ≥8 chars, 1 uppercase, 1 number (`_passwordPolicyError`, auth.dart).
- *Login is blocked for suspended/banned users* via `sanctionLockout` — that ties auth to feature #6.

---

## 2. Book a ride + 50% wallet payment

**File:** `forsa_drive_api/lib/routes/bookings.dart` (POST /bookings)

```dart
double price = (ride['price'] as num).toDouble();
if (promoCode.isNotEmpty) {                         // optional org discount code
  final org = db.queryOne("SELECT discount_percent FROM organizations "
    "WHERE UPPER(discount_code)=? AND status='approved'", [promoCode]);
  if (org == null) return jsonError('Invalid or expired promo code');
  price = price * (1 - (org['discount_percent'] as num) / 100);
}
final paidNow = price * seats * 0.5;                // ← 50% upfront
final balance = (user['balance'] as num?)?.toDouble() ?? 0.0;
if (balance < paidNow) return jsonError('Insufficient balance. Please deposit funds first.');

db.execute("INSERT INTO bookings (ride_id, passenger_id, seats, paid_amount, status) "
           "VALUES (?,?,?,?,'pending')", [rideId, userId, seats, paidNow]);
db.execute('UPDATE users SET balance = balance - ? WHERE id = ?', [paidNow, userId]); // hold
db.execute('INSERT INTO payments (user_id, amount, type, description) VALUES (?,?,?,?)',
  [userId, -paidNow, 'charge', 'Booking #$bookingId - 50% upfront']);                 // ledger
db.execute('INSERT INTO notifications (...) VALUES (...)', [ride['driver_id'], ...]);  // notify
```

**If asked "where does the money go?":**
- 50% is deducted from the passenger's wallet at booking and **held**; the driver is credited it on arrival (`rides.dart`, arrival route). The other 50% is settled as cash.
- Seat availability is computed live: `available_seats - SUM(booked seats)`.
- A passenger can't book their own ride (`ride['driver_id'] == userId` guard).
- Be honest: payment is fully demonstrated **on mobile**; web is the admin channel.

---

## 3. Privacy-by-design: limited vs full profile (48h window)

**File:** `forsa_drive_api/lib/profile_access.dart` (mirrors `web/classes/profileaccess.php`)

```dart
bool canViewFull(viewer, target, {int? rideId}) {
  if (viewer['id'] == target['id']) return true;          // yourself
  if (viewer['is_admin'] == 1) return true;               // admin
  if (viewer['is_helpdesk_agent'] == 1) return true;      // support
  // otherwise: only if a confirmed/completed booking ties the two together,
  // and only within 48h after the ride completes:
  final row = db.queryOne('''
    SELECT 1 FROM bookings b JOIN rides r ON r.id = b.ride_id
    WHERE b.status IN ('confirmed','completed')
      AND ((b.passenger_id = ? AND r.driver_id = ?) OR (b.passenger_id = ? AND r.driver_id = ?))
      AND (r.status IN ('confirmed','in_progress','active','open')
        OR (r.status='completed' AND COALESCE(r.completed_at, r.departure_time)
            > datetime('now', printf('-%d hours', ?))))
    LIMIT 1''', params);
  return row != null;
}
```
**Limited profile** exposes only `public_id`, **first name**, picture, rating average, verified badge — never phone/full name. `fullProfile()` adds phone, full name, plate number.

**If asked "how do you protect privacy?":**
- Visibility is enforced **server-side**, not hidden in the UI.
- Strangers see a *limited* card; the *full* profile (phone, plate) unlocks only after a confirmed booking, and closes 48h after the ride.
- Third parties are referenced by `public_id`, ratings show **first name only**.

---

## 4. Community feed + ranking algorithm

**File:** `forsa_drive_api/lib/routes/feed.dart` (GET /feed/)

The feed is **not** sorted by date — it is a weighted ranking computed in SQL:
```sql
(
  CASE WHEN <boost active> THEN 200 ELSE 0 END        -- paid boost
  + COALESCE(u.score, 3.0) * 10                        -- driver reliability
  + CASE WHEN u.governorate = (SELECT governorate FROM users WHERE id = ?)
         THEN 50 ELSE 0 END                            -- same-region proximity
  - CAST((julianday('now') - julianday(fp.created_at)) * 24 AS INTEGER) * 3   -- recency decay
) AS rank_score
...
ORDER BY rank_score DESC, fp.created_at DESC
```
Post types: `driver_offer`, `passenger_request`, `group_post`. Likes & comments are separate tables (`feed_likes`, `feed_comments`).

**If asked "how does the feed rank posts?":**
> Boosted posts get +200, same-governorate +50, the author's reliability score ×10, minus 3 points per hour of age. So a trusted local driver's fresh offer floats up, an old post sinks, and a paid boost jumps to the top until it expires.

---

## 5. Tiered boost (monetization)

**Files:** `forsa_drive_api/lib/boost_tiers.dart` (prices), `routes/feed.dart` (`_applyBoost`)

```dart
const Map<String, BoostTier> kBoostTiers = {
  '12h': BoostTier('12h', '12 hours', 1.5, '+12 hours'),
  '24h': BoostTier('24h', '24 hours', 2.5, '+24 hours'),
  '48h': BoostTier('48h', '48 hours', 4.0, '+48 hours'),
  '7d' : BoostTier('7d',  '7 days',  10.0, '+7 days'),
};

String? _applyBoost(int uid, int postId, BoostTier tier, String description) {
  db.execute('UPDATE users SET balance = balance - ? WHERE id = ?', [tier.price, uid]); // charge
  db.execute("UPDATE feed_posts SET is_boosted=1, boost_tier=?, boosted_at=datetime('now'), "
    "boost_expires_at=datetime('now', ?) WHERE id=?", [tier.key, tier.sqlModifier, postId]);
  db.execute("INSERT INTO payments (user_id, amount, type, description) VALUES (?,?,?,?)",
    [uid, tier.price, 'boost', '$description (${tier.label})']);
}
```
A boost is "active" only while `is_boosted=1 AND boost_expires_at > now` (`boostActiveSql`), so **expired boosts automatically fall back** to normal ranking.

**If asked:** prices/durations are a single source of truth mirrored in `web/server/boost_tiers.php` so both backends charge identically against the shared DB. It's a real wallet charge logged as a `boost` payment.

---

## 6. Progressive sanctions (Warning → Suspension → Ban)

**File:** `web/classes/sanctions.php` (mirrored by `sanctionLockout` in `helpers.dart`)

```php
public static function applyExpiry(PDO $db, array &$user): void {   // "expire on read"
  // auto-lift a temporary suspension once suspended_until <= now
  $stmt = $db->prepare("UPDATE users SET suspended=0, suspended_until=NULL, ban_reason=NULL
     WHERE id=? AND suspended=1 AND suspended_until IS NOT NULL AND suspended_until <= datetime('now')");
  $stmt->execute([$user['id']]);
  if ($stmt->rowCount() > 0) self::log($db, $user['id'], null, 'lift', 'Suspension period elapsed', ...);
}
public static function lockoutMessage(array $user): ?string {       // shown at login
  if (empty($user['suspended'])) return null;
  if (empty($user['suspended_until'])) return "...permanently banned. Reason: ...";   // ban
  return "...suspended until {$until} UTC. Reason: ...";                              // suspension
}
```
State on `users`: `suspended`, `suspended_until` (NULL = permanent ban), `warnings_count`. Every action is appended to `user_sanctions` (audit trail). Suspension window: 7–30 days.

**If asked:** suspensions **auto-expire** without a cron job — they're lifted lazily on the next login/request ("expire on read"). The same logic runs in both PHP and Dart so a suspended user is blocked on web *and* mobile.

---

## 7. HelpDesk auto-assignment (load balancing across two runtimes)

**File:** `forsa_drive_api/lib/routes/misc.dart` (`_helpdeskAutoAssign`)

```dart
for (final candidate in candidates) {              // longest-idle agents first
  final changed = db.executeWithChanges(
    "UPDATE helpdesk_conversations SET agent_id=?, status='assigned', "
    "assignment_method='auto', assigned_at=datetime('now') "
    "WHERE id=? AND agent_id IS NULL "             // ticket still unassigned, AND
    "  AND NOT EXISTS (SELECT 1 FROM helpdesk_conversations "
    "                  WHERE agent_id=? AND status IN ('open','assigned'))",  // agent still free
    [agentId, convId, agentId]);
  if (changed > 0) { /* notify agent, deep-link to ticket */ break; }
  // changed == 0 → the PHP side grabbed this agent first; try the next candidate
}
```
Candidate query orders by `last_assigned` so the **longest-idle** free agent is chosen (fair round-robin). One agent = one active ticket.

**If asked:** the assignment is **one atomic guarded UPDATE**. Because PHP web and the Dart API both write the same SQLite file, two assignments could race — the `WHERE ... AND NOT EXISTS(...)` clause makes the DB the referee. If it changes 0 rows, the other runtime won and we move on. A bot answers FAQs first; escalation triggers this assignment.

---

## 8. Live GPS tracking

**File:** `forsa_drive_api/lib/routes/rides.dart` (POST/GET `/rides/<id>/location`)

```dart
// Driver pushes position (only the ride's driver may):
if (ride['driver_id'] != user['id'])
  return jsonError('Only the driver can share location for this ride', statusCode: 403);
db.execute('INSERT INTO ride_locations (ride_id, driver_id, lat, lng, accuracy, speed, heading) '
           'VALUES (?,?,?,?,?,?,?)', [...]);
db.execute('''DELETE FROM ride_locations WHERE ride_id=? AND id NOT IN (
   SELECT id FROM ride_locations WHERE ride_id=? ORDER BY recorded_at DESC LIMIT 200)''', ...); // prune

// Read latest position — driver OR a passenger with a booking on that ride:
final booking = db.queryOne("SELECT id FROM bookings WHERE ride_id=? AND passenger_id=? "
  "AND status IN ('confirmed','pending','completed') LIMIT 1", [rideId, user['id']]);
canAccess = isDriver || booking != null;
```
Flutter side uses `geolocator` (GPS) + `flutter_map` to draw the position.

**If asked:** lat/lng are validated and capped at the last 200 points per ride. Access is authorized server-side — only the driver or a passenger booked on that ride can read the location.

---

## 9. Ratings & reputation (with anti-abuse rules)

**File:** `forsa_drive_api/lib/routes/misc.dart` (ratingsRouter, POST /)

Guards enforced before a rating is accepted:
```dart
if (score < 1 || score > 5)            return jsonError(..., 422);   // valid range
if (targetId == raterId)               return jsonError('cannot rate yourself', 422);
if (ride['status'] != 'completed')     return jsonError('only after completion', 409);
if (!validPair)                        return jsonError('must be the other party', 403); // passenger⇄driver
final existing = db.queryOne('SELECT id FROM ratings WHERE ride_id=? AND from_user_id=? AND to_user_id=?', ...);
if (existing != null)                  return jsonError('already rated', 409);           // one per ride
db.execute('INSERT INTO ratings (ride_id, from_user_id, to_user_id, score, comment) VALUES (?,?,?,?,?)', ...);
final avg = db.queryScalar('SELECT AVG(score) FROM ratings WHERE to_user_id=?', [targetId]);
db.execute('UPDATE users SET score=? WHERE id=?', [avg ?? 5.0, targetId]);  // denormalized reputation
```
A `UNIQUE(ride_id, from_user_id, to_user_id)` index is the hard guard; the code check just gives a clean message.

**If asked:** you can only rate the *other party* of a ride you actually took, only after it's completed, once. `users.score` is the cached average of **received** ratings (drives the feed ranking in #4).

---

## 10. Student verification (university-email OTP)

**File:** `forsa_drive_api/lib/routes/misc.dart` (studentRouter)

```dart
const _allowedDomainSuffixes = ['insat.rnu.tn','enit.rnu.tn','esprit.tn','tek-up.tn', ...];
bool _isUniversityEmail(String email) {
  final domain = email.split('@')[1].toLowerCase().trim();
  return _allowedDomainSuffixes.any((s) => domain == s || domain.endsWith('.$s'));
}
String _generateOtp() => (100000 + math.Random.secure().nextInt(900000)).toString(); // 6-digit
```
Flow: `send-otp` (checks the email is a recognized .tn university domain) → 6-digit code, 10-min validity → `verify-otp` sets `is_student=1`.

**If asked:** the OTP proves the user controls a university inbox. Be honest: there's **no SMTP in the demo** — the code is printed to the server console (`_sendOtpEmail`); in production it's a SendGrid/SMTP call. The web side also supports document + admin review.

---

## 11. Public ForsaDrive ID (privacy-safe identity)

**File:** `forsa_drive_api/lib/public_id.dart` (mirrors `web/classes/publicid.php`)

```dart
const prefixPassenger='FD-P-', prefixDriver='FD-D-', prefixAdmin='FD-A-', prefixHelpdesk='FD-H-';
String prefixForUser(u) =>
  u['is_admin']==1 ? prefixAdmin : u['is_helpdesk_agent']==1 ? prefixHelpdesk
  : u['is_driver']==1 ? prefixDriver : prefixPassenger;
String ensurePublicId(int userId) {            // idempotent, issued once at signup, immutable
  ... if (existing.isNotEmpty) return existing;
  final newId = _generateUnique(prefixForUser(u));
  db.execute('UPDATE users SET public_id=? WHERE id=?', [newId, userId]);
}
```
**If asked:** every user gets a stable public ID like `FD-P-10001`; reports and ratings reference *that*, never the internal DB row id. The prefix encodes the role and the sequence space is shared across both backends (same SQLite file).

---

## 12. Face validation (on-device pre-check)

**File:** `forsa_drive_flutter/lib/services/face_check_service.dart`

```dart
Future<FaceCheckResult> countFacesOnDevice(String? filePath) async {
  if (!faceCheckSupported || filePath == null) return FaceCheckResult(ran:false, faceCount:0);
  final detector = FaceDetector(options: FaceDetectorOptions(performanceMode: accurate));
  final faces = await detector.processImage(InputImage.fromFilePath(filePath))
                              .timeout(const Duration(seconds: 6));   // never freeze the UI
  return FaceCheckResult(ran: true, faceCount: faces.length);
}
```
**If asked (be honest):** this is a Google **ML Kit** on-device face *detector* (counts faces, ensures exactly one) — a fast pre-check before upload. It is **not** a trained face-*recognition* model; the architecture is a pluggable pipeline and the backend stays the source of truth. On unsupported platforms it returns `ran:false` and defers to the backend.

---

## Honesty cheat-sheet (don't get caught overclaiming)
- **In-app notifications:** real (DB rows). **FCM push / email:** scaffolded/simulated.
- **Face check:** detection (count faces), not recognition; mock-friendly pipeline.
- **Student discount:** verify it reduces the total in your build before claiming savings.
- **Schema:** show the **live DB / `db.dart`**, never `setup_db.php`.
- **Payments:** demonstrated on **mobile**; web is the admin channel.
```
