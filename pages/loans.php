<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';
$editLoan = null;

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
    }
}

if (isset($_GET['saved'])) $message = 'Loan record saved successfully.';
if (isset($_GET['cancelled'])) $message = 'Loan marked as cancelled.';

$filterStatus = $_GET['status'] ?? 'Active';
$filterEmployee = (int)($_GET['employee_id'] ?? 0);

// Load Loan Types for form
$loanTypes = [];
$ltRes = $conn->query('SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_type_name ASC');
if ($ltRes) while ($lt = $ltRes->fetch_assoc()) $loanTypes[] = $lt;

// Load Active Employees for form
$employees = [];
$empRes = $conn->query('SELECT employee_id, employee_no, first_name, last_name, department, basic_salary FROM employees ORDER BY last_name ASC, first_name ASC');
if ($empRes) while ($emp = $empRes->fetch_assoc()) $employees[] = $emp;

// Query loans with details
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
$sql = "SELECT el.*, e.employee_no, e.first_name, e.last_name, e.department, lt.loan_type_name,
        (SELECT COALESCE(SUM(lr.amount), 0) FROM loan_repayments lr WHERE lr.loan_id = el.loan_id) AS total_repaid,
        (SELECT COUNT(*) FROM loan_repayments lr WHERE lr.loan_id = el.loan_id) AS repayments_count
        FROM employee_loans el
        JOIN employees e ON e.employee_id = el.employee_id
        JOIN loan_types lt ON lt.loan_type_id = el.loan_type_id
        $whereSql
        ORDER BY el.loan_id DESC";

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

// Summary stats
$totalPrincipal = 0;
$totalBalance = 0;
$activeCount = 0;
foreach ($loans as $l) {
    if ($l['status'] === 'Active') {
        $totalPrincipal += (float)$l['principal'];
        $totalBalance += (float)$l['remaining_balance'];
        $activeCount++;
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
                    <h1>Employee Loans <span class="count-pill"><?php echo count($loans); ?></span></h1>
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

                    <div class="form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
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

                    <div style="margin-top: 18px; display:flex; gap:10px;">
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
                                <td style="text-align:right;">
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
            </section>
        </div>
    </main>
</div>
<script>
// Auto-calculate installment or term when typing
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
