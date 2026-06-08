# claude.md — Project Rules & How We Work Together

## What This Project Is

A Port Management System (PMS) built in PHP, HTML, CSS, and MySQL.
It manages the full lifecycle of a vessel arriving at a port: berth allocation → customs → warehouse storage → transport → invoicing.

This is a group academic project. Scope has been deliberately reduced from a competition-scale system to something completable on a deadline.

---

## Tech Stack — No Exceptions

| Layer | Technology |
|---|---|
| Backend | PHP (procedural — no frameworks, no Composer) |
| Database | MySQL |
| Frontend | HTML5 + CSS3 + Vanilla JavaScript |
| Architecture | Server-rendered pages (no SPA, no React, no Vue) |
| Auth | PHP sessions (`$_SESSION`) |
| Passwords | `password_hash()` / `password_verify()` — bcrypt |

Do not suggest or introduce: Laravel, Symfony, PDO abstractions beyond a single `db.php`, npm, node, Tailwind (via CDN is fine), or any JS framework.

---

## File & Folder Structure

```
/pms
├── /config
│   └── db.php                  # single PDO connection — require_once this everywhere
├── /includes
│   ├── auth.php                # session_start(), require_login(), require_role()
│   └── helpers.php             # audit_log_entry(), status_badge(), format_dt()
├── /modules
│   ├── /vessel                 # arrivals.php, berths.php, schedule.php
│   ├── /customs                # manifests.php, inspections.php
│   ├── /cargo                  # containers.php, storage.php
│   ├── /logistics              # transport.php, delivery.php
│   ├── /workforce              # shifts.php, tasks.php
│   ├── /finance                # invoices.php, payments.php
│   └── /admin                  # users.php, audit.php
├── /assets
│   ├── /css
│   │   └── main.css
│   └── /js
│       └── main.js
├── /sql
│   └── schema.sql
├── dashboard.php
├── login.php
└── index.php
```

---

## Coding Rules

### PHP
- Every page starts with `require_once '../config/db.php'` and `require_once '../includes/auth.php'`
- Every protected page calls `require_login()` as the very first logic line
- Role-restricted pages call `require_role(['role1', 'role2'])` immediately after
- All database queries use **PDO prepared statements** — no string interpolation in SQL ever
- Process form submissions at the top of the file (before any HTML output)
- Redirect after successful POST using `header('Location: ...')` + `exit`
- No inline SQL in HTML sections — query first, render second
- Always `htmlspecialchars()` any user-supplied value rendered in HTML

### HTML / CSS
- Use semantic HTML5 (`<main>`, `<nav>`, `<section>`, `<header>`, `<footer>`)
- One shared `main.css` for global styles
- Module-specific styles go in `<style>` blocks at the top of the page (acceptable for this project scale)
- No inline `style=""` attributes unless absolutely unavoidable
- All forms use `method="post"` — never `method="get"` for state-changing actions

### Database
- Never use raw string concatenation to build SQL queries
- Always use transactions for multi-table writes (e.g., approving an arrival AND allocating a berth)
- Status changes must be validated in PHP before the UPDATE query — check current status first

### Security Minimums
- `password_hash($password, PASSWORD_BCRYPT)` for storing passwords
- `password_verify($input, $hash)` for checking passwords
- Session started with `session_start()` on every page via `auth.php`
- No direct `$_GET` or `$_POST` values in SQL — always bind via PDO

---

## The 4 Roles

| Role Slug | Label | Primary Responsibility |
|---|---|---|
| `admin` | Admin | Full access — user management, audit log |
| `harbor_master` | Harbor Master | Arrival requests, berth allocation |
| `operations` | Operations | Customs, cargo, storage, transport |
| `finance` | Workforce & Finance | Shifts, tasks, invoices, payments |

Role is stored in `$_SESSION['role']` on login. All role checks use this session value.

---

## The `auth.php` Contract

```php
// Redirects to /login.php if no session
function require_login() { ... }

// Redirects to /403.php if role not in allowed array
// Usage: require_role(['admin', 'harbor_master'])
function require_role(array $allowed) { ... }
```

---

## The `helpers.php` Contract

```php
// Writes one row to audit_log table
// Call after every successful CREATE / UPDATE / DELETE / APPROVE / REJECT
function audit_log_entry($pdo, $user_id, $action, $module, $record_id, $description) { ... }

// Returns an HTML <span> with a CSS class based on status string
function status_badge($status) { ... }

// Returns a formatted datetime string: "12 Jun 2025, 14:30"
function format_dt($datetime_string) { ... }
```

---

## How to Ask Claude for Help

### To build a new page, say:
> "Build `/modules/vessel/arrivals.php`. Use the schema in memory.md. The page lists all arrival requests. Harbor Master and Admin can approve or reject. Call `audit_log_entry()` on every approve/reject."

### To fix a bug, say:
> "On `/modules/cargo/storage.php`, the storage slot is not being marked as occupied after assignment. Here is the relevant code: [paste code]"

### To write a SQL query, say:
> "Write a PDO prepared statement that selects all containers with status = 'cleared' that have no row in container_assignments yet."

### To continue from a stop point, say:
> "Read restart.md and continue from where we stopped."

---

## What Claude Should Always Do

- Output **complete, runnable files** — not partial snippets unless explicitly asked
- Include the `require_once` headers at the top of every PHP file
- Include role guards on every protected page
- Write the `audit_log_entry()` call on every state-changing action
- Add HTML comments marking major sections: `<!-- FORM -->`, `<!-- TABLE -->`, `<!-- FILTERS -->`
- Keep CSS inside the file for module pages (no separate CSS file per module)

## What Claude Should Never Do

- Use `mysqli_*` functions — use PDO only
- Put SQL queries inside loops
- Output HTML before processing POST data
- Use `die()` for error handling in production paths — use redirects
- Forget `exit` after `header('Location: ...')`
- Skip input validation before database writes
