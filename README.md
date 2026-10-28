# Vacman Enterprise

Vacman Enterprise is a server-rendered inventory and sales management system for **Yellowman Ventures**. It is built with PHP, MySQL, Bootstrap, and Chart.js, and is designed to run locally with XAMPP or in a Docker-based deployment.

## Features

- Dashboard with bar-and-pie inventory-mix charts, sales, stock, activity, and role-scoped daily machine-fee summaries
- Product catalog with automatic product codes, category assignment, per-store stock editing, store grouping, and product/category/store filtering
- Store-grouped inventory tracking with physical-count adjustments and role-based store visibility
- Multi-item sales checkout with VAT, discount and payment balance calculations, printable receipts, and audited receipt corrections for managers and administrators
- Store-grouped transaction history showing the latest five transactions per store each day
- Administrator-only sale deletion with automatic inventory restoration and audit logging
- Multi-service fee registration for Sawing, Planning, Design, Shooting, and Facial Board, with printable receipts for administrators, managers, and cashiers
- Customer deposit orders with item lists, instalment payments, and inventory deduction only when items are collected
- Attendance for all active team members except administrators and managers, including system users and non-login staff
- Purchase-order creation, cancellation, and stock receiving
- Inter-store transfer workflow
- Store and user management with required operating-store assignments and a searchable administrator audit trail showing 20 events per page
- Sales, inventory, and low-stock reports with CSV export
- Multi-role access control allowing each account to combine administrator, manager, mini-manager, cashier, warehouse, and sales-clerk permissions
- CSRF protection, prepared SQL statements, password hashing, and audit logging
- Responsive navigation with a menu button that toggles the sidebar on desktop and mobile

## Requirements

- PHP 8.2 or later with PDO MySQL enabled
- MySQL or MariaDB
- Apache with `mod_rewrite` and `.htaccess` overrides enabled
- A modern web browser

The standard XAMPP installation includes the required local services and PHP extensions.

## Local setup with XAMPP

