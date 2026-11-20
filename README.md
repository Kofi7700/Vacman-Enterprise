# Vacman Enterprise

Vacman Enterprise is a PHP and MySQL inventory, sales, and store-management system built for **Yellowman Ventures**. It supports local development with XAMPP and production hosting on Hostinger.

## Main features

- Product catalog and inventory grouped by store
- Sales, deposits, purchases, transfers, receipts, and CSV reports
- Physical stock counts and low-stock monitoring
- Machine-fee collection and staff attendance
- Store and user administration with audit logging
- Multiple roles per account: administrator, manager, mini manager, cashier, warehouse, and sales clerk
- Store-scoped access for staff; administrators and managers have broader access
- Shared MySQL session storage for multi-instance deployments
- CSRF protection, password hashing, prepared statements, and transactional stock updates

New stores start with empty inventory. Products are assigned only when an authorized user explicitly adds them to a store.

## Product conversion rules

Conversions are product-based and apply automatically in every store where the matching product is assigned.

### Timber

- `2*6 Essa`: 1 piece = 3 × `2*2 Essa`, or 1 × `2*2 Essa` + 1 × `2*4 Essa`
- `2*8 Wood B/C`: 1 piece = 4 × `2*2 Wood B/C`, 2 × `2*2 Wood B/C` + 1 × `2*4 Wood B/C`, or 2 × `2*4 Wood B/C`
- `2*8 Otie`: 1 piece = 4 × `2*2 Otie`, 2 × `2*2 Otie` + 1 × `2*4 Otie`, or 2 × `2*4 Otie`
- `2*8 Ceiba`: 1 piece = 2 × `2*4 Ceiba`

### Roofing sheets

Products containing `Sheet` or `Alluzinc` use 20 pieces per bundle. Incomplete bundles display the remaining pieces, such as `2 bundles + 5 pieces` for 45 pieces.

This rule does not apply to roofing nails, roofing plaster, rain gutters, ridge cups, or valley gutters.

### Louvre products

- 24-inch louvre glass: 40 pieces per box
- 30-inch louvre glass: 32 pieces per box
- 36-inch louvre glass: 27 pieces per box
- All louvre frames: 10 pieces per box

### Nails

Products containing `Nail` or `Nails` are stocked, purchased, sold, transferred, priced, and reported in pounds.

- 7 pounds = 1 box
- Total pounds = `(boxes × 7) + extra pounds`
- Extra pounds must be from 0 through 6
- Example: 5 boxes + 3 pounds records 38 pounds
- 38 pounds display as `5 boxes + 3 pounds (38 pounds)`

Existing nail quantities are interpreted as pounds and are not automatically multiplied. Products that match none of these rules use standard piece-based quantities.

## Requirements

- PHP 8.2 or later with PDO MySQL
- MySQL or MariaDB
- Apache or an Apache-compatible server with `.htaccess` support
- A modern web browser

## Local setup

1. Place the project in `C:\xampp\htdocs\vacman`.
2. Start Apache and MySQL in XAMPP.
3. Import `config/database.sql` through phpMyAdmin.
4. Open [http://localhost/vacman/](http://localhost/vacman/).
5. Sign in with an administrator account provisioned for the environment.

Do not publish usernames or passwords in source control or documentation.

## Configuration

Settings are read from environment variables and fall back to local XAMPP defaults.

| Variable | Default | Purpose |
| --- | --- | --- |
| `DB_HOST` | `localhost` | Database hostname |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `vacman_enterprise_system` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | Empty | Database password |
| `DB_SSL` | `false` | Enable database TLS |
| `DB_SSL_VERIFY_SERVER_CERT` | `true` | Verify the database certificate |
| `DB_SSL_CA` | Empty | CA certificate path |
| `DB_SSL_CA_BASE64` | Empty | Base64-encoded CA certificate |
| `APP_DEBUG` | `true` | Display development errors |
| `SESSION_LIFETIME` | `28800` | Session lifetime in seconds |

The application timezone is `Africa/Accra`. Set `APP_DEBUG=false` in production. Authentication and CSRF sessions are stored in the MySQL `app_sessions` table.

## Access summary

- Administrators manage all areas, users, deletions, and the audit trail.
- Managers have broad store access but cannot perform administrator-only actions.
- Mini managers, cashiers, warehouse staff, and sales clerks are restricted to their assigned store where applicable.
- Warehouse staff manage products, stock, purchases, and transfer fulfillment.
- Sales staff record sales; managers and administrators approve protected workflows such as deposit collection.
- Accounts can hold multiple roles, and their permissions are combined.

## Hostinger deployment

1. Create a custom PHP/HTML website in hPanel.
2. Select PHP 8.2 or later and confirm PDO MySQL is enabled.
3. Upload the project contents directly into `public_html` so `index.php` and `.htaccess` are at its root.
4. Initialize the database locally with `config/database.sql`, then export the completed database from phpMyAdmin.
5. Remove `CREATE DATABASE`, `DROP DATABASE`, `USE`, `DEFINER`, `PROCEDURE`, `FUNCTION`, `TRIGGER`, and privilege statements from the deployment export.
6. Create a Hostinger MySQL database and import the cleaned export through phpMyAdmin.
7. Configure the exact `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` values shown in hPanel.
8. Set `APP_DEBUG=false`. Enable database TLS only when required by the database provider.
9. Enable SSL, force HTTPS, verify login and permissions, and test stock-related workflows before entering live data.

The development `config/database.sql` contains local database-creation and migration procedures and should not be imported unchanged into Hostinger shared hosting.

Hostinger references:

- [Upload a PHP website](https://www.hostinger.com/support/how-to-upload-a-website-from-backups/)
- [Prepare and import a database](https://www.hostinger.com/support/1864324-how-to-upload-and-set-up-your-database-at-hostinger/)
- [Change the PHP version](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/)
- [Enable HTTPS](https://www.hostinger.com/support/1583201-how-to-enable-or-disable-https-for-your-website/)

## Security

- Keep production credentials outside the repository.
- Use HTTPS and unique account/database passwords.
- Keep database certificate verification enabled when TLS is used.
- Keep `APP_DEBUG=false` in production.
- Back up the application and database after deployment and before major changes.

## Current limitations

- Currency is fixed to Ghanaian cedi (`GHS`).
- Notifications do not yet have an email, SMS, or in-app interface.
- Sales history is capped at 100 records; purchase and transfer lists are also capped rather than paginated.
