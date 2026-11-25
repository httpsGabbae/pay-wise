<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$feedback_message = '';
$feedback_error = '';

function is_payroll_period_locked($database_connection, ?int $payroll_period_id): bool
{
    if (!$payroll_period_id || $payroll_period_id <= 0) {
        return false;
    }
    $period_query = $database_connection->prepare('SELECT status FROM payroll_periods WHERE period_id = ? LIMIT 1');
    $period_query->bind_param('i', $payroll_period_id);
    $period_query->execute();
    $period_row = $period_query->get_result()->fetch_assoc();
    if ($period_row && in_array($period_row['status'], ['Approved', 'Paid'], true)) {
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form_action = $_POST['action'] ?? '';

    if ($form_action === 'add_income') {
        $target_mode = trim($_POST['application_target_mode'] ?? 'individual');
        $selected_payroll_period_id = (int)($_POST['period_id'] ?? 0) ?: null;
        $income_name = trim($_POST['income_name'] ?? '');
        $adjustment_amount = round((float)str_replace(',', '', $_POST['amount'] ?? 0), 2);
        $is_taxable_flag = isset($_POST['is_taxable']) ? 1 : 0;
        $payment_date = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $adjustment_notes = trim($_POST['notes'] ?? '');

        if (is_payroll_period_locked($conn, $selected_payroll_period_id)) {
            $feedback_error = 'Cannot add an adjustment to an Approved or Paid payroll period.';
        } elseif ($income_name === '' || $adjustment_amount <= 0) {
            $feedback_error = 'Please provide an income description and an amount greater than 0.';
        } elseif ($target_mode === 'department') {
            $target_department_name = trim($_POST['target_department_name'] ?? '');
            if ($target_department_name === '') {
                $feedback_error = 'Please choose a department for batch addition.';
            } else {
                $employee_query = $conn->prepare("SELECT employee_id FROM employees WHERE department = ? AND COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive')");
                $employee_query->bind_param('s', $target_department_name);
                $employee_query->execute();
                $employee_results = $employee_query->get_result();

                $inserted_records_count = 0;
                $insert_statement = $conn->prepare('INSERT INTO other_income (employee_id, period_id, income_name, amount, is_taxable, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');

                while ($employee_row = $employee_results->fetch_assoc()) {
                    $target_employee_id = (int)$employee_row['employee_id'];
                    $insert_statement->bind_param('iisdiss', $target_employee_id, $selected_payroll_period_id, $income_name, $adjustment_amount, $is_taxable_flag, $payment_date, $adjustment_notes);
                    if ($insert_statement->execute()) {
                        $inserted_records_count++;
                    }
                }

                header('Location: other_income.php?batch_saved=' . $inserted_records_count . '&tab=income');
                exit;
            }
        } elseif ($target_mode === 'all_staff') {
            $employee_query = $conn->query("SELECT employee_id FROM employees WHERE COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive')");
            $inserted_records_count = 0;
            $insert_statement = $conn->prepare('INSERT INTO other_income (employee_id, period_id, income_name, amount, is_taxable, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');

            while ($employee_row = $employee_query->fetch_assoc()) {
                $target_employee_id = (int)$employee_row['employee_id'];
                $insert_statement->bind_param('iisdiss', $target_employee_id, $selected_payroll_period_id, $income_name, $adjustment_amount, $is_taxable_flag, $payment_date, $adjustment_notes);
                if ($insert_statement->execute()) {
                    $inserted_records_count++;
                }
            }

            header('Location: other_income.php?batch_saved=' . $inserted_records_count . '&tab=income');
            exit;
        } else {
            $target_employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($target_employee_id <= 0) {
                $feedback_error = 'Please select an employee.';
            } else {
                $insert_statement = $conn->prepare('INSERT INTO other_income (employee_id, period_id, income_name, amount, is_taxable, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert_statement->bind_param('iisdiss', $target_employee_id, $selected_payroll_period_id, $income_name, $adjustment_amount, $is_taxable_flag, $payment_date, $adjustment_notes);
                if ($insert_statement->execute()) {
                    header('Location: other_income.php?saved=1&tab=income');
                    exit;
                }
                $feedback_error = 'Failed to add other income record.';
            }
        }
    } elseif ($form_action === 'update_income') {
        $income_record_id = (int)($_POST['income_id'] ?? 0);
        $target_employee_id = (int)($_POST['employee_id'] ?? 0);
        $selected_payroll_period_id = (int)($_POST['period_id'] ?? 0) ?: null;
        $income_name = trim($_POST['income_name'] ?? '');
        $adjustment_amount = round((float)str_replace(',', '', $_POST['amount'] ?? 0), 2);
        $is_taxable_flag = isset($_POST['is_taxable']) ? 1 : 0;
        $payment_date = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $adjustment_notes = trim($_POST['notes'] ?? '');

        $check_query = $conn->prepare('SELECT period_id FROM other_income WHERE income_id = ? LIMIT 1');
        $check_query->bind_param('i', $income_record_id);
        $check_query->execute();
        $existing_record = $check_query->get_result()->fetch_assoc();

        if (!$existing_record) {
            $feedback_error = 'Income record not found.';
        } elseif (is_payroll_period_locked($conn, (int)$existing_record['period_id']) || is_payroll_period_locked($conn, $selected_payroll_period_id)) {
            $feedback_error = 'Cannot update an adjustment tied to an Approved or Paid payroll period.';
        } elseif ($target_employee_id <= 0 || $income_name === '' || $adjustment_amount <= 0) {
            $feedback_error = 'Please complete all required fields with valid amounts.';
        } else {
            $update_statement = $conn->prepare('UPDATE other_income SET employee_id = ?, period_id = ?, income_name = ?, amount = ?, is_taxable = ?, pay_date = ?, notes = ? WHERE income_id = ?');
            $update_statement->bind_param('iisdissi', $target_employee_id, $selected_payroll_period_id, $income_name, $adjustment_amount, $is_taxable_flag, $payment_date, $adjustment_notes, $income_record_id);
            if ($update_statement->execute()) {
                header('Location: other_income.php?updated=1&tab=income');
                exit;
            }
            $feedback_error = 'Failed to update income record.';
        }
    } elseif ($form_action === 'delete_income') {
        $income_record_id = (int)($_POST['income_id'] ?? 0);
        $check_query = $conn->prepare('SELECT period_id FROM other_income WHERE income_id = ? LIMIT 1');
        $check_query->bind_param('i', $income_record_id);
        $check_query->execute();
        $existing_record = $check_query->get_result()->fetch_assoc();

        if ($existing_record && is_payroll_period_locked($conn, (int)$existing_record['period_id'])) {
            $feedback_error = 'Cannot delete an adjustment from an Approved or Paid payroll period.';
        } elseif ($income_record_id > 0) {
            $delete_statement = $conn->prepare('DELETE FROM other_income WHERE income_id = ?');
            $delete_statement->bind_param('i', $income_record_id);
            if ($delete_statement->execute()) {
                header('Location: other_income.php?deleted=1&tab=income');
                exit;
            }
            $feedback_error = 'Unable to delete income record.';
        }
    } elseif ($form_action === 'add_deduction') {
        $target_mode = trim($_POST['application_target_mode'] ?? 'individual');
        $selected_payroll_period_id = (int)($_POST['period_id'] ?? 0) ?: null;
        $deduction_name = trim($_POST['deduction_name'] ?? '');
        $adjustment_amount = round((float)str_replace(',', '', $_POST['amount'] ?? 0), 2);
        $payment_date = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $adjustment_notes = trim($_POST['notes'] ?? '');

        if (is_payroll_period_locked($conn, $selected_payroll_period_id)) {
            $feedback_error = 'Cannot add a deduction to an Approved or Paid payroll period.';
        } elseif ($deduction_name === '' || $adjustment_amount <= 0) {
            $feedback_error = 'Please provide a deduction description and an amount greater than 0.';
        } elseif ($target_mode === 'department') {
            $target_department_name = trim($_POST['target_department_name'] ?? '');
            if ($target_department_name === '') {
                $feedback_error = 'Please choose a department for batch addition.';
            } else {
                $employee_query = $conn->prepare("SELECT employee_id FROM employees WHERE department = ? AND COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive')");
                $employee_query->bind_param('s', $target_department_name);
                $employee_query->execute();
                $employee_results = $employee_query->get_result();

                $inserted_records_count = 0;
                $insert_statement = $conn->prepare('INSERT INTO other_deductions (employee_id, period_id, deduction_name, amount, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?)');

                while ($employee_row = $employee_results->fetch_assoc()) {
                    $target_employee_id = (int)$employee_row['employee_id'];
                    $insert_statement->bind_param('iisdss', $target_employee_id, $selected_payroll_period_id, $deduction_name, $adjustment_amount, $payment_date, $adjustment_notes);
                    if ($insert_statement->execute()) {
                        $inserted_records_count++;
                    }
                }

                header('Location: other_income.php?batch_saved=' . $inserted_records_count . '&tab=deduction');
                exit;
            }
        } elseif ($target_mode === 'all_staff') {
            $employee_query = $conn->query("SELECT employee_id FROM employees WHERE COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive')");
            $inserted_records_count = 0;
            $insert_statement = $conn->prepare('INSERT INTO other_deductions (employee_id, period_id, deduction_name, amount, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?)');

            while ($employee_row = $employee_query->fetch_assoc()) {
                $target_employee_id = (int)$employee_row['employee_id'];
                $insert_statement->bind_param('iisdss', $target_employee_id, $selected_payroll_period_id, $deduction_name, $adjustment_amount, $payment_date, $adjustment_notes);
                if ($insert_statement->execute()) {
                    $inserted_records_count++;
                }
            }

            header('Location: other_income.php?batch_saved=' . $inserted_records_count . '&tab=deduction');
            exit;
        } else {
            $target_employee_id = (int)($_POST['employee_id'] ?? 0);
            if ($target_employee_id <= 0) {
                $feedback_error = 'Please select an employee.';
            } else {
                $insert_statement = $conn->prepare('INSERT INTO other_deductions (employee_id, period_id, deduction_name, amount, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?)');
                $insert_statement->bind_param('iisdss', $target_employee_id, $selected_payroll_period_id, $deduction_name, $adjustment_amount, $payment_date, $adjustment_notes);
                if ($insert_statement->execute()) {
                    header('Location: other_income.php?saved=1&tab=deduction');
                    exit;
                }
                $feedback_error = 'Failed to add other deduction record.';
            }
        }
    } elseif ($form_action === 'update_deduction') {
        $deduction_record_id = (int)($_POST['deduction_id'] ?? 0);
        $target_employee_id = (int)($_POST['employee_id'] ?? 0);
        $selected_payroll_period_id = (int)($_POST['period_id'] ?? 0) ?: null;
        $deduction_name = trim($_POST['deduction_name'] ?? '');
        $adjustment_amount = round((float)str_replace(',', '', $_POST['amount'] ?? 0), 2);
        $payment_date = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $adjustment_notes = trim($_POST['notes'] ?? '');

        $check_query = $conn->prepare('SELECT period_id FROM other_deductions WHERE deduction_id = ? LIMIT 1');
        $check_query->bind_param('i', $deduction_record_id);
        $check_query->execute();
        $existing_record = $check_query->get_result()->fetch_assoc();

        if (!$existing_record) {
            $feedback_error = 'Deduction record not found.';
        } elseif (is_payroll_period_locked($conn, (int)$existing_record['period_id']) || is_payroll_period_locked($conn, $selected_payroll_period_id)) {
            $feedback_error = 'Cannot update a deduction tied to an Approved or Paid payroll period.';
        } elseif ($target_employee_id <= 0 || $deduction_name === '' || $adjustment_amount <= 0) {
            $feedback_error = 'Please complete all required fields with valid amounts.';
        } else {
            $update_statement = $conn->prepare('UPDATE other_deductions SET employee_id = ?, period_id = ?, deduction_name = ?, amount = ?, pay_date = ?, notes = ? WHERE deduction_id = ?');
            $update_statement->bind_param('iisdssi', $target_employee_id, $selected_payroll_period_id, $deduction_name, $adjustment_amount, $payment_date, $adjustment_notes, $deduction_record_id);
            if ($update_statement->execute()) {
                header('Location: other_income.php?updated=1&tab=deduction');
                exit;
            }
            $feedback_error = 'Failed to update deduction record.';
        }
    } elseif ($form_action === 'delete_deduction') {
        $deduction_record_id = (int)($_POST['deduction_id'] ?? 0);
        $check_query = $conn->prepare('SELECT period_id FROM other_deductions WHERE deduction_id = ? LIMIT 1');
        $check_query->bind_param('i', $deduction_record_id);
        $check_query->execute();
        $existing_record = $check_query->get_result()->fetch_assoc();

        if ($existing_record && is_payroll_period_locked($conn, (int)$existing_record['period_id'])) {
            $feedback_error = 'Cannot delete a deduction from an Approved or Paid payroll period.';
        } elseif ($deduction_record_id > 0) {
            $delete_statement = $conn->prepare('DELETE FROM other_deductions WHERE deduction_id = ?');
            $delete_statement->bind_param('i', $deduction_record_id);
            if ($delete_statement->execute()) {
                header('Location: other_income.php?deleted=1&tab=deduction');
                exit;
            }
            $feedback_error = 'Unable to delete deduction record.';
        }
    }
}

if (isset($_GET['saved'])) $feedback_message = 'Adjustment entry saved successfully.';
if (isset($_GET['updated'])) $feedback_message = 'Adjustment entry updated successfully.';
if (isset($_GET['deleted'])) $feedback_message = 'Adjustment entry removed successfully.';
if (isset($_GET['batch_saved'])) {
    $count = (int)$_GET['batch_saved'];
    $feedback_message = "Successfully created {$count} batch adjustment entries.";
}

$filter_period_id = (int)($_GET['period_id'] ?? 0);
$filter_employee_id = (int)($_GET['employee_id'] ?? 0);
$filter_department_name = trim($_GET['department_name'] ?? '');
$filter_search_keyword = trim($_GET['search_keyword'] ?? '');
$active_view_tab = trim($_GET['tab'] ?? 'all');
if (!in_array($active_view_tab, ['all', 'income', 'deduction'], true)) {
    $active_view_tab = 'all';
}

$editing_income_id = (int)($_GET['edit_income_id'] ?? 0);
$editing_deduction_id = (int)($_GET['edit_deduction_id'] ?? 0);
$editing_income_record = null;
$editing_deduction_record = null;

if ($editing_income_id > 0) {
    $edit_query = $conn->prepare('SELECT * FROM other_income WHERE income_id = ? LIMIT 1');
    $edit_query->bind_param('i', $editing_income_id);
    $edit_query->execute();
    $editing_income_record = $edit_query->get_result()->fetch_assoc();
    if ($editing_income_record && is_payroll_period_locked($conn, (int)$editing_income_record['period_id'])) {
        $feedback_error = 'This income item is locked and cannot be edited because its payroll period has been finalized.';
        $editing_income_record = null;
    } else {
        $active_view_tab = 'income';
    }
}

if ($editing_deduction_id > 0) {
    $edit_query = $conn->prepare('SELECT * FROM other_deductions WHERE deduction_id = ? LIMIT 1');
    $edit_query->bind_param('i', $editing_deduction_id);
    $edit_query->execute();
    $editing_deduction_record = $edit_query->get_result()->fetch_assoc();
    if ($editing_deduction_record && is_payroll_period_locked($conn, (int)$editing_deduction_record['period_id'])) {
        $feedback_error = 'This deduction item is locked and cannot be edited because its payroll period has been finalized.';
        $editing_deduction_record = null;
    } else {
        $active_view_tab = 'deduction';
    }
}

$payroll_periods_list = [];
$periods_result = $conn->query('SELECT * FROM payroll_periods ORDER BY start_date DESC');
if ($periods_result) {
    while ($period_row = $periods_result->fetch_assoc()) {
        $payroll_periods_list[] = $period_row;
    }
}

$active_employees_list = [];
$employees_result = $conn->query("SELECT employee_id, employee_no, first_name, last_name, department FROM employees WHERE COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive') ORDER BY last_name ASC, first_name ASC");
if ($employees_result) {
    while ($employee_row = $employees_result->fetch_assoc()) {
        $active_employees_list[] = $employee_row;
    }
}

$unique_departments_list = [];
$departments_result = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
if ($departments_result) {
    while ($department_row = $departments_result->fetch_assoc()) {
        $unique_departments_list[] = $department_row['department'];
    }
}

$income_where_clauses = [];
if ($filter_period_id > 0) {
    $income_where_clauses[] = "oi.period_id = " . (int)$filter_period_id;
}
if ($filter_employee_id > 0) {
    $income_where_clauses[] = "oi.employee_id = " . (int)$filter_employee_id;
}
if ($filter_department_name !== '') {
    $escaped_department = $conn->real_escape_string($filter_department_name);
    $income_where_clauses[] = "e.department = '{$escaped_department}'";
}
if ($filter_search_keyword !== '') {
    $escaped_keyword = $conn->real_escape_string($filter_search_keyword);
    $income_where_clauses[] = "(e.first_name LIKE '%{$escaped_keyword}%' OR e.last_name LIKE '%{$escaped_keyword}%' OR e.employee_no LIKE '%{$escaped_keyword}%' OR oi.income_name LIKE '%{$escaped_keyword}%' OR oi.notes LIKE '%{$escaped_keyword}%')";
}
$income_sql_where = $income_where_clauses ? 'WHERE ' . implode(' AND ', $income_where_clauses) : '';

$other_income_records = [];
$income_query_result = $conn->query("SELECT oi.*, e.employee_no, e.first_name, e.last_name, e.department, pp.period_code, pp.status AS period_status
    FROM other_income oi
    JOIN employees e ON e.employee_id = oi.employee_id
    LEFT JOIN payroll_periods pp ON pp.period_id = oi.period_id
    {$income_sql_where}
    ORDER BY oi.income_id DESC");
if ($income_query_result) {
    while ($income_row = $income_query_result->fetch_assoc()) {
        $other_income_records[] = $income_row;
    }
}

$deduction_where_clauses = [];
if ($filter_period_id > 0) {
    $deduction_where_clauses[] = "od.period_id = " . (int)$filter_period_id;
}
if ($filter_employee_id > 0) {
    $deduction_where_clauses[] = "od.employee_id = " . (int)$filter_employee_id;
}
if ($filter_department_name !== '') {
    $escaped_department = $conn->real_escape_string($filter_department_name);
    $deduction_where_clauses[] = "e.department = '{$escaped_department}'";
}
if ($filter_search_keyword !== '') {
    $escaped_keyword = $conn->real_escape_string($filter_search_keyword);
    $deduction_where_clauses[] = "(e.first_name LIKE '%{$escaped_keyword}%' OR e.last_name LIKE '%{$escaped_keyword}%' OR e.employee_no LIKE '%{$escaped_keyword}%' OR od.deduction_name LIKE '%{$escaped_keyword}%' OR od.notes LIKE '%{$escaped_keyword}%')";
}
$deduction_sql_where = $deduction_where_clauses ? 'WHERE ' . implode(' AND ', $deduction_where_clauses) : '';

$other_deduction_records = [];
$deduction_query_result = $conn->query("SELECT od.*, e.employee_no, e.first_name, e.last_name, e.department, pp.period_code, pp.status AS period_status
    FROM other_deductions od
    JOIN employees e ON e.employee_id = od.employee_id
    LEFT JOIN payroll_periods pp ON pp.period_id = od.period_id
    {$deduction_sql_where}
    ORDER BY od.deduction_id DESC");
if ($deduction_query_result) {
    while ($deduction_row = $deduction_query_result->fetch_assoc()) {
        $other_deduction_records[] = $deduction_row;
    }
}

if (isset($_GET['export_csv'])) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=lipa_city_colleges_adjustments_' . date('Ymd_His') . '.csv');
    $output_stream = fopen('php://output', 'w');
    fputs($output_stream, "\xEF\xBB\xBF");

    fputcsv($output_stream, ['Category', 'Employee ID', 'Employee Name', 'Department', 'Payroll Period', 'Pay Date', 'Adjustment Description', 'Taxable', 'Amount (PHP)', 'Notes', 'Period Status']);

    foreach ($other_income_records as $income_item) {
        fputcsv($output_stream, [
            'Other Income',
            $income_item['employee_no'],
            $income_item['last_name'] . ', ' . $income_item['first_name'],
            $income_item['department'],
            $income_item['period_code'] ?: 'General',
            $income_item['pay_date'],
            $income_item['income_name'],
            (int)$income_item['is_taxable'] === 1 ? 'Yes' : 'No',
            number_format((float)$income_item['amount'], 2, '.', ''),
            $income_item['notes'],
            $income_item['period_status'] ?: 'Draft'
        ]);
    }

    foreach ($other_deduction_records as $deduction_item) {
        fputcsv($output_stream, [
            'Other Deduction',
            $deduction_item['employee_no'],
            $deduction_item['last_name'] . ', ' . $deduction_item['first_name'],
            $deduction_item['department'],
            $deduction_item['period_code'] ?: 'General',
            $deduction_item['pay_date'],
            $deduction_item['deduction_name'],
            'N/A',
            number_format(-(float)$deduction_item['amount'], 2, '.', ''),
            $deduction_item['notes'],
            $deduction_item['period_status'] ?: 'Draft'
        ]);
    }

    fclose($output_stream);
    exit;
}

$total_income_amount = 0.0;
$unique_affected_employee_ids = [];
foreach ($other_income_records as $income_item) {
    $total_income_amount += (float)$income_item['amount'];
    $unique_affected_employee_ids[$income_item['employee_id']] = true;
}

$total_deductions_amount = 0.0;
foreach ($other_deduction_records as $deduction_item) {
    $total_deductions_amount += (float)$deduction_item['amount'];
    $unique_affected_employee_ids[$deduction_item['employee_id']] = true;
}

$net_adjustment_amount = $total_income_amount - $total_deductions_amount;
$total_affected_employees_count = count($unique_affected_employee_ids);
$total_records_count = count($other_income_records) + count($other_deduction_records);

$filter_query_params = [];
if ($filter_period_id > 0) $filter_query_params['period_id'] = $filter_period_id;
if ($filter_employee_id > 0) $filter_query_params['employee_id'] = $filter_employee_id;
if ($filter_department_name !== '') $filter_query_params['department_name'] = $filter_department_name;
if ($filter_search_keyword !== '') $filter_query_params['search_keyword'] = $filter_search_keyword;
$filter_query_string = $filter_query_params ? '&' . http_build_query($filter_query_params) : '';
$export_query_string = $filter_query_params ? '&' . http_build_query($filter_query_params) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Adjustments</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('other_income'); ?>
    <main class="main">
        <?php topbar('Adjustments'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Payroll Adjustments & Other Items</h1>
                    <p>Teaching honoraria, overload allowances, incentives, and itemized deductions.</p>
                </div>
            </div>

            <?php if ($feedback_message): ?><div class="notice ok"><?php echo e($feedback_message); ?></div><?php endif; ?>
            <?php if ($feedback_error): ?><div class="notice err"><?php echo e($feedback_error); ?></div><?php endif; ?>

            <!-- Suggested Preset Autocomplete Lists -->
            <datalist id="suggested_income_categories">
                <option value="Overload Teaching">
                <option value="Teaching Honorarium">
                <option value="Thesis Advising">
                <option value="Proctoring Fee">
                <option value="Performance Bonus">
                <option value="Salary Differential / Retro Pay">
                <option value="Substitute Teaching">
                <option value="Special Allowance">
                <option value="Research Grant Incentive">
            </datalist>

            <datalist id="suggested_deduction_categories">
                <option value="Faculty Club Dues">
                <option value="Uniform Installment">
                <option value="ID / Badge Replacement">
                <option value="Cooperative Contribution">
                <option value="Cash Advance Recovery">
                <option value="Withholding Tax Adjustment">
                <option value="Overpayment Recovery">
                <option value="Laptop / Device Installment">
                <option value="Graduation Regalia Fee">
            </datalist>

            <!-- 1. Executive Summary Financial KPI Cards (Phase 1) -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 18px;">
                <div class="card" style="padding: 14px 18px; border-left: 4px solid var(--ok-text, #16a34a);">
                    <div style="font-size: 11.5px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Total Additions</div>
                    <div style="font-size: 22px; font-weight: 700; color: var(--ok-text, #16a34a); margin-top: 4px;">
                        +<?php echo money($total_income_amount); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                        <?php echo count($other_income_records); ?> income item<?php echo count($other_income_records) === 1 ? '' : 's'; ?>
                    </div>
                </div>

                <div class="card" style="padding: 14px 18px; border-left: 4px solid var(--err-text, #dc2626);">
                    <div style="font-size: 11.5px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Total Deductions</div>
                    <div style="font-size: 22px; font-weight: 700; color: var(--err-text, #dc2626); margin-top: 4px;">
                        -<?php echo money($total_deductions_amount); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                        <?php echo count($other_deduction_records); ?> deduction item<?php echo count($other_deduction_records) === 1 ? '' : 's'; ?>
                    </div>
                </div>

                <div class="card" style="padding: 14px 18px; border-left: 4px solid <?php echo $net_adjustment_amount >= 0 ? 'var(--ok-text, #16a34a)' : 'var(--err-text, #dc2626)'; ?>;">
                    <div style="font-size: 11.5px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Net Adjustment Impact</div>
                    <div style="font-size: 22px; font-weight: 700; color: <?php echo $net_adjustment_amount >= 0 ? 'var(--ok-text, #16a34a)' : 'var(--err-text, #dc2626)'; ?>; margin-top: 4px;">
                        <?php echo ($net_adjustment_amount >= 0 ? '+' : '') . money($net_adjustment_amount); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                        <?php echo $net_adjustment_amount >= 0 ? 'Net addition to gross pay' : 'Net reduction from payroll'; ?>
                    </div>
                </div>

                <div class="card" style="padding: 14px 18px; border-left: 4px solid #3b82f6;">
                    <div style="font-size: 11.5px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Staff Affected</div>
                    <div style="font-size: 22px; font-weight: 700; color: #1e293b; margin-top: 4px;">
                        <?php echo $total_affected_employees_count; ?> Staff Member<?php echo $total_affected_employees_count === 1 ? '' : 's'; ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                        <?php echo $total_records_count; ?> total recorded line<?php echo $total_records_count === 1 ? '' : 's'; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Search, Filter, and Export Toolbar (Phase 3) -->
            <div class="card" style="margin-bottom: 18px; padding: 12px 16px;">
                <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <input type="hidden" name="tab" value="<?php echo e($active_view_tab); ?>">

                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-size:12.5px; font-weight:600; color:var(--text-muted);">Period</label>
                        <select name="period_id" onchange="this.form.submit()" style="padding:6px 10px; font-size:13px;">
                            <option value="0">All Periods</option>
                            <?php foreach ($payroll_periods_list as $period_item): ?>
                                <option value="<?php echo (int)$period_item['period_id']; ?>" <?php echo $filter_period_id === (int)$period_item['period_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($period_item['period_code'] . ' (' . date('M d', strtotime($period_item['start_date'])) . ' – ' . date('M d', strtotime($period_item['end_date'])) . ')'); ?>
                                    <?php if (in_array($period_item['status'], ['Approved', 'Paid'], true)): ?> [🔒 <?php echo e($period_item['status']); ?>]<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-size:12.5px; font-weight:600; color:var(--text-muted);">Department</label>
                        <select name="department_name" onchange="this.form.submit()" style="padding:6px 10px; font-size:13px; max-width:200px;">
                            <option value="">All Departments</option>
                            <?php foreach ($unique_departments_list as $department_option): ?>
                                <option value="<?php echo e($department_option); ?>" <?php echo $filter_department_name === $department_option ? 'selected' : ''; ?>>
                                    <?php echo e($department_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-size:12.5px; font-weight:600; color:var(--text-muted);">Employee</label>
                        <select name="employee_id" onchange="this.form.submit()" style="padding:6px 10px; font-size:13px; max-width:220px;">
                            <option value="0">All Employees</option>
                            <?php foreach ($active_employees_list as $employee_option): ?>
                                <option value="<?php echo (int)$employee_option['employee_id']; ?>" <?php echo $filter_employee_id === (int)$employee_option['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($employee_option['last_name'] . ', ' . $employee_option['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:6px; flex:1; min-width:180px;">
                        <input type="text" name="search_keyword" value="<?php echo e($filter_search_keyword); ?>" placeholder="Search description, employee, notes..." style="width:100%; padding:6px 10px; font-size:13px;">
                        <button type="submit" class="btn btn-small btn-secondary"><?php echo ui_icon('search'); ?></button>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; margin-left:auto;">
                        <a href="other_income.php?export_csv=1<?php echo $export_query_string; ?>" class="btn btn-small btn-secondary" title="Export current list to CSV">
                            <span><?php echo ui_icon('file-chart'); ?></span> Export CSV
                        </a>
                        <?php if ($filter_period_id > 0 || $filter_employee_id > 0 || $filter_department_name !== '' || $filter_search_keyword !== ''): ?>
                            <a href="other_income.php?tab=<?php echo e($active_view_tab); ?>" class="btn btn-small btn-secondary">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 3. Tabbed Navigation Switcher (Phase 3) -->
            <div style="display:flex; gap:8px; margin-bottom:18px; border-bottom:1px solid var(--border, #e2e8f0); padding-bottom:8px;">
                <a href="?tab=all<?php echo $filter_query_string; ?>" class="btn btn-small <?php echo $active_view_tab === 'all' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius:20px; padding:5px 14px;">
                    All Adjustments (<?php echo $total_records_count; ?>)
                </a>
                <a href="?tab=income<?php echo $filter_query_string; ?>" class="btn btn-small <?php echo $active_view_tab === 'income' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius:20px; padding:5px 14px;">
                    Other Incomes (+<?php echo count($other_income_records); ?>)
                </a>
                <a href="?tab=deduction<?php echo $active_view_tab === 'deduction' ? 'btn-primary' : 'btn-secondary'; ?>" class="btn btn-small <?php echo $active_view_tab === 'deduction' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius:20px; padding:5px 14px;">
                    Other Deductions (-<?php echo count($other_deduction_records); ?>)
                </a>
            </div>

            <!-- 4. Main Two-Column or Focused View Layout -->
            <div style="display:grid; grid-template-columns: <?php echo $active_view_tab === 'all' ? 'repeat(auto-fit, minmax(480px, 1fr))' : '1fr'; ?>; gap: 24px; align-items:start;">

                <!-- LEFT COLUMN: OTHER INCOMES -->
                <?php if ($active_view_tab === 'all' || $active_view_tab === 'income'): ?>
                <div>
                    <section class="card" id="income-form-section" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <div>
                                <h2 style="color:var(--ok-text);">
                                    <?php echo $editing_income_record ? 'Edit Other Income #' . (int)$editing_income_record['income_id'] : '+ Add Other Income'; ?>
                                </h2>
                                <p><?php echo $editing_income_record ? 'Update amount, period, or tax status for this adjustment' : 'Overload teaching, honoraria, incentives, and allowances'; ?></p>
                            </div>
                        </div>
                        <form method="post" style="padding: 18px 20px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $editing_income_record ? 'update_income' : 'add_income'; ?>">
                            <?php if ($editing_income_record): ?>
                                <input type="hidden" name="income_id" value="<?php echo (int)$editing_income_record['income_id']; ?>">
                            <?php endif; ?>

                            <!-- Batch Mode Selection (Segmented Control) -->
                            <?php if (!$editing_income_record): ?>
                            <div class="field" style="margin-bottom:14px;">
                                <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px; margin-bottom:5px; display:block;">Target Staff</label>
                                <div style="display:inline-flex; width:100%; background:#f1f5f9; padding:3px; border-radius:8px; border:1px solid #e2e8f0; gap:3px;">
                                    <button type="button" id="income_target_btn_individual" onclick="setAdjustmentTarget('income', 'individual')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:600; border-radius:6px; border:none; background:#ffffff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.08); cursor:pointer;">
                                        Individual Staff
                                    </button>
                                    <button type="button" id="income_target_btn_department" onclick="setAdjustmentTarget('income', 'department')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:500; border-radius:6px; border:none; background:transparent; color:#64748b; cursor:pointer;">
                                        By Department
                                    </button>
                                    <button type="button" id="income_target_btn_all_staff" onclick="setAdjustmentTarget('income', 'all_staff')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:500; border-radius:6px; border:none; background:transparent; color:#64748b; cursor:pointer;">
                                        All Active Staff
                                    </button>
                                </div>
                                <input type="hidden" name="application_target_mode" id="income_target_mode_input" value="individual">
                            </div>
                            <?php endif; ?>

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Payroll Period</label>
                                    <select name="period_id">
                                        <option value="">(None / General)</option>
                                        <?php foreach ($payroll_periods_list as $period_option): ?>
                                            <?php $is_period_option_locked = in_array($period_option['status'], ['Approved', 'Paid'], true); ?>
                                            <option value="<?php echo (int)$period_option['period_id']; ?>"
                                                <?php echo ($editing_income_record ? (int)$editing_income_record['period_id'] : $filter_period_id) === (int)$period_option['period_id'] ? 'selected' : ''; ?>
                                                <?php echo $is_period_option_locked ? 'disabled' : ''; ?>>
                                                <?php echo e($period_option['period_code']); ?>
                                                <?php if ($is_period_option_locked): ?> (🔒 <?php echo e($period_option['status']); ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Pay Date</label>
                                    <input type="date" name="pay_date" value="<?php echo e($editing_income_record ? $editing_income_record['pay_date'] : date('Y-m-d')); ?>">
                                </div>
                            </div>

                            <!-- Target: Individual Employee Selection -->
                            <div id="income_individual_employee_wrapper" class="field" style="margin-bottom:12px;">
                                <label>Employee <span class="required">*</span></label>
                                <select name="employee_id" id="income_employee_select" <?php echo !$editing_income_record ? 'required' : ''; ?>>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($active_employees_list as $employee_option): ?>
                                        <option value="<?php echo (int)$employee_option['employee_id']; ?>"
                                            <?php echo ($editing_income_record ? (int)$editing_income_record['employee_id'] : $filter_employee_id) === (int)$employee_option['employee_id'] ? 'selected' : ''; ?>>
                                            <?php echo e($employee_option['employee_no'] . ' — ' . $employee_option['last_name'] . ', ' . $employee_option['first_name'] . ' (' . ($employee_option['department'] ?: 'No Dept') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Target: Department Selection (Hidden unless chosen) -->
                            <div id="income_department_wrapper" class="field" style="margin-bottom:12px; display:none;">
                                <label>Target Department <span class="required">*</span></label>
                                <select name="target_department_name">
                                    <option value="">Select Department</option>
                                    <?php foreach ($unique_departments_list as $department_option): ?>
                                        <option value="<?php echo e($department_option); ?>" <?php echo $filter_department_name === $department_option ? 'selected' : ''; ?>>
                                            <?php echo e($department_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Income Description with Suggested Presets (Phase 1) -->
                            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Income Type / Preset <span class="required">*</span></label>
                                    <input type="text" list="suggested_income_categories" name="income_name" placeholder="Choose preset or type description" value="<?php echo e($editing_income_record ? $editing_income_record['income_name'] : ''); ?>" required>
                                </div>
                                <div class="field">
                                    <label>Amount (₱) <span class="required">*</span></label>
                                    <input type="number" step="0.01" min="1" name="amount" placeholder="0.00" value="<?php echo $editing_income_record ? (float)$editing_income_record['amount'] : ''; ?>" required>
                                </div>
                            </div>

                            <!-- Taxability Option -->
                            <div style="margin-bottom:14px;">
                                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; font-size:12.5px; font-weight:600; color:var(--text, #1e293b); margin:0;">
                                    <input type="checkbox" name="is_taxable" value="1" <?php echo (!isset($editing_income_record) || (int)$editing_income_record['is_taxable'] === 1) ? 'checked' : ''; ?> style="width:16px !important; min-width:16px !important; max-width:16px !important; height:16px !important; min-height:16px !important; max-height:16px !important; margin:0 !important; padding:0 !important; accent-color:#0b918d; cursor:pointer;">
                                    <span>Subject to Withholding Tax (Taxable Income)</span>
                                </label>
                                <p style="font-size:11px; color:var(--text-muted); margin:3px 0 0 24px;">Uncheck for non-taxable allowances, De Minimis benefits, or direct reimbursables.</p>
                            </div>

                            <div class="field" style="margin-bottom:16px;">
                                <label>Reference Notes</label>
                                <input type="text" name="notes" placeholder="Optional memorandum number, approval voucher, or details" value="<?php echo e($editing_income_record ? $editing_income_record['notes'] : ''); ?>">
                            </div>

                            <div style="display:flex; gap:10px;">
                                <button type="submit" class="btn btn-primary" style="flex:1;">
                                    <?php echo $editing_income_record ? 'Save Changes' : 'Add Income Entry'; ?>
                                </button>
                                <?php if ($editing_income_record): ?>
                                    <a href="other_income.php?tab=income<?php echo $filter_query_string; ?>" class="btn btn-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <!-- Table of Other Incomes -->
                    <section class="card">
                        <div class="card-head">
                            <div>
                                <h2>Other Income Records</h2>
                                <p><?php echo count($other_income_records); ?> itemized addition<?php echo count($other_income_records) === 1 ? '' : 's'; ?></p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period / Date</th>
                                        <th>Income Description</th>
                                        <th>Tax Status</th>
                                        <th style="text-align:right;">Amount</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($other_income_records)): ?>
                                    <tr><td colspan="6" class="empty">No other income records found matching current criteria.</td></tr>
                                <?php else: foreach ($other_income_records as $income_item): ?>
                                    <?php $is_item_locked = in_array($income_item['period_status'], ['Approved', 'Paid'], true); ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($income_item['last_name'] . ', ' . $income_item['first_name']); ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?php echo e($income_item['employee_no']); ?> · <?php echo e($income_item['department'] ?: 'Unassigned'); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size:12px; font-weight:600;"><?php echo e($income_item['period_code'] ?: date('M d, Y', strtotime($income_item['pay_date']))); ?></span>
                                            <?php if ($is_item_locked): ?>
                                                <div><span style="display:inline-block; font-size:10px; padding:1px 6px; border-radius:10px; background:#f1f5f9; color:#475569; font-weight:600;">🔒 <?php echo e($income_item['period_status']); ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo e($income_item['income_name']); ?></strong>
                                            <?php if ($income_item['notes']): ?>
                                                <div style="font-size:11px; color:var(--text-muted);"><?php echo e($income_item['notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$income_item['is_taxable'] === 1): ?>
                                                <span style="display:inline-block; font-size:11px; padding:2px 8px; border-radius:12px; background:#fef3c7; color:#92400e; font-weight:600;">Taxable</span>
                                            <?php else: ?>
                                                <span style="display:inline-block; font-size:11px; padding:2px 8px; border-radius:12px; background:#e0f2fe; color:#0369a1; font-weight:600;">Non-Taxable</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:700; color:var(--ok-text);">
                                            +<?php echo money($income_item['amount']); ?>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <?php if ($is_item_locked): ?>
                                                <span style="font-size:11px; color:var(--text-muted);" title="This record is locked because its payroll period has been finalized.">Locked</span>
                                            <?php else: ?>
                                                <a href="other_income.php?edit_income_id=<?php echo (int)$income_item['income_id']; ?><?php echo $filter_query_string; ?>#income-form-section" class="btn btn-small btn-secondary" title="Edit Item">
                                                    <?php echo ui_icon('pencil'); ?>
                                                </a>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this income entry for <?php echo e($income_item['last_name']); ?>?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_income">
                                                    <input type="hidden" name="income_id" value="<?php echo (int)$income_item['income_id']; ?>">
                                                    <button type="submit" class="btn btn-small btn-danger" title="Delete Item"><?php echo ui_icon('trash'); ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <!-- RIGHT COLUMN: OTHER DEDUCTIONS -->
                <?php if ($active_view_tab === 'all' || $active_view_tab === 'deduction'): ?>
                <div>
                    <section class="card" id="deduction-form-section" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <div>
                                <h2 style="color:var(--err-text);">
                                    <?php echo $editing_deduction_record ? 'Edit Other Deduction #' . (int)$editing_deduction_record['deduction_id'] : '- Add Other Deduction'; ?>
                                </h2>
                                <p><?php echo $editing_deduction_record ? 'Update amount, period, or notes for this deduction' : 'Faculty club dues, uniform charges, and itemized deductions'; ?></p>
                            </div>
                        </div>
                        <form method="post" style="padding: 18px 20px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $editing_deduction_record ? 'update_deduction' : 'add_deduction'; ?>">
                            <?php if ($editing_deduction_record): ?>
                                <input type="hidden" name="deduction_id" value="<?php echo (int)$editing_deduction_record['deduction_id']; ?>">
                            <?php endif; ?>

                            <!-- Batch Mode Selection (Segmented Control) -->
                            <?php if (!$editing_deduction_record): ?>
                            <div class="field" style="margin-bottom:14px;">
                                <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px; margin-bottom:5px; display:block;">Target Staff</label>
                                <div style="display:inline-flex; width:100%; background:#f1f5f9; padding:3px; border-radius:8px; border:1px solid #e2e8f0; gap:3px;">
                                    <button type="button" id="deduction_target_btn_individual" onclick="setAdjustmentTarget('deduction', 'individual')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:600; border-radius:6px; border:none; background:#ffffff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.08); cursor:pointer;">
                                        Individual Staff
                                    </button>
                                    <button type="button" id="deduction_target_btn_department" onclick="setAdjustmentTarget('deduction', 'department')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:500; border-radius:6px; border:none; background:transparent; color:#64748b; cursor:pointer;">
                                        By Department
                                    </button>
                                    <button type="button" id="deduction_target_btn_all_staff" onclick="setAdjustmentTarget('deduction', 'all_staff')" style="flex:1; padding:6px 10px; font-size:12px; font-weight:500; border-radius:6px; border:none; background:transparent; color:#64748b; cursor:pointer;">
                                        All Active Staff
                                    </button>
                                </div>
                                <input type="hidden" name="application_target_mode" id="deduction_target_mode_input" value="individual">
                            </div>
                            <?php endif; ?>

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Payroll Period</label>
                                    <select name="period_id">
                                        <option value="">(None / General)</option>
                                        <?php foreach ($payroll_periods_list as $period_option): ?>
                                            <?php $is_period_option_locked = in_array($period_option['status'], ['Approved', 'Paid'], true); ?>
                                            <option value="<?php echo (int)$period_option['period_id']; ?>"
                                                <?php echo ($editing_deduction_record ? (int)$editing_deduction_record['period_id'] : $filter_period_id) === (int)$period_option['period_id'] ? 'selected' : ''; ?>
                                                <?php echo $is_period_option_locked ? 'disabled' : ''; ?>>
                                                <?php echo e($period_option['period_code']); ?>
                                                <?php if ($is_period_option_locked): ?> (🔒 <?php echo e($period_option['status']); ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Pay Date</label>
                                    <input type="date" name="pay_date" value="<?php echo e($editing_deduction_record ? $editing_deduction_record['pay_date'] : date('Y-m-d')); ?>">
                                </div>
                            </div>

                            <!-- Target: Individual Employee Selection -->
                            <div id="deduction_individual_employee_wrapper" class="field" style="margin-bottom:12px;">
                                <label>Employee <span class="required">*</span></label>
                                <select name="employee_id" id="deduction_employee_select" <?php echo !$editing_deduction_record ? 'required' : ''; ?>>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($active_employees_list as $employee_option): ?>
                                        <option value="<?php echo (int)$employee_option['employee_id']; ?>"
                                            <?php echo ($editing_deduction_record ? (int)$editing_deduction_record['employee_id'] : $filter_employee_id) === (int)$employee_option['employee_id'] ? 'selected' : ''; ?>>
                                            <?php echo e($employee_option['employee_no'] . ' — ' . $employee_option['last_name'] . ', ' . $employee_option['first_name'] . ' (' . ($employee_option['department'] ?: 'No Dept') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Target: Department Selection (Hidden unless chosen) -->
                            <div id="deduction_department_wrapper" class="field" style="margin-bottom:12px; display:none;">
                                <label>Target Department <span class="required">*</span></label>
                                <select name="target_department_name">
                                    <option value="">Select Department</option>
                                    <?php foreach ($unique_departments_list as $department_option): ?>
                                        <option value="<?php echo e($department_option); ?>" <?php echo $filter_department_name === $department_option ? 'selected' : ''; ?>>
                                            <?php echo e($department_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Deduction Description with Suggested Presets (Phase 1) -->
                            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Deduction Type / Preset <span class="required">*</span></label>
                                    <input type="text" list="suggested_deduction_categories" name="deduction_name" placeholder="Choose preset or type description" value="<?php echo e($editing_deduction_record ? $editing_deduction_record['deduction_name'] : ''); ?>" required>
                                </div>
                                <div class="field">
                                    <label>Amount (₱) <span class="required">*</span></label>
                                    <input type="number" step="0.01" min="1" name="amount" placeholder="0.00" value="<?php echo $editing_deduction_record ? (float)$editing_deduction_record['amount'] : ''; ?>" required>
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:16px;">
                                <label>Reference Notes</label>
                                <input type="text" name="notes" placeholder="Optional reference notes, invoice number, or details" value="<?php echo e($editing_deduction_record ? $editing_deduction_record['notes'] : ''); ?>">
                            </div>

                            <div style="display:flex; gap:10px;">
                                <button type="submit" class="btn btn-primary" style="flex:1; background:var(--err-text, #dc2626); border-color:var(--err-text, #dc2626);">
                                    <?php echo $editing_deduction_record ? 'Save Changes' : 'Add Deduction Entry'; ?>
                                </button>
                                <?php if ($editing_deduction_record): ?>
                                    <a href="other_income.php?tab=deduction<?php echo $filter_query_string; ?>" class="btn btn-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <!-- Table of Other Deductions -->
                    <section class="card">
                        <div class="card-head">
                            <div>
                                <h2>Other Deduction Records</h2>
                                <p><?php echo count($other_deduction_records); ?> itemized deduction<?php echo count($other_deduction_records) === 1 ? '' : 's'; ?></p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period / Date</th>
                                        <th>Deduction Description</th>
                                        <th style="text-align:right;">Amount</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($other_deduction_records)): ?>
                                    <tr><td colspan="5" class="empty">No other deductions recorded matching current criteria.</td></tr>
                                <?php else: foreach ($other_deduction_records as $deduction_item): ?>
                                    <?php $is_item_locked = in_array($deduction_item['period_status'], ['Approved', 'Paid'], true); ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($deduction_item['last_name'] . ', ' . $deduction_item['first_name']); ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?php echo e($deduction_item['employee_no']); ?> · <?php echo e($deduction_item['department'] ?: 'Unassigned'); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size:12px; font-weight:600;"><?php echo e($deduction_item['period_code'] ?: date('M d, Y', strtotime($deduction_item['pay_date']))); ?></span>
                                            <?php if ($is_item_locked): ?>
                                                <div><span style="display:inline-block; font-size:10px; padding:1px 6px; border-radius:10px; background:#f1f5f9; color:#475569; font-weight:600;">🔒 <?php echo e($deduction_item['period_status']); ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo e($deduction_item['deduction_name']); ?></strong>
                                            <?php if ($deduction_item['notes']): ?>
                                                <div style="font-size:11px; color:var(--text-muted);"><?php echo e($deduction_item['notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:700; color:var(--err-text);">
                                            -<?php echo money($deduction_item['amount']); ?>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <?php if ($is_item_locked): ?>
                                                <span style="font-size:11px; color:var(--text-muted);" title="This record is locked because its payroll period has been finalized.">Locked</span>
                                            <?php else: ?>
                                                <a href="other_income.php?edit_deduction_id=<?php echo (int)$deduction_item['deduction_id']; ?><?php echo $filter_query_string; ?>#deduction-form-section" class="btn btn-small btn-secondary" title="Edit Item">
                                                    <?php echo ui_icon('pencil'); ?>
                                                </a>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this deduction entry for <?php echo e($deduction_item['last_name']); ?>?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_deduction">
                                                    <input type="hidden" name="deduction_id" value="<?php echo (int)$deduction_item['deduction_id']; ?>">
                                                    <button type="submit" class="btn btn-small btn-danger" title="Delete Item"><?php echo ui_icon('trash'); ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<script>
function setAdjustmentTarget(section, mode) {
    const hidden_input = document.getElementById(section + '_target_mode_input');
    if (hidden_input) hidden_input.value = mode;

    const modes = ['individual', 'department', 'all_staff'];
    modes.forEach(function(m) {
        const btn = document.getElementById(section + '_target_btn_' + m);
        if (!btn) return;
        if (m === mode) {
            btn.style.background = '#ffffff';
            btn.style.color = '#0f172a';
            btn.style.fontWeight = '600';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        } else {
            btn.style.background = 'transparent';
            btn.style.color = '#64748b';
            btn.style.fontWeight = '500';
            btn.style.boxShadow = 'none';
        }
    });

    const employee_wrapper = document.getElementById(section + '_individual_employee_wrapper');
    const department_wrapper = document.getElementById(section + '_department_wrapper');
    const employee_select = document.getElementById(section + '_employee_select');

    if (mode === 'individual') {
        if (employee_wrapper) employee_wrapper.style.display = 'block';
        if (department_wrapper) department_wrapper.style.display = 'none';
        if (employee_select) employee_select.required = true;
    } else if (mode === 'department') {
        if (employee_wrapper) employee_wrapper.style.display = 'none';
        if (department_wrapper) department_wrapper.style.display = 'block';
        if (employee_select) employee_select.required = false;
    } else {
        if (employee_wrapper) employee_wrapper.style.display = 'none';
        if (department_wrapper) department_wrapper.style.display = 'none';
        if (employee_select) employee_select.required = false;
    }
}
</script>
</body>
</html>
