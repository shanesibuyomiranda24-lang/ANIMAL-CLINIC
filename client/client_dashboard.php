<?php
// client/client_dashboard.php - pet owner overview and clinic information.
require_once __DIR__ . '/client_layout.php';
$c = client_boot();
$pdo = $c['pdo'];
$unpaid = $services = [];
$petCount = $upcomingCount = $vaccineCount = 0;
$upcoming = [];
if (!$c['dbError']) {
    $petTotal = qall($pdo, 'SELECT COUNT(*) AS total FROM Pets WHERE owner_id = ? AND archived_at IS NULL', [$c['ownerId']]);
    $petCount = (int) ($petTotal[0]['total'] ?? 0);
    $upcoming = client_upcoming($pdo, $c['ownerId'], 1);
    $visitCount = qall($pdo,
        "SELECT COUNT(*) AS total FROM Appointments a JOIN Pets p ON p.pet_id = a.pet_id
         WHERE p.owner_id = ? AND p.archived_at IS NULL AND a.archived_at IS NULL
           AND a.appointment_date >= CURRENT_DATE AND a.status IN ('Pending','Scheduled','Check-in','In Consultation')", [$c['ownerId']]);
    $upcomingCount = (int) ($visitCount[0]['total'] ?? 0);
    $unpaid = client_unpaid($pdo, $c['ownerId']);
    $services = qall($pdo, 'SELECT service_id, service_name, base_price, duration_minutes, description FROM Services WHERE is_active = 1 ORDER BY service_name');
    $vaccineTotal = qall($pdo,
        'SELECT COUNT(*) AS total
         FROM Vaccinations v JOIN Pets p ON p.pet_id = v.pet_id
         WHERE p.owner_id = ? AND p.archived_at IS NULL
           AND v.next_due_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)', [$c['ownerId']]);
    $vaccineCount = (int) ($vaccineTotal[0]['total'] ?? 0);
}
$balance = 0.0;
foreach ($unpaid as $u) {
    $balance += (float) $u['total_charged'] - (float) $u['total_paid'];
}
$next = $upcoming[0] ?? null;
$first = $c['fullName'] !== '' ? explode(' ', $c['fullName'])[0] : '';

client_header($c, 'Home', 'Your pet care, all in one place', 'home');
?>
        <section class="clinic-banner" id="home" aria-label="Welcome">
          <div class="clinic-banner-copy">
            <p class="clinic-banner-eyebrow">A healthier, happier life for your pet</p>
            <h2><?= $first !== '' ? 'Welcome back, ' . e($first) . '!' : 'Welcome to ' . e(CLINIC_INFO['name']) . '!' ?></h2>
            <p>Keep visits, vaccinations and bills organized. Book your pet’s next visit whenever you need us.</p>
            <div class="clinic-banner-actions">
              <a class="button light" href="book_appointment.php">Book a visit</a>
              <a class="button ghost" href="my_pets.php">Explore my pets</a>
            </div>
          </div>
        </section>
        <nav class="site-jump" aria-label="Homepage sections">
          <a href="#home">Home</a><a href="#about">About Us</a><a href="#services">Services</a>
          <a href="#gallery">Projects / Gallery</a><a href="#updates">News &amp; Announcements</a>
          <a href="#stories">Testimonials</a><a href="#contact">Contact Us</a>
        </nav>

        <section class="stat-grid four" aria-label="Account summary">
          <article class="stat-card" style="--accent:#23935b"><span class="stat-label">My pets</span><strong class="stat-value"><?= $petCount ?></strong><span class="stat-note"><?= $petCount === 1 ? 'One lovely patient' : 'Pets in your care' ?></span></article>
          <article class="stat-card" style="--accent:#4164c9"><span class="stat-label">Upcoming visits</span><strong class="stat-value"><?= $upcomingCount ?></strong><span class="stat-note"><?= $next ? 'Next: ' . e(date('M j, g:i A', strtotime($next['appointment_date']))) : 'Ready when you are' ?></span></article>
          <article class="stat-card" style="--accent:#b67815"><span class="stat-label">Vaccines due soon</span><strong class="stat-value"><?= $vaccineCount ?></strong><span class="stat-note"><?= $vaccineCount > 0 ? 'Due or overdue in the next 30 days' : 'No upcoming reminders' ?></span></article>
          <article class="stat-card" style="--accent:<?= $balance > 0 ? '#c4453d' : '#008b78' ?>"><span class="stat-label">Balance due</span><strong class="stat-value<?= $balance > 0 ? ' due' : '' ?>">₱<?= number_format($balance, 2) ?></strong><span class="stat-note"><?= $balance > 0 ? 'Settle at the front desk' : 'You are all paid up' ?></span></article>
        </section>

        <section class="site-section about-section" id="about">
          <div class="about-grid">
            <div class="about-copy">
              <p class="section-eyebrow">About our clinic</p>
              <h2>Thoughtful care for every member of your family</h2>
              <p>We make it easier to look after the pets you love, with friendly veterinary care and a simple place to manage appointments, health records and vaccinations.</p>
              <a class="button secondary" href="#services">Explore our services</a>
            </div>
            <div class="about-art"><img src="../assets/images/Animal%20Clinic%20Heart%20Emblem%20Transparent.png" alt="Animal Clinic heart emblem"></div>
          </div>
          <div class="about-highlights">
            <article class="about-highlight"><span>01</span><div><h3>Care with compassion</h3><p>Practical, considerate support for pets and their people.</p></div></article>
            <article class="about-highlight"><span>02</span><div><h3>Care that stays connected</h3><p>Appointments, vaccinations and records together in your portal.</p></div></article>
            <article class="about-highlight"><span>03</span><div><h3>Here for every stage</h3><p>Reach out to our team when your pet needs attention.</p></div></article>
          </div>
        </section>

        <section class="site-section services-section" id="services">
          <p class="section-eyebrow">How we can help</p>
          <h2>Our services</h2>
          <p class="lead">Explore our care options. Online booking and listed prices reflect the clinic’s current service list; contact us about availability and pricing for other services.</p>
          <div class="card-grid services-grid" aria-label="Clinic services">
            <?php if ($services): ?>
              <?php foreach ($services as $i => $service): ?>
                <article class="card service-card" style="--accent:<?= ['#0284c7','#23935b','#7a4fc4'][$i % 3] ?>">
                  <h3><?= e($service['service_name']) ?></h3>
                  <p><?= e($service['description'] ?: 'Ask the clinic for details about this service.') ?></p>
                  <p class="service-meta">₱<?= number_format((float) $service['base_price'], 2) ?> · <?= (int) $service['duration_minutes'] ?> minutes</p>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <article class="card service-card service-unavailable"><h3>No services are listed right now</h3><p>Please contact the clinic for assistance.</p></article>
            <?php endif; ?>
            <article class="card service-card" style="--accent:#0284c7"><h3>Dental care</h3><p>Oral health checks and care recommendations for your pet.</p><p class="service-meta">Ask us about availability and pricing</p></article>
            <article class="card service-card" style="--accent:#23935b"><h3>Diagnostic testing</h3><p>Supportive tests to help your veterinarian assess your pet’s health.</p><p class="service-meta">Ask us about availability and pricing</p></article>
            <article class="card service-card" style="--accent:#7a4fc4"><h3>Spay and neuter</h3><p>Discuss preparation, suitability and aftercare with our clinic team.</p><p class="service-meta">Ask us about availability and pricing</p></article>
            <article class="card service-card" style="--accent:#b67815"><h3>Microchipping</h3><p>A simple identification option to help your pet get home safely.</p><p class="service-meta">Ask us about availability and pricing</p></article>
            <article class="card service-card" style="--accent:#c4453d"><h3>Minor procedures</h3><p>Speak with a veterinarian about routine procedures and follow-up care.</p><p class="service-meta">Ask us about availability and pricing</p></article>
          </div>
        </section>

        <section class="site-section" id="gallery">
          <h2>Clinic gallery</h2>
          <p class="lead">A few friendly reminders of the care and companionship we celebrate every day.</p>
          <div class="gallery-grid">
            <figure class="gallery-card"><img src="../assets/images/gallery-dogs.jpg" alt="Four dogs gathered together on the grass"><figcaption>Friends who make every day brighter</figcaption></figure>
            <figure class="gallery-card"><img src="../assets/images/gallery-cats.jpg" alt="A group of cats gathered outdoors"><figcaption>Curious cats, caring homes</figcaption></figure>
            <figure class="gallery-card"><img src="../assets/images/gallery-hamsters.jpg" alt="Three hamsters exploring their cozy habitat"><figcaption>Small companions, lots of love</figcaption></figure>
          </div>
        </section>

        <section class="site-section" id="updates">
          <h2>News &amp; announcements</h2>
          <p class="lead">Useful information for planning your pet’s next visit.</p>
          <div class="card-grid">
            <article class="card" style="--accent:#0284c7"><h3>Book online</h3><p>Choose a pet and service to see dates and times based on current clinic schedules.</p></article>
            <article class="card" style="--accent:#b67815"><h3>Requests need confirmation</h3><p>Your appointment request stays pending until clinic staff confirms it. We’ll notify you when its status changes.</p></article>
            <article class="card" style="--accent:#23935b"><h3>Keep records close</h3><p>Check your vaccination history and upcoming due dates from the client portal.</p></article>
          </div>
        </section>

        <section class="site-section" id="stories">
          <h2>Testimonials</h2>
          <div class="story-callout">
            <span class="story-mark" aria-hidden="true">“</span>
            <div><h3>Your experience matters</h3><p>We share client feedback only with the owner’s permission. Contact us if you’d like to tell us about your visit.</p></div>
            <a class="button secondary" href="mailto:<?= e(CLINIC_INFO['email']) ?>">Share feedback</a>
          </div>
        </section>

        <section class="site-section" id="contact">
          <p class="section-eyebrow">We’re easy to reach</p>
          <h2>Contact us</h2>
          <p class="lead">Questions about an appointment or your pet’s account? Find us, call, or send our team a message.</p>
          <div class="contact-layout">
            <div class="contact-details">
              <div class="contact-grid">
                <article class="contact-card"><span class="contact-label">Visit</span><h3><?= e(CLINIC_INFO['address']) ?></h3></article>
                <article class="contact-card"><span class="contact-label">Call</span><h3><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', CLINIC_INFO['phone'])) ?>"><?= e(CLINIC_INFO['phone']) ?></a></h3></article>
                <article class="contact-card"><span class="contact-label">Email</span><h3><a href="mailto:<?= e(CLINIC_INFO['email']) ?>"><?= e(CLINIC_INFO['email']) ?></a></h3></article>
              </div>
              <div class="faq">
                <h3>Frequently asked questions</h3>
                <details>
                  <summary>How do I request an appointment?</summary>
                  <p>Choose your pet and service, then select an available date and time in <a href="book_appointment.php">Book a Visit</a>. The clinic will notify you after confirming your request.</p>
                </details>
                <details>
                  <summary>What should I bring to the visit?</summary>
                  <p>Bring any vaccination or medical records you have for your pet, along with notes you would like to discuss with the veterinary team.</p>
                </details>
                <details>
                  <summary>How can I change an appointment?</summary>
                  <p>Contact the clinic by phone or email and include your pet’s name and appointment date so our team can help.</p>
                </details>
              </div>
            </div>
            <aside class="location-card">
              <div class="location-heading"><h3>Find our clinic</h3><span>Map &amp; directions</span></div>
              <div class="map-frame">
                <iframe
                  src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3885.3082696853266!2d123.72183007585869!3d13.142944811097104!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33a103e420c01fbb%3A0x98886975fb5a21ef!2sBicol%20University%20College%20of%20Science%20(BUCS)!5e0!3m2!1sen!2sph!4v1795114312346!5m2!1sen!2sph"
                  title="Map to Bicol University College of Science"
                  loading="lazy"
                  referrerpolicy="strict-origin-when-cross-origin"
                  allowfullscreen></iframe>
              </div>
              <a class="map-link" href="https://www.google.com/maps/search/?api=1&amp;query=Bicol%20University%20College%20of%20Science" target="_blank" rel="noopener noreferrer">Open directions in Google Maps</a>
            </aside>
          </div>
        </section>
<?php client_footer($c, true);
