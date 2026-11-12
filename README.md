# Lipa City Colleges — Payroll & HR Workspace

A web-based employee + payroll workspace: one admin app for people, attendance, pay, reports, and print-ready records — plus a self-service portal for employees.

Built with plain PHP + MySQLi. No framework, no build step. Runs under XAMPP.

- Admin entry: `index.html` → `login.php` → `pages/admin_dashboard.php`
- Employee portal entry: `employee/login.php`
- Detailed product spec lives in [`docs/README.md`](docs/README.md)

## Features

**Admin**
- Workforce dashboard: active headcount, today's check-ins, this month's payroll, needs-attention flags (late / missing department / missing salary)
- Employee directory: searchable/sortable, photos + avatars, full dossier record (identity, contact, government IDs SSS/PhilHealth/Pag-IBIG/TIN/ATM, employment, emergency contact, dependents, education, references)
- Departments & positions: CRUD, per-department identity colors, positions scoped per department, safe-delete (refused when in use)
- Attendance: daily records with filters and late/present/absent/leave/half-day badges, printable report
- Payroll processing: per-employee pay periods, allowances / other earnings / deductions, Draft → Approved → Paid lifecycle, payslip preview
- Monthly reports: month picker, totals (records, gross, deductions, net, paid count), department breakdown, 6-month Gross-vs-Net trend
- Print & PDF: single employee, directory, all profiles, single payslip, all payroll, monthly report (FPDF) + browser-print views
- Responsive desktop + 360px mobile, light + dark mode

**Employee self-service portal (`employee/`)**
- Dashboard with today's attendance state, recent payroll, profile completeness
- One-click Time In / Time Out with live clock
- Read-only profile, personal payroll history + print, attendance history + print, password change

## Tech stack

| Layer | Choice |
|---|---|
| Language | Plain PHP (8.x, tested on 8.2) + MySQLi |
| Database | MySQL / MariaDB (XAMPP defaults), timezone `Asia/Manila` |
| PDFs | FPDF via Composer (`setasign/fpdf`, committed under `vendor/`) |
| Frontend | Server-rendered PHP + page CSS under `assets/css/`, `assets/js/` |
| No | No framework, no router, no tests/lint/CI |

## Requirements

- XAMPP (Apache + MySQL + PHP 8.x) or equivalent PHP + MySQL host
- phpMyAdmin (for one-click SQL import) or MySQL CLI
- Composer only if you need to reinstall `vendor/` (FPDF is already committed)

## Quick start (5 minutes)

1. **Place the app in the docroot**
   - Copy this folder into XAMPP's `htdocs/`, e.g. `D:\Xampp\htdocs\Pay-wise`
   - Start Apache + MySQL in the XAMPP Control Panel.

2. **Create the database** — pick ONE:
   - **Easiest (self-contained):** in phpMyAdmin click Import → choose `paywise_schema.sql` → Go. It creates the `paywise_payroll` database itself. Nothing to create first.
   - **Alternative:** create an empty `paywise_payroll` database first, then import `schema.sql` (same tables, no `CREATE DATABASE`).
   - Fresh installs only — never import over an old database unless you intend to replace it (the files contain `DROP TABLE`).

3. **Check the DB connection** in `config/database.php`:
   ```php
   $dbHost = 'localhost'; $dbUser = 'root'; $dbPass = ''; $dbName = 'paywise_payroll';
   ```
   Timezone is forced to `Asia/Manila` (`+08:00`) in both PHP and MySQL.

4. **Open the app**
   - Admin: `http://localhost/Pay-wise/` (redirects to `login.php`)
   - Employee portal: `http://localhost/Pay-wise/employee/login.php`

5. **(Optional) Reinstall PDFs lib**
   ```sh
   composer install
   ```

6. **Verify a change**
   ```sh
   php -l pages/payroll.php
   ```
   Then load the page manually. There is no test suite.

## Logins

| Role | Credential | Notes |
|---|---|---|
| Admin — Superadmin | full access + manages accounts (`pages/accounts.php`) | everything, user management |
| Admin — Finance | payroll, salary, reports | no employee directory, no user management |
| Admin — HR | employees, attendance, departments, payroll (masked: attendance shown, ₱ figures hidden) | no pay figures, no reports, read-only payroll |
| Employee | own profile, pay history, attendance, password | only own rows (SQL-scoped) |

There is no self-registration: accounts are created by a superadmin. New installs seed one superadmin (`admin`); existing databases run `migrate_roles.sql` once to add the role columns (current admins become superadmin).
| Employee (seeded sample) | `25-0001` / `Employee@123` | Available on a fresh import; password changeable in the portal. |

