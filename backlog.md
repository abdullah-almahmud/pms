# backlog.md — Everything Left To Do

## How To Use This File

- `[ ]` = not started
- `[~]` = in progress
- `[x]` = complete
- Update this file at the end of every session

---

## PHASE 1 — Foundation
*Must be done before any other work starts. Member 1 owns this entirely.*

- [ ] Create MySQL database `pms`
- [ ] **`/sql/schema.sql`** — full schema (21 tables + seed data from memory.md)
- [ ] **`/config/db.php`** — PDO connection, ERRMODE_EXCEPTION, return `$pdo`
- [ ] **`/includes/auth.php`** — `session_start()`, `require_login()`, `require_role(array)`
- [ ] **`/includes/helpers.php`** — `audit_log_entry()`, `status_badge()`, `format_dt()`
- [ ] **`/assets/css/main.css`** — base reset, layout, nav sidebar, table styles, form styles, badge styles (.badge-cleared, .badge-held, .badge-rejected, .badge-pending, .badge-approved, .badge-paid, .badge-unpaid, .badge-overdue)
- [ ] **`/assets/js/main.js`** — confirm dialogs for destructive actions, basic form validation feedback
- [ ] **`/login.php`**
  - [ ] GET: render login form (email + password)
  - [ ] POST: query users WHERE email + status=active, verify password_hash
  - [ ] On success: set `$_SESSION['user_id']`, `$_SESSION['role']`, `$_SESSION['name']`, redirect to `/dashboard.php`
  - [ ] On failure: show inline error "Invalid email or password"
  - [ ] If already logged in: redirect to `/dashboard.php`
- [ ] **`/logout.php`** — `session_destroy()`, redirect to `/login.php`
- [ ] **`/403.php`** — "Access Denied" message + link back to dashboard
- [ ] **`/index.php`** — if session active redirect to dashboard, else redirect to login
- [ ] **`/dashboard.php`**
  - [ ] `require_login()`
  - [ ] Admin cards: total active users, pending arrival requests, containers in_transit, unpaid invoices
  - [ ] Harbor Master cards: count of pending arrivals, count of occupied berths, today's docking list
  - [ ] Operations cards: containers awaiting inspection (status=arrived/inspecting), containers awaiting storage (status=cleared), active transport assignments
  - [ ] Finance cards: shifts today, unassigned tasks count, unpaid invoices count, overdue invoices count
  - [ ] Shared nav sidebar visible on all pages with links appropriate to role

---

## PHASE 2 — Vessel & Berth (Member 1)

### `/modules/vessel/arrivals.php`
- [ ] `require_role(['admin','harbor_master'])`
- [ ] GET: list ALL arrival_requests joined with vessels, ordered by created_at DESC
- [ ] Display columns: Vessel Name, Company, ETA, Cargo Type, Origin Port, Status (badge), Actions
- [ ] Filter bar: filter by status (all / pending / approved / rejected)
- [ ] APPROVE action (POST):
  - [ ] Validate request is currently `pending`
  - [ ] Update arrival_requests SET status='approved', reviewed_by, reviewed_at
  - [ ] Show berth allocation form inline or redirect to berths.php with arrival_request_id pre-selected
  - [ ] `audit_log_entry()` with action='APPROVE', module='vessel'
- [ ] REJECT action (POST):
  - [ ] Validate request is currently `pending`
  - [ ] Require a rejection note (notes field)
  - [ ] Update arrival_requests SET status='rejected', reviewed_by, reviewed_at, notes
  - [ ] `audit_log_entry()` with action='REJECT', module='vessel'
- [ ] NEW ARRIVAL form (inline or modal):
  - [ ] Fields: vessel_name (or select existing vessel), vessel_type, tonnage, company_name, ETA (datetime), cargo_type, origin_port
  - [ ] If vessel is new: INSERT into vessels first, then INSERT arrival_requests
  - [ ] `audit_log_entry()` with action='CREATE', module='vessel'

### `/modules/vessel/berths.php`
- [ ] `require_role(['admin','harbor_master'])`
- [ ] GET: show grid of all berths with current status (available/occupied badge)
- [ ] For each OCCUPIED berth: show which vessel is docked (join berth_allocations → arrival_requests → vessels)
- [ ] ALLOCATE BERTH form (POST):
  - [ ] Dropdown of approved arrival_requests that have no existing berth_allocation
  - [ ] Dropdown of available berths
  - [ ] Fields: docking_start (datetime), expected_departure (datetime)
  - [ ] Validate: berth status = 'available', arrival_request status = 'approved', no existing allocation for that request
  - [ ] INSERT berth_allocations
  - [ ] UPDATE berths SET status='occupied'
  - [ ] `audit_log_entry()` with action='CREATE', module='vessel'
