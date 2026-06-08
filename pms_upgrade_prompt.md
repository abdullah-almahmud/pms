# PMS Upgrade Instructions — Complete Prompt for AI

## Context Files You Must Read First

Before doing anything, read both attached files completely:
- `memory.md` — the full database schema, all 21 tables, all status lifecycles, team ownership, and the 18 existing pages
- `claude.md` — all coding rules: PDO only, no mysqli, PHP session auth, role guards, audit log calls, file structure

Do not start any phase until you have confirmed you understand both files. If anything is unclear, ask before writing code.

---

## What This Project Is

A Port Management System (PMS). PHP procedural, MySQL, HTML5/CSS3/Vanilla JS. No frameworks. No Composer. No npm. Server-rendered pages only. Every protected page must call `require_login()` then `require_role()`. All SQL must use PDO prepared statements. No string interpolation in SQL ever.

---

## Phase 1 — Schema Additions (SQL only, no PHP yet)

**File to edit:** `/sql/schema.sql`

Add the following at the bottom of the file, after all existing CREATE TABLE statements. Do not modify any existing table definitions unless explicitly told to below.

### 1.1 — Add columns to `task_assignments`

```sql
ALTER TABLE task_assignments
    ADD COLUMN assigned_to  INT          DEFAULT NULL,
    ADD COLUMN started_at   DATETIME     DEFAULT NULL,
    ADD COLUMN completed_at DATETIME     DEFAULT NULL,
    ADD COLUMN sla_deadline DATETIME     DEFAULT NULL,
    ADD CONSTRAINT fk_ta_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id);
```

### 1.2 — Add columns to `berths`

```sql
ALTER TABLE berths
    ADD COLUMN is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN depth_metres DECIMAL(6,2) DEFAULT NULL,
    ADD COLUMN quay_length  DECIMAL(8,2) DEFAULT NULL,
    ADD COLUMN notes        TEXT         DEFAULT NULL;
```

### 1.3 — Create `employee_flags` table

```sql
CREATE TABLE IF NOT EXISTS employee_flags (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NOT NULL,
    flag_type       ENUM(
                        'HIGH_REJECTION_RATE',
                        'SLOW_INSPECTION',
                        'STALE_TASK',
                        'LATE_BERTH_ALLOCATION',
                        'STORAGE_QUEUE_OVERFLOW',
                        'LATE_INVOICE',
                        'TRANSPORT_DELAY'
                    ) NOT NULL,
    threshold_value DECIMAL(10,2) DEFAULT NULL,
    actual_value    DECIMAL(10,2) DEFAULT NULL,
    flagged_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME DEFAULT NULL,
    resolved_by     INT      DEFAULT NULL,
    resolve_note    TEXT     DEFAULT NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

### 1.4 — Create `worker_roles` table (replaces ENUM on `workers`)

```sql
CREATE TABLE IF NOT EXISTS worker_roles (
    id    INT PRIMARY KEY AUTO_INCREMENT,
    name  VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL
);

INSERT INTO worker_roles (name, label) VALUES
    ('crane_operator',    'Crane Operator'),
    ('loader',            'Loader'),
    ('driver',            'Driver'),
    ('inspector',         'Inspector'),
    ('supervisor',        'Supervisor'),
    ('forklift_operator', 'Forklift Operator'),
    ('security_guard',    'Security Guard');
```

### 1.5 — Add new roles to the `roles` table

```sql
INSERT IGNORE INTO roles (name, label) VALUES
    ('customs_officer',       'Customs Officer'),
    ('manifest_clerk',        'Manifest Clerk'),
    ('cargo_manager',         'Cargo Manager'),
    ('logistics_coordinator', 'Logistics Coordinator'),
    ('workforce_manager',     'Workforce Manager'),
    ('finance_officer',       'Finance Officer'),
    ('port_supervisor',       'Port Supervisor');
