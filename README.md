# ANIMAL_CLINIC

PHP/MySQL animal clinic management system.

## Setup

1. Place the project in `C:\xampp\htdocs\ANIMAL_CLINIC`.
2. Import `database/animal_clinic.sql` in phpMyAdmin.
   For an existing database, apply `database/migrations/001_appointment_payment_preferences.sql` once instead.
3. Confirm `includes/amc_db.php` uses the local MySQL credentials.
4. Open `/ANIMAL_CLINIC/login.php`.
5. Use `animalclinic@gmail.com` and the configured password, or run
   `setup_admin.php` locally for a first-time password setup.

The application uses PDO, MySQLi compatibility, prepared statements, CSRF
validation, role checks, database constraints/triggers, BLOB pet photos,
activity logging, and role-targeted notifications.
