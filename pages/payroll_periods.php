<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';
$editPeriod = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_period') {
        $id = (int)($_POST['period_id'] ?? 0);
        $code = strtoupper(trim($_POST['period_code'] ?? ''));
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $payDate = trim($_POST['pay_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($code === '' || $start === '' || $end === '' || $payDate === '') {
            $error = 'Period code, start date, end date, and pay date are required.';
        } elseif ($end < $start) {
            $error = 'End date cannot be earlier than start date.';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE payroll_periods SET period_code=?, start_date=?, end_date=?, pay_date=?, notes=? WHERE period_id=?');
                $stmt->bind_param('sssssi', $code, $start, $end, $payDate, $notes, $id);
                if ($stmt->execute()) {
                    header('Location: payroll_periods.php?saved=1');
                    exit;
                }
                $error = 'Unable to update payroll period.';
            } else {
                $stmt = $conn->prepare('INSERT INTO payroll_periods (period_code, start_date, end_date, pay_date, status, notes) VALUES (?, ?, ?, ?, "Draft", ?)');
                $stmt->bind_param('sssss', $code, $start, $end, $payDate, $notes);
                if ($stmt->execute()) {
                    header('Location: payroll_periods.php?saved=1');
                    exit;
                }
                $error = 'Unable to create payroll period (code may already exist).';
            }
        }
    } elseif ($action === 'generate_batch') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        if ($periodId <= 0) {
            $error = 'Invalid period selected.';
        } else {
            $stmt = $conn->prepare('SELECT * FROM payroll_periods WHERE period_id=? LIMIT 1');
            $stmt->bind_param('i', $periodId);
            $stmt->execute();
            $period = $stmt->get_result()->fetch_assoc();

            if (!$period) {
                $error = 'Payroll period not found.';
            } else {
                // Fetch all active employees
                $empQuery = "SELECT * FROM employees WHERE employment_status IN ('Regular', 'Probationary', 'Contractual', 'Part-Time')";
                $emps = $conn->query($empQuery);

                $generated = 0;
                $skipped = 0;

                while ($emp = $emps->fetch_assoc()) {
                    $empId = (int)$emp['employee_id'];

                    // Check if payroll record already exists for this period and employee
                    $chk = $conn->prepare('SELECT payroll_id FROM payroll_records WHERE employee_id=? AND (period_id=? OR (period_start=? AND period_end=?)) LIMIT 1');
                    $chk->bind_param('iiss', $empId, $periodId, $period['start_date'], $period['end_date']);
                    $chk->execute();
                    if ($chk->get_result()->fetch_assoc()) {
                        $skipped++;
                        continue;
                    }

                    $basic = round((float)$emp['basic_salary'], 2);
                    $allowances = round((float)($emp['allowances'] ?? 0), 2);
                    $otherEarnings = 0.00;

                    // 1. Calculate active loan installment for this employee
                    $loanInstallments = 0.00;
                    $loanStmt = $conn->prepare('SELECT loan_id, installment, remaining_balance FROM employee_loans WHERE employee_id=? AND status="Active" AND remaining_balance > 0 AND repayment_start <= ?');
                    $loanStmt->bind_param('is', $empId, $period['pay_date']);
                    $loanStmt->execute();
                    $loanRes = $loanStmt->get_result();
                    while ($lRow = $loanRes->fetch_assoc()) {
                        $inst = min((float)$lRow['installment'], (float)$lRow['remaining_balance']);
                        $loanInstallments += $inst;
                    }
                    $loanInstallments = round($loanInstallments, 2);

                    // 2. Calculate itemized other income for this period
                    $otherIncStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total_inc FROM other_income WHERE employee_id=? AND (period_id=? OR pay_date=?)');
                    $otherIncStmt->bind_param('iis', $empId, $periodId, $period['pay_date']);
                    $otherIncStmt->execute();
                    $otherIncAmount = round((float)$otherIncStmt->get_result()->fetch_assoc()['total_inc'], 2);

                    // 3. Calculate itemized other deductions for this period
                    $otherDedStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total_ded FROM other_deductions WHERE employee_id=? AND (period_id=? OR pay_date=?)');
                    $otherDedStmt->bind_param('iis', $empId, $periodId, $period['pay_date']);
                    $otherDedStmt->execute();
                    $otherDedAmount = round((float)$otherDedStmt->get_result()->fetch_assoc()['total_ded'], 2);

                    // 4. Calculate attendance deductions and overtime from attendance records
                    $attStmt = $conn->prepare('SELECT COALESCE(SUM(computed_deduction), 0) AS total_att_ded, COALESCE(SUM(computed_overtime), 0) AS total_ot FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?');
                    $attStmt->bind_param('iss', $empId, $period['start_date'], $period['end_date']);
                    $attStmt->execute();
                    $attRes = $attStmt->get_result()->fetch_assoc();
                    $attDeduction = round((float)$attRes['total_att_ded'], 2);
                    $otPay = round((float)$attRes['total_ot'], 2);

                    $gross = round($basic + $allowances + $otherEarnings + $otPay + $otherIncAmount, 2);
                    $totalDeductions = round($loanInstallments + $attDeduction + $otherDedAmount, 2);
                    $net = round(max(0, $gross - $totalDeductions), 2);
                    $notes = "Batch generated for Period {$period['period_code']}";

                    $ins = $conn->prepare('INSERT INTO payroll_records (employee_id, period_id, period_start, period_end, basic_salary, allowances, other_earnings, overtime_pay, other_income_amount, deductions, loan_deductions, attendance_deductions, other_deductions_amount, gross_pay, net_pay, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Draft", ?)');
                    $ins->bind_param('iissddddddddddds', $empId, $periodId, $period['start_date'], $period['end_date'], $basic, $allowances, $otherEarnings, $otPay, $otherIncAmount, $totalDeductions, $loanInstallments, $attDeduction, $otherDedAmount, $gross, $net, $notes);
                    if ($ins->execute()) {
                        $generated++;
                    }
                }

                header("Location: payroll_periods.php?generated=$generated&skipped=$skipped");
                exit;
            }
        }
    } elseif ($action === 'update_period_status') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if ($periodId > 0 && in_array($newStatus, ['Draft', 'Approved', 'Paid'], true)) {
            $conn->begin_transaction();
            try {
                // Update period status
                $stmt = $conn->prepare('UPDATE payroll_periods SET status=? WHERE period_id=?');
                $stmt->bind_param('si', $newStatus, $periodId);
                $stmt->execute();

                // Also cascade to draft/approved payroll records under this period
                $stmt2 = $conn->prepare('UPDATE payroll_records SET status=? WHERE period_id=?');
                $stmt2->bind_param('si', $newStatus, $periodId);
                $stmt2->execute();

                // If moving to 'Paid', post loan repayments for active loans and deduct remaining balance!
                if ($newStatus === 'Paid') {
                    // Fetch all paid records in this period with loan deductions
                    $payRecords = $conn->prepare('SELECT p.payroll_id, p.employee_id, p.loan_deductions, pp.pay_date FROM payroll_records p JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.period_id=? AND p.loan_deductions > 0');
                    $payRecords->bind_param('i', $periodId);
                    $payRecords->execute();
                    $pRows = $payRecords->get_result();

                    while ($pRow = $pRows->fetch_assoc()) {
                        $pId = (int)$pRow['payroll_id'];
                        $empId = (int)$pRow['employee_id'];
                        $pDate = $pRow['pay_date'];

                        // Check if repayment already logged for this payroll
                        $chkRepay = $conn->prepare('SELECT repayment_id FROM loan_repayments WHERE payroll_id=? LIMIT 1');
                        $chkRepay->bind_param('i', $pId);
                        $chkRepay->execute();
                        if ($chkRepay->get_result()->fetch_assoc()) {
                            continue; // Already posted
                        }

                        // Get active loans for this employee
                        $lStmt = $conn->prepare('SELECT loan_id, installment, remaining_balance FROM employee_loans WHERE employee_id=? AND status="Active" AND remaining_balance > 0 ORDER BY loan_id ASC');
                        $lStmt->bind_param('i', $empId);
                        $lStmt->execute();
                        $lRes = $lStmt->get_result();

                        while ($l = $lRes->fetch_assoc()) {
                            $loanId = (int)$l['loan_id'];
                            $repayAmt = min((float)$l['installment'], (float)$l['remaining_balance']);
                            if ($repayAmt <= 0) continue;

                            // Insert repayment
                            $repayIns = $conn->prepare('INSERT INTO loan_repayments (loan_id, payroll_id, employee_id, amount, repayment_date, notes) VALUES (?, ?, ?, ?, ?, "Automated payroll amortization deduction")');
                            $repayIns->bind_param('iiids', $loanId, $pId, $empId, $repayAmt, $pDate);
                            $repayIns->execute();

                            // Update loan balance
                            $newBal = max(0, round((float)$l['remaining_balance'] - $repayAmt, 2));
                            $loanStatus = $newBal <= 0 ? 'Paid' : 'Active';
                            $updLoan = $conn->prepare('UPDATE employee_loans SET remaining_balance=?, status=? WHERE loan_id=?');
                            $updLoan->bind_param('dsi', $newBal, $loanStatus, $loanId);
                            $updLoan->execute();
                        }
                    }
                }

                $conn->commit();
                header('Location: payroll_periods.php?status_updated=1');
                exit;
            } catch (Exception $ex) {
                $conn->rollback();
                $error = 'Failed to update period status: ' . $ex->getMessage();
            }
        }
    }
}

