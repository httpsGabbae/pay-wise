<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';
$editLoan = null;

function recalculate_loan_balance_and_status(mysqli $conn, int $loanId): void
{
    $stmt = $conn->prepare('SELECT principal FROM employee_loans WHERE loan_id = ? LIMIT 1');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $loanRow = $stmt->get_result()->fetch_assoc();
    if (!$loanRow) return;

    $principal = (float)$loanRow['principal'];

    $sumStmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total_repaid FROM loan_repayments WHERE loan_id = ?');
    $sumStmt->bind_param('i', $loanId);
    $sumStmt->execute();
    $totalRepaid = (float)$sumStmt->get_result()->fetch_assoc()['total_repaid'];

    $remainingBalance = max(0, round($principal - $totalRepaid, 2));
    $newStatus = ($remainingBalance <= 0) ? 'Paid' : 'Active';

    $updStmt = $conn->prepare('UPDATE employee_loans SET remaining_balance = ?, status = ? WHERE loan_id = ?');
    $updStmt->bind_param('dsi', $remainingBalance, $newStatus, $loanId);
    $updStmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $loanTypeId = (int)($_POST['loan_type_id'] ?? 0);
        $loanDate = trim($_POST['loan_date'] ?? date('Y-m-d'));
        $principal = round((float)($_POST['principal'] ?? 0), 2);
        $installment = round((float)($_POST['installment'] ?? 0), 2);
        $termPeriods = (int)($_POST['term_periods'] ?? 1);
        $repaymentStart = trim($_POST['repayment_start'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');

        if ($employeeId <= 0 || $loanTypeId <= 0) {
            $error = 'Please select both an employee and a loan type.';
        } elseif ($principal <= 0 || $installment <= 0) {
            $error = 'Principal and deduction per payroll must be greater than zero.';
        } elseif ($installment > $principal) {
            $error = 'Installment cannot exceed total principal amount.';
        } elseif ($termPeriods < 1) {
            $error = 'Number of payroll periods must be at least 1.';
        } else {
            if ($loanId > 0) {
                $stmt = $conn->prepare('UPDATE employee_loans SET employee_id=?, loan_type_id=?, loan_date=?, principal=?, installment=?, term_periods=?, repayment_start=?, remarks=? WHERE loan_id=? AND status="Active"');
                $stmt->bind_param('iisddissi', $employeeId, $loanTypeId, $loanDate, $principal, $installment, $termPeriods, $repaymentStart, $remarks, $loanId);
                if ($stmt->execute()) {
                    recalculate_loan_balance_and_status($conn, $loanId);
                    header('Location: loans.php?saved=1');
                    exit;
                }
                $error = 'Failed to update loan details.';
            } else {
                $remainingBalance = $principal;
                $status = 'Active';
                $stmt = $conn->prepare('INSERT INTO employee_loans (employee_id, loan_type_id, loan_date, principal, installment, term_periods, repayment_start, remaining_balance, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iisddisdss', $employeeId, $loanTypeId, $loanDate, $principal, $installment, $termPeriods, $repaymentStart, $remainingBalance, $status, $remarks);
                if ($stmt->execute()) {
                    header('Location: loans.php?saved=1');
                    exit;
                }
                $error = 'Failed to record employee loan: ' . $conn->error;
            }
        }
    } elseif ($action === 'cancel_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        if ($loanId > 0) {
            $stmt = $conn->prepare('UPDATE employee_loans SET status="Cancelled" WHERE loan_id=?');
            $stmt->bind_param('i', $loanId);
            if ($stmt->execute()) {
                header('Location: loans.php?cancelled=1');
                exit;
            }
            $error = 'Unable to cancel loan.';
        }
    } elseif ($action === 'record_repayment') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $repaymentDate = trim($_POST['repayment_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? 'Direct payment');

        if ($loanId <= 0 || $amount <= 0) {
            $error = 'Please enter a valid loan and repayment amount greater than zero.';
        } else {
            $lStmt = $conn->prepare('SELECT employee_id FROM employee_loans WHERE loan_id=? LIMIT 1');
            $lStmt->bind_param('i', $loanId);
            $lStmt->execute();
            $lRow = $lStmt->get_result()->fetch_assoc();
            if ($lRow) {
                $empId = (int)$lRow['employee_id'];
                $ins = $conn->prepare('INSERT INTO loan_repayments (loan_id, payroll_id, employee_id, amount, repayment_date, notes) VALUES (?, NULL, ?, ?, ?, ?)');
                $ins->bind_param('iidss', $loanId, $empId, $amount, $repaymentDate, $notes);
                if ($ins->execute()) {
                    recalculate_loan_balance_and_status($conn, $loanId);
                    header('Location: loans.php?view_repayments=' . $loanId . '&repaid=1');
                    exit;
                }
                $error = 'Failed to record repayment.';
            } else {
                $error = 'Loan record not found.';
            }
        }
    } elseif ($action === 'update_repayment') {
        $repaymentId = (int)($_POST['repayment_id'] ?? 0);
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $repaymentDate = trim($_POST['repayment_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($repaymentId <= 0 || $loanId <= 0 || $amount <= 0) {
            $error = 'Please enter a valid repayment amount greater than zero.';
        } else {
            $upd = $conn->prepare('UPDATE loan_repayments SET amount=?, repayment_date=?, notes=? WHERE repayment_id=?');
            $upd->bind_param('dssi', $amount, $repaymentDate, $notes, $repaymentId);
            if ($upd->execute()) {
                recalculate_loan_balance_and_status($conn, $loanId);
                header('Location: loans.php?view_repayments=' . $loanId . '&repaid_updated=1');
                exit;
            }
            $error = 'Failed to update repayment.';
        }
    } elseif ($action === 'delete_repayment') {
        $repaymentId = (int)($_POST['repayment_id'] ?? 0);
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($repaymentId > 0 && $loanId > 0) {
            $del = $conn->prepare('DELETE FROM loan_repayments WHERE repayment_id=?');
            $del->bind_param('i', $repaymentId);
            if ($del->execute()) {
                recalculate_loan_balance_and_status($conn, $loanId);
                header('Location: loans.php?view_repayments=' . $loanId . '&repaid_deleted=1');
                exit;
            }
            $error = 'Failed to delete repayment.';
        }
    }
}

if (isset($_GET['saved'])) $message = 'Loan record saved successfully.';
if (isset($_GET['cancelled'])) $message = 'Loan marked as cancelled.';
if (isset($_GET['repaid'])) $message = 'Repayment successfully recorded and balance updated.';
if (isset($_GET['repaid_updated'])) $message = 'Repayment record updated and balance recalculated.';
if (isset($_GET['repaid_deleted'])) $message = 'Repayment record removed and balance recalculated.';

$filterStatus = $_GET['status'] ?? 'Active';
$filterEmployee = (int)($_GET['employee_id'] ?? 0);
$viewLoanId = (int)($_GET['view_repayments'] ?? 0);
$editRepaymentId = (int)($_GET['edit_repayment'] ?? 0);

// Load Loan Types for form
$loanTypes = [];
$ltRes = $conn->query('SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_type_name ASC');
if ($ltRes) while ($lt = $ltRes->fetch_assoc()) $loanTypes[] = $lt;

// Load Active Employees for form
$employees = [];
$empRes = $conn->query('SELECT employee_id, employee_no, first_name, last_name, department, basic_salary FROM employees ORDER BY last_name ASC, first_name ASC');
if ($empRes) while ($emp = $empRes->fetch_assoc()) $employees[] = $emp;

// Query loans with details and pagination
$where = [];
$params = [];
$types = '';

if ($filterStatus !== 'All') {
    $where[] = 'el.status = ?';
    $params[] = $filterStatus;
    $types .= 's';
}

if ($filterEmployee > 0) {
    $where[] = 'el.employee_id = ?';
    $params[] = $filterEmployee;
    $types .= 'i';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 1. Pagination Calculation (Max 10 records per page)
$countSql = "SELECT COUNT(*) AS total_count FROM employee_loans el $whereSql";
if ($types !== '') {
    $cStmt = $conn->prepare($countSql);
    $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $totalLoansCount = (int)$cStmt->get_result()->fetch_assoc()['total_count'];
} else {
    $totalLoansCount = (int)$conn->query($countSql)->fetch_assoc()['total_count'];
}

$recordsPerPage = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalLoansCount / $recordsPerPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $recordsPerPage;

$sql = "SELECT el.*, e.employee_no, e.first_name, e.last_name, e.department, lt.loan_type_name,
        (SELECT COALESCE(SUM(lr.amount), 0) FROM loan_repayments lr WHERE lr.loan_id = el.loan_id) AS total_repaid,
        (SELECT COUNT(*) FROM loan_repayments lr WHERE lr.loan_id = el.loan_id) AS repayments_count
        FROM employee_loans el
        JOIN employees e ON e.employee_id = el.employee_id
        JOIN loan_types lt ON lt.loan_type_id = el.loan_type_id
        $whereSql
        ORDER BY el.loan_id DESC
        LIMIT $recordsPerPage OFFSET $offset";

if ($types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $loansResult = $stmt->get_result();
} else {
    $loansResult = $conn->query($sql);
}

$loans = [];
if ($loansResult) while ($l = $loansResult->fetch_assoc()) $loans[] = $l;

// Summary stats across active loans
$activeCount = (int)$conn->query("SELECT COUNT(*) AS c FROM employee_loans WHERE status='Active'")->fetch_assoc()['c'];
$totalPrincipal = (float)$conn->query("SELECT COALESCE(SUM(principal),0) AS p FROM employee_loans WHERE status='Active'")->fetch_assoc()['p'];
$totalBalance = (float)$conn->query("SELECT COALESCE(SUM(remaining_balance),0) AS b FROM employee_loans WHERE status='Active'")->fetch_assoc()['b'];

// If a loan's repayments are being viewed
$selectedLoan = null;
$repaymentsList = [];
$editingRepayment = null;

if ($viewLoanId > 0) {
    $selStmt = $conn->prepare('SELECT el.*, e.employee_no, e.first_name, e.last_name, e.department, lt.loan_type_name FROM employee_loans el JOIN employees e ON e.employee_id=el.employee_id JOIN loan_types lt ON lt.loan_type_id=el.loan_type_id WHERE el.loan_id=? LIMIT 1');
    $selStmt->bind_param('i', $viewLoanId);
    $selStmt->execute();
    $selectedLoan = $selStmt->get_result()->fetch_assoc();

    if ($selectedLoan) {
        $repStmt = $conn->prepare('SELECT lr.*, pp.period_code, p.period_start, p.period_end FROM loan_repayments lr LEFT JOIN payroll_records p ON p.payroll_id=lr.payroll_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE lr.loan_id=? ORDER BY lr.repayment_date DESC, lr.repayment_id DESC');
        $repStmt->bind_param('i', $viewLoanId);
        $repStmt->execute();
        $repRes = $repStmt->get_result();
        while ($r = $repRes->fetch_assoc()) {
            $repaymentsList[] = $r;
            if ($editRepaymentId > 0 && (int)$r['repayment_id'] === $editRepaymentId) {
                $editingRepayment = $r;
            }
        }
    }
}

$showAdd = isset($_GET['new']) && $_GET['new'] === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Loans</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('loans'); ?>
    <main class="main">
        <?php topbar('Loans'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Employee Loans <span class="count-pill"><?php echo $totalLoansCount; ?></span></h1>
                    <p>Active loan releases, installments, and payroll amortization.</p>
                </div>
                <div class="heading-actions">
                    <a href="loan_types.php" class="btn btn-secondary"><?php echo ui_icon('shield'); ?> Loan Types</a>
                    <a href="loans.php?new=1" class="btn btn-primary"><?php echo ui_icon('plus'); ?> New Loan</a>
                </div>
            </div>

            <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

            <div class="dashboard-kpis" style="margin-bottom: 18px;">
                <div class="kpi card">
                    <span class="kpi-label">Active loans</span>
                    <strong><?php echo $activeCount; ?></strong>
                    <small>Amortizing</small>
                </div>
                <div class="kpi card">
                    <span class="kpi-label">Active principal</span>
                    <strong><?php echo money($totalPrincipal); ?></strong>
                    <small>Total released</small>
                </div>
                <div class="kpi card">
                    <span class="kpi-label">Outstanding balance</span>
                    <strong style="color:var(--brand-accent);"><?php echo money($totalBalance); ?></strong>
                    <small>Remaining collectible</small>
                </div>
            </div>

            <!-- Repayments Manager Section (When a loan is selected) -->
            <?php if ($selectedLoan): ?>
            <section class="card" id="repayment-section" style="margin-bottom: 20px; border: 2px solid var(--accent, #0f9f9a);">
                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background: #f0fdfa;">
                    <div>
                        <h2 style="color: #0f766e; margin:0 0 4px 0;">Repayments for Loan #<?php echo (int)$selectedLoan['loan_id']; ?> — <?php echo e($selectedLoan['last_name'] . ', ' . $selectedLoan['first_name']); ?></h2>
                        <p style="margin:0; font-size:12px; color: #115e59;">
                            Principal: <?php echo money($selectedLoan['principal']); ?> · Remaining Balance: <strong><?php echo money($selectedLoan['remaining_balance']); ?></strong> · Status: <span class="badge badge-info"><?php echo e($selectedLoan['status']); ?></span>
                        </p>
                    </div>
                    <a href="loans.php" class="btn btn-small btn-secondary"><?php echo ui_icon('close'); ?> Close Repayment View</a>
                </div>

                <div style="padding: 16px 20px;">
                    <!-- Record or Edit Repayment Form -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px;">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #1e293b;">
                            <?php echo $editingRepayment ? 'Edit Repayment #' . (int)$editingRepayment['repayment_id'] : 'Record Direct / Cash Payment'; ?>
                        </h3>
                        <form method="post" class="repayment-form-flex">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $editingRepayment ? 'update_repayment' : 'record_repayment'; ?>">
                            <input type="hidden" name="loan_id" value="<?php echo (int)$selectedLoan['loan_id']; ?>">
                            <?php if ($editingRepayment): ?>
                                <input type="hidden" name="repayment_id" value="<?php echo (int)$editingRepayment['repayment_id']; ?>">
                            <?php endif; ?>

                            <div class="repayment-form-field" style="flex:1; min-width:140px;">
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Amount (₱) <span class="required">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" value="<?php echo $editingRepayment ? (float)$editingRepayment['amount'] : (float)$selectedLoan['installment']; ?>" required style="width:100%; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;">
                            </div>

                            <div class="repayment-form-field" style="flex:1; min-width:140px;">
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Payment Date <span class="required">*</span></label>
                                <input type="date" name="repayment_date" value="<?php echo $editingRepayment ? $editingRepayment['repayment_date'] : date('Y-m-d'); ?>" required style="width:100%; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;">
                            </div>

                            <div class="repayment-form-field" style="flex:2; min-width:200px;">
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Receipt / Notes</label>
                                <input type="text" name="notes" placeholder="e.g. Cashier OR #12345 or Advance payment" value="<?php echo e($editingRepayment ? $editingRepayment['notes'] : ''); ?>" style="width:100%; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;">
                            </div>

                            <div class="repayment-form-actions">
                                <button type="submit" class="btn btn-primary" style="padding:7px 16px; font-size:13px;">
                                    <?php echo $editingRepayment ? 'Save Changes' : '+ Post Payment'; ?>
                                </button>
                                <?php if ($editingRepayment): ?>
                                    <a href="loans.php?view_repayments=<?php echo (int)$selectedLoan['loan_id']; ?>" class="btn btn-secondary" style="padding:7px 12px; font-size:13px;">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Repayments History Table -->
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #1e293b;">Posted Repayment History</h3>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Date</th>
                                    <th>Method / Period</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Notes</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($repaymentsList)): ?>
                                    <tr><td colspan="6" class="empty">No payments recorded for this loan yet.</td></tr>
                                <?php else: foreach ($repaymentsList as $rep): ?>
                                    <tr>
                                        <td>#<?php echo (int)$rep['repayment_id']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($rep['repayment_date'])); ?></td>
                                        <td>
                                            <?php if (!empty($rep['period_code'])): ?>
                                                <span class="badge" style="background:#f1f5f9; color:#475569;">Payroll: <?php echo e($rep['period_code']); ?></span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#e0f2fe; color:#0369a1;">Direct Payment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:700; color:#059669;"><?php echo money($rep['amount']); ?></td>
                                        <td><span class="mini"><?php echo e($rep['notes'] ?: 'No notes'); ?></span></td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <a href="../print/loan_payment_receipt.php?id=<?php echo (int)$rep['repayment_id']; ?>" target="_blank" class="btn btn-small btn-secondary" style="padding:3px 8px; font-size:11px;" title="Print Official Receipt">📄 Receipt</a>
                                            <a href="loans.php?view_repayments=<?php echo (int)$selectedLoan['loan_id']; ?>&edit_repayment=<?php echo (int)$rep['repayment_id']; ?>#repayment-section" class="btn btn-small btn-secondary" style="padding:3px 8px; font-size:11px;">Edit</a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this payment of <?php echo money($rep['amount']); ?>? The remaining balance will be increased.');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_repayment">
                                                <input type="hidden" name="repayment_id" value="<?php echo (int)$rep['repayment_id']; ?>">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$selectedLoan['loan_id']; ?>">
                                                <button type="submit" class="btn btn-small btn-secondary" style="padding:3px 8px; font-size:11px; color:#b91c1c;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($showAdd): ?>
            <section class="card" style="margin-bottom: 20px;">
                <div class="card-head">
                    <div>
                        <h2>Grant employee loan</h2>
                        <p>Installments deduct automatically when a pay period is marked Paid.</p>
                    </div>
                    <a href="loans.php" class="btn btn-small btn-secondary"><?php echo ui_icon('close'); ?> Cancel</a>
                </div>
                <form method="post" style="padding: 18px 20px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_loan">

                    <div class="form-grid loan-form-grid">
                        <div class="field">
                            <label>Employee <span class="required">*</span></label>
                            <select name="employee_id" required>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo (int)$emp['employee_id']; ?>">
                                        <?php echo e($emp['employee_no'] . ' — ' . $emp['last_name'] . ', ' . $emp['first_name'] . ' (' . ($emp['department'] ?: 'Unassigned') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Loan type <span class="required">*</span></label>
                            <select name="loan_type_id" required>
                                <option value="">Select type</option>
                                <?php foreach ($loanTypes as $lt): ?>
                                    <option value="<?php echo (int)$lt['loan_type_id']; ?>">
                                        <?php echo e($lt['loan_type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Grant date <span class="required">*</span></label>
                            <input type="date" name="loan_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="field">
                            <label>Principal (₱) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="1" name="principal" placeholder="0.00" id="inpPrincipal" required>
                        </div>

                        <div class="field">
                            <label>Payroll deduction (₱) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="1" name="installment" placeholder="0.00" id="inpInstallment" required>
                        </div>

                        <div class="field">
                            <label>Pay periods (terms)</label>
                            <input type="number" min="1" max="120" name="term_periods" value="6" id="inpTerms" required>
                        </div>

                        <div class="field">
                            <label>Repayment start <span class="required">*</span></label>
                            <input type="date" name="repayment_start" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="field" style="grid-column: 1 / -1;">
                            <label>Remarks</label>
                            <input type="text" name="remarks" placeholder="Optional voucher reference or note">
                        </div>
                    </div>

                    <div style="margin-top: 18px; display:flex; flex-wrap:wrap; gap:10px;">
                        <button type="submit" class="btn btn-primary">Save loan</button>
                        <a href="loans.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <section class="card">
                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <h2>Loan Ledger</h2>
                    <form method="get" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <select name="status" onchange="this.form.submit()" style="padding:6px 12px; font-size:13px;">
                            <option value="Active" <?php echo $filterStatus === 'Active' ? 'selected' : ''; ?>>Active loans</option>
                            <option value="Paid" <?php echo $filterStatus === 'Paid' ? 'selected' : ''; ?>>Fully paid</option>
                            <option value="Cancelled" <?php echo $filterStatus === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="All" <?php echo $filterStatus === 'All' ? 'selected' : ''; ?>>All statuses</option>
                        </select>
                        <select name="employee_id" onchange="this.form.submit()" style="padding:6px 12px; font-size:13px; max-width:240px;">
                            <option value="0">All employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp['employee_id']; ?>" <?php echo $filterEmployee === (int)$emp['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($emp['last_name'] . ', ' . $emp['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($filterStatus !== 'Active' || $filterEmployee > 0): ?>
                            <a href="loans.php" class="btn btn-small btn-secondary">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Loan #</th>
                                <th>Employee</th>
                                <th>Loan Type</th>
                                <th>Granted Date</th>
                                <th style="text-align:right;">Principal</th>
                                <th style="text-align:right;">Installment</th>
                                <th style="text-align:right;">Repaid</th>
                                <th style="text-align:right;">Remaining</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($loans)): ?>
                            <tr><td colspan="10" class="empty">No loan records found for the selected filter.</td></tr>
                        <?php else: foreach ($loans as $l): ?>
                            <tr>
                                <td><strong>#<?php echo (int)$l['loan_id']; ?></strong></td>
                                <td>
                                    <strong><?php echo e($l['last_name'] . ', ' . $l['first_name']); ?></strong>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo e($l['employee_no']); ?> · <?php echo e($l['department']); ?></div>
                                </td>
                                <td><?php echo e($l['loan_type_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($l['loan_date'])); ?></td>
                                <td style="text-align:right; font-weight:600;"><?php echo money($l['principal']); ?></td>
                                <td style="text-align:right;"><?php echo money($l['installment']); ?> <small style="color:var(--text-muted);">/pay</small></td>
                                <td style="text-align:right; color:var(--brand-teal); font-weight:600;">
                                    <?php echo money($l['total_repaid']); ?>
                                    <div style="font-size:11px; color:var(--text-muted);"><?php echo (int)$l['repayments_count']; ?> payments</div>
                                </td>
                                <td style="text-align:right; font-weight:700; color:<?php echo (float)$l['remaining_balance'] > 0 ? '#b91c1c' : 'inherit'; ?>;">
                                    <?php echo money($l['remaining_balance']); ?>
                                </td>
                                <td>
                                    <?php
                                    $bClass = 'badge-ok';
                                    if ($l['status'] === 'Active') $bClass = 'badge-info';
                                    elseif ($l['status'] === 'Cancelled') $bClass = 'badge-err';
                                    ?>
                                    <span class="badge <?php echo $bClass; ?>"><?php echo e($l['status']); ?></span>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <a href="loans.php?view_repayments=<?php echo (int)$l['loan_id']; ?>#repayment-section" class="btn btn-small btn-secondary" title="View, Record, and Edit Repayments">
                                        Repayments (<?php echo (int)$l['repayments_count']; ?>)
                                    </a>
                                    <?php if ($l['status'] === 'Active' && (int)$l['repayments_count'] === 0): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this loan?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="cancel_loan">
                                        <input type="hidden" name="loan_id" value="<?php echo (int)$l['loan_id']; ?>">
                                        <button type="submit" class="btn btn-small btn-secondary" title="Cancel Loan">Cancel</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Toolbar (Max 10 records per page) -->
                <?php if ($totalLoansCount > 0): 
                    $pageStart = $offset + 1;
                    $pageEnd = min($totalLoansCount, $offset + $recordsPerPage);
                    $prevPage = $currentPage > 1 ? $currentPage - 1 : 1;
                    $nextPage = $currentPage < $totalPages ? $currentPage + 1 : $totalPages;
                    
                    // Build base query string preserving filters
                    $queryArgs = $_GET;
                ?>
                <div class="pagination-toolbar" style="padding:14px 20px; border-top:1px solid var(--line, #e2e8f0); background: #ffffff;">
                    <div style="font-size:13px; color:var(--text-muted, #64748b);">
                        Showing <strong><?php echo $pageStart; ?></strong> to <strong><?php echo $pageEnd; ?></strong> of <strong><?php echo $totalLoansCount; ?></strong> loans
                    </div>
                    
                    <div style="display:flex; align-items:center; gap:8px;">
                        <?php if ($currentPage > 1): 
                            $queryArgs['page'] = $prevPage;
                        ?>
                            <a href="loans.php?<?php echo http_build_query($queryArgs); ?>" class="btn btn-small btn-secondary">
                                &larr; Previous
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-small btn-secondary" disabled style="opacity:0.5; cursor:not-allowed;">
                                &larr; Previous
                            </button>
                        <?php endif; ?>

                        <span style="font-size:13px; font-weight:600; padding:0 8px; color:var(--text, #1e293b);">
                            Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
                        </span>

                        <?php if ($currentPage < $totalPages): 
                            $queryArgs['page'] = $nextPage;
                        ?>
                            <a href="loans.php?<?php echo http_build_query($queryArgs); ?>" class="btn btn-small btn-secondary">
                                Next &rarr;
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-small btn-secondary" disabled style="opacity:0.5; cursor:not-allowed;">
                                Next &rarr;
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </section>
        </div>
    </main>
</div>
<script>
const inpPrincipal = document.getElementById('inpPrincipal');
const inpInstallment = document.getElementById('inpInstallment');
const inpTerms = document.getElementById('inpTerms');

if (inpPrincipal && inpInstallment && inpTerms) {
    inpPrincipal.addEventListener('input', () => {
        const p = parseFloat(inpPrincipal.value) || 0;
        const t = parseInt(inpTerms.value) || 1;
        if (p > 0 && t > 0) inpInstallment.value = (p / t).toFixed(2);
    });
    inpTerms.addEventListener('input', () => {
        const p = parseFloat(inpPrincipal.value) || 0;
        const t = parseInt(inpTerms.value) || 1;
        if (p > 0 && t > 0) inpInstallment.value = (p / t).toFixed(2);
    });
}
</script>
</body>
</html>
