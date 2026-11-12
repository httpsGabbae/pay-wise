-- ============================================================
-- PAYWISE — LCC Payroll Edition Migration Script (MySQL / MariaDB)
-- Run this script in phpMyAdmin or MySQL CLI to upgrade the paywise_payroll database.
-- Safe to run on existing database (uses IF NOT EXISTS and graceful column checks).
-- ============================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- 1. App settings update to Lipa City Colleges branding
INSERT INTO app_settings (setting_key, setting_value) VALUES
('company_name', 'Lipa City Colleges'),
('company_address', 'G.A. Solis Street, Lipa City, Batangas'),
('employee_number_digits', '4'),
('employee_number_prefix', '25')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

UPDATE app_settings SET setting_value = 'Lipa City Colleges' WHERE setting_key = 'company_name';
UPDATE app_settings SET setting_value = 'G.A. Solis Street, Lipa City, Batangas' WHERE setting_key = 'company_address';

-- 2. Master Payroll Periods
CREATE TABLE IF NOT EXISTS payroll_periods (
    period_id INT NOT NULL AUTO_INCREMENT,
    period_code VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    pay_date DATE NOT NULL,
    status ENUM('Draft', 'Approved', 'Paid') NOT NULL DEFAULT 'Draft',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (period_id),
    UNIQUE KEY uq_period_code (period_code),
    KEY idx_period_dates (start_date, end_date),
    KEY idx_period_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Loan Types
CREATE TABLE IF NOT EXISTS loan_types (
    loan_type_id INT NOT NULL AUTO_INCREMENT,
    loan_type_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (loan_type_id),
    UNIQUE KEY uq_loan_type_name (loan_type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO loan_types (loan_type_id, loan_type_name, description, is_active) VALUES
(1, 'Salary Loan', 'Standard faculty and staff company salary advance', 1),
(2, 'Emergency Loan', 'Emergency financial assistance loan', 1),
(3, 'SSS Salary Loan', 'Social Security System educational/salary loan', 1),
(4, 'Pag-IBIG Calamity/Multi-Purpose Loan', 'Government housing and multi-purpose loan amortization', 1),
(5, 'Educational Assistance Loan', 'Tuition and academic advancement loan', 1);

-- 4. Employee Loans
CREATE TABLE IF NOT EXISTS employee_loans (
    loan_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    loan_type_id INT NOT NULL,
    loan_date DATE NOT NULL,
    principal DECIMAL(12,2) NOT NULL,
    installment DECIMAL(12,2) NOT NULL,
    term_periods INT NOT NULL DEFAULT 1,
    repayment_start DATE NOT NULL,
    remaining_balance DECIMAL(12,2) NOT NULL,
    status ENUM('Active', 'Paid', 'Cancelled') NOT NULL DEFAULT 'Active',
    remarks VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (loan_id),
    KEY idx_loan_employee (employee_id),
    KEY idx_loan_type (loan_type_id),
    KEY idx_loan_status (status),
    CONSTRAINT fk_loan_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_loan_type FOREIGN KEY (loan_type_id) REFERENCES loan_types (loan_type_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Loan Repayments
CREATE TABLE IF NOT EXISTS loan_repayments (
    repayment_id INT NOT NULL AUTO_INCREMENT,
    loan_id INT NOT NULL,
    payroll_id INT DEFAULT NULL,
    employee_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    repayment_date DATE NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (repayment_id),
    KEY idx_repayment_loan (loan_id),
    KEY idx_repayment_payroll (payroll_id),
    KEY idx_repayment_employee (employee_id),
    CONSTRAINT fk_repayment_loan FOREIGN KEY (loan_id) REFERENCES employee_loans (loan_id) ON DELETE CASCADE,
    CONSTRAINT fk_repayment_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Other Income
CREATE TABLE IF NOT EXISTS other_income (
    income_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    period_id INT DEFAULT NULL,
    income_name VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pay_date DATE DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (income_id),
    KEY idx_income_employee (employee_id),
    KEY idx_income_period (period_id),
    CONSTRAINT fk_income_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_income_period FOREIGN KEY (period_id) REFERENCES payroll_periods (period_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Other Deductions
CREATE TABLE IF NOT EXISTS other_deductions (
    deduction_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    period_id INT DEFAULT NULL,
    deduction_name VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pay_date DATE DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (deduction_id),
    KEY idx_deduction_employee (employee_id),
    KEY idx_deduction_period (period_id),
    CONSTRAINT fk_deduction_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_deduction_period FOREIGN KEY (period_id) REFERENCES payroll_periods (period_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 8. Alter attendance table with computed metrics and period link
SET @dbname = DATABASE();
SET @tablename = 'attendance';

-- Add period_id
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'period_id') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN period_id INT DEFAULT NULL AFTER employee_id'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add daily_rate
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'daily_rate') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add absences_count
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'absences_count') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN absences_count DECIMAL(5,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add tardiness_hours
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'tardiness_hours') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN tardiness_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add undertime_hours
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'undertime_hours') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN undertime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add overtime_hours
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'overtime_hours') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add computed_deduction
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'computed_deduction') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN computed_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add computed_overtime
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'computed_overtime') > 0,
  'SELECT 1',
  'ALTER TABLE attendance ADD COLUMN computed_overtime DECIMAL(10,2) NOT NULL DEFAULT 0.00'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 9. Alter payroll_records with period link and granular deduction columns
SET @tablename = 'payroll_records';

-- Add period_id
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'period_id') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN period_id INT DEFAULT NULL AFTER employee_id'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add loan_deductions
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'loan_deductions') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN loan_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER deductions'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add attendance_deductions
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'attendance_deductions') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN attendance_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER loan_deductions'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add overtime_pay
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'overtime_pay') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER other_earnings'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add other_income_amount
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'other_income_amount') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN other_income_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_pay'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add other_deductions_amount
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'other_deductions_amount') > 0,
  'SELECT 1',
  'ALTER TABLE payroll_records ADD COLUMN other_deductions_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER attendance_deductions'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 10. Update/Add Lipa City Colleges Departments and Positions
