-- Schema Updates (Already executed shifts/invoices alters)
ALTER TABLE workers MODIFY COLUMN job_role VARCHAR(100) NOT NULL;

-- Seed Data: Companies
INSERT IGNORE INTO companies (name, email, phone, address) VALUES
('Maersk Logistics', 'contact@maersk.com', '+123456789', 'Denmark'),
('MSC Cargo', 'info@msc.com', '+987654321', 'Switzerland'),
('CMA CGM', 'support@cmacgm.com', '+11223344', 'France'),
('Hapag-Lloyd', 'hello@hl.com', '+55667788', 'Germany'),
('Evergreen Marine', 'contact@evergreen.com', '+99887766', 'Taiwan');

-- Seed Data: Vessels
INSERT IGNORE INTO vessels (vessel_name, vessel_type, tonnage, company_name, company_id) VALUES
('MV Ocean Explorer', 'Container Ship', 65000.00, 'Maersk Logistics', (SELECT id FROM companies WHERE name='Maersk Logistics')),
('MV Pacific Queen', 'Bulk Carrier', 80000.00, 'MSC Cargo', (SELECT id FROM companies WHERE name='MSC Cargo')),
('MV Atlantic Star', 'Ro-Ro', 40000.00, 'CMA CGM', (SELECT id FROM companies WHERE name='CMA CGM')),
('MV Baltic Prince', 'Liquid Tanker', 55000.00, 'Hapag-Lloyd', (SELECT id FROM companies WHERE name='Hapag-Lloyd')),
('MV Sea Breeze', 'Container Ship', 90000.00, 'Evergreen Marine', (SELECT id FROM companies WHERE name='Evergreen Marine'));

-- Seed Data: Workers (Various roles)
INSERT IGNORE INTO workers (name, job_role, contact) VALUES
('Tariqul Islam', 'crane_operator', 'tariqul@port.local'),
('Abdul Karim', 'loader', 'akarim@port.local'),
('Shahidul Haq', 'driver', 'shahidul@port.local'),
('Rafiq Hossain', 'inspector', 'rafiq@port.local'),
('Mizanur Rahman', 'supervisor', 'mizanur@port.local'),
('Nadim Mahmud', 'forklift_operator', 'nadim@port.local'),
('Iqbal Hasan', 'security_guard', 'iqbal@port.local'),
('Zayed Khan', 'loader', 'zayed@port.local'),
('Rubel Mia', 'driver', 'rubel@port.local'),
('Sultan Ahmed', 'crane_operator', 'sultan@port.local'),
('Golam Mustafa', 'inspector', 'golam@port.local'),
('Faisal Amin', 'loader', 'faisal@port.local'),
('Rezaul Karim', 'driver', 'rezaul@port.local'),
('Elias Hossain', 'forklift_operator', 'elias@port.local'),
('Ashraful Islam', 'supervisor', 'ashraful@port.local');

-- Seed Data: Users
-- Using the same password hash as admin: admin123
INSERT IGNORE INTO users (name, email, password_hash, role_id, company_id) VALUES
('Harbor Master User', 'hmaster@pms.local', '$2y$10$.XytlTfAMJyrHigN6omx4uTzUra0X5o2btn1qdQpvH82VyLvFf3qO', (SELECT id FROM roles WHERE name='harbor_master'), NULL),
('Operations Manager', 'ops@pms.local', '$2y$10$.XytlTfAMJyrHigN6omx4uTzUra0X5o2btn1qdQpvH82VyLvFf3qO', (SELECT id FROM roles WHERE name='operations'), NULL),
('Finance Controller', 'finance@pms.local', '$2y$10$.XytlTfAMJyrHigN6omx4uTzUra0X5o2btn1qdQpvH82VyLvFf3qO', (SELECT id FROM roles WHERE name='finance'), NULL),
('Maersk Rep', 'rep@maersk.com', '$2y$10$.XytlTfAMJyrHigN6omx4uTzUra0X5o2btn1qdQpvH82VyLvFf3qO', (SELECT id FROM roles WHERE name='company'), (SELECT id FROM companies WHERE name='Maersk Logistics'));
