# Testing Organization Login System

## Quick Start

### Step 1: Run Migration
```bash
php web/migrations/2026_06_organization_login.php
```
Expected output: `✓ Columns already exist (safe to retry)` or `✓ Migration completed successfully`

### Step 2: Test Organization Application

1. Open browser → `http://localhost/ForsaDrive/`
2. Scroll to **"For Organizations"** section
3. Click **"Apply for Discount"** button
4. Fill form with test data:
   - **Organization Name:** Test University
   - **Organization Type:** University
   - **Contact Person:** John Smith
   - **Contact Email:** org@test.local
   - **Phone:** +216 92 123 456
   - **Staff Email Domain:** test.tn
   - **Requested Discount:** 20%
   - **Address:** 123 Main St
5. Click **Submit Application**
6. ✅ Should see: "Application submitted! We'll review it within 2–3 business days..."

### Step 3: Admin Approval

1. Login as admin:
   - Go to `http://localhost/ForsaDrive/Pages/admin.php`
   - Use admin credentials

2. Navigate to **Admin Panel** → **Verifications** → **Organizations**

3. Find the "Test University" application

4. Click **Approve**
   - System generates:
     - Discount code (e.g., TEST1234)
     - Random password
   - Email sent to org@test.local (check logs or CLI output)

5. ✅ Should see: "Organization approved. Discount code: TEST1234"

### Step 4: Organization Login

1. Go to `http://localhost/ForsaDrive/Pages/org_login.php`

2. Enter credentials:
   - **Email:** org@test.local
   - **Password:** (from approval email or check admin logs)
   - **Security Answer:** Sum of two numbers shown (e.g., if "3 + 4 = ", enter 7)

3. Click **Login to Dashboard**

4. ✅ Should redirect to organization dashboard

### Step 5: Dashboard Features

**Overview Tab:**
- [ ] Organization name displayed
- [ ] Status shows "Approved"
- [ ] Discount code visible (e.g., TEST1234)
- [ ] Discount percentage shows 20%
- [ ] Stats show 0 members, 0 bookings

**Members Tab:**
- [ ] Form to add members visible
- [ ] Add test member:
  - Name: Jane Doe
  - Email: jane@test.local
  - Phone: +216 92 999 888
  - Role: Manager
- [ ] Click "Add Member"
- [ ] ✅ Member appears in list below
- [ ] Member shows as "Manager" badge
- [ ] Delete button works

**Usage Tab:**
- [ ] Stats empty (no rides using code yet)
- [ ] Shows "0 total bookings"

### Step 6: Logout
- [ ] Click "Logout" in top right
- [ ] ✅ Redirects to organization login page
- [ ] Cannot access dashboard without logging in

## Test Cases

### Test 1: Duplicate Organization Applications
```
Precondition: Logged in as regular user
1. Apply for org #1: "Tech Corp"
2. Apply again for org #2: "Tech Corp" (same name)
3. ✅ Second should be rejected (duplicate pending check)
```

### Test 2: Password Hashing
```
1. Approve organization
2. Check SQLite database:
   - SELECT password FROM organizations WHERE id=1;
3. ✅ Password should be hashed (starts with $2y$, ~60 chars)
4. ✅ Raw password should NOT be stored
```

### Test 3: Invalid Login Attempts
```
1. Go to org_login.php
2. Try:
   - Wrong email → ✅ "Invalid email or password"
   - Correct email, wrong password → ✅ "Invalid email or password"
   - Pending org email → ✅ "not yet activated"
3. Captcha answer wrong → ✅ "Incorrect answer"
```

### Test 4: Session Persistence
```
1. Login to org dashboard
2. Navigate to ?tab=members
3. Refresh page → ✅ Stay logged in
4. Close browser, reopen
5. Go to dashboard → ✅ Redirects to login page
```

### Test 5: Reject Organization
```
1. Apply for org: "Fake Corp"
2. As admin, click Reject with reason: "Insufficient documentation"
3. Email should be sent to contact email
4. Org cannot login (status=rejected)
5. ✅ Check email for rejection message
```

### Test 6: Code Copy Button
```
1. Login to org dashboard
2. Click "Copy" button on discount code
3. Paste somewhere (Ctrl+V) → ✅ Shows "TEST1234"
4. ✅ Toast notification: "Code copied!"
```

### Test 7: Discount Code Format
```
Org name: "ISET Kelibia"
1. Apply and approve
2. Code should be: ISET####
3. ✅ 4-letter prefix + 4-digit random
```

## Troubleshooting

### Issue: "Invalid email or password" but credentials look correct
- Check: Status is "approved" (not "pending" or "rejected")
- Check: Password was hashed correctly
- Check: Email matches contact_email exactly (case-insensitive)

### Issue: Login page shows but no organization tables in DB
- Run migration: `php web/migrations/2026_06_organization_login.php`
- Check database file exists: `ForsaDrive_PFE/forsa_drive_api/database/DB.db`

### Issue: Email not sent on approval
- System uses PHP mail() — configure SMTP if needed
- For testing, emails are logged to console/stderr
- Check your WAMP configuration for sendmail_path

### Issue: Dashboard blank after login
- Check browser console for JavaScript errors
- Verify organization status is "approved" or "active"
- Clear browser cache (Ctrl+Shift+Delete)

## Next: Mobile Integration

The Flutter app (via Dart API) already has organization discount code support:
- Bookings can use `promo_code` field
- Code is validated against organizations table
- Discount applied automatically
- Works with web-approved organizations

## Admin Controls Added

In admin.php, these actions were added:
- `approve_org` — Approve with code + password generation
- `reject_org` — Reject with reason

Both send HTML emails to contact_email.

## Database Queries

To check organization status:
```sql
-- View all organizations
SELECT id, name, status, discount_code, created_at FROM organizations ORDER BY created_at DESC;

-- View members of org #1
SELECT * FROM organization_members WHERE organization_id=1;

-- View bookings using org code
SELECT COUNT(*) FROM bookings 
WHERE ride_id IN (SELECT id FROM rides WHERE discount_code='TEST1234');
```

## Performance Notes

- Organization dashboard loads members on each page load
- Usage stats query counts bookings (can be slow with many records)
- Consider adding indexes for production:
  ```sql
  CREATE INDEX idx_org_members_org ON organization_members(organization_id);
  CREATE INDEX idx_org_status ON organizations(status);
  ```
