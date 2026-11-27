# ANIMAL_CLINIC

PHP/MySQL animal clinic management system.

## Setup

1. Place the project in `C:\xampp\htdocs\ANIMAL-CLINIC`.
2. Import `database/animal_clinic.sql` in phpMyAdmin.
   For an existing database, apply `database/migrations/001_appointment_payment_preferences.sql` once instead.
   Existing installations should also apply `database/migrations/011_temporary_password_reset.sql` and `database/migrations/012_password_recovery_requests.sql` once for administrator-reviewed password recovery.
3. Configure the local MySQL credentials in `includes/database.php`.
4. Open `/ANIMAL-CLINIC/login.php`.
5. Use `animalclinic@gmail.com` and the configured password, or run
   `setup_admin.php` locally for a first-time password setup.

New passwords must be 7–14 characters and include an uppercase letter, a
lowercase letter, a number, and a special character. Existing account passwords
are not changed automatically; login continues to accept them until users
choose a new password. The fresh-install demo password is `Admin@123`.

Groomer accounts use the dedicated `/ANIMAL-CLINIC/groomer/` pages after login.

The application uses PDO, MySQLi compatibility, prepared statements, CSRF
validation, role checks, database constraints/triggers, BLOB pet photos,
activity logging, and role-targeted notifications.
