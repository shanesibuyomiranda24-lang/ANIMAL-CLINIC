# Database

Import `animal_clinic.sql` in phpMyAdmin or MariaDB 10.11+.

The script creates `animal_clinic_management`, all normalized tables and foreign
keys, seed services, views, triggers, and stored procedures.

For an existing installation, run `migrations/001_appointment_payment_preferences.sql`
once in phpMyAdmin or MariaDB before using appointment booking. Fresh imports
already include these columns and payment methods; do not run the migration on a
fresh database.

Run `migrations/002_add_booking_services.sql` to add the diagnostic and
request-a-quote services to an existing installation. The included rates are
sample Philippine starting prices; review and update them in Staff > Services
before accepting bookings.

Run `migrations/003_fix_payment_notification_route.sql` once on an existing
installation to update the stored payment procedure's administrator
notification link. Fresh imports already include the corrected route.

Run `migrations/004_veterinarian_daily_capacity.sql` once on an existing
installation to add the adjustable daily appointment limit (default 10) and
the admin-controlled staff schedule-management permission. Fresh imports
already include both fields.

Run `migrations/005_controlled_payment_corrections.sql` once on an existing
installation to require a reason and a recorded status before voiding a payment,
retain the audit entry, recalculate the invoice balance, and notify the affected
client and active administrators. Fresh imports already include this procedure.

Run `migrations/007_backfill_vet_service_assignments.sql` once on an existing
installation to assign existing services to veterinarians created after the
service catalog was seeded. New veterinarian accounts receive these service
assignments automatically.

Run `migrations/008_pickup_ready_services.sql` once on an existing installation
to add service categories, the grooming and hygiene services (60 minutes each),
and appointment pickup-ready tracking. Staff can categorize services as
Grooming & Hygiene or Surgery & Minor Procedures so owners can be notified when
a pet is ready.

Run `migrations/009_groomer_schedules.sql` once on an existing installation to
link groomer profiles to staff accounts and add Admin-controlled grooming
schedule access, published working hours, leave, and schedule review requests.
Fresh imports already include these tables and columns. Admin must grant a
staff account grooming schedule access before that groomer can submit working
hours or leave; only approved hours are used for client booking.

Run `migrations/010_appointment_pickup_confirmation.sql` once on an existing
installation to record when an owner confirms pickup and allow grooming-only
appointments to be completed without a medical record. Veterinary appointments
still require a finalized medical record before completion. Fresh imports
already include the `picked_up_at` appointment column and updated trigger.

PHP database settings are in `includes/amc_db.php`.
