<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance', 'hr');
require_once __DIR__ . '/../includes/layout.php';

/* HR sees a masked, read-only view: pay figures are never queried and the
   create/status actions are refused server-side (not just hidden). */
$isHr = admin_role() === 'hr';

// JSON calculation API for interactive payroll authoring
if (isset($_GET['api']) && $_GET['api'] === 'calc_employee') {
    if ($isHr) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json');
    $empId = (int)($_GET['emp_id'] ?? 0);
    $periodId = (int)($_GET['period_id'] ?? 0);
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';

    if ($empId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
        exit;
    }

    $eStmt = $conn->prepare('SELECT employee_id, basic_salary FROM employees WHERE employee_id=? LIMIT 1');
    $eStmt->bind_param('i', $empId);
    $eStmt->execute();
    $emp = $eStmt->get_result()->fetch_assoc();
    if (!$emp) {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    $basic = (float)$emp['basic_salary'];
    $allowances = 0.0;

    if ($periodId > 0) {
        $pStmt = $conn->prepare('SELECT start_date, end_date, pay_date FROM payroll_periods WHERE period_id=? LIMIT 1');
        $pStmt->bind_param('i', $periodId);
        $pStmt->execute();
        $pRow = $pStmt->get_result()->fetch_assoc();
        if ($pRow) {
            $start = $pRow['start_date'];
            $end = $pRow['end_date'];
        }
    }

    // 1. Loans: sum active loan installments
    $lStmt = $conn->prepare('SELECT COALESCE(SUM(installment), 0) AS loan_sum FROM employee_loans WHERE employee_id=? AND status="Active" AND remaining_balance > 0');
    $lStmt->bind_param('i', $empId);
    $lStmt->execute();
    $loanSum = round((float)$lStmt->get_result()->fetch_assoc()['loan_sum'], 2);

    // 2. Attendance deductions and overtime
    $attDed = 0.0;
    $otPay = 0.0;
    if ($start !== '' && $end !== '') {
        $aStmt = $conn->prepare('SELECT COALESCE(SUM(computed_deduction), 0) AS att_ded, COALESCE(SUM(computed_overtime), 0) AS ot_pay FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?');
        $aStmt->bind_param('iss', $empId, $start, $end);
        $aStmt->execute();
        $aRes = $aStmt->get_result()->fetch_assoc();
        $attDed = round((float)$aRes['att_ded'], 2);
        $otPay = round((float)$aRes['ot_pay'], 2);
    }

    // 3. Other income
    $otherInc = 0.0;
    if ($periodId > 0) {
        $oiStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS oi_sum FROM other_income WHERE employee_id=? AND (period_id=? OR (pay_date BETWEEN ? AND ?))');
        $oiStmt->bind_param('iiss', $empId, $periodId, $start, $end);
        $oiStmt->execute();
        $otherInc = round((float)$oiStmt->get_result()->fetch_assoc()['oi_sum'], 2);
    } elseif ($start !== '' && $end !== '') {
        $oiStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS oi_sum FROM other_income WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
        $oiStmt->bind_param('iss', $empId, $start, $end);
        $oiStmt->execute();
        $otherInc = round((float)$oiStmt->get_result()->fetch_assoc()['oi_sum'], 2);
    }

    // 4. Other deductions
    $otherDed = 0.0;
    if ($periodId > 0) {
        $odStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS od_sum FROM other_deductions WHERE employee_id=? AND (period_id=? OR (pay_date BETWEEN ? AND ?))');
        $odStmt->bind_param('iiss', $empId, $periodId, $start, $end);
        $odStmt->execute();
        $otherDed = round((float)$odStmt->get_result()->fetch_assoc()['od_sum'], 2);
    } elseif ($start !== '' && $end !== '') {
        $odStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS od_sum FROM other_deductions WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
        $odStmt->bind_param('iss', $empId, $start, $end);
        $odStmt->execute();
        $otherDed = round((float)$odStmt->get_result()->fetch_assoc()['od_sum'], 2);
    }

    $totalDed = round($loanSum + $attDed + $otherDed, 2);
    $gross = round($basic + $allowances + $otPay + $otherInc, 2);
    $net = round(max(0, $gross - $totalDed), 2);

    echo json_encode([
        'success' => true,
        'basic_salary' => $basic,
        'allowances' => $allowances,
        'overtime_pay' => $otPay,
        'other_income' => $otherInc,
        'loan_deductions' => $loanSum,
        'attendance_deductions' => $attDed,
        'other_deductions' => $otherDed,
        'total_deductions' => $totalDed,
        'gross_pay' => $gross,
        'net_pay' => $net
    ]);
    exit;
}

$message = '';
$error = '';
$showForm = isset($_GET['add']) && $_GET['add'] === '1';
if ($isHr) {
    $showForm = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('superadmin', 'finance');
    require_csrf();
    $postAction = $_POST['action'] ?? 'create';

    if ($postAction === 'update_status') {
        $payrollId = (int)($_POST['payroll_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['Draft', 'Approved', 'Paid'];
        if ($payrollId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid payroll status update.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE payroll_records SET status=? WHERE payroll_id=?');
                $stmt->bind_param('si', $newStatus, $payrollId);
                $stmt->execute();

                // If moving to Paid, post loan repayments and reduce loan balances if not yet done
                if ($newStatus === 'Paid') {
                    $pStmt = $conn->prepare('SELECT p.payroll_id, p.employee_id, p.loan_deductions, p.period_end, pp.pay_date FROM payroll_records p LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? LIMIT 1');
                    $pStmt->bind_param('i', $payrollId);
                    $pStmt->execute();
                    $pRow = $pStmt->get_result()->fetch_assoc();

                    if ($pRow) {
                        $pId = (int)$pRow['payroll_id'];
                        $empId = (int)$pRow['employee_id'];
                        $pDate = $pRow['pay_date'] ?: $pRow['period_end'];

                        // Check if already posted
                        $chkRepay = $conn->prepare('SELECT repayment_id FROM loan_repayments WHERE payroll_id=? LIMIT 1');
                        $chkRepay->bind_param('i', $pId);
                        $chkRepay->execute();
                        if (!$chkRepay->get_result()->fetch_assoc()) {
                            $lStmt = $conn->prepare('SELECT loan_id, installment, remaining_balance FROM employee_loans WHERE employee_id=? AND status="Active" AND remaining_balance > 0 ORDER BY loan_id ASC');
                            $lStmt->bind_param('i', $empId);
                            $lStmt->execute();
                            $lRes = $lStmt->get_result();

                            while ($l = $lRes->fetch_assoc()) {
                                $loanId = (int)$l['loan_id'];
                                $repayAmt = min((float)$l['installment'], (float)$l['remaining_balance']);
                                if ($repayAmt <= 0) continue;

                                $repayIns = $conn->prepare('INSERT INTO loan_repayments (loan_id, payroll_id, employee_id, amount, repayment_date, notes) VALUES (?, ?, ?, ?, ?, "Automated payroll amortization deduction")');
                                $repayIns->bind_param('iiids', $loanId, $pId, $empId, $repayAmt, $pDate);
                                $repayIns->execute();

                                $newBal = max(0, round((float)$l['remaining_balance'] - $repayAmt, 2));
                                $newLoanStatus = ($newBal <= 0) ? 'Paid' : 'Active';
                                $updLoan = $conn->prepare('UPDATE employee_loans SET remaining_balance=?, status=? WHERE loan_id=?');
                                $updLoan->bind_param('dsi', $newBal, $newLoanStatus, $loanId);
                                $updLoan->execute();
                            }
                        }
                    }
                }

                $conn->commit();
                $ref = !empty($_GET['period_id']) ? '?period_id=' . (int)$_GET['period_id'] . '&status_updated=1' : '?status_updated=1';
                header('Location: payroll.php' . $ref);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Unable to update payroll status: ' . $e->getMessage();
            }
        }
    } else {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $periodId = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : null;
        $start = $_POST['period_start'] ?? '';
        $end = $_POST['period_end'] ?? '';
        $allowances = round((float)($_POST['allowances'] ?? 0), 2);
        $otherEarnings = round((float)($_POST['other_earnings'] ?? 0), 2);
        $overtimePay = round((float)($_POST['overtime_pay'] ?? 0), 2);
        $otherIncomeAmount = round((float)($_POST['other_income_amount'] ?? 0), 2);

        $loanDeductions = round((float)($_POST['loan_deductions'] ?? 0), 2);
        $attDeductions = round((float)($_POST['attendance_deductions'] ?? 0), 2);
        $otherDedAmount = round((float)($_POST['other_deductions_amount'] ?? 0), 2);
        $deductions = round((float)($_POST['deductions'] ?? 0), 2);
        $notes = trim($_POST['notes'] ?? '');

        // Recalculate total deductions if manually entered or itemized
        $computedDed = round($loanDeductions + $attDeductions + $otherDedAmount, 2);
        if ($deductions < $computedDed) {
            $deductions = $computedDed;
        }

        $startTs = $start === '' ? false : strtotime($start);
        $endTs = $end === '' ? false : strtotime($end);

        if ($employeeId <= 0 || $start === '' || $end === '') {
            $error = 'Please choose an employee and enter the payroll period.';
            $showForm = true;
        } elseif ($startTs === false || $endTs === false) {
            $error = 'Invalid payroll period dates.';
            $showForm = true;
        } elseif ($endTs < $startTs) {
            $error = 'The payroll end date cannot be earlier than the start date.';
            $showForm = true;
        } elseif ($allowances < 0 || $otherEarnings < 0 || $overtimePay < 0 || $deductions < 0) {
            $error = 'Payroll amounts cannot be negative.';
            $showForm = true;
        } else {
            $stmt = $conn->prepare('SELECT basic_salary FROM employees WHERE employee_id=? LIMIT 1');
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $employee = $stmt->get_result()->fetch_assoc();

            if (!$employee) {
                $error = 'The selected employee was not found.';
                $showForm = true;
            } else {
                $basic = round((float)$employee['basic_salary'], 2);
                $gross = round($basic + $allowances + $otherEarnings + $overtimePay + $otherIncomeAmount, 2);
                $net = round(max(0, $gross - $deductions), 2);

                if ($deductions > $gross) {
                    $error = 'Deductions cannot exceed gross pay. Net pay cannot be negative.';
                    $showForm = true;
                } else {
                    $stmt = $conn->prepare('SELECT payroll_id FROM payroll_records WHERE employee_id=? AND period_start=? AND period_end=? LIMIT 1');
                    $stmt->bind_param('iss', $employeeId, $start, $end);
                    $stmt->execute();
                    $duplicate = $stmt->get_result()->fetch_assoc();

                    if ($duplicate) {
                        $error = 'A payroll record already exists for this employee and period.';
                        $showForm = true;
                    } else {
                        $ins = $conn->prepare('INSERT INTO payroll_records (employee_id, period_id, period_start, period_end, basic_salary, allowances, other_earnings, overtime_pay, other_income_amount, deductions, loan_deductions, attendance_deductions, other_deductions_amount, gross_pay, net_pay, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Draft", ?)');
                        $ins->bind_param('iissddddddddddds', $employeeId, $periodId, $start, $end, $basic, $allowances, $otherEarnings, $overtimePay, $otherIncomeAmount, $deductions, $loanDeductions, $attDeductions, $otherDedAmount, $gross, $net, $notes);

                        if ($ins->execute()) {
                            header('Location: payroll.php?saved=1');
                            exit;
                        }

                        $error = 'Unable to save the payroll record.';
                        $showForm = true;
                    }
                }
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Payroll record saved successfully.';
}
if (isset($_GET['status_updated'])) {
    $message = 'Payroll status updated successfully.';
}

$periodFilter = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$periodInfo = null;
if ($periodFilter > 0) {
    $pQuery = $conn->prepare('SELECT period_id, period_code, start_date, end_date, pay_date, status FROM payroll_periods WHERE period_id=? LIMIT 1');
    $pQuery->bind_param('i', $periodFilter);
    $pQuery->execute();
    $periodInfo = $pQuery->get_result()->fetch_assoc();
}

// Available periods for filter dropdown and form select
$periodsList = $conn->query("SELECT period_id, period_code, start_date, end_date, pay_date, status FROM payroll_periods ORDER BY start_date DESC LIMIT 30");

if ($isHr) {
    if ($periodFilter > 0) {
        $stmt = $conn->prepare('SELECT p.payroll_id,p.employee_id,p.period_id,p.period_start,p.period_end,p.status,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position,pp.period_code FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.period_id=? ORDER BY p.payroll_id DESC');
        $stmt->bind_param('i', $periodFilter);
        $stmt->execute();
        $records = $stmt->get_result();
    } else {
        $records = $conn->query('SELECT p.payroll_id,p.employee_id,p.period_id,p.period_start,p.period_end,p.status,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position,pp.period_code FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id ORDER BY p.payroll_id DESC');
    }
} else {
    if ($periodFilter > 0) {
        $stmt = $conn->prepare('SELECT p.*,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position,pp.period_code FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.period_id=? ORDER BY p.payroll_id DESC');
        $stmt->bind_param('i', $periodFilter);
        $stmt->execute();
        $records = $stmt->get_result();
    } else {
        $records = $conn->query('SELECT p.*,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position,pp.period_code FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id ORDER BY p.payroll_id DESC');
    }
}

$employees = $isHr ? false : $conn->query('SELECT employee_id,employee_no,first_name,last_name,basic_salary FROM employees ORDER BY last_name,first_name');

/* HR attendance-per-period: one query for the whole list, bucketed in PHP */
$attByRecord = [];
if ($isHr && $records && $records->num_rows > 0) {
    $rows = [];
    $minStart = null; $maxEnd = null; $empIds = [];
    while ($r = $records->fetch_assoc()) {
        $rows[] = $r;
        $empIds[(int)$r['employee_id']] = true;
        if ($minStart === null || $r['period_start'] < $minStart) $minStart = $r['period_start'];
        if ($maxEnd === null || $r['period_end'] > $maxEnd) $maxEnd = $r['period_end'];
    }
    $records->data_seek(0);
    if ($empIds && $minStart !== null) {
        $ids = implode(',', array_map('intval', array_keys($empIds)));
        $stmt = $conn->prepare("SELECT employee_id,attendance_date,status,time_in FROM attendance WHERE employee_id IN ($ids) AND attendance_date >= ? AND attendance_date <= ?");
        if ($stmt) {
            $stmt->bind_param('ss', $minStart, $maxEnd);
            $stmt->execute();
            $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) {
                $logged = 0; $late = 0;
                foreach ($logs as $l) {
                    if ((int)$l['employee_id'] !== (int)$r['employee_id']) continue;
                    if ($l['attendance_date'] < $r['period_start'] || $l['attendance_date'] > $r['period_end']) continue;
                    if ($l['time_in'] !== null || $l['status'] !== 'Absent') $logged++;
                    if ($l['status'] === 'Late') $late++;
                }
                $attByRecord[(int)$r['payroll_id']] = $logged . ' day' . ($logged === 1 ? '' : 's') . ' logged' . ($late ? ' · ' . $late . ' late' : '');
            }
        }
    }
    foreach ($rows as $r) {
        if (!isset($attByRecord[(int)$r['payroll_id']])) $attByRecord[(int)$r['payroll_id']] = 'No logs in period';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Payroll</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
    <div class="app">
        <?php sidebar('payroll'); ?>
        <main class="main">
            <?php topbar('Payroll'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <?php if ($isHr): ?>
                        <h1>Payroll Attendance Review</h1>
                        <p>Attendance logs per pay period.</p>
                        <?php else: ?>
                        <h1>Payroll <span class="count-pill"><?php echo $records ? (int)$records->num_rows : 0; ?></span></h1>
                        <p>Process payroll runs, loan amortizations, and payslips.</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isHr): ?>
                    <div class="print-tools">
                        <a class="btn btn-secondary" href="payroll_periods.php"><?php echo ui_icon('calendar'); ?> Pay Periods</a>
                        <button type="button" class="btn btn-secondary" onclick="window.print()"><?php echo ui_icon('printer'); ?> Print</button>
                        <a class="btn btn-primary" href="payroll.php?add=1"><?php echo ui_icon('plus'); ?> New Payroll</a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

                <?php if ($periodInfo): ?>
                <div class="card" style="margin-bottom: 1rem; border-left: 3px solid var(--accent, #1d4ed8); padding: 0.9rem 1.15rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <h3 style="margin: 0 0 0.15rem; font-size: 1rem;">Period <?php echo e($periodInfo['period_code']); ?> <span class="badge"><?php echo e($periodInfo['status']); ?></span></h3>
                            <p style="margin: 0; color: var(--text-muted, #64748b); font-size: 0.85rem;">
                                <?php echo date('M d, Y', strtotime($periodInfo['start_date'])); ?> – <?php echo date('M d, Y', strtotime($periodInfo['end_date'])); ?> · Pay date <?php echo date('M d, Y', strtotime($periodInfo['pay_date'])); ?>
                            </p>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <a class="btn btn-secondary btn-small" href="payroll.php">All periods</a>
                            <a class="btn btn-secondary btn-small" href="payroll_periods.php">Manage periods</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <section class="card payroll-list-card">
                    <div class="card-head" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <h2><?php echo $isHr ? 'Periods & Attendance' : 'Records'; ?></h2>
                        </div>
                        <form method="get" style="display: flex; align-items: center; gap: 0.5rem;">
                            <label for="period_filter" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted, #64748b);">Period</label>
                            <select id="period_filter" name="period_id" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--border-color, #cbd5e1); font-size: 0.85rem;">
                                <option value="">All periods</option>
                                <?php if ($periodsList): while ($pl = $periodsList->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$pl['period_id']; ?>" <?php echo $periodFilter === (int)$pl['period_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($pl['period_code']); ?> (<?php echo date('M d', strtotime($pl['start_date'])); ?> – <?php echo date('M d, Y', strtotime($pl['end_date'])); ?>)
                                    </option>
                                <?php endwhile; $periodsList->data_seek(0); endif; ?>
                            </select>
                            <?php if ($periodFilter > 0): ?>
                                <a href="payroll.php" class="btn btn-secondary btn-small">Reset</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table class="table payroll-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Period</th>
                                    <?php if ($isHr): ?>
                                    <th>Attendance</th>
                                    <th>Status</th>
                                    <?php else: ?>
                                    <th>Gross</th>
                                    <th>Deductions</th>
                                    <th>Net Pay</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$records || $records->num_rows === 0): ?>
                                    <tr>
                                        <?php if ($isHr): ?>
                                        <td colspan="6" class="empty">No payroll records found.</td>
                                        <?php else: ?>
                                        <td colspan="9" class="empty">No payroll records found. Click <strong>New Payroll</strong> or generate a batch under <strong>Pay Periods</strong>.</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php else: while ($row = $records->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($row['last_name'] . ', ' . $row['first_name']); ?></strong>
                                            <div class="mini"><?php echo e($row['employee_no']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge" style="font-size:0.75rem; background: var(--bg-hover, #f1f5f9); color: var(--text-color, #334155);">
                                                <?php echo e($row['department'] ?: 'Unassigned'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo e($row['position'] ?: 'Unassigned'); ?></td>
                                        <td>
                                            <?php if (!empty($row['period_code'])): ?>
                                                <div style="font-weight: 600; font-size: 0.85rem; color: var(--accent, #1d4ed8);"><?php echo e($row['period_code']); ?></div>
                                            <?php endif; ?>
                                            <div class="mini"><?php echo e(date('M d, Y', strtotime($row['period_start'])) . ' – ' . date('M d, Y', strtotime($row['period_end']))); ?></div>
                                        </td>
                                        <?php if ($isHr): ?>
                                        <td><a class="dash-link" style="margin:0;padding:0;border:0" href="attendance.php"><?php echo e($attByRecord[(int)$row['payroll_id']] ?? 'No logs in period'); ?></a></td>
                                        <td><span class="badge"><?php echo e($row['status']); ?></span></td>
                                        <?php else: ?>
                                        <td><?php echo money($row['gross_pay']); ?></td>
                                        <td>
                                            <?php echo money($row['deductions']); ?>
                                            <?php if (!empty($row['loan_deductions']) && (float)$row['loan_deductions'] > 0): ?>
                                                <div class="mini" title="Includes active loan installments">Loans: <?php echo money($row['loan_deductions']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo money($row['net_pay']); ?></strong></td>
                                        <td>
                                            <form method="post" style="display:inline-flex; align-items: center; gap: 0.35rem;" onsubmit="return confirm('Update payroll record status?')">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="payroll_id" value="<?php echo (int)$row['payroll_id']; ?>">
                                                <select name="status" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; border-radius: var(--radius-sm, 4px); border: 1px solid var(--border-color, #cbd5e1);">
                                                    <option value="Draft" <?php echo $row['status'] === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                                    <option value="Approved" <?php echo $row['status'] === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                    <option value="Paid" <?php echo $row['status'] === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                                </select>
                                                <button type="submit" class="btn btn-secondary btn-small" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Save</button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="icon-actions" style="display: flex; gap: 0.35rem;">
                                                <button type="button" class="icon-action print" title="Print statement" aria-label="Print statement" onclick="printPayrollRecord(<?php echo (int)$row['payroll_id']; ?>); return false;">
                                                    <?php echo ui_icon('printer'); ?>
                                                </button>
                                                <a class="icon-action" title="Download PDF Payslip" target="_blank" rel="noopener" href="../print/payroll.php?id=<?php echo (int)$row['payroll_id']; ?>" style="display: inline-flex; align-items: center; justify-content: center; text-decoration: none;">
                                                    <?php echo ui_icon('file-chart'); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div id="nativePayrollPrintSheet" class="native-print-sheet" aria-hidden="true"></div>

                <?php if ($showForm): ?>
                    <section class="card form-card payroll-form-card">
                        <div class="card-head">
                            <div>
                                <h2>New Payroll Record</h2>
                                <p>Salary, active loan installments, and attendance adjustments calculate automatically.</p>
                            </div>
                            <a class="btn btn-secondary" href="payroll.php">Cancel</a>
                        </div>
                        <form method="post" id="payrollForm">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create">
                            <div class="form-body">
                                <div class="section-title">Employee & Period</div>
                                <div class="form-grid">
                                    <div class="field full">
                                        <label>Employee <span class="required">*</span></label>
                                        <select name="employee_id" id="employee_id" required>
                                            <option value="">Select employee</option>
                                            <?php while ($employee = $employees->fetch_assoc()): ?>
                                                <option value="<?php echo (int)$employee['employee_id']; ?>" data-salary="<?php echo e($employee['basic_salary']); ?>" data-allowance="<?php echo e($employee['allowances'] ?? '0.00'); ?>">
                                                    <?php echo e($employee['employee_no'] . ' — ' . $employee['last_name'] . ', ' . $employee['first_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="field full">
                                        <label>Pay period</label>
                                        <select name="period_id" id="period_id">
                                            <option value="">Custom date range</option>
                                            <?php if ($periodsList): while ($pl = $periodsList->fetch_assoc()): ?>
                                                <option value="<?php echo (int)$pl['period_id']; ?>" data-start="<?php echo e($pl['start_date']); ?>" data-end="<?php echo e($pl['end_date']); ?>">
                                                    <?php echo e($pl['period_code']); ?> (<?php echo date('M d, Y', strtotime($pl['start_date'])); ?> – <?php echo date('M d, Y', strtotime($pl['end_date'])); ?>)
                                                </option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                    </div>
                                    <div class="field"><label>Period start <span class="required">*</span></label><input type="date" name="period_start" id="period_start" required></div>
                                    <div class="field"><label>Period end <span class="required">*</span></label><input type="date" name="period_end" id="period_end" required></div>
                                </div>

                                <div class="section-title">Earnings</div>
                                <div class="form-grid">
                                    <div class="field"><label>Basic salary</label><input id="basic_salary" name="basic_salary" value="0.00" readonly style="background: var(--bg-hover, #f1f5f9);"></div>
                                    <div class="field"><label>Allowances</label><input type="number" min="0" step="0.01" name="allowances" id="allowances" value="0.00"></div>
                                    <div class="field"><label>Overtime pay</label><input type="number" min="0" step="0.01" name="overtime_pay" id="overtime_pay" value="0.00"></div>
                                    <div class="field"><label>Other income</label><input type="number" min="0" step="0.01" name="other_income_amount" id="other_income_amount" value="0.00"></div>
                                    <div class="field"><label>Bonus / incentives</label><input type="number" min="0" step="0.01" name="other_earnings" id="other_earnings" value="0.00"></div>
                                </div>

                                <div class="section-title">Deductions</div>
                                <div class="form-grid">
                                    <div class="field"><label>Loan amortizations</label><input type="number" min="0" step="0.01" name="loan_deductions" id="loan_deductions" value="0.00" readonly style="background: var(--bg-hover, #f1f5f9);"></div>
                                    <div class="field"><label>Attendance deductions</label><input type="number" min="0" step="0.01" name="attendance_deductions" id="attendance_deductions" value="0.00" readonly style="background: var(--bg-hover, #f1f5f9);"></div>
                                    <div class="field"><label>Other deductions</label><input type="number" min="0" step="0.01" name="other_deductions_amount" id="other_deductions_amount" value="0.00"></div>
                                    <div class="field"><label>Total deductions</label><input type="number" min="0" step="0.01" name="deductions" id="deductions" value="0.00"></div>
                                    <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Optional notes for this payslip"></textarea></div>
                                </div>

                                <div class="pay-preview">
                                    <div><span>Gross Pay</span><strong id="gross">₱ 0.00</strong></div>
                                    <div><span>Total Deductions</span><strong id="total_ded_preview" style="color: var(--danger, #e11d48);">₱ 0.00</strong></div>
                                    <div><span>Net Pay</span><strong id="net" style="color: var(--accent, #1d4ed8);">₱ 0.00</strong></div>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">Save payroll record</button>
                                <a class="btn btn-secondary" href="payroll.php">Cancel</a>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php if ($showForm): ?>
        <script>
            async function fetchCalculations() {
                const empSelect = document.getElementById('employee_id');
                const periodSelect = document.getElementById('period_id');
                const startInput = document.getElementById('period_start');
                const endInput = document.getElementById('period_end');

                const empId = empSelect.value;
                const periodId = periodSelect ? periodSelect.value : '';
                const start = startInput.value;
                const end = endInput.value;

                if (!empId) {
                    updateTotals();
                    return;
                }

                try {
                    const params = new URLSearchParams({
                        api: 'calc_employee',
                        emp_id: empId,
                        period_id: periodId,
                        start: start,
                        end: end
                    });
                    const res = await fetch('payroll.php?' + params.toString());
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('basic_salary').value = parseFloat(data.basic_salary).toFixed(2);
                        document.getElementById('allowances').value = parseFloat(data.allowances).toFixed(2);
                        document.getElementById('overtime_pay').value = parseFloat(data.overtime_pay).toFixed(2);
                        document.getElementById('other_income_amount').value = parseFloat(data.other_income).toFixed(2);
                        document.getElementById('loan_deductions').value = parseFloat(data.loan_deductions).toFixed(2);
                        document.getElementById('attendance_deductions').value = parseFloat(data.attendance_deductions).toFixed(2);
                        document.getElementById('other_deductions_amount').value = parseFloat(data.other_deductions).toFixed(2);
                        document.getElementById('deductions').value = parseFloat(data.total_deductions).toFixed(2);
                        updateTotals();
                    }
                } catch (err) {
                    console.error('Auto calc failed:', err);
                    updateTotals();
                }
            }

            function updateTotals() {
                const basic = parseFloat(document.getElementById('basic_salary').value || 0);
                const allowances = parseFloat(document.getElementById('allowances').value || 0);
                const ot = parseFloat(document.getElementById('overtime_pay').value || 0);
                const otherInc = parseFloat(document.getElementById('other_income_amount').value || 0);
                const otherEarn = parseFloat(document.getElementById('other_earnings').value || 0);

                const loanDed = parseFloat(document.getElementById('loan_deductions').value || 0);
                const attDed = parseFloat(document.getElementById('attendance_deductions').value || 0);
                const otherDed = parseFloat(document.getElementById('other_deductions_amount').value || 0);
                let totalDed = parseFloat(document.getElementById('deductions').value || 0);

                const computedDed = loanDed + attDed + otherDed;
                if (totalDed < computedDed) {
                    totalDed = computedDed;
                    document.getElementById('deductions').value = totalDed.toFixed(2);
                }

                const gross = basic + allowances + ot + otherInc + otherEarn;
                const net = Math.max(0, gross - totalDed);

                document.getElementById('gross').textContent = '₱ ' + gross.toLocaleString('en-PH', { minimumFractionDigits: 2 });
                document.getElementById('total_ded_preview').textContent = '₱ ' + totalDed.toLocaleString('en-PH', { minimumFractionDigits: 2 });
                document.getElementById('net').textContent = '₱ ' + net.toLocaleString('en-PH', { minimumFractionDigits: 2 });
            }

            const periodSelect = document.getElementById('period_id');
            if (periodSelect) {
                periodSelect.addEventListener('change', function() {
                    const opt = this.options[this.selectedIndex];
                    if (opt && opt.dataset.start && opt.dataset.end) {
                        document.getElementById('period_start').value = opt.dataset.start;
                        document.getElementById('period_end').value = opt.dataset.end;
                    }
                    fetchCalculations();
                });
            }

            document.getElementById('employee_id').addEventListener('change', fetchCalculations);
            document.getElementById('period_start').addEventListener('change', fetchCalculations);
            document.getElementById('period_end').addEventListener('change', fetchCalculations);

            document.getElementById('allowances').addEventListener('input', updateTotals);
            document.getElementById('overtime_pay').addEventListener('input', updateTotals);
            document.getElementById('other_income_amount').addEventListener('input', updateTotals);
            document.getElementById('other_earnings').addEventListener('input', updateTotals);
            document.getElementById('other_deductions_amount').addEventListener('input', updateTotals);
            document.getElementById('deductions').addEventListener('input', updateTotals);
        </script>
    <?php endif; ?>

    <script>
    async function printPayrollRecord(id){
        const sheet = document.getElementById('nativePayrollPrintSheet');
        if(!sheet) return;

        sheet.innerHTML = '<div class="print-loading">Preparing Lipa City Colleges payroll statement…</div>';
        sheet.classList.add('active');

        const cleanup = () => {
            sheet.classList.remove('active');
            sheet.innerHTML = '';
        };

        window.addEventListener('afterprint', cleanup, {once:true});

        try{
            const response = await fetch('payroll_preview.php?id=' + encodeURIComponent(id), {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            if(!response.ok) throw new Error('Request failed');
            sheet.innerHTML = await response.text();
            window.print();
        }catch(error){
            cleanup();
            alert('Unable to prepare the payroll statement.');
        }
    }
    </script>
</body>

</html>
