# memory.md — Key Facts & Decisions

## Project Identity

- **Name:** Port Management System (PMS)
- **Type:** Academic group project (PHP + MySQL, server-rendered)
- **Scope:** Reduced from competition-scale to completable-on-deadline
- **Members:** 4
- **Stack:** PHP (procedural), MySQL, HTML5, CSS3, Vanilla JS

---

## Scope Decisions (Final)

| Item | Original | Final |
|---|---|---|
| Modules | 9 | 7 |
| Pages | 47 | 18 |
| Roles | 8 | 4 |
| DB Tables | 29 | 21 |

**Cut entirely:** Module 7 (Reporting & Analytics), Module 9 (External Stakeholder Portal)
**Reason:** Reporting is read-only aggregation with no business logic — add last if time permits. Portal requires a second auth system — not worth it.

---

## The 4 Roles (Final Decision)

| DB slug | Display label | What they do |
|---|---|---|
| `admin` | Admin | Everything — user accounts, audit log, full read access |
| `harbor_master` | Harbor Master | Approve arrivals, assign berths, view schedule |
| `operations` | Operations | Manifests, inspections, containers, storage, transport |
| `finance` | Workforce & Finance | Shifts, tasks, invoices, payments |

`operations` is a merge of: Customs Officer + Cargo Manager + Logistics Coordinator.
`finance` is a merge of: Workforce Manager + Finance Officer.

---

## The 18 Pages (Final List)

| # | File Path | Roles Allowed |
|---|---|---|
| 1 | `/login.php` | all (unauthenticated) |
| 2 | `/403.php` | system |
| 3 | `/dashboard.php` | all |
| 4 | `/modules/vessel/arrivals.php` | admin, harbor_master |
| 5 | `/modules/vessel/berths.php` | admin, harbor_master |
| 6 | `/modules/vessel/schedule.php` | all |
| 7 | `/modules/customs/manifests.php` | admin, operations |
| 8 | `/modules/customs/inspections.php` | admin, operations |
| 9 | `/modules/cargo/containers.php` | admin, operations |
| 10 | `/modules/cargo/storage.php` | admin, operations |
| 11 | `/modules/logistics/transport.php` | admin, operations |
| 12 | `/modules/logistics/delivery.php` | admin, operations |
| 13 | `/modules/workforce/shifts.php` | admin, finance |
| 14 | `/modules/workforce/tasks.php` | admin, finance |
| 15 | `/modules/finance/invoices.php` | admin, finance |
| 16 | `/modules/finance/payments.php` | admin, finance |
| 17 | `/modules/admin/users.php` | admin |
| 18 | `/modules/admin/audit.php` | admin |
| 19 | `/modules/admin/employee_monitor.php` | admin |
| 20 | `/modules/admin/flags.php`            | admin |
| 21 | `/modules/admin/reports.php`          | admin |

---

## Core Data Flow

```
1. Arrival request submitted (vessel details + ETA)
2. Harbor Master approves → Berth allocated
3. Vessel docks → Cargo manifest submitted (list of containers)
4. Each container inspected → CLEARED / HELD / REJECTED
5. CLEARED containers assigned to a storage slot
6. Transport assigned to STORED containers → status IN_TRANSIT
7. Delivery confirmed → status DELIVERED
8. Invoices generated (berth / storage / transport)
9. Payments recorded against invoices
```

---

## Container Status Lifecycle

```
ARRIVED → INSPECTING → CLEARED  → STORED → IN_TRANSIT → DELIVERED
                     → HELD     (blocked, cannot proceed)
                     → REJECTED (dead end)
```

**Rule:** Status can only move forward. Validate current status before any UPDATE.

---

## Other Status Lifecycles

**Arrival Request:** `pending` → `approved` | `rejected`
**Berth:** `available` → `occupied` → `available`
**Transport:** `scheduled` → `in_transit` → `delivered` | `delayed`
**Invoice:** `unpaid` → `paid` | `overdue`

---

## Complete Database Schema