- [ ] RECORD DEPARTURE button (POST, per allocated berth):
  - [ ] Set berth_allocations.actual_departure = NOW()
  - [ ] UPDATE berths SET status='available'
  - [ ] `audit_log_entry()` with action='UPDATE', module='vessel'
- [ ] RECORD ACTUAL ARRIVAL (POST):
  - [ ] Set berth_allocations.actual_arrival = NOW()

### `/modules/vessel/schedule.php`
- [ ] `require_role(['admin','harbor_master','operations','finance'])`
- [ ] GET: read-only table of all berth_allocations joined with vessels and berths
- [ ] Display columns: Vessel Name, Company, Berth, Docking Start, Expected Departure, Actual Arrival, Actual Departure, Status
- [ ] Status derived: if actual_departure set → "Departed", if actual_arrival set → "Docked", else → "Expected"
- [ ] Filter: by date range, by berth
- [ ] No write actions on this page

---

## PHASE 2 — Customs (Member 2)

### `/modules/customs/manifests.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: list all cargo_manifests joined with arrival_requests → vessels, ordered by submitted_at DESC
- [ ] Display columns: Vessel Name, Arrival ETA, Submitted By, Submitted At, Item Count, Actions (View)
- [ ] View manifest: show all manifest_items for that manifest in a sub-table
- [ ] SUBMIT NEW MANIFEST form (POST):
  - [ ] Select from approved arrival_requests that have NO existing manifest
  - [ ] Dynamic form: add multiple manifest items (container_code, cargo_type, declared_weight, declared_value, importer_name, exporter_name) — use JS to add/remove rows
  - [ ] Validate: at least 1 manifest item required
  - [ ] INSERT cargo_manifests, then INSERT manifest_items (loop)
  - [ ] `audit_log_entry()` with action='CREATE', module='customs'

### `/modules/customs/inspections.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: list all containers joined with vessels, show current status
- [ ] Filter by: inspection result (all / pending / cleared / held / rejected), vessel
- [ ] "Pending" = containers with status 'arrived' or 'inspecting' and no row in customs_inspections
- [ ] RECORD INSPECTION (POST, per container):
  - [ ] Form fields: result (cleared/held/rejected), hold_reason (required if held)
  - [ ] Validate: container does not already have an inspection record
  - [ ] INSERT customs_inspections
  - [ ] UPDATE containers SET status = result value ('cleared'/'held'/'rejected')
  - [ ] `audit_log_entry()` with action='CREATE', module='customs'

---

## PHASE 3 — Cargo & Warehouse (Member 2)

### `/modules/cargo/containers.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: list all containers with status badge, vessel name, direction, type
- [ ] Filter by: status, direction (inbound/outbound), container_type
- [ ] REGISTER NEW CONTAINER form (POST):
  - [ ] Fields: container_code, container_type, size_teu, weight_kg, direction, vessel_id (select from vessels), manifest_item_id (optional select)
  - [ ] Validate: container_code is unique
  - [ ] INSERT containers with status='arrived'
  - [ ] `audit_log_entry()` with action='CREATE', module='cargo'
- [ ] View container detail: show full status history (current status, inspection result, storage slot if any, transport if any)

### `/modules/cargo/storage.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: two panels:
  - [ ] Left: list of cleared containers with no current container_assignment (status='cleared')
  - [ ] Right: grid of storage_locations with status (available/occupied badge), show occupying container if occupied
- [ ] ASSIGN TO STORAGE (POST):
  - [ ] Select container (must be status=cleared) + select storage slot (must be status=available)
  - [ ] Validate type compatibility: container.container_type = storage_location.slot_type
  - [ ] INSERT container_assignments (assigned_at=NOW(), vacated_at=NULL)
  - [ ] UPDATE containers SET status='stored'
  - [ ] UPDATE storage_locations SET status='occupied'
  - [ ] `audit_log_entry()` with action='CREATE', module='cargo'
- [ ] VACATE SLOT (POST, when container leaves storage for transport):
  - [ ] Set container_assignments.vacated_at = NOW()
  - [ ] UPDATE storage_locations SET status='available'
  - [ ] (Container status updated to 'in_transit' by transport module)

