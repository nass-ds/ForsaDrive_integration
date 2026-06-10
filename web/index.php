<?php
require_once 'server/language.php';
require_once 'server/session.php';

$selectedLang    = $GLOBALS['selectedLang'];
$selectedCountry = $GLOBALS['selectedCountry'];
$languages       = $GLOBALS['languages'];

$loggedIn   = isLoggedIn();
$isAdmin    = $loggedIn && !empty($_SESSION['user_data']['is_admin']);
$username   = $loggedIn ? ($_SESSION['user_data']['username'] ?? 'User') : '';

// Contact form feedback
$mailsent = isset($_GET['mailsent']) ? (int)$_GET['mailsent'] : -1;
$mailError = htmlspecialchars($_GET['error'] ?? '');

// ── Org form submission ───────────────────────────────────────────────────────
$orgMsg = ''; $orgErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['org_apply'])) {
    $orgName      = trim($_POST['org_name']      ?? '');
    $orgType      = $_POST['org_type']            ?? 'company';
    $contactName  = trim($_POST['contact_name']  ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $emailDomain  = trim($_POST['email_domain']  ?? '');
    $discountReq  = min(50, max(10, (int)($_POST['discount_pct'] ?? 10)));
    $address      = trim($_POST['org_address']   ?? '');
    $notes        = trim($_POST['org_notes']     ?? '');

    $validTypes = ['company','university','school','ngo','government','other'];
    if (!$orgName)                                                    $orgErr = 'Organization name is required.';
    elseif (!$contactName)                                            $orgErr = 'Contact person name is required.';
    elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL))        $orgErr = 'A valid contact email is required.';
    elseif (!in_array($orgType, $validTypes))                         $orgErr = 'Invalid organization type.';

    if (!$orgErr) {
        try {
            $pdo = getDB();
            $userId = $loggedIn ? $_SESSION['user_id'] : null;
            // Populate both the canonical columns the Dart API reads
            // (contact_person, staff_email_domain) and the legacy columns the
            // web admin displays (contact_name, email_domain).
            $pdo->prepare("
                INSERT INTO organizations
                    (user_id, name, type, contact_person, contact_name,
                     contact_email, phone, staff_email_domain, email_domain,
                     discount_percent, address, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([$userId, $orgName, $orgType, $contactName, $contactName,
                         $contactEmail, $contactPhone, $emailDomain, $emailDomain,
                         $discountReq, $address, $notes, 'pending']);
            $orgMsg = "Application submitted! We'll review it within 2–3 business days and send your discount code to " . htmlspecialchars($contactEmail) . ".";
        } catch (Exception $e) {
            $orgErr = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── Logout action ─────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($selectedLang) ?>" dir="<?= $languages[$selectedLang]['dir'] ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ForsaDrive — Ride-Sharing Platform</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/index.css?v=<?= time() ?>">
</head>
<body>

<!-- ══════════════════════════════════════════════════
     NAVBAR
══════════════════════════════════════════════════ -->
<header id="mainHeader">
    <nav>
        <a href="#home" class="nav-brand">Forsa<span>Drive</span></a>

        <ul class="nav-links" id="navLinks">
            <li><a href="#home">Home</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#reviews">Reviews</a></li>
            <li><a href="#organizations" class="accent-link"><i class="fas fa-building me-1"></i>For Organizations</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>

        <div class="nav-selectors">
            <select id="languageSelect" class="country-selector">
                <?php foreach ($languages as $code => $lang): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $code === $selectedLang ? 'selected' : '' ?>>
                        <?= $lang['flag'] ?> <?= htmlspecialchars($lang['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="nav-actions">
            <?php if ($loggedIn): ?>
                <a href="<?= $isAdmin ? 'Pages/admin.php' : 'Pages/interface.php' ?>" class="btn-nav-dashboard">
                    <i class="fas fa-<?= $isAdmin ? 'shield-alt' : 'home' ?> me-1"></i>
                    <?= $isAdmin ? 'Admin Panel' : 'Dashboard' ?>
                </a>
                <a href="?logout=1" class="btn-nav-logout">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            <?php else: ?>
                <a href="Pages/org_login.php" class="btn-nav-login" title="Organization Login">
                    <i class="fas fa-building me-1"></i>Org Login
                </a>
                <a href="Pages/login.php" class="btn-nav-login">Login</a>
                <a href="Pages/signup.php" class="btn-nav-signup">Sign Up Free</a>
            <?php endif; ?>
        </div>

        <button class="hamburger" id="hamburger" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
    </nav>
</header>

<!-- ══════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ -->
<section id="home">
    <div class="hero-grid">
        <!-- Left: text -->
        <div>
            <div class="hero-tag"><i class="fas fa-bolt"></i> Tunisia's #1 Ride-Sharing Platform</div>
            <h1 class="hero-title">
                Ride Together,<br><em>Save More</em>
            </h1>
            <p class="hero-sub">
                Connect with drivers and passengers across all 24 Tunisian governorates.
                Affordable, safe, and community-driven carpooling — for everyone.
            </p>
            <div class="hero-cta">
                <?php if ($loggedIn): ?>
                    <a href="<?= $isAdmin ? 'Pages/admin.php' : 'Pages/interface.php' ?>" class="btn-hero-primary">
                        <i class="fas fa-arrow-right me-2"></i>Go to Dashboard
                    </a>
                <?php else: ?>
                    <a href="Pages/signup.php" class="btn-hero-primary">
                        <i class="fas fa-user-plus me-2"></i>Get Started Free
                    </a>
                    <a href="Pages/login.php" class="btn-hero-outline">Sign In</a>
                <?php endif; ?>
            </div>
            <div class="hero-stats">
                <div>
                    <div class="hero-stat-val">400k+</div>
                    <div class="hero-stat-lbl">Daily Rides</div>
                </div>
                <div>
                    <div class="hero-stat-val">15M</div>
                    <div class="hero-stat-lbl">Happy Users</div>
                </div>
                <div>
                    <div class="hero-stat-val">24</div>
                    <div class="hero-stat-lbl">Governorates</div>
                </div>
                <div>
                    <div class="hero-stat-val">4.8★</div>
                    <div class="hero-stat-lbl">Avg Rating</div>
                </div>
            </div>
        </div>

        <!-- Right: mock app card -->
        <div class="hero-visual">
            <div class="hero-float-badge2">
                <span class="stars">★★★★★</span>&nbsp; 4.9 rated
            </div>
            <div class="hero-phone">
                <div class="hp-header">
                    <div class="hp-avatar">Y</div>
                    <div>
                        <div class="hp-name">Youssef Ben Abid</div>
                        <div class="hp-sub">Driver · 4.9 ★ · 320 trips</div>
                    </div>
                </div>
                <div class="hp-route">
                    <div class="hp-loc"><i class="fas fa-circle" style="color:#10b981;font-size:.5rem"></i> Tunis — Bab Souika</div>
                    <hr class="hp-divider">
                    <div class="hp-loc"><i class="fas fa-location-dot" style="color:#ef4444"></i> Nabeul — Kélibia</div>
                </div>
                <div class="hp-price">
                    <div>
                        <div style="color:rgba(255,255,255,.5);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;">Ride Price</div>
                        <div class="hp-price-val">12.50 TND</div>
                    </div>
                    <div>
                        <div class="hp-badge">3 seats left</div>
                        <div style="color:rgba(255,255,255,.45);font-size:.72rem;margin-top:.25rem;">Today · 08:30</div>
                    </div>
                </div>
                <button class="hp-btn">Book This Ride →</button>
            </div>
            <div class="hero-float-badge">
                <i class="fas fa-shield-check"></i> Verified Driver
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════════════ -->
<section id="how-it-works" class="fd-section fd-section-alt">
    <div class="container">
        <div class="text-center" style="margin-bottom:3rem">
            <span class="section-eyebrow">Simple Process</span>
            <h2 class="section-title">How ForsaDrive Works</h2>
            <p class="section-sub">Three easy steps to start saving on every journey across Tunisia</p>
        </div>
        <div class="steps-grid fade-up">
            <div class="step-card">
                <div class="step-num">1</div>
                <div class="step-icon"><i class="fas fa-user-plus"></i></div>
                <h4>Create Your Account</h4>
                <p>Sign up in minutes. Choose to ride as a passenger or offer rides as a driver — or both!</p>
            </div>
            <div class="step-card">
                <div class="step-num">2</div>
                <div class="step-icon"><i class="fas fa-search"></i></div>
                <h4>Find or Post a Ride</h4>
                <p>Search available rides by route and date, or post your own trip and set your price.</p>
            </div>
            <div class="step-card">
                <div class="step-num">3</div>
                <div class="step-icon"><i class="fas fa-handshake"></i></div>
                <h4>Book & Connect</h4>
                <p>Confirm your booking, chat with your driver or passenger, and hit the road together.</p>
            </div>
            <div class="step-card">
                <div class="step-num">4</div>
                <div class="step-icon"><i class="fas fa-star"></i></div>
                <h4>Rate Your Experience</h4>
                <p>After every trip, leave a rating to help build a trusted and safe community.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     FEATURES
══════════════════════════════════════════════════ -->
<section id="features" class="fd-section">
    <div class="container">
        <div class="text-center" style="margin-bottom:3rem">
            <span class="section-eyebrow">Why Choose Us</span>
            <h2 class="section-title">Everything You Need in One Place</h2>
            <p class="section-sub">Built for the Tunisian community — safe, affordable, and feature-rich</p>
        </div>
        <div class="features-grid fade-up">
            <div class="feature-card">
                <div class="feature-icon fi-blue"><i class="fas fa-shield-halved"></i></div>
                <div>
                    <h4>Verified Profiles</h4>
                    <p>Every driver is verified with ID check. AI-powered face detection ensures genuine profile photos.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon fi-green"><i class="fas fa-wallet"></i></div>
                <div>
                    <h4>Secure Payments</h4>
                    <p>Built-in wallet with instant refunds on cancellation. Student discounts of up to 50% off.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon fi-amber"><i class="fas fa-map-location-dot"></i></div>
                <div>
                    <h4>All 24 Governorates</h4>
                    <p>Coverage across every governorate in Tunisia — from Tunis to Tataouine.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon fi-purple"><i class="fas fa-comments"></i></div>
                <div>
                    <h4>In-App Messaging</h4>
                    <p>Chat directly with your driver or passenger, plus 24/7 HelpDesk support with live agents.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon fi-red"><i class="fas fa-car-side"></i></div>
                <div>
                    <h4>All Vehicle Types</h4>
                    <p>Cars, vans, pickup trucks, minibuses, motorcycles — and filter by AC, WiFi, accessibility.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon fi-teal"><i class="fas fa-building"></i></div>
                <div>
                    <h4>Corporate Discounts</h4>
                    <p>Companies and universities can register for group discount codes for all their members.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     REVIEWS
══════════════════════════════════════════════════ -->
<section id="reviews" class="fd-section fd-section-alt">
    <div class="container">
        <div class="text-center" style="margin-bottom:3rem">
            <span class="section-eyebrow">Testimonials</span>
            <h2 class="section-title">What Our Community Says</h2>
            <p class="section-sub">Real stories from drivers and passengers across Tunisia</p>
        </div>
        <div class="reviews-grid fade-up">
            <div class="review-card">
                <div class="review-stars">★★★★★</div>
                <p class="review-text">"ForsaDrive saved me hundreds of dinars on my daily Tunis–Sousse commute. The booking process is smooth and the drivers are always on time."</p>
                <div class="review-author">
                    <div class="review-avatar ra-blue">SB</div>
                    <div>
                        <div class="review-name">Sana Bouazizi</div>
                        <div class="review-role">Passenger · Sousse</div>
                    </div>
                </div>
            </div>
            <div class="review-card">
                <div class="review-stars">★★★★★</div>
                <p class="review-text">"As a student in Sfax, the student discount is a game changer. I can afford to go home every weekend now. The app is simple and reliable."</p>
                <div class="review-author">
                    <div class="review-avatar ra-green">AM</div>
                    <div>
                        <div class="review-name">Ahmed Mrabet</div>
                        <div class="review-role">Student · Sfax</div>
                    </div>
                </div>
            </div>
            <div class="review-card">
                <div class="review-stars">★★★★☆</div>
                <p class="review-text">"I started driving on ForsaDrive to cover my fuel costs. Now it's a real side income. The driver dashboard tracks everything perfectly."</p>
                <div class="review-author">
                    <div class="review-avatar ra-amber">LC</div>
                    <div>
                        <div class="review-name">Leila Chaabane</div>
                        <div class="review-role">Driver · Nabeul</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     ORGANIZATIONS
══════════════════════════════════════════════════ -->
<section id="organizations" class="fd-section">
    <div class="container">
        <div class="text-center" style="margin-bottom:2.5rem">
            <span class="section-eyebrow" style="background:rgba(245,158,11,.2);border:1px solid rgba(245,158,11,.4)">Corporate Program</span>
            <h2 class="section-title">Discounts for Organizations</h2>
            <p class="section-sub" style="color:rgba(255,255,255,.62)">
                Companies, universities, NGOs and government bodies can apply for exclusive discount codes —
                <strong style="color:var(--fd-accent)">10% to 50% off</strong> every ride, billed to your organization.
            </p>
        </div>

        <?php if ($orgMsg): ?>
        <div class="org-alert-ok" style="max-width:700px;margin:0 auto 1.5rem">
            <i class="fas fa-check-circle fa-lg"></i><span><?= htmlspecialchars($orgMsg) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($orgErr): ?>
        <div class="org-alert-err" style="max-width:700px;margin:0 auto 1.5rem">
            <i class="fas fa-exclamation-circle fa-lg"></i><span><?= htmlspecialchars($orgErr) ?></span>
        </div>
        <?php endif; ?>

        <div class="org-form-card">
            <form method="POST" id="orgForm">
                <input type="hidden" name="org_apply" value="1">
                <div class="org-form-grid">
                    <div>
                        <label class="org-label">Organization Name *</label>
                        <input type="text" name="org_name" class="org-input" placeholder="e.g. ISET Kélibia" required
                               value="<?= htmlspecialchars($_POST['org_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="org-label">Organization Type *</label>
                        <select name="org_type" class="org-input">
                            <?php foreach(['company'=>'Company','university'=>'University','school'=>'School / Institute','ngo'=>'NGO / Non-Profit','government'=>'Government Body','other'=>'Other'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_POST['org_type'] ?? 'company') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="org-label">Contact Person *</label>
                        <input type="text" name="contact_name" class="org-input" placeholder="Full name" required
                               value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="org-label">Contact Email *</label>
                        <input type="email" name="contact_email" class="org-input" placeholder="billing@example.com" required
                               value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="org-label">Phone Number</label>
                        <input type="tel" name="contact_phone" class="org-input" placeholder="+216 XX XXX XXX"
                               value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="org-label">Staff Email Domain <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
                        <input type="text" name="email_domain" class="org-input" placeholder="e.g. university.tn"
                               value="<?= htmlspecialchars($_POST['email_domain'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="org-label">Requested Discount % <span style="color:#9ca3af;font-weight:400">(10–50%)</span></label>
                        <input type="number" name="discount_pct" class="org-input" min="10" max="50" placeholder="10"
                               value="<?= htmlspecialchars($_POST['discount_pct'] ?? '10') ?>">
                    </div>
                    <div>
                        <label class="org-label">Address <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
                        <input type="text" name="org_address" class="org-input" placeholder="Street, City, Governorate"
                               value="<?= htmlspecialchars($_POST['org_address'] ?? '') ?>">
                    </div>
                </div>
                <div style="margin-bottom:1rem">
                    <label class="org-label">Additional Notes</label>
                    <textarea name="org_notes" class="org-input" rows="3"
                              placeholder="Any special requirements or context..."><?= htmlspecialchars($_POST['org_notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn-org-submit">
                    <i class="fas fa-paper-plane me-2"></i>Submit Application
                </button>
            </form>
        </div>

        <div class="org-benefits">
            <?php foreach([
                ['fa-tag',              'Discount codes 10%–50%'],
                ['fa-users',            'Group member management'],
                ['fa-file-invoice-dollar','Monthly invoicing'],
                ['fa-headset',          'Priority support'],
                ['fa-clock',            '2–3 day review'],
            ] as [$icon,$label]): ?>
            <div class="org-pill"><i class="fas <?= $icon ?>"></i><?= $label ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     LOCATION
══════════════════════════════════════════════════ -->
<section id="location" class="fd-section fd-section-alt">
    <div class="container">
        <div class="text-center" style="margin-bottom:2.5rem">
            <span class="section-eyebrow">Find Us</span>
            <h2 class="section-title">Our Headquarters</h2>
        </div>
        <div class="map-card">
            <div class="map-container"><div id="map"></div></div>
            <div class="map-info">
                <p><i class="fas fa-map-marker-alt"></i><strong>Address:</strong> 8025 Hammam Al Ghezaz, Nabeul, Tunisia</p>
                <p><i class="fas fa-clock"></i><strong>Working Hours:</strong> Monday–Friday &nbsp;9:00 AM – 2:00 PM</p>
                <p><i class="fas fa-envelope"></i><strong>Email:</strong> contact@forsadrive.tn</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     CONTACT
══════════════════════════════════════════════════ -->
<section id="contact" class="fd-section">
    <div class="container">
        <div class="text-center" style="margin-bottom:3rem">
            <span class="section-eyebrow">Get In Touch</span>
            <h2 class="section-title">Contact Us</h2>
            <p class="section-sub">Have a question or feedback? We'd love to hear from you.</p>
        </div>
        <div class="contact-grid">
            <div class="contact-info-block fade-up">
                <h3>We're here to help</h3>
                <p>Whether you have a technical issue, a partnership inquiry, or just want to say hello — reach out and our team will get back to you.</p>
                <div class="contact-detail">
                    <div class="contact-detail-icon"><i class="fas fa-phone"></i></div>
                    <div class="contact-detail-text">
                        <strong>Phone (Anas)</strong>
                        <span>+216 26 295 416</span>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon"><i class="fas fa-phone"></i></div>
                    <div class="contact-detail-text">
                        <strong>Phone (Youssef)</strong>
                        <span>+216 29 131 170</span>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon"><i class="fas fa-envelope"></i></div>
                    <div class="contact-detail-text">
                        <strong>Email</strong>
                        <span>contact@forsadrive.tn</span>
                    </div>
                </div>
                <div class="contact-detail">
                    <div class="contact-detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="contact-detail-text">
                        <strong>Location</strong>
                        <span>Kélibia, Nabeul, Tunisia</span>
                    </div>
                </div>
            </div>
            <div class="contact-form-card fade-up">
                <?php if ($mailsent === 1): ?>
                <div style="background:#dcfce7;color:#166534;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.88rem">
                    <i class="fas fa-check-circle me-2"></i>Your message was sent! We'll get back to you soon.
                </div>
                <?php elseif ($mailsent === 0): ?>
                <div style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.88rem">
                    <i class="fas fa-exclamation-circle me-2"></i><?= $mailError ?: 'Could not send your message. Please try again.' ?>
                </div>
                <?php endif; ?>
                <form action="server/send_contact.php" method="post">
                    <label class="cf-label">Your Name</label>
                    <input type="text" name="name" class="cf-input" placeholder="Full name" required>
                    <label class="cf-label">Email Address</label>
                    <input type="email" name="email" class="cf-input" placeholder="you@example.com" required>
                    <label class="cf-label">Message</label>
                    <textarea name="message" class="cf-input" placeholder="How can we help?" required></textarea>
                    <button type="submit" class="btn-cf-submit">
                        <i class="fas fa-paper-plane me-2"></i>Send Message
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     FOOTER
══════════════════════════════════════════════════ -->
<footer>
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="footer-brand">Forsa<span>Drive</span></div>
                <p class="footer-desc">Tunisia's community-driven ride-sharing platform. Safe, affordable, and available across all 24 governorates.</p>
                <div class="footer-socials">
                    <a href="#" class="footer-social"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="footer-social"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="footer-social"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="footer-social"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="footer-col">
                <h5>Platform</h5>
                <ul>
                    <li><a href="Pages/signup.php">Register</a></li>
                    <li><a href="Pages/login.php">Sign In</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="#features">Features</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h5>For Organizations</h5>
                <ul>
                    <li><a href="#organizations">Corporate Discounts</a></li>
                    <li><a href="#organizations">University Program</a></li>
                    <li><a href="#organizations">Apply Now</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h5>Support</h5>
                <ul>
                    <li><a href="Pages/helpdesk.php">HelpDesk</a></li>
                    <li><a href="#contact">Contact Us</a></li>
                    <li><a href="Pages/signup.php">Create Account</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2025 ForsaDrive · Built by Anas Younes &amp; Youssef Ben Abid · All rights reserved.</p>
            <div class="footer-socials">
                <a href="tel:+21626295416" class="footer-social" title="+216 26 295 416"><i class="fas fa-phone"></i></a>
                <a href="tel:+21629131170" class="footer-social" title="+216 29 131 170"><i class="fas fa-phone"></i></a>
            </div>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<script src="js/index.js"></script>
<script>
// ── Navbar scroll effect ──────────────────────────────────────────────────────
window.addEventListener('scroll', () => {
    document.getElementById('mainHeader').classList.toggle('scrolled', window.scrollY > 40);
});

// ── Hamburger / mobile menu ───────────────────────────────────────────────────
const hamburger = document.getElementById('hamburger');
const navLinks  = document.getElementById('navLinks');
hamburger.addEventListener('click', () => {
    hamburger.classList.toggle('open');
    navLinks.classList.toggle('open');
});
navLinks.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
        hamburger.classList.remove('open');
        navLinks.classList.remove('open');
    });
});

// ── Language selector ─────────────────────────────────────────────────────────
document.getElementById('languageSelect').addEventListener('change', function() {
    window.location.href = `?lang=${this.value}`;
});

// ── Scroll fade-in ────────────────────────────────────────────────────────────
const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); } });
}, { threshold: 0.12 });
document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));
</script>
</body>
</html>