```

### 1.6 — Create the stored procedure for officer flagging

```sql
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS sp_check_officer_flags(IN p_user_id INT)
BEGIN
    DECLARE v_rejection_rate DECIMAL(5,2) DEFAULT 0;
    DECLARE v_avg_minutes    DECIMAL(10,2) DEFAULT 0;

    -- Calculate rejection rate over last 30 days
    SELECT
        IFNULL(SUM(ci.result = 'rejected') * 100.0 / NULLIF(COUNT(*), 0), 0),
        IFNULL(AVG(TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at)), 0)
    INTO v_rejection_rate, v_avg_minutes
    FROM customs_inspections ci
    JOIN task_assignments ta
         ON  ta.assigned_to = p_user_id
         AND ta.ref_id      = ci.container_id
         AND ta.ref_type    = 'container'
    WHERE ci.inspected_by  = p_user_id
      AND ci.inspected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);

    -- Flag: rejection rate > 40%
    IF v_rejection_rate > 40 THEN
        INSERT IGNORE INTO employee_flags (user_id, flag_type, threshold_value, actual_value)
        VALUES (p_user_id, 'HIGH_REJECTION_RATE', 40.00, v_rejection_rate);
    END IF;

    -- Flag: average inspection time > 120 minutes
    IF v_avg_minutes > 120 THEN
        INSERT IGNORE INTO employee_flags (user_id, flag_type, threshold_value, actual_value)
        VALUES (p_user_id, 'SLOW_INSPECTION', 120.00, v_avg_minutes);
    END IF;
END$$
DELIMITER ;
```

### 1.7 — Create the DB view for employee monitoring

```sql
CREATE OR REPLACE VIEW vw_employee_task_summary AS
SELECT
    u.id                                                         AS user_id,
    u.name                                                       AS employee_name,
    u.email,
    r.label                                                      AS role_label,
    COUNT(ta.id)                                                 AS tasks_total,
    SUM(ta.status = 'done')                                      AS tasks_done,
    SUM(ta.status = 'in_progress')                               AS tasks_active,
    SUM(ta.status = 'assigned')                                  AS tasks_pending,
    ROUND(AVG(
        CASE WHEN ta.completed_at IS NOT NULL AND ta.started_at IS NOT NULL
             THEN TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at)
             ELSE NULL
        END
    ), 1)                                                        AS avg_task_minutes,
    SUM(
        CASE WHEN ta.sla_deadline IS NOT NULL
                  AND ta.completed_at IS NULL
                  AND ta.sla_deadline < NOW()
             THEN 1 ELSE 0
        END
    )                                                            AS sla_breaches,
    COUNT(ef.id)                                                 AS active_flags
FROM users u
JOIN roles r ON r.id = u.role_id
LEFT JOIN task_assignments ta ON ta.assigned_to = u.id
LEFT JOIN employee_flags ef
    ON  ef.user_id    = u.id
    AND ef.resolved_at IS NULL
GROUP BY u.id, u.name, u.email, r.label;
```

**After writing all SQL:** Output the complete updated `schema.sql` as a single runnable file. Do not split it. Do not omit any existing tables.

---

## Phase 2 — Update `includes/helpers.php`

**File to edit:** `/includes/helpers.php`

Add these three functions to the existing file. Do not remove `audit_log_entry()`, `status_badge()`, or `format_dt()`.

### 2.1 — `flag_employee()`

```php
/**
 * Inserts an employee flag row. Call after any action that could
 * indicate poor performance (e.g. after saving an inspection result).
 * Uses INSERT IGNORE so duplicate active flags are silently skipped.
 */
function flag_employee(PDO $pdo, int $user_id, string $flag_type,
                       float $threshold, float $actual): void {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO employee_flags
            (user_id, flag_type, threshold_value, actual_value)
        VALUES (:uid, :type, :threshold, :actual)
    ");
    $stmt->execute([
        ':uid'       => $user_id,
        ':type'      => $flag_type,
        ':threshold' => $threshold,
        ':actual'    => $actual,
    ]);
}
```

### 2.2 — `task_start()` and `task_complete()`

```php
/**
 * Marks a task as started. Sets started_at = NOW(), status = in_progress.
 * Call when a user clicks "Start Task" on their task list.
 */
