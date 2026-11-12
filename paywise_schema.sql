-- ============================================================
-- PAYWISE — Full Fresh Database Schema with Database Creation (MySQL / MariaDB)
-- Lipa City Colleges Edition
-- ============================================================

CREATE DATABASE IF NOT EXISTS paywise_payroll CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE paywise_payroll;

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS loan_repayments;
DROP TABLE IF EXISTS employee_loans;
DROP TABLE IF EXISTS loan_types;
DROP TABLE IF EXISTS other_deductions;
DROP TABLE IF EXISTS other_income;
DROP TABLE IF EXISTS payroll_records;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS employee_references;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS payroll_periods;
DROP TABLE IF EXISTS app_settings;
DROP TABLE IF EXISTS admins;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- 1. ADMINS
-- --------------------------------------------------------
CREATE TABLE admins (
    admin_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('superadmin','finance','hr') NOT NULL DEFAULT 'superadmin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id),
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO admins (admin_id, username, password_hash, full_name, role, is_active, created_at)
VALUES (1, 'admin', '$2y$10$cK4PFcHW5dXRAMOZuTb2GeBosLrZWt7WSfV7NACSSFcfZPo.PbWHK', 'System Administrator', 'superadmin', 1, '2026-08-13 05:25:14');

-- --------------------------------------------------------
-- 2. APP SETTINGS
-- --------------------------------------------------------
CREATE TABLE app_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('company_address', 'G.A. Solis Street, Lipa City, Batangas'),
('company_name', 'Lipa City Colleges'),
('employee_number_digits', '4'),
('employee_number_prefix', '25');

-- --------------------------------------------------------
-- 3. DEPARTMENTS
-- --------------------------------------------------------
CREATE TABLE departments (
    department_id INT NOT NULL AUTO_INCREMENT,
    department_name VARCHAR(150) NOT NULL,
    department_code VARCHAR(30) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (department_id),
    UNIQUE KEY uq_department_name (department_name),
    UNIQUE KEY uq_department_code (department_code),
    KEY idx_department_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO departments (department_id, department_name, department_code, description, status) VALUES
(1, 'College of Computing Technology and Engineering', 'CCTE', 'Computing, computer science, and engineering department', 'Active'),
(2, 'College of Nursing', 'CON', 'Nursing and healthcare health sciences department', 'Active'),
(3, 'College of Criminology', 'COC', 'Criminal justice and law enforcement education', 'Active'),
(4, 'College of Education and Liberal Arts', 'CELA', 'Teacher education, humanities, and liberal arts', 'Active'),
(5, 'College of Business Accountancy', 'CBA', 'Business administration, management, and accountancy', 'Active'),
(6, 'College of International Tourism & Hospitality Management', 'CITHM', 'Tourism, hotel, and restaurant management', 'Active');

-- --------------------------------------------------------
-- 4. POSITIONS
-- --------------------------------------------------------
CREATE TABLE positions (
    position_id INT NOT NULL AUTO_INCREMENT,
    department_id INT NOT NULL,
    position_name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id),
    UNIQUE KEY uq_department_position (department_id, position_name),
    KEY idx_position_department (department_id),
    CONSTRAINT fk_position_department FOREIGN KEY (department_id) REFERENCES departments (department_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO positions (department_id, position_name) VALUES
(1, 'Dean'), (1, 'Professor'), (1, 'Associate Professor'), (1, 'Assistant Professor'), (1, 'Part-Time Teacher'), (1, 'Secretary'), (1, 'Cashier'),
(2, 'Dean'), (2, 'Professor'), (2, 'Assistant Professor'), (2, 'Clinical Instructor'), (2, 'Secretary'),
(3, 'Dean'), (3, 'Professor'), (3, 'Instructor'), (3, 'Part-Time Teacher'), (3, 'Secretary'),
(4, 'Dean'), (4, 'Professor'), (4, 'Assistant Professor'), (4, 'Instructor'), (4, 'Secretary'),
(5, 'Dean'), (5, 'Professor'), (5, 'Accountancy Instructor'), (5, 'Part-Time Teacher'), (5, 'Secretary'),
(6, 'Dean'), (6, 'Professor'), (6, 'Hospitality Instructor'), (6, 'Secretary');

-- --------------------------------------------------------
-- 5. EMPLOYEES
-- --------------------------------------------------------
CREATE TABLE employees (
    employee_id INT NOT NULL AUTO_INCREMENT,
    employee_no VARCHAR(20) NOT NULL,
    portal_password VARCHAR(255) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) NOT NULL,
    gender ENUM('Male','Female','Other') NOT NULL DEFAULT 'Other',
    birth_date DATE DEFAULT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    civil_status VARCHAR(30) DEFAULT NULL,
    nationality VARCHAR(50) DEFAULT NULL,
    religion VARCHAR(50) DEFAULT NULL,
    permanent_address TEXT DEFAULT NULL,
    present_address TEXT DEFAULT NULL,
    sss_no VARCHAR(30) DEFAULT NULL,
    philhealth_no VARCHAR(30) DEFAULT NULL,
    pagibig_no VARCHAR(30) DEFAULT NULL,
    tin_no VARCHAR(30) DEFAULT NULL,
    atm_no VARCHAR(40) DEFAULT NULL,
    department VARCHAR(150) DEFAULT NULL,
    position VARCHAR(120) DEFAULT NULL,
    employment_status ENUM('Regular','Probationary','Contractual','Part-Time') NOT NULL DEFAULT 'Regular',
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_hired DATE DEFAULT NULL,
    emergency_contact_name VARCHAR(100) DEFAULT NULL,
    emergency_contact_phone VARCHAR(30) DEFAULT NULL,
    emergency_contact_address TEXT DEFAULT NULL,
    dependent_name VARCHAR(100) DEFAULT NULL,
    dependent_relationship VARCHAR(50) DEFAULT NULL,
    dependent_birth_date DATE DEFAULT NULL,
    education_background TEXT DEFAULT NULL,
    character_reference TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id),
    UNIQUE KEY uq_employee_no (employee_no),
    KEY idx_employee_department (department),
    KEY idx_employee_status (employment_status),
    KEY idx_employee_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO employees
    (employee_no, portal_password, first_name, middle_name, last_name, gender, email, department, position, employment_status, basic_salary, date_hired)
VALUES
    ('25-0001', '$2y$12$RgvcOSwmrocDtSEbbT2zFO6V5mgTwm4Q12Vj7jg5TKIAPnJ0rjngG', 'Juan', 'D.', 'Dela Cruz', 'Male', 'juan.delacruz@example.com', 'College of Computing Technology and Engineering', 'Professor', 'Regular', 25000.00, '2026-06-01')
ON DUPLICATE KEY UPDATE portal_password = VALUES(portal_password);

-- --------------------------------------------------------
-- 6. EMPLOYEE REFERENCES
-- --------------------------------------------------------
CREATE TABLE employee_references (
    reference_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    reference_name VARCHAR(120) NOT NULL,
    reference_address TEXT DEFAULT NULL,
    reference_occupation VARCHAR(100) DEFAULT NULL,
    reference_contact_number VARCHAR(40) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reference_id),
    KEY idx_reference_employee (employee_id),
    CONSTRAINT fk_reference_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 7. MASTER PAYROLL PERIODS
-- --------------------------------------------------------
CREATE TABLE payroll_periods (
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

INSERT INTO payroll_periods (period_id, period_code, start_date, end_date, pay_date, status, notes)
VALUES (1, '2026-10-P2', '2026-10-16', '2026-10-31', '2026-11-05', 'Draft', 'Second half of October payroll period');

-- --------------------------------------------------------
-- 8. ATTENDANCE
-- --------------------------------------------------------
CREATE TABLE attendance (
    attendance_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    period_id INT DEFAULT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    absences_count DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tardiness_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    undertime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    overtime_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    computed_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    computed_overtime DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('Present','Late','Absent','On Leave','Half Day') NOT NULL DEFAULT 'Present',
    remarks VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (attendance_id),
    UNIQUE KEY uq_employee_attendance_date (employee_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    KEY idx_attendance_period (period_id),
    KEY idx_attendance_status (status),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_period FOREIGN KEY (period_id) REFERENCES payroll_periods(period_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 9. LOAN TYPES
-- --------------------------------------------------------
CREATE TABLE loan_types (
    loan_type_id INT NOT NULL AUTO_INCREMENT,
    loan_type_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (loan_type_id),
    UNIQUE KEY uq_loan_type_name (loan_type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO loan_types (loan_type_id, loan_type_name, description, is_active) VALUES
(1, 'Salary Loan', 'Standard faculty and staff company salary advance', 1),
(2, 'Emergency Loan', 'Emergency financial assistance loan', 1),
(3, 'SSS Salary Loan', 'Social Security System educational/salary loan', 1),
(4, 'Pag-IBIG Calamity/Multi-Purpose Loan', 'Government housing and multi-purpose loan amortization', 1),
(5, 'Educational Assistance Loan', 'Tuition and academic advancement loan', 1);

-- --------------------------------------------------------
-- 10. EMPLOYEE LOANS
-- --------------------------------------------------------
CREATE TABLE employee_loans (
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

-- --------------------------------------------------------
-- 11. LOAN REPAYMENTS
-- --------------------------------------------------------
CREATE TABLE loan_repayments (
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

-- --------------------------------------------------------
-- 12. OTHER INCOME
-- --------------------------------------------------------
CREATE TABLE other_income (
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

-- --------------------------------------------------------
-- 13. OTHER DEDUCTIONS
-- --------------------------------------------------------
CREATE TABLE other_deductions (
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

-- --------------------------------------------------------
-- 14. PAYROLL RECORDS
-- --------------------------------------------------------
CREATE TABLE payroll_records (
    payroll_id INT NOT NULL AUTO_INCREMENT,
    employee_id INT NOT NULL,
    period_id INT DEFAULT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_income_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    loan_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    attendance_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_deductions_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('Draft','Approved','Paid') NOT NULL DEFAULT 'Draft',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payroll_id),
    KEY idx_payroll_employee (employee_id),
    KEY idx_payroll_period_fk (period_id),
    KEY idx_payroll_period (period_start, period_end),
    KEY idx_payroll_status (status),
    CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES employees (employee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_period FOREIGN KEY (period_id) REFERENCES payroll_periods (period_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