---

## PHASE 3 — Logistics & Transport (Member 3)

### `/modules/logistics/transport.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: list all transport_assignments joined with containers, ordered by scheduled_pickup
- [ ] Display columns: Container Code, Vessel, Mode, Carrier, Vehicle, Destination, Scheduled Pickup, Status badge
- [ ] Filter by: status, transport_mode
- [ ] CREATE TRANSPORT ASSIGNMENT form (POST):
  - [ ] Select container (must be status='stored')
  - [ ] Fields: transport_mode, carrier_name, vehicle_id, destination, scheduled_pickup (datetime)
  - [ ] Validate: container.status = 'stored'
  - [ ] INSERT transport_assignments (status='scheduled')
  - [ ] UPDATE containers SET status='in_transit'
  - [ ] UPDATE storage_locations (via container_assignments) SET status='available' + set vacated_at
  - [ ] `audit_log_entry()` with action='CREATE', module='logistics'
- [ ] UPDATE STATUS (POST): change transport status to 'in_transit' or 'delivered'
  - [ ] On 'delivered': UPDATE containers SET status='delivered'

### `/modules/logistics/delivery.php`
- [ ] `require_role(['admin','operations'])`
- [ ] GET: list all delivery_logs joined with transport_assignments → containers
- [ ] If no delivery_log exists for a transport: show "No delivery log yet" with option to create one
- [ ] Display columns: Container Code, Carrier, Destination, Expected Delivery, Actual Delivery, Status, Delay Reason
- [ ] CREATE / UPDATE DELIVERY LOG (POST):
  - [ ] Fields: transport_assignment_id, expected_delivery (datetime), actual_delivery (datetime, optional), delay_reason (text, optional)
  - [ ] If actual_delivery set + no delay_reason: UPDATE transport_assignments SET status='delivered'
  - [ ] If delay_reason set: UPDATE transport_assignments SET status='delayed'
  - [ ] `audit_log_entry()` with action='UPDATE', module='logistics'

---

## PHASE 4 — Workforce (Member 3)

### `/modules/workforce/shifts.php`
- [ ] `require_role(['admin','finance'])`
- [ ] GET: list all shifts with date, time range, zone, worker count
- [ ] For each shift: show list of assigned workers (via shift_assignments)
- [ ] Filter by: date, zone
- [ ] CREATE SHIFT form (POST):
  - [ ] Fields: shift_date, start_time, end_time, zone
  - [ ] INSERT shifts
  - [ ] `audit_log_entry()` with action='CREATE', module='workforce'
- [ ] ASSIGN WORKER TO SHIFT (POST):
  - [ ] Select worker (active only) + select shift
  - [ ] Validate: worker not already in this shift (UNIQUE constraint handles DB level, validate in PHP too)
  - [ ] INSERT shift_assignments
  - [ ] `audit_log_entry()` with action='CREATE', module='workforce'
- [ ] REMOVE worker from shift (POST): DELETE shift_assignments row

### `/modules/workforce/tasks.php`
- [ ] `require_role(['admin','finance'])`
- [ ] GET: list all task_assignments joined with workers, ordered by assigned_at DESC
- [ ] Display columns: Worker Name, Job Role, Task Type, Linked To (ref_type + ref_id), Status badge, Shift
- [ ] Filter by: worker, task_type, status
- [ ] CREATE TASK form (POST):
  - [ ] Select worker (active only)
  - [ ] Select shift (optional)
  - [ ] Fields: task_type (enum dropdown), ref_type (vessel/container/storage_location), ref_id (dynamic based on ref_type)
  - [ ] INSERT task_assignments (status='assigned')
  - [ ] `audit_log_entry()` with action='CREATE', module='workforce'
- [ ] UPDATE TASK STATUS (POST): assigned → in_progress → done
  - [ ] `audit_log_entry()` with action='UPDATE', module='workforce'

---

## PHASE 4 — Finance (Member 4)

### `/modules/finance/invoices.php`
- [ ] `require_role(['admin','finance'])`
- [ ] GET: list all invoices with type, client, amount, due date, status badge
- [ ] Filter by: invoice_type, status
- [ ] GENERATE INVOICE form (POST):
  - [ ] Select invoice_type (berth / storage / transport)
  - [ ] Based on type, show dropdown of eligible ref_ids:
    - [ ] berth: select from berth_allocations (with vessel name label)
    - [ ] storage: select from container_assignments (with container code label)
    - [ ] transport: select from transport_assignments (with container code label)
  - [ ] Fields: client_name, amount, due_date
  - [ ] INSERT invoices (status='unpaid')
  - [ ] `audit_log_entry()` with action='CREATE', module='finance'