function task_start(PDO $pdo, int $task_id): void {
    $stmt = $pdo->prepare("
        UPDATE task_assignments
        SET    status     = 'in_progress',
               started_at = NOW()
        WHERE  id         = :id
          AND  status     = 'assigned'
    ");
    $stmt->execute([':id' => $task_id]);
}

/**
 * Marks a task as done. Sets completed_at = NOW(), status = done.
 * Call when a user clicks "Complete Task".
 */
function task_complete(PDO $pdo, int $task_id): void {
    $stmt = $pdo->prepare("
        UPDATE task_assignments
        SET    status       = 'done',
               completed_at = NOW()
        WHERE  id           = :id
          AND  status       = 'in_progress'
    ");
    $stmt->execute([':id' => $task_id]);
}
```

**Output:** The complete updated `helpers.php` file.

---

## Phase 3 — Build `/modules/admin/employee_monitor.php`

This is a brand new file. Build the complete file from scratch.

**Access:** Admin only. Role guard: `require_role(['admin'])`.

**What this page does:**
1. Reads all rows from `vw_employee_task_summary` — one row per user.
2. Displays a table with columns: Employee Name, Role, Tasks (Pending / Active / Done), Avg Task Time (minutes), SLA Breaches, Active Flags.
3. For any user with `active_flags > 0`, render their name with a red `<span class="badge badge-danger">` and add a "View Flags" link.
4. For any user with `sla_breaches > 0`, colour that cell amber using a CSS class.
5. At the top of the page, show a summary bar: total users, total active flags across all users, total SLA breaches.

**POST action — Resolve a flag:**
When admin clicks "View Flags" for a user, the same page shows a second section below the table: a list of that user's unresolved flags (`SELECT * FROM employee_flags WHERE user_id = :uid AND resolved_at IS NULL`). Each flag has a small form with a textarea for `resolve_note` and a submit button. On POST, run:

```sql
UPDATE employee_flags
SET resolved_at = NOW(),
    resolved_by = :admin_id,
    resolve_note = :note
WHERE id = :flag_id
  AND resolved_at IS NULL;
```

Then call `audit_log_entry()` and redirect back to the same page with `?user_id=X` to keep the panel open.

**Coding rules to follow:**
- Process all POST at the top before any HTML output.
- Use PDO prepared statements for every query.
- `htmlspecialchars()` on every value rendered in HTML.
- Add HTML comments: `<!-- SUMMARY BAR -->`, `<!-- EMPLOYEE TABLE -->`, `<!-- FLAG PANEL -->`.
- Keep all CSS in a `<style>` block at the top of the page.
- No inline `style=""` attributes.

**Output:** The complete runnable `employee_monitor.php` file.

---

## Phase 4 — Build `/modules/admin/flags.php`

This is a new file. Admin only.

**What this page does:**
1. Shows the complete flag history: all rows from `employee_flags` joined with `users` (flagged user name) and `users` again for `resolved_by` name.
2. Has a filter bar at the top (GET parameters): filter by `flag_type` (dropdown of all ENUM values) and by `status` (unresolved / resolved / all). Default: unresolved.
3. Displays a table: Employee, Flag Type, Threshold, Actual Value, Flagged At, Status, Resolved By, Resolve Note.
4. Flag type values should be rendered as human-readable labels (e.g. `HIGH_REJECTION_RATE` → "High Rejection Rate").
5. Unresolved rows get a red left border via CSS. Resolved rows get a green left border.

**SQL query for this page (write as a PDO prepared statement):**

```sql
SELECT
    ef.id,
    ef.flag_type,
    ef.threshold_value,
    ef.actual_value,
    ef.flagged_at,
    ef.resolved_at,
    ef.resolve_note,
    u_flagged.name  AS flagged_user,
    u_flagged.email AS flagged_email,
    r.label         AS flagged_role,
    u_resolver.name AS resolved_by_name
FROM employee_flags ef
JOIN  users u_flagged  ON u_flagged.id  = ef.user_id
JOIN  roles r          ON r.id          = u_flagged.role_id
LEFT JOIN users u_resolver ON u_resolver.id = ef.resolved_by
WHERE (:flag_type = '' OR ef.flag_type = :flag_type)
  AND (
      (:status = 'unresolved' AND ef.resolved_at IS NULL)
   OR (:status = 'resolved'   AND ef.resolved_at IS NOT NULL)
   OR (:status = 'all')
  )
ORDER BY ef.flagged_at DESC;
```

Note: PDO does not allow the same named parameter twice. Rename duplicates to `:flag_type2` and `:status2`, `:status3` in the actual prepared statement.

**Output:** The complete runnable `flags.php` file.

---

## Phase 5 — Rewrite `/modules/vessel/berths.php`

**Replace** the current hardcoded visual box layout entirely. Build a functional, data-driven berths management page.

**Access:** Admin and Harbor Master. `require_role(['admin', 'harbor_master'])`.

**What this page does:**

### Section A — Berth Status Table

Query all berths with their current vessel (if occupied):

```sql
SELECT
    b.id,
    b.berth_name,
    b.berth_type,
    b.max_tonnage,
    b.depth_metres,
    b.quay_length,
    b.status,
    b.is_active,
    b.notes,
    v.vessel_name                AS current_vessel,
    ar.eta                       AS vessel_eta,
    ba.expected_departure        AS vessel_departs,
    ba.docking_start
FROM berths b
LEFT JOIN berth_allocations ba
    ON  ba.berth_id        = b.id
    AND ba.actual_departure IS NULL
LEFT JOIN arrival_requests ar ON ar.id  = ba.arrival_request_id
LEFT JOIN vessels          v  ON v.id   = ar.vessel_id
WHERE b.is_active = 1
ORDER BY b.berth_type, b.berth_name;
```

Display this as a clean HTML table. Columns: Berth Name, Type, Max Tonnage (t), Depth (m), Status badge (available = green, occupied = red), Current Vessel (or "—"), Expected Departure (or "—"), Actions.

Actions column:
- If `status = occupied`: show a "Release Berth" button (POST form). On POST, set `berth.status = available` and write `berth_allocations.actual_departure = NOW()`. Use a transaction. Call `audit_log_entry()`.
- If `status = available`: show "—".

### Section B — Add New Berth (Admin only)

Show this section only if `$_SESSION['role'] === 'admin'`.

A form to insert a new berth:
- Fields: berth_name (text, required), berth_type (select: Container / Bulk / General / RoRo / Liquid), max_tonnage (number), depth_metres (number), quay_length (number), notes (textarea).
- On POST: validate that `berth_name` is not empty, then INSERT. Redirect. Call `audit_log_entry()`.

**Output:** The complete runnable `berths.php` file.

---

## Phase 6 — Add Advanced SQL Queries as a Report Page

**Build:** `/modules/admin/reports.php`
**Access:** Admin only.

This page is read-only. It runs five analytical queries and displays the results as tables. No forms. No POST handling. It exists to demonstrate DBMS capability.

### Report 1 — Company Throughput Ranking (Window Function)

```sql
SELECT
    v.company_name,
    COUNT(c.id)                                          AS total_cleared,
    RANK() OVER (ORDER BY COUNT(c.id) DESC)              AS company_rank,
    ROUND(
        COUNT(c.id) * 100.0 /
        SUM(COUNT(c.id)) OVER ()
    , 2)                                                 AS pct_of_total
FROM vessels v
JOIN containers c ON c.vessel_id = v.id
WHERE c.status = 'cleared'
GROUP BY v.company_name
ORDER BY company_rank;
```

Display as a table. Label the page section: "Company Throughput Ranking".

### Report 2 — Zone Capacity Utilisation

```sql
SELECT
    zone,
    slot_type,
    COUNT(*)                                              AS total_slots,
    SUM(status = 'occupied')                              AS occupied,
    SUM(status = 'available')                             AS available,
    ROUND(SUM(status = 'occupied') * 100.0 / COUNT(*), 1) AS pct_used,
    CASE
        WHEN SUM(status = 'occupied') * 100.0 / COUNT(*) >= 90 THEN 'critical'
        WHEN SUM(status = 'occupied') * 100.0 / COUNT(*) >= 70 THEN 'high'
        ELSE 'normal'
    END                                                   AS capacity_status
FROM storage_locations
GROUP BY zone, slot_type
ORDER BY pct_used DESC;
```

Colour-code the `pct_used` cell: red if `capacity_status = critical`, amber if `high`, green if `normal`.

### Report 3 — Containers Above Officer's Own Average Inspection Time (Correlated Subquery)

```sql
SELECT
    u.name                                                      AS officer,
    c.container_code,
    TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at)       AS minutes_taken
FROM customs_inspections ci
JOIN users            u  ON u.id  = ci.inspected_by
JOIN containers       c  ON c.id  = ci.container_id
JOIN task_assignments ta ON ta.assigned_to = ci.inspected_by
                        AND ta.ref_id       = ci.container_id
                        AND ta.ref_type     = 'container'
WHERE TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at) > (
    SELECT AVG(TIMESTAMPDIFF(MINUTE, ta2.started_at, ta2.completed_at))
    FROM   customs_inspections ci2
    JOIN   task_assignments    ta2
           ON  ta2.assigned_to = ci2.inspected_by
           AND ta2.ref_id      = ci2.container_id
           AND ta2.ref_type    = 'container'
    WHERE  ci2.inspected_by = ci.inspected_by
)
ORDER BY u.name, minutes_taken DESC;
```

If no rows returned, show: "No inspection data yet."

### Report 4 — Rolling 7-Day Container Arrival Volume (Window Function)

```sql
SELECT
    arrival_day,
    daily_count,
    SUM(daily_count) OVER (
        ORDER BY arrival_day
        ROWS BETWEEN 6 PRECEDING AND CURRENT ROW
    ) AS rolling_7d