if (isset($_GET['saved'])) $message = 'Payroll period saved.';
if (isset($_GET['status_updated'])) $message = 'Payroll period and child payslips updated.';
if (isset($_GET['generated'])) {
    $gen = (int)$_GET['generated'];
    $skip = (int)$_GET['skipped'];
    $message = "Batch processing complete: $gen draft payroll record(s) created ($skip already existed).";
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM payroll_periods WHERE period_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editPeriod = $stmt->get_result()->fetch_assoc();
}

// Fetch all periods with statistics
$sql = "SELECT pp.*,
        COUNT(pr.payroll_id) AS total_records,
        COALESCE(SUM(pr.gross_pay), 0) AS total_gross,
        COALESCE(SUM(pr.deductions), 0) AS total_deductions,
        COALESCE(SUM(pr.net_pay), 0) AS total_net
        FROM payroll_periods pp
        LEFT JOIN payroll_records pr ON pr.period_id = pp.period_id
        GROUP BY pp.period_id
        ORDER BY pp.start_date DESC";
$res = $conn->query($sql);
$periods = [];
if ($res) while ($r = $res->fetch_assoc()) $periods[] = $r;

$showAdd = isset($_GET['new']) && $_GET['new'] === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Pay Periods</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('payroll_periods'); ?>
    <main class="main">
        <?php topbar('Pay Periods'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Pay Periods <span class="count-pill"><?php echo count($periods); ?></span></h1>
                    <p>Cut-off schedules, batch generation, and disbursement status.</p>
                </div>
                <div class="heading-actions">
                    <a href="payroll.php" class="btn btn-secondary"><?php echo ui_icon('wallet'); ?> All Payroll</a>
                    <a href="payroll_periods.php?new=1" class="btn btn-primary"><?php echo ui_icon('plus'); ?> New Period</a>
                </div>
            </div>

            <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

            <?php if ($showAdd || $editPeriod): ?>
            <section class="card" style="margin-bottom: 20px;">
                <div class="card-head">
                    <div>
                        <h2><?php echo $editPeriod ? 'Edit Pay Period' : 'New Pay Period'; ?></h2>
                    </div>
                    <a href="payroll_periods.php" class="btn btn-small btn-secondary"><?php echo ui_icon('close'); ?> Cancel</a>
                </div>
                <form method="post" style="padding: 18px 20px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_period">
                    <input type="hidden" name="period_id" value="<?php echo (int)($editPeriod['period_id'] ?? 0); ?>">

                    <div class="form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                        <div class="field">
                            <label>Period code <span class="required">*</span></label>
                            <input type="text" name="period_code" value="<?php echo e($editPeriod['period_code'] ?? date('Y-m') . '-P1'); ?>" placeholder="e.g. 2026-10-P2" required>
                        </div>

                        <div class="field">
                            <label>Start date <span class="required">*</span></label>
                            <input type="date" name="start_date" value="<?php echo e($editPeriod['start_date'] ?? date('Y-m-01')); ?>" required>
                        </div>

                        <div class="field">
                            <label>End date <span class="required">*</span></label>
                            <input type="date" name="end_date" value="<?php echo e($editPeriod['end_date'] ?? date('Y-m-15')); ?>" required>
                        </div>

                        <div class="field">
                            <label>Pay date <span class="required">*</span></label>
                            <input type="date" name="pay_date" value="<?php echo e($editPeriod['pay_date'] ?? date('Y-m-20')); ?>" required>
                        </div>

                        <div class="field" style="grid-column: 1 / -1;">
                            <label>Notes</label>
                            <input type="text" name="notes" value="<?php echo e($editPeriod['notes'] ?? ''); ?>" placeholder="e.g. Mid-month payroll">
                        </div>
                    </div>

                    <div style="margin-top: 18px; display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary"><?php echo $editPeriod ? 'Update period' : 'Save period'; ?></button>
                        <a href="payroll_periods.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <section class="card">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Coverage</th>
                                <th>Pay Date</th>
                                <th style="text-align:center;">Payslips</th>
                                <th style="text-align:right;">Gross</th>
                                <th style="text-align:right;">Net Pay</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($periods)): ?>
                            <tr><td colspan="8" class="empty">No payroll periods configured yet.</td></tr>
                        <?php else: foreach ($periods as $p): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($p['period_code']); ?></strong>
                                    <?php if ($p['notes']): ?>
                                        <div style="font-size:12px; color:var(--text-muted);"><?php echo e($p['notes']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($p['start_date'])); ?> – <?php echo date('M d, Y', strtotime($p['end_date'])); ?>
                                </td>
                                <td><strong><?php echo date('M d, Y', strtotime($p['pay_date'])); ?></strong></td>
                                <td style="text-align:center;">
                                    <span class="badge" style="background:var(--card-sub); font-size:13px;">
                                        <?php echo (int)$p['total_records']; ?> employee(s)
                                    </span>
                                </td>
                                <td style="text-align:right; font-weight:600;"><?php echo money($p['total_gross']); ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--brand-teal);"><?php echo money($p['total_net']); ?></td>
                                <td>
                                    <?php
                                    $bClass = 'badge-warn';
                                    if ($p['status'] === 'Approved') $bClass = 'badge-info';
                                    elseif ($p['status'] === 'Paid') $bClass = 'badge-ok';
                                    ?>
                                    <span class="badge <?php echo $bClass; ?>"><?php echo e($p['status']); ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px; align-items:center;">
                                        <!-- View Payroll -->
                                        <a href="payroll.php?period_id=<?php echo (int)$p['period_id']; ?>" class="btn btn-small btn-secondary" title="View Payslips">
                                            View Pay
                                        </a>

                                        <!-- Generate Batch if Draft -->
                                        <?php if ($p['status'] === 'Draft'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Generate draft payslips for all active employees for period <?php echo e($p['period_code']); ?>?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="generate_batch">
                                            <input type="hidden" name="period_id" value="<?php echo (int)$p['period_id']; ?>">
                                            <button type="submit" class="btn btn-small btn-primary" title="Generate Batch">
                                                ⚡ Batch Generate
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Status Progression Button -->
                                        <?php if ($p['status'] === 'Draft' && (int)$p['total_records'] > 0): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Approve all payroll records in this period?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_period_status">
                                            <input type="hidden" name="period_id" value="<?php echo (int)$p['period_id']; ?>">
                                            <input type="hidden" name="status" value="Approved">
                                            <button type="submit" class="btn btn-small btn-secondary" style="color:var(--brand-teal);">
                                                Approve All
                                            </button>
                                        </form>
                                        <?php elseif ($p['status'] === 'Approved'): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Release & Mark Paid? This will post loan repayments and update loan balances.');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_period_status">
                                            <input type="hidden" name="period_id" value="<?php echo (int)$p['period_id']; ?>">
                                            <input type="hidden" name="status" value="Paid">
                                            <button type="submit" class="btn btn-small btn-primary" style="background:var(--ok-text);">
                                                ✓ Mark Paid
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <a href="payroll_periods.php?edit=<?php echo (int)$p['period_id']; ?>" class="btn btn-small btn-secondary" title="Edit Dates">
                                            <?php echo ui_icon('pencil'); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