INSERT IGNORE INTO departments (department_name, department_code, description, status) VALUES
('College of Computing Technology and Engineering', 'CCTE', 'Computing, computer science, and engineering department', 'Active'),
('College of Nursing', 'CON', 'Nursing and healthcare health sciences department', 'Active'),
('College of Criminology', 'COC', 'Criminal justice and law enforcement education', 'Active'),
('College of Education and Liberal Arts', 'CELA', 'Teacher education, humanities, and liberal arts', 'Active'),
('College of Business Accountancy', 'CBA', 'Business administration, management, and accountancy', 'Active'),
('College of International Tourism & Hospitality Management', 'CITHM', 'Tourism, hotel, and restaurant management', 'Active');

-- Add CITHM if not present
INSERT IGNORE INTO departments (department_name, department_code, description, status)
SELECT 'College of International Tourism & Hospitality Management', 'CITHM', 'Tourism, hotel, and restaurant management', 'Active'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM departments WHERE department_code = 'CITHM');

-- Common Academic & Administrative Positions
INSERT IGNORE INTO positions (department_id, position_name)
SELECT d.department_id, pos.pname
FROM departments d
CROSS JOIN (
    SELECT 'Dean' AS pname UNION SELECT 'Professor' UNION SELECT 'Associate Professor'
    UNION SELECT 'Assistant Professor' UNION SELECT 'Part-Time Teacher' UNION SELECT 'Secretary'
    UNION SELECT 'Cashier' UNION SELECT 'Instructor'
) pos
WHERE d.status = 'Active';

SET FOREIGN_KEY_CHECKS = 1;

-- Seed initial sample Payroll Period
INSERT IGNORE INTO payroll_periods (period_id, period_code, start_date, end_date, pay_date, status, notes)
VALUES (1, '2026-10-P2', '2026-10-16', '2026-10-31', '2026-11-05', 'Draft', 'Second half of October payroll period');