- [ ] MARK AS OVERDUE button (POST, for unpaid invoices past due_date):
  - [ ] UPDATE invoices SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()
  - [ ] (Can be triggered manually or auto-checked on page load)

### `/modules/finance/payments.php`
- [ ] `require_role(['admin','finance'])`
- [ ] GET: list all payments joined with invoices, ordered by payment_date DESC
- [ ] RECORD PAYMENT form (POST):
  - [ ] Select invoice (unpaid or overdue only)
  - [ ] Fields: amount_paid, payment_date, notes
  - [ ] INSERT payments
  - [ ] UPDATE invoices SET status='paid' (assuming full payment — partial not required in reduced scope)
  - [ ] `audit_log_entry()` with action='CREATE', module='finance'
- [ ] View invoice detail: show all payments made against it

---

## PHASE 5 — Admin (Member 1)

### `/modules/admin/users.php`
- [ ] `require_role(['admin'])`
- [ ] GET: list all users with name, email, role label, status badge
- [ ] Filter by: role, status
- [ ] CREATE USER form (POST):
  - [ ] Fields: name, email, password (plain text input → hash with password_hash()), role_id
  - [ ] Validate: email unique
  - [ ] INSERT users
  - [ ] `audit_log_entry()` with action='CREATE', module='admin'
- [ ] EDIT USER (POST):
  - [ ] Can change: name, email, role_id
  - [ ] Password change: separate optional field — only update hash if new password provided
  - [ ] `audit_log_entry()` with action='UPDATE', module='admin'
- [ ] DEACTIVATE / REACTIVATE (POST):
  - [ ] Toggle status between 'active' and 'inactive'
  - [ ] Cannot deactivate own account
  - [ ] `audit_log_entry()` with action='UPDATE', module='admin'

### `/modules/admin/audit.php`
- [ ] `require_role(['admin'])`
- [ ] GET: list all audit_log rows joined with users (for name), ordered by created_at DESC
- [ ] Display columns: Timestamp, User Name, Action, Module, Record ID, Description
- [ ] Filters: user (select), module (select), action (select), date range (from/to)
- [ ] Read-only — no write actions

---

## OPTIONAL (Add If Time Permits — In Priority Order)

- [ ] **Dashboard live counts** — add COUNT queries to dashboard for each role (if not already done with full detail)
- [ ] **Cargo Flow summary** — simple one-page report: container counts by status (SELECT status, COUNT(*) FROM containers GROUP BY status)
- [ ] **Container status history** — add a ContainerStatusLog table and write to it on every status change; show timeline on container detail view
- [ ] **Duty & tax field** — add `duty_amount DECIMAL(15,2)` to customs_inspections; show on inspection page; auto-populate invoice amount suggestion
- [ ] **Worker registry page** — `/modules/workforce/workers.php` — list + add + deactivate workers (currently workers must be inserted directly or via seed data)
- [ ] **Logout confirmation** — add a confirm prompt before logout
- [ ] **Pagination** — add LIMIT/OFFSET to all list pages that could have many rows
- [ ] **Print-friendly invoice view** — `/modules/finance/invoice_print.php?id=X` — clean HTML for browser print
- [ ] **Overdue auto-flag on page load** — run `UPDATE invoices SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()` at the top of invoices.php GET handler

---

## Shared / Cross-Cutting Tasks

- [ ] **Navigation sidebar** — role-aware links rendered in all pages (include from a shared `/includes/nav.php`)
- [ ] **Page title consistency** — all pages have `<title>PMS — [Page Name]</title>`
- [ ] **Flash messages** — success/error messages after POST redirect (store in `$_SESSION['flash']`, render and clear on next page load)
- [ ] **Empty state handling** — all list pages show "No records found" when table is empty (not a blank table)
- [ ] **Seed test data** — create a SQL file `/sql/seed_test_data.sql` with sample vessels, users, containers for testing the full flow end-to-end

---

## Definition of Done (Per Page)

A page is complete when:
1. Role guard is in place and tested (wrong role → 403)
2. All listed features above work end-to-end
3. All POST actions call `audit_log_entry()`
4. All status transitions are validated before DB write
5. HTML renders without layout breaks
6. No raw PHP errors or warnings on the page
