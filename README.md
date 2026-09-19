# Vacman Enterprise — Inventory System (Yellowman Ventures)

This is a from-scratch PHP + MySQL rebuild of the uploaded prototype. The
original zip contained static HTML pages with hard-coded JavaScript mock
data (no real PHP anywhere, despite a couple of files having "`.php`"
comments and even literal PHP code pasted inside `.html` files with a
stray ` ```php ` markdown fence at the top of `products.html`). None of it
talked to the database in `vacman_enterprise_system.sql`. This rebuild is
a real, working application, server-rendered classic PHP, built for
**XAMPP on localhost**.

## 1. Setup (XAMPP)

1. Copy the whole `vacman/` folder into `C:\xampp\htdocs\` (Windows) or
   `/Applications/XAMPP/htdocs/` (Mac) or `/opt/lampp/htdocs/` (Linux),
   so the app lives at `htdocs/vacman/`.
2. Start Apache and MySQL in the XAMPP control panel.
3. Open phpMyAdmin (`http://localhost/phpmyadmin`), create nothing
   manually — just go to the **Import** tab and import
   `vacman/config/database.sql`. It creates the database
   (`vacman_enterprise_system`), all tables, and seed data.
4. Visit `http://localhost/vacman/` in your browser.
5. Log in with one of the demo accounts below, then **change the
   passwords** from the Profile page (or Admin → User Management).

If your MySQL root user has a password, or you renamed the folder to
something other than `vacman`, update `config/config.php` (`DB_PASS`)
and `.htaccess` (the `/vacman/` paths) to match.

`.htaccess` only takes effect if Apache's `AllowOverride` is set to
`All` for `htdocs` (XAMPP's default `httpd.conf` usually already has
this). If the custom error pages don't seem to apply, that setting is
the first thing to check — the app still works fine either way, since
PHP-level errors (403/404/500) are rendered directly by the pages
themselves regardless of `.htaccess`.

### Demo logins

| Username     | Password       | Role         |
|--------------|----------------|--------------|
| `admin`      | `Admin@123`    | admin        |
| `manager1`   | `Manager@123`  | manager      |
| `warehouse1` | `Warehouse@123`| warehouse    |
| `clerk1`     | `Clerk@123`    | sales_clerk  |

## 2. What was actually wrong with the upload

- **No PHP ran anything.** Every page was static HTML with JS arrays
  (`sampleData`) standing in for a database. `admin.html`, `dashboard.html`,
  `sales.html`, `stores.html`, `transfers.html`, `reports.html`, and
  `inventoryms.html` had zero server code.
- `login.html` and `products.html` *did* contain real PHP — but saved
  with a `.html` extension, so a stock Apache/XAMPP setup would serve it
  as plain text/download it instead of executing it. `products.html`
  also literally starts with a stray ` ```php ` line pasted in by mistake,
  which alone would break the page.
- Both files pointed at a **different database name**
  (`yellowman_inventory`) than the one in the SQL dump you provided
  (`vacman_enterprise_system`).
- `products.html` deleted rows via a plain `GET` link
  (`?delete=<id>`) with **no CSRF protection and no login/role check on
  the delete branch** — anyone who guessed the URL, or was tricked into
  clicking a link, could delete a product while logged in as any user.
- `login.html` redirected to pages that don't exist
  (`warehouse_dashboard.php`, `sales_dashboard.php`, `manager_dashboard.php`).
- `profile.html` and `logout.html` were empty (0 bytes).
- `dashboard.html`'s embedded Chart.js config was invalid JavaScript —
  missing `data:` keys on the labels/values, and colors written as
  `var(--primary)` inside a plain `.js` array literal (`var()` is CSS
  syntax; it does nothing there and Chart.js would render blank/broken
  bars).
- Branding was inconsistent: "Yellowman Inventory" vs "InventoryMS" vs
  the zip/database's "Vacman Enterprise" name; "Accra, Ghana" hard-coded
  in the login/dashboard footers while the one store actually in the
  database is in Sogakope.

## 3. What this rebuild does, mapped to your 6 points

1. **Real PHP application**, not a patch of the mocks — every page is
   server-rendered PHP that reads/writes MySQL directly via PDO.
2. **Renamed and connected.** Every file is `.php`, uses one shared
   `config/config.php` for session/DB/auth setup, and `includes/header.php`
   + `includes/footer.php` for one consistent layout and navigation
   across all pages. `config/database.sql` matches the real DB name used
   everywhere in the code.
3. **JavaScript fixed.** The dashboard and sales charts are rebuilt with
   valid Chart.js config (proper `data:` keys, literal hex colors instead
   of `var(--x)` inside JS). Every link goes to a page that exists.
4. **No more mock data.** Sales, stores, transfers, purchases, inventory
   counts, and reports are 100% database-backed. Recording a sale locks
   the inventory row (`SELECT ... FOR UPDATE`) and decrements real stock
   in a transaction; completing a transfer moves real stock between two
   real store rows; marking a purchase "delivered" adds real stock.
5. **Security/authorization:**
   - Every page calls `require_login()` or `require_role(...)`.
   - Every state-changing form carries a CSRF token, verified with
     `verify_csrf()` before touching the database.
   - Deletes go through `POST` with a `_method=DELETE` override field —
     never a bare `GET` link — and are restricted to `admin`.
   - Server-side `validate()` checks required/numeric/positive fields on
     every form before it reaches SQL; all queries are parameterized
     (PDO prepared statements) — no string-built SQL anywhere.
   - `errors/403.php`, `404.php`, `500.php` are generic, safe pages.
     Uncaught exceptions and fatal errors are logged server-side and show
     the 500 page instead of a raw stack trace or SQL error
     (`APP_DEBUG` in `config/config.php` controls this — leave it `false`
     once you're done testing).
6. **Standardized branding/currency/layout:** `APP_NAME` ("Vacman
   Enterprise") and `BUSINESS_NAME` ("Yellowman Ventures") are single
   constants in `config/config.php`, used everywhere. All money goes
   through one `money()` helper (`GHS ₵`, two decimals). Layout, sidebar,
   and navbar live in `includes/header.php`/`footer.php` and
   `assets/css/style.css` — one theme, not four different ones.

## 4. Pages

| File | Purpose | Access |
|---|---|---|
| `login.php` / `logout.php` | Auth | Everyone / logged in |
| `dashboard.php` | KPIs, low-stock alerts, recent activity, charts | Logged in |
| `products.php` | Product catalog CRUD | View: everyone. Add/Edit: admin, warehouse. Delete: admin |
| `inventory.php` | Per-store stock levels + physical count adjustment | View: everyone. Adjust: admin, warehouse |
| `sales.php` | Point-of-sale style sale recording, one or multiple products per checkout | View: everyone. Record: admin, manager, sales_clerk |
| `purchases.php` | Purchase orders + receiving stock | admin, manager, warehouse |
| `transfers.php` | Inter-store transfer request → approve → in transit → completed | Request: admin/manager/warehouse. Approve: admin/manager. Fulfill: admin/warehouse |
| `stores.php` | Store CRUD | admin, manager (delete: admin) |
| `reports.php` | Sales summary, top products, low stock, inventory valuation, CSV export | Logged in |
| `admin.php` | User management, audit log feed | admin only |
| `profile.php` | Edit own details / change password | Logged in |

## 5. Two intentional design calls worth knowing about

- **`inventoryms.html` and `admin.html`'s dashboard-style content were
  not ported as a separate page.** They were near-duplicates of
  `dashboard.html` under a different color theme/name ("InventoryMS" in
  blue vs "Yellowman" in red). Keeping both would have re-introduced the
  branding inconsistency point 6 asked to fix, so their useful ideas
  (stat cards, category chart) were folded into the one `dashboard.php`.
- **No "delete user" button in `admin.php`.** The schema has
  `sales.user_id` as `ON DELETE CASCADE` — deleting a user would silently
  delete every sale they ever recorded. Rather than quietly changing your
  schema's delete behavior, `admin.php` only supports editing a user's
  details/role/password. If you want hard-delete, decide first whether
  `sales.user_id` should become `ON DELETE SET NULL` (keeps the sale,
  loses who recorded it) instead.

## 6. Not built (flag if you need them)

- Email/SMS notifications (the `notifications` table exists in the
  schema but no UI consumes it yet).
- Multi-currency support (currency is a fixed constant, GHS ₵).
- Pagination on long tables (sales/products lists cap at 100 rows via
  `LIMIT` for now).

## 7. Deploying to Render

Render has no native PHP runtime and no managed MySQL, so this repo
includes a `Dockerfile` and expects the database to run as a separate
service. Push the contents of this folder to the root of a GitHub repo
(not nested in a `vacman/` subfolder), then:

1. **Database** — deploy [render-examples/mysql](https://github.com/render-examples/mysql)
   as a Render **Private Service** (Docker, paid plan — private
   services and disks aren't on the free tier) with a disk mounted at
   `/var/lib/mysql`, and set `MYSQL_DATABASE` / `MYSQL_USER` /
   `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD`. Import `config/database.sql`
   into it (e.g. via an [Adminer](https://render.com/docs/deploy-adminer)
   web service or `mysql ... < config/database.sql` over SSH).
2. **App** — create a Render **Web Service** from this repo with the
   **Docker** runtime and **Port** set to `10000`. Add these environment
   variables (`config/config.php` reads them automatically, falling
   back to XAMPP defaults locally):

   | Key | Value |
   | --- | --- |
   | `DB_HOST` | the MySQL private service's internal address, e.g. `mysql-foo:3306` |
   | `DB_NAME` | value of `MYSQL_DATABASE` above |
   | `DB_USER` | value of `MYSQL_USER` above |
   | `DB_PASS` | value of `MYSQL_PASSWORD` above |
   | `APP_DEBUG` | `false` |

3. Deploy. Render builds the Docker image and serves the app on a
   `*.onrender.com` URL (with free managed TLS), or on a custom domain
   you attach under the service's Settings.