1. Place this repository at `C:\xampp\htdocs\vacman` on Windows. Typical alternatives are `/Applications/XAMPP/htdocs/vacman` on macOS and `/opt/lampp/htdocs/vacman` on Linux.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open [phpMyAdmin](http://localhost/phpmyadmin), select **Import**, and import `config/database.sql`.
4. Visit [http://localhost/vacman/](http://localhost/vacman/).
5. Sign in with a demo account and change its password from the Profile page.

The SQL import creates the `vacman_enterprise_system` database, its tables, and sample data. You do not need to create the database first.

### Demo accounts

| Username | Password | Role |
| --- | --- | --- |
| `admin` | `Admin@123` | Administrator |
| `manager1` | `Manager@123` | Manager |
| `minimanager1` | `MiniManager@123` | Mini manager (Store 2) |
| `cashier1` | `Cashier@123` | Cashier |
| `warehouse1` | `Warehouse@123` | Warehouse |
| `clerk1` | `Clerk@123` | Sales clerk |

> The demo passwords are public and must be changed before the application is exposed to other users.

## Configuration

Application settings live in `config/config.php`. Database settings can be supplied as environment variables; when they are absent, the application uses the local XAMPP defaults shown below.

| Variable | Local default | Description |
| --- | --- | --- |
| `DB_HOST` | `localhost` | MySQL host, optionally including a port |
| `DB_NAME` | `vacman_enterprise_system` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | Empty | Database password |
| `APP_DEBUG` | `true` | Displays PHP errors during local development |

Set `APP_DEBUG=false` in production. Errors are still written to the PHP error log.

If Apache ignores `.htaccess`, confirm that `AllowOverride All` is enabled for the document root. When the app is hosted below the web root (for example, at `/vacman/`), prefix the `RedirectMatch` and `ErrorDocument` paths in `.htaccess` with `/vacman` so the protected directories and custom error pages resolve correctly.

## Roles and access

| Area | Access |
| --- | --- |
| Dashboard and reports | All signed-in users |
| Products | Administrators, managers, and warehouse staff can view all stores; other users see only their assigned store. Administrators and warehouse staff can add or edit; only administrators can delete |
| Inventory | Administrators, managers, and warehouse staff can view all stores; other users see only their assigned store. Administrators and warehouse staff can adjust stock |
| Sales | Administrators and managers can view all stores; other users see only their assigned store. Administrators, managers, mini managers, and sales clerks can record sales; store-assigned users can act only within their store. Only administrators can delete sales |
| Machine fees | Administrators and managers can register and view payments across all stores; cashiers can register and view payments only for their assigned store. Only administrators can delete payments |
| Deposits | Customer item orders can be partly or fully paid without changing inventory. Stock is validated and deducted only when the customer collects the order; store-assigned users are restricted to their store |
| Attendance | Includes mini managers, cashiers, warehouse users, sales clerks, and non-login staff. Administrators and managers mark across stores; mini managers mark only their assigned store |
| Purchases | Administrators, managers, mini managers, and warehouse staff; mini managers are restricted to their assigned store |
| Transfers | Administrators, managers, mini managers, and warehouse staff; mini managers can request from and approve arrivals for their assigned store |
| Stores | Administrators and managers; only administrators can delete |
| User administration and audit trail | Administrators only; the audit trail shows the newest 20 events per page |
| Profile | All signed-in users |

Accounts may hold more than one role. Permissions are combined: if any assigned role grants an action, the account can perform it. A store assignment is required whenever an account includes mini-manager, cashier, warehouse, or sales-clerk responsibilities.

## Project structure

```text
vacman/
|-- assets/              # CSS, JavaScript, images, and user uploads
|-- config/
|   |-- config.php       # Application and database configuration
|   `-- database.sql     # Schema and seed data
|-- errors/              # Custom HTTP error pages
|-- includes/            # Authentication, database, CSRF, layout, and helpers
|-- admin.php            # User administration and audit log
|-- dashboard.php        # KPIs, alerts, charts, and recent activity
|-- inventory.php        # Per-store stock and physical counts
|-- machine_fees.php     # Machine fee registration and history
|-- machine_fee_receipt.php # Printable machine fee receipt
|-- deposits.php         # Customer deposit orders and collection
|-- deposit_receipt.php  # Printable partial/full deposit receipt
|-- attendance.php       # Staff clock-in/out and attendance register
|-- products.php         # Store-grouped product catalog
|-- purchases.php        # Purchase orders and receiving
|-- receipt.php          # Store-protected printable sale receipt
|-- reports.php          # Operational reports and CSV export
|-- sales.php            # Checkout and store-grouped sales history
|-- stores.php           # Store management
`-- transfers.php        # Inter-store transfers
```

## Docker and Render deployment

The included `Dockerfile` runs PHP 8.2 with Apache on port `10000`. The database must run as a separate MySQL service.

1. Push this directory as the root of a Git repository.
2. Provision a MySQL instance and import `config/database.sql`.
3. Create a Render Web Service using the repository's Dockerfile.
4. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `APP_DEBUG=false`.
5. Deploy the service. The container exposes port `10000`.

Render does not provide a native PHP runtime or managed MySQL. PHP runs through Docker, and MySQL can be deployed as a private service with persistent storage. See Render's [Docker](https://render.com/docs/docker) and [MySQL](https://render.com/docs/deploy-mysql) documentation for current platform instructions.

## Security notes

- All application pages require authentication or an explicit role.
- State-changing requests use CSRF tokens.
- SQL queries use PDO prepared statements.
- Sales and stock movements use database transactions and row locking.
- Destructive actions use CSRF-protected `POST` requests rather than query-string actions.
- Production deployments should use HTTPS, unique database credentials, changed demo passwords, and `APP_DEBUG=false`.

## Current limitations

- The notifications table has no email, SMS, or in-app notification interface.
- Currency is fixed to Ghanaian cedi (`GHS`).
- Sales history is capped at 100 records and displays at most five transactions per store per day; purchase and transfer lists are also capped rather than paginated.