```sql
-- ============================================================
-- PORT MANAGEMENT SYSTEM — schema.sql
-- Run this file once to create all tables.
-- Seed data for roles and a default admin is included at bottom.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id    INT PRIMARY KEY AUTO_INCREMENT,
    name  VARCHAR(50)  NOT NULL UNIQUE,   -- 'admin', 'harbor_master', 'operations', 'finance'
    label VARCHAR(100) NOT NULL
);

-- ------------------------------------------------------------
-- 2. users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id       INT NOT NULL,
    status        ENUM('active','inactive') DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- ------------------------------------------------------------
-- 3. vessels
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vessels (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    vessel_name  VARCHAR(150) NOT NULL,
    vessel_type  VARCHAR(100),
    tonnage      DECIMAL(10,2),
    company_name VARCHAR(150),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 4. arrival_requests
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS arrival_requests (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    vessel_id     INT NOT NULL,
    eta           DATETIME NOT NULL,
    cargo_type    VARCHAR(100),
    origin_port   VARCHAR(150),
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by   INT,
    reviewed_at   DATETIME,
    notes         TEXT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vessel_id)   REFERENCES vessels(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 5. berths
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS berths (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    berth_name   VARCHAR(50)  NOT NULL UNIQUE,
    berth_type   VARCHAR(100),
    max_tonnage  DECIMAL(10,2),
    status       ENUM('available','occupied') DEFAULT 'available'
);

-- ------------------------------------------------------------
-- 6. berth_allocations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS berth_allocations (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    arrival_request_id  INT NOT NULL UNIQUE,
    berth_id            INT NOT NULL,
    allocated_by        INT NOT NULL,
    docking_start       DATETIME NOT NULL,
    expected_departure  DATETIME NOT NULL,
    actual_arrival      DATETIME,
    actual_departure    DATETIME,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (arrival_request_id) REFERENCES arrival_requests(id),
    FOREIGN KEY (berth_id)           REFERENCES berths(id),
    FOREIGN KEY (allocated_by)       REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 7. cargo_manifests
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cargo_manifests (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    arrival_request_id  INT NOT NULL UNIQUE,
    submitted_by        INT NOT NULL,
    submitted_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes               TEXT,
    FOREIGN KEY (arrival_request_id) REFERENCES arrival_requests(id),
    FOREIGN KEY (submitted_by)       REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 8. manifest_items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manifest_items (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    manifest_id      INT NOT NULL,
    container_code   VARCHAR(50) NOT NULL,
    cargo_type       VARCHAR(100),
    declared_weight  DECIMAL(10,2),
    declared_value   DECIMAL(15,2),
    importer_name    VARCHAR(150),
    exporter_name    VARCHAR(150),
    FOREIGN KEY (manifest_id) REFERENCES cargo_manifests(id)
);

-- ------------------------------------------------------------
-- 9. containers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS containers (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    container_code   VARCHAR(50) NOT NULL UNIQUE,
    container_type   ENUM('standard','refrigerated','hazmat','bulk') NOT NULL,
    size_teu         DECIMAL(4,1),
    weight_kg        DECIMAL(10,2),
    direction        ENUM('inbound','outbound') NOT NULL,
    vessel_id        INT NOT NULL,
    manifest_item_id INT,
    status           ENUM('arrived','inspecting','cleared','held','rejected','stored','in_transit','delivered') DEFAULT 'arrived',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vessel_id)        REFERENCES vessels(id),
    FOREIGN KEY (manifest_item_id) REFERENCES manifest_items(id)
);

-- ------------------------------------------------------------
-- 10. customs_inspections
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customs_inspections (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    container_id  INT NOT NULL UNIQUE,
    inspected_by  INT NOT NULL,
    result        ENUM('cleared','held','rejected') NOT NULL,
    hold_reason   TEXT,
    inspected_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (container_id) REFERENCES containers(id),
    FOREIGN KEY (inspected_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 11. storage_locations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storage_locations (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    slot_code  VARCHAR(50) NOT NULL UNIQUE,
    zone       VARCHAR(100),
    slot_type  ENUM('standard','refrigerated','hazmat','bulk') NOT NULL,
    status     ENUM('available','occupied') DEFAULT 'available'
);

-- ------------------------------------------------------------
-- 12. container_assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS container_assignments (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    container_id        INT NOT NULL,
    storage_location_id INT NOT NULL,
    assigned_by         INT NOT NULL,
    assigned_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    vacated_at          DATETIME,
    FOREIGN KEY (container_id)        REFERENCES containers(id),
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id),
    FOREIGN KEY (assigned_by)         REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 13. transport_assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transport_assignments (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    container_id     INT NOT NULL,
    transport_mode   ENUM('truck','train','river') NOT NULL,
    carrier_name     VARCHAR(150),
    vehicle_id       VARCHAR(100),
    destination      VARCHAR(255),
    scheduled_pickup DATETIME NOT NULL,
    assigned_by      INT NOT NULL,
    status           ENUM('scheduled','in_transit','delivered','delayed') DEFAULT 'scheduled',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (container_id) REFERENCES containers(id),
    FOREIGN KEY (assigned_by)  REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 14. delivery_logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_logs (
    id                      INT PRIMARY KEY AUTO_INCREMENT,
    transport_assignment_id INT NOT NULL UNIQUE,
    expected_delivery       DATETIME NOT NULL,
    actual_delivery         DATETIME,
    delay_reason            TEXT,
    updated_by              INT NOT NULL,
    updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transport_assignment_id) REFERENCES transport_assignments(id),
    FOREIGN KEY (updated_by)              REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 15. workers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workers (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    job_role   ENUM('crane_operator','loader','driver','inspector','supervisor') NOT NULL,
    contact    VARCHAR(150),
    status     ENUM('active','inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 16. shifts
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shifts (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    shift_date  DATE NOT NULL,
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    zone        VARCHAR(100),
    created_by  INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 17. shift_assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shift_assignments (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    shift_id     INT NOT NULL,
    worker_id    INT NOT NULL,
    assigned_by  INT NOT NULL,
    assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_shift (worker_id, shift_id),
    FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    FOREIGN KEY (worker_id)   REFERENCES workers(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 18. task_assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_assignments (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    worker_id    INT NOT NULL,
    shift_id     INT,
    task_type    ENUM('loading','unloading','crane_operation','inspection_support','driving') NOT NULL,
    ref_type     ENUM('vessel','container','storage_location') NOT NULL,
    ref_id       INT NOT NULL,
    status       ENUM('assigned','in_progress','done') DEFAULT 'assigned',
    assigned_by  INT NOT NULL,
    assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id)   REFERENCES workers(id),
    FOREIGN KEY (shift_id)    REFERENCES shifts(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 19. invoices
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    invoice_type  ENUM('berth','storage','transport') NOT NULL,
    ref_id        INT NOT NULL,
    client_name   VARCHAR(150) NOT NULL,
    amount        DECIMAL(15,2) NOT NULL,
    due_date      DATE NOT NULL,
    status        ENUM('unpaid','paid','overdue') DEFAULT 'unpaid',
    generated_by  INT NOT NULL,
    generated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 20. payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id    INT NOT NULL,
    amount_paid   DECIMAL(15,2) NOT NULL,
    payment_date  DATE NOT NULL,
    recorded_by   INT NOT NULL,
    notes         TEXT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id)  REFERENCES invoices(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- 21. audit_log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    user_id     INT NOT NULL,
    action      VARCHAR(50) NOT NULL,
    module      VARCHAR(50) NOT NULL,
    record_id   INT NOT NULL,
    description TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Note: employee_flags and worker_roles are now tables 22 and 23.

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO roles (name, label) VALUES
('admin',         'Admin'),
('harbor_master', 'Harbor Master'),
('operations',    'Operations'),
('finance',       'Workforce & Finance');

-- Default admin account — password: admin123 (change immediately)
INSERT INTO users (name, email, password_hash, role_id) VALUES
('System Admin', 'admin@pms.local',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 (SELECT id FROM roles WHERE name = 'admin'));

-- Sample berths
INSERT INTO berths (berth_name, berth_type, max_tonnage) VALUES
('Berth A', 'Container', 50000),
('Berth B', 'Container', 80000),
('Berth C', 'Bulk',      60000),
('Berth D', 'General',   30000);

-- Sample storage locations
INSERT INTO storage_locations (slot_code, zone, slot_type) VALUES
('A-01', 'Warehouse A', 'standard'),
('A-02', 'Warehouse A', 'standard'),
('A-03', 'Warehouse A', 'standard'),
('B-01', 'Warehouse B', 'refrigerated'),
('B-02', 'Warehouse B', 'refrigerated'),
('C-01', 'Hazmat Yard', 'hazmat'),
('C-02', 'Hazmat Yard', 'hazmat'),
('D-01', 'Bulk Yard',   'bulk'),
('D-02', 'Bulk Yard',   'bulk');
```