## How it works

**Payroll math** (`pages/payroll.php`) — the core transaction:
- `gross = basic_salary (always read from the employee record, never from POST) + allowances + other_earnings`
- `net = gross − deductions`
- Guards enforced server-side: no negatives, `deductions ≤ gross`, `period_end ≥ period_start`, no duplicate `(employee_id, period_start, period_end)`
- Status moves only through the `update_status` action: `Draft → Approved → Paid`

**Monthly reports** include pay periods overlapping the month (`period_start <= monthEnd AND period_end >= monthStart`), so a period spanning two months appears in both.

**Employee numbers** auto-generate as `PREFIX-NNNN` (default `25-NNNN`) via `next_employee_no()`, configurable through `app_settings` keys `employee_number_prefix` / `employee_number_digits`.

**Employment status** always uses `active_employment_statuses()` / `is_active_employment_status()` — never a hardcoded list. Inactive/separated staff are excluded from active counts but never deleted silently.

**Money formatting:** `money()` (`₱ …`) for HTML, `pdf_money()` (`PHP …`) for FPDF — the `₱` glyph cannot render in PDFs, so never put it there.

**Photos:** use `upload_employee_photo()` / `delete_employee_photo()` (2MB max, JPG/PNG + `getimagesize` check, `uploads/employee_photos/emp_…` naming, old-file cleanup). Logos: `uploads/logo.png` (layout + PDFs), `uploads/logo1.jpg` (login page).

## Project structure

```
index.html / login.php        Admin entry + login form (POSTs to auth/login.php)
auth/                         Admin login, logout, password change (min 8 chars)
config/database.php           DB connection + shared helpers: e(), money(), CSRF, statuses
includes/auth.php             Admin session gate (require first on protected admin pages)
includes/layout.php           Shared UI: sidebar(), topbar(), tutorial(), ui_icon()
includes/pdf.php              Shared FPDF helpers: pdf_header(), pdf_footer(), row helpers
pages/                        Admin screens: dashboard, employees, departments,
                              positions, attendance, payroll, reports, previews
employee/                     Standalone portal (own auth.php, layout.php, session key
                              $_SESSION['employee_id'], active-status check)
print/                        FPDF documents (require includes/auth.php + includes/pdf.php)
assets/css|js                 Design system + page styles (bump ?v= when changing)
uploads/                      Employee photos, logos (writable by web server)
paywise_schema.sql            Fresh-install schema WITH create-database (recommended)
schema.sql                    Same tables WITHOUT create-database (create DB first)
attendance_portal.sql / employee_portal.sql / supabase_schema.sql
                              Auxiliary / variant schemas
vendor/                       Committed Composer deps (FPDF) — leave alone
docs/README.md                Full MVP/product specification
```

Root `*.zip` archives are committed artifacts — leave alone.

## Conventions (read before contributing)

From `AGENTS.md` — these are enforced, not suggestions:

- Protected admin pages start with `require_once __DIR__.'/../includes/auth.php'`. Portal pages use `employee/auth.php` instead.
- Every `POST` handler in `pages/` / `print/` calls `require_csrf()`; every form echoes `csrf_field()` (419 on failure).
- Escape all HTML interpolation with `e()`.
- `print/*.php` are FPDF documents, not web views.
- Bump `?v=` cache-bust strings in `assets/` when changing CSS/JS.

## Security notes

- Session gates on every protected page, CSRF tokens on all POSTs, `htmlspecialchars` via `e()`, hashed passwords (`password_hash`), basic salary never trusted from client input.
- No admin self-registration; employee portal is scoped at the SQL level so employees only ever see their own rows.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Database connection failed` | Start MySQL in XAMPP; confirm `config/database.php` host/user/pass/name; confirm the DB was imported. |
| Import errors / empty app | Import into a fresh DB. `paywise_schema.sql` needs no pre-created DB; `schema.sql` needs an empty `paywise_payroll` DB first. |
| Logged out immediately / redirect loop | Check PHP sessions are writable; open via `http://localhost/...`, not `file://`. |
| Photos won't upload | `uploads/employee_photos/` must be writable; max 2MB JPG/PNG. |
| PDFs broken / garbled peso sign | Use `pdf_money()` + `pdf_text()` helpers; never emit `₱` into FPDF. |
| Styles stale after CSS edit | Bump the `?v=` query string on the stylesheet link. |

## Out of scope (v1)

Automatic tax/SSS/PhilHealth/Pag-IBIG tables, bank disbursement files, audit-log UI, email/SMS notifications, leave/overtime engine, biometric integration, multi-branch support, public API.
