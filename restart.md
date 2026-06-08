# restart.md — Where We Are & Where We Stopped

## Current Status: Planning Complete — Implementation Not Started

All planning and design decisions are finalized. No code has been written yet.
This file is the handoff point between planning and building.

---

## What Is 100% Done (Planning)

- [x] Project scope defined and reduced (from 47 pages → 18 pages)
- [x] Roles finalized (8 → 4 roles, with merge rationale documented)
- [x] All 18 pages defined with URLs and role access
- [x] Full database schema written (21 tables, all FK constraints, seed data)
- [x] Core data flow documented (8-step spine)
- [x] All status lifecycles defined (container, berth, arrival, invoice, transport)
- [x] File/folder structure decided
- [x] Team member module assignments decided
- [x] Coding rules and conventions decided (in claude.md)

---

## What To Build First (Phase 1 — Foundation)

These must be completed before anyone else can work. Member 1 owns this.

### Step 1 — Database
- [ ] Create MySQL database named `pms`
- [ ] Run `/sql/schema.sql` to create all 21 tables
- [ ] Verify seed data inserted (4 roles, 1 admin user, 4 berths, 9 storage slots)

### Step 2 — Config & Shared Files
- [ ] Write `/config/db.php` — PDO connection
- [ ] Write `/includes/auth.php` — `require_login()`, `require_role()`
- [ ] Write `/includes/helpers.php` — `audit_log_entry()`, `status_badge()`, `format_dt()`
- [ ] Write `/assets/css/main.css` — base styles, nav, tables, forms, badges

### Step 3 — Auth Pages
- [ ] Build `/login.php` — email + password form, session creation, role-based redirect
- [ ] Build `/403.php` — unauthorized page
- [ ] Build `/index.php` — redirect to dashboard if logged in, else to login

### Step 4 — Dashboard
- [ ] Build `/dashboard.php` — role-aware summary cards (COUNT queries per role)

---

## Phase 2 — Core Spine (After Phase 1 is done)

### Member 1 — Vessel & Berth
- [ ] `/modules/vessel/arrivals.php` — list + approve/reject
- [ ] `/modules/vessel/berths.php` — berth status grid + allocate berth
- [ ] `/modules/vessel/schedule.php` — read-only docking schedule

### Member 2 — Customs
- [ ] `/modules/customs/manifests.php` — list manifests + submit new manifest with line items
- [ ] `/modules/customs/inspections.php` — list containers by manifest + record inspection result

---

## Phase 3 — Operations (After Phase 2)

### Member 2 — Cargo
- [ ] `/modules/cargo/containers.php` — list all containers + register new container
- [ ] `/modules/cargo/storage.php` — view storage slots + assign cleared container to slot

### Member 3 — Logistics
- [ ] `/modules/logistics/transport.php` — list transport + create new assignment
- [ ] `/modules/logistics/delivery.php` — track expected vs actual + log delays

---

## Phase 4 — People & Money (Can run parallel to Phase 3)

### Member 3 — Workforce
- [ ] `/modules/workforce/shifts.php` — create shifts + assign workers
- [ ] `/modules/workforce/tasks.php` — assign tasks to workers

### Member 4 — Finance
- [ ] `/modules/finance/invoices.php` — list + generate invoices
- [ ] `/modules/finance/payments.php` — record payments against invoices

---

## Phase 5 — Admin Layer (After Phase 1, can run any time)

### Member 1 — Admin
- [ ] `/modules/admin/users.php` — create/edit/deactivate users + assign roles
- [ ] `/modules/admin/audit.php` — read-only audit log with date/user/module filters

---

## The Very First Prompt To Send Claude

Copy and paste this exactly to start building:

```
Read claude.md and memory.md first.

We are starting Phase 1 of the PMS project. Build the following files in order:

1. /config/db.php
   - PDO connection to MySQL
   - Database name: pms
   - Host: localhost, user: root, pass: (leave as empty string, I will fill in)
   - Return $pdo as a variable
   - Set error mode to ERRMODE_EXCEPTION

2. /includes/auth.php
   - session_start() at top
   - require_login(): if no $_SESSION['user_id'], redirect to /login.php and exit
   - require_role(array $allowed): if $_SESSION['role'] not in $allowed, redirect to /403.php and exit

3. /includes/helpers.php
   - audit_log_entry($pdo, $user_id, $action, $module, $record_id, $description): INSERT into audit_log
   - status_badge($status): return <span class="badge badge-{$status}">{$status}</span>
   - format_dt($dt): return date('d M Y, H:i', strtotime($dt))

4. /sql/schema.sql
   - The full schema from memory.md exactly as written

5. /login.php
   - POST: validate email + password against users table (status = active), set session, redirect by role
   - GET: show login form
   - Redirect targets: admin→/dashboard.php, harbor_master→/dashboard.php, operations→/dashboard.php, finance→/dashboard.php

6. /403.php
   - Simple unauthorized page with a back link

7. /index.php
   - If logged in, redirect to /dashboard.php; else redirect to /login.php

Output each file completely. Do not skip any file.
```

---

## When Resuming Mid-Project

If you are coming back after a session, use this prompt:

```
Read claude.md, memory.md, and backlog.md.
Check backlog.md for the first unchecked item under "In Progress" or "Up Next".
Build that file completely. When done, tell me which backlog item to mark as complete
and what the next item is.
```

---

## Known Decisions That Cannot Be Changed

- No frameworks — plain PHP only
- PDO only — no mysqli
- 4 roles exactly as defined — do not add more
- `operations` role covers customs + cargo + logistics (it is ONE role, not three)
- The `finance` role covers both workforce scheduling AND finance/billing
- All 21 table names are fixed — do not rename them
- Container status enum values are fixed — do not change the spelling