---

## Key Constraints to Remember

| Rule | Enforced By |
|---|---|
| One berth allocation per arrival | `UNIQUE` on `arrival_request_id` in `berth_allocations` |
| One manifest per arrival | `UNIQUE` on `arrival_request_id` in `cargo_manifests` |
| One inspection per container | `UNIQUE` on `container_id` in `customs_inspections` |
| Container code globally unique | `UNIQUE` on `container_code` in `containers` |
| Worker not in two same shifts | `UNIQUE KEY uq_worker_shift (worker_id, shift_id)` |
| Only cleared containers → storage | PHP check: `container.status = 'cleared'` |
| Only stored containers → transport | PHP check: `container.status = 'stored'` |
| No duplicate active flag per user+type | employee_flags has no UNIQUE — handle in PHP before insert |
| task timing requires started_at first  | task_complete() only runs if started_at IS NOT NULL        |

---

## DB Objects

| Name | Type | Description |
|---|---|---|
| sp_check_officer_flags(user_id) | Stored Procedure | Checks and inserts flags for a given user |
| trg_invoice_overdue             | Trigger          | Auto-sets unpaid invoices to overdue on UPDATE |
| vw_employee_task_summary        | View             | Per-user task + flag summary for admin monitor |

---

## Team Member Module Ownership

| Member | Modules | Pages |
|---|---|---|
| Member 1 | Vessel & Berth (Mod 1) + Admin/Auth (Mod 8) | arrivals, berths, schedule, users, audit, login, dashboard |
| Member 2 | Customs (Mod 2) + Cargo & Warehouse (Mod 3) | manifests, inspections, containers, storage |
| Member 3 | Logistics (Mod 4) + Workforce (Mod 5) | transport, delivery, shifts, tasks |
| Member 4 | Finance (Mod 6) | invoices, payments |

---

## Default Admin Login

- **Email:** `admin@pms.local`
- **Password:** `admin123`
- Change after first login.
