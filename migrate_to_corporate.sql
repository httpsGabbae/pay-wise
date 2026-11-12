-- PAYWISE — migrate existing database from school seed to corporate seed
-- Run once on an existing database (phpMyAdmin SQL tab or mysql CLI).
-- Fresh installs do NOT need this: paywise_schema.sql / schema.sql /
-- supabase_schema.sql already seed corporate departments.
--
-- What it does (idempotent, safe to run twice):
-- 1. Renames the 6 school departments to corporate departments by id.
-- 2. Rebuilds positions to the corporate title list.
-- 3. Remaps employees.department / employees.position free-text values.

-- 1. Departments by id (old: Colleges -> new: corporate)
UPDATE departments SET department_name='Operations', department_code='OPS', description='Company operations and field teams', status='Active' WHERE department_id=1;
UPDATE departments SET department_name='Finance & Accounting', department_code='FIN', description='Finance, accounting and payroll', status='Active' WHERE department_id=2;
UPDATE departments SET department_name='Human Resources', department_code='HR', description='People, hiring and personnel records', status='Active' WHERE department_id=3;
UPDATE departments SET department_name='Information Technology', department_code='IT', description='Systems, software and IT support', status='Active' WHERE department_id=4;
UPDATE departments SET department_name='Sales & Marketing', department_code='SALES', description='Sales, marketing and client growth', status='Active' WHERE department_id=5;
UPDATE departments SET department_name='Customer Support', department_code='SUPPORT', description='Customer service and client support', status='Active' WHERE department_id=6;

-- Any leftover school-named rows (installed without ids) get renamed by name too
UPDATE departments SET department_name='Operations', department_code='OPS' WHERE department_name='College of Computing Technology and Engineering';
UPDATE departments SET department_name='Finance & Accounting', department_code='FIN' WHERE department_name='College of Business Accountancy';
UPDATE departments SET department_name='Human Resources', department_code='HR' WHERE department_name='College of Education and Liberal Arts';
UPDATE departments SET department_name='Information Technology', department_code='IT' WHERE department_name LIKE '%Computing%';
UPDATE departments SET department_name='Sales & Marketing', department_code='SALES' WHERE department_name='College of Internal and Tourism Management';
UPDATE departments SET department_name='Customer Support', department_code='SUPPORT' WHERE department_name='College of Nursing' OR department_name='College of Criminal Justice Education';

-- 2. Positions: rebuild corporate list (employees store free text, so this is safe)
DELETE FROM positions;
INSERT INTO positions (position_id, department_id, position_name, created_at) VALUES
(1,1,'Operations Manager','2026-09-08 14:56:08'),
(2,1,'Supervisor','2026-09-08 14:56:08'),
(3,1,'Team Lead','2026-09-08 14:56:08'),
(4,1,'Staff','2026-09-08 14:56:08'),
(5,1,'Assistant','2026-09-08 14:56:08'),
(6,2,'Finance Manager','2026-09-08 14:56:08'),
(7,2,'Accountant','2026-09-08 14:56:08'),
(8,2,'Payroll Officer','2026-09-08 14:56:08'),
(9,2,'Staff','2026-09-08 14:56:08'),
(10,3,'HR Manager','2026-09-08 14:56:08'),
(11,3,'HR Officer','2026-09-08 14:56:08'),
(12,3,'Recruiter','2026-09-08 14:56:08'),
(13,3,'Staff','2026-09-08 14:56:08'),
(14,4,'IT Manager','2026-09-08 14:56:08'),
(15,4,'Developer','2026-09-08 14:56:08'),
(16,4,'Support Specialist','2026-09-08 14:56:08'),
(17,4,'Staff','2026-09-08 14:56:08'),
(18,5,'Sales Manager','2026-09-08 14:56:08'),
(19,5,'Sales Officer','2026-09-08 14:56:08'),
(20,5,'Marketing Officer','2026-09-08 14:56:08'),
(21,5,'Staff','2026-09-08 14:56:08'),
(22,6,'Support Manager','2026-09-08 14:56:08'),
(23,6,'Support Agent','2026-09-08 14:56:08'),
(24,6,'Staff','2026-09-08 14:56:08');

-- 3a. Employees: department free-text remap
UPDATE employees SET department='Information Technology' WHERE department='College of Computing Technology and Engineering';
UPDATE employees SET department='Customer Support' WHERE department='College of Nursing';
UPDATE employees SET department='Sales & Marketing' WHERE department='College of Internal and Tourism Management';
UPDATE employees SET department='Operations' WHERE department='College of Criminal Justice Education';
UPDATE employees SET department='Finance & Accounting' WHERE department='College of Business Accountancy';
UPDATE employees SET department='Human Resources' WHERE department='College of Education and Liberal Arts';

-- 3b. Employees: academic title remap (keep Staff/Accountant, generalize teaching titles)
UPDATE employees SET position='Manager' WHERE position='Dean';
UPDATE employees SET position='Supervisor' WHERE position='Department Head';
UPDATE employees SET position='Team Lead' WHERE position='Coordinator';
UPDATE employees SET position='Staff' WHERE position IN ('Professor','Associate Professor','Assistant Professor','Lecturer','Instructor','Clinical Instructor','Administrative Staff','Laboratory Staff','IT Staff');
UPDATE employees SET position='Support Specialist' WHERE position IN ('Technician');
