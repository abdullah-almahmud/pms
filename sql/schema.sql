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
    name  VARCHAR(50)  NOT NULL UNIQUE,   -- 'admin', 'harbor_master', 'operations', 'finance', 'company'
    label VARCHAR(100) NOT NULL
);

-- ------------------------------------------------------------
-- 1.5. companies (Normalized B2B Entity)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL UNIQUE,
    email       VARCHAR(150) UNIQUE,
    phone       VARCHAR(50),
    address     TEXT,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
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
    company_id    INT NULL,
    status        ENUM('active','inactive') DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
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
    company_id   INT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
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
-- 9.5. container_status_history (Cargo Tracking Timeline Logs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS container_status_history (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    container_id  INT NOT NULL,
    status        VARCHAR(50) NOT NULL,
    changed_by    INT NOT NULL,
    changed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes         TEXT,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by)   REFERENCES users(id)
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
    company_id    INT NULL,
    amount        DECIMAL(15,2) NOT NULL,
    due_date      DATE NOT NULL,
    status        ENUM('unpaid','paid','overdue') DEFAULT 'unpaid',
    generated_by  INT NOT NULL,
    generated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
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

-- ------------------------------------------------------------
-- 22. Indexes for High Scalability
-- ------------------------------------------------------------
CREATE INDEX idx_container_code ON containers(container_code);
CREATE INDEX idx_containers_status ON containers(status);
CREATE INDEX idx_arrival_status ON arrival_requests(status);
CREATE INDEX idx_invoice_status ON invoices(status);
CREATE INDEX idx_audit_created ON audit_log(created_at);
CREATE INDEX idx_vessels_company ON vessels(company_id);
CREATE INDEX idx_invoices_company ON invoices(company_id);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO roles (name, label) VALUES
('admin',         'Admin'),
('harbor_master', 'Harbor Master'),
('operations',    'Operations'),
('finance',       'Workforce & Finance'),
('company',       'Shipping Company/Client');

-- Default admin account — password: admin123
INSERT INTO users (name, email, password_hash, role_id) VALUES
('System Admin', 'admin@pms.local',
 '$2y$10$.XytlTfAMJyrHigN6omx4uTzUra0X5o2btn1qdQpvH82VyLvFf3qO',
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

-- Sample workers
INSERT INTO workers (name, job_role, contact) VALUES
('Ahmed Khan',     'crane_operator', 'ahmed.khan@port.local'),
('Rahim Uddin',    'loader',         'rahim.uddin@port.local'),
('Kamal Hossain',  'driver',         'kamal.hossain@port.local'),
('Nasir Ahmed',    'inspector',      'nasir.ahmed@port.local'),
('Farhan Ali',     'supervisor',     'farhan.ali@port.local'),
('Jamal Mia',      'loader',         'jamal.mia@port.local'),
('Sajid Rahman',   'crane_operator', 'sajid.rahman@port.local'),
('Tanvir Hasan',   'driver',         'tanvir.hasan@port.local');

-- Sample vessel
INSERT INTO vessels (vessel_name, vessel_type, tonnage, company_name) VALUES
('MV Bangla Star', 'Container Ship', 45000.00, 'Bangladesh Shipping Corp');

-- ============================================================
-- PHASE 1 UPDATES
-- ============================================================

ALTER TABLE task_assignments
    ADD COLUMN assigned_to  INT          DEFAULT NULL,
    ADD COLUMN started_at   DATETIME     DEFAULT NULL,
    ADD COLUMN completed_at DATETIME     DEFAULT NULL,
    ADD COLUMN sla_deadline DATETIME     DEFAULT NULL,
    ADD CONSTRAINT fk_ta_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id);

ALTER TABLE berths
    ADD COLUMN is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN depth_metres DECIMAL(6,2) DEFAULT NULL,
    ADD COLUMN quay_length  DECIMAL(8,2) DEFAULT NULL,
    ADD COLUMN notes        TEXT         DEFAULT NULL;

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

INSERT IGNORE INTO roles (name, label) VALUES
    ('customs_officer',       'Customs Officer'),
    ('manifest_clerk',        'Manifest Clerk'),
    ('cargo_manager',         'Cargo Manager'),
    ('logistics_coordinator', 'Logistics Coordinator'),
    ('workforce_manager',     'Workforce Manager'),
    ('finance_officer',       'Finance Officer'),
    ('port_supervisor',       'Port Supervisor');

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

-- ============================================================
-- PHASE 7 UPDATES
-- ============================================================
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