FROM (
    SELECT
        DATE(ar.eta)  AS arrival_day,
        COUNT(c.id)   AS daily_count
    FROM arrival_requests ar
    JOIN containers c ON c.vessel_id = ar.vessel_id
    GROUP BY DATE(ar.eta)
) sub
ORDER BY arrival_day DESC
LIMIT 30;
```

### Report 5 — Full Invoice Status Summary

```sql
SELECT
    invoice_type,
    COUNT(*)                                                  AS total_invoices,
    SUM(status = 'unpaid')                                    AS unpaid,
    SUM(status = 'paid')                                      AS paid,
    SUM(status = 'overdue')                                   AS overdue,
    ROUND(SUM(amount), 2)                                     AS total_value,
    ROUND(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 2) AS collected,
    ROUND(
        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END)
        * 100.0 / NULLIF(SUM(amount), 0)
    , 1)                                                      AS collection_rate_pct
FROM invoices
GROUP BY invoice_type;
```

**Page layout:** Each report is its own `<section>` with an `<h2>` heading. Use a single `<style>` block at the top. No other CSS file needed.

**Output:** The complete runnable `reports.php` file.

---

## Phase 7 — Add Trigger to `schema.sql`

Add this trigger to `schema.sql`, after the stored procedure from Phase 1.6:

```sql
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_invoice_overdue
BEFORE UPDATE ON invoices
FOR EACH ROW
BEGIN
    IF NEW.status = 'unpaid' AND NEW.due_date < CURDATE() THEN
        SET NEW.status = 'overdue';
    END IF;
