# Organization Login System Implementation

## Overview
Organizations can now apply for discount codes, receive login credentials, and manage their members through a dedicated web dashboard.

## What Was Added

### 1. Database Schema Updates
**Migration:** `web/migrations/2026_06_organization_login.php`

New columns added to `organizations` table:
- `user_id` — tracks which user applied (optional)
- `password` — hashed password for org login
- `rejection_reason` — reason if application rejected

**Status values:**
- `pending` — awaiting admin review
- `approved` — accepted; organization can login
- `rejected` — not approved; shown rejection reason

### 2. Organization Login Page
**File:** `web/Pages/org_login.php`

Features:
- Email + password login (contact_email)
- CAPTCHA security (simple math question)
- Redirects to org dashboard on success
- Clean, branded UI matching ForsaDrive theme

**Access:** `http://localhost/ForsaDrive/Pages/org_login.php`

### 3. Organization Dashboard
**File:** `web/Pages/org_dashboard.php`

**Overview Tab:**
- Organization details (name, type, contact, applied date)
- Discount percentage
- Member count
- Total bookings using code

**Members Tab:**
- Add new members (name, email, phone, role: member/manager/admin)
- Delete members
- View member list with roles
- Members are stored for management purposes

**Usage Tab:**
- Statistics on discount code usage
- Total bookings made with the code
- Confirmed vs cancelled bookings
- Total passengers served

**Discount Code:**
- Unique code displayed at top (auto-generated on approval)
- Copy button for easy sharing
- Shows discount percentage

### 4. Admin Approval Workflow
**Updated:** `web/Pages/admin.php`

**Approve Organization:**
1. Admin clicks "Approve" on pending org in Verifications > Organizations
2. System:
   - Generates unique discount code (format: `PREFIX####`, e.g., `ISET1234`)
   - Generates random 16-character password
   - Hashes password with bcrypt
   - Stores both in database
   - Changes status to `approved`
   - **Sends email** with credentials:
     - Organization name
     - Contact email
     - Generated password
     - Login URL
     - Discount code

**Reject Organization:**
1. Admin enters rejection reason
2. System:
   - Sets status to `rejected`
   - Stores reason in database
   - **Sends email** to org contact explaining decision

### 5. Session Management
**Updated:** `web/server/session.php`

New functions for organization sessions:
```php
isOrgLoggedIn()       // Check if org is logged in
getCurrentOrg()       // Get current org data
loginOrg($org)        // Create org session
logoutOrg()          // Destroy org session
requireOrgLogin()    // Protect pages requiring org login
```

## User Flow

### For Organizations

1. **Apply for discount:**
   - Fill form on homepage (no login required)
   - Contact email is used as login email
   - Admin reviews within 2-3 days

2. **Receive approval:**
   - Email arrives with:
     - Discount code
     - Login credentials
     - Dashboard URL

3. **Login to dashboard:**
   - Visit `Pages/org_login.php`
   - Enter contact email + password
   - Access organization dashboard

4. **Manage organization:**
   - View discount code and statistics
   - Add/remove members
   - Monitor usage
   - Change password (optional, for future feature)

### For Admins

1. **Review applications:**
   - Go to Admin Panel → Verifications → Organizations
   - See pending applications

2. **Approve/Reject:**
   - Click "Approve" to auto-generate credentials and send email
   - Click "Reject" with reason to decline and notify org
   - Organization gets password + discount code via email

## Database Schema Changes

```sql
ALTER TABLE organizations ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE organizations ADD COLUMN password TEXT;
ALTER TABLE organizations ADD COLUMN rejection_reason TEXT;
```

## Email Notifications

### Approval Email (HTML)
- Discount code displayed prominently
- Login credentials clearly shown
- Discount percentage mentioned
- Password change recommendation
- Support contact info

### Rejection Email (HTML)
- Explanation message
- Rejection reason
- Option to reapply
- Support contact info

## Files Modified/Created

**New Files:**
- `web/migrations/2026_06_organization_login.php` — Database migration
- `web/Pages/org_login.php` — Organization login page
- `web/Pages/org_dashboard.php` — Organization dashboard
- `ORGANIZATION_LOGIN_SETUP.md` — This file

**Modified Files:**
- `web/server/session.php` — Added org session functions
- `web/Pages/admin.php` — Updated approve/reject org actions
- `web/index.php` — Added org form user_id capture + nav link

## Testing Checklist

- [ ] Run migration: `php web/migrations/2026_06_organization_login.php`
- [ ] Apply for organization on homepage (fill form)
- [ ] Login as admin and approve the organization
- [ ] Check email for credentials (will be logged, not actually sent in demo)
- [ ] Visit `Pages/org_login.php` and login with sent credentials
- [ ] View organization dashboard
- [ ] Add/remove members
- [ ] Check discount code display
- [ ] Logout and verify redirect to login page

## Security Notes

1. **Passwords:** Hashed with bcrypt (password_hash/password_verify)
2. **Session:** Uses PHP sessions, stored server-side
3. **CAPTCHA:** Simple math question prevents bot signups
4. **Email:** Uses PHP mail() function (configure SMTP for production)
5. **SQL:** Uses prepared statements (PDO) to prevent injection
6. **Input validation:** All form fields validated before storage

## Future Enhancements

1. Password reset functionality for organizations
2. Change password feature
3. Two-factor authentication (2FA)
4. Organization member self-registration
5. API keys for organizations
6. Monthly billing reports
7. Discount usage analytics
8. Member activity logs
9. SAML/SSO integration
10. Member email domain verification

## Discount Code Generation

The discount code is generated using the organization name:
- Takes first 4 chars of org name (uppercase, alphanumeric only)
- Appends 4-digit random number
- Example: "ISET" + "1234" = "ISET1234"

This makes codes memorable and org-specific.