END$$
DELIMITER ;
```

**Output:** Confirm that `schema.sql` includes this trigger. Do not re-output the whole file; just confirm and show the added block.

---

## Phase 8 — Update Navigation and `memory.md`

### 8.1 — Navigation

Find wherever the admin navigation links are rendered (likely in a `<nav>` in `dashboard.php` or a shared include). Add these new links, visible only to admin:

```php
<?php if ($_SESSION['role'] === 'admin'): ?>
    <a href="/modules/admin/employee_monitor.php">Employee Monitor</a>
    <a href="/modules/admin/flags.php">Flag History</a>
    <a href="/modules/admin/reports.php">Reports</a>
<?php endif; ?>
```

### 8.2 — Update `memory.md`

Add the following to the "The 18 Pages" section (it is now 21 pages):

```
| 19 | /modules/admin/employee_monitor.php | admin |
| 20 | /modules/admin/flags.php            | admin |
| 21 | /modules/admin/reports.php          | admin |
```

Add to "Complete Database Schema": note that `employee_flags` and `worker_roles` are now tables 22 and 23.

Add to "Key Constraints to Remember":
```
| No duplicate active flag per user+type | employee_flags has no UNIQUE — handle in PHP before insert |
| task timing requires started_at first  | task_complete() only runs if started_at IS NOT NULL        |
```

Add new section "DB Objects":
```
| sp_check_officer_flags(user_id) | Stored Procedure | Checks and inserts flags for a given user |
| trg_invoice_overdue             | Trigger          | Auto-sets unpaid invoices to overdue on UPDATE |
| vw_employee_task_summary        | View             | Per-user task + flag summary for admin monitor |
```

**Output:** The complete updated `memory.md` file.

---

## Output Order

Build phases in this exact order. Complete each phase fully before starting the next. For each phase, output the complete file — not partial snippets, not diffs. After each file, print a one-line summary: `✓ Phase N complete — filename.ext written.`

If you encounter an ambiguity (e.g. a column referenced in a query that does not exist in the schema), stop, state the ambiguity clearly, and ask before proceeding.
