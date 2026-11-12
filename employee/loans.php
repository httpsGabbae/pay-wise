<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$employee = current_employee($conn);
if (!$employee) {
    header('Location: logout.php');
    exit;
}

$empId = (int)$employee['employee_id'];

// Fetch employee loans
$loansStmt = $conn->prepare('
    SELECT el.*, lt.loan_type_name AS loan_name, lt.description AS loan_type_desc
    FROM employee_loans el
    JOIN loan_types lt ON lt.loan_type_id = el.loan_type_id
    WHERE el.employee_id = ?
    ORDER BY (el.status = "Active") DESC, el.loan_id DESC
');
$loansStmt->bind_param('i', $empId);
$loansStmt->execute();
$loansRes = $loansStmt->get_result();

$loans = [];
$totalBorrowed = 0.0;
$totalRepaid = 0.0;
$activeBalance = 0.0;
$activeCount = 0;
$nextInstallment = 0.0;

while ($l = $loansRes->fetch_assoc()) {
    $loans[] = $l;
    $totalBorrowed += (float)$l['principal'];
    $repaid = (float)$l['principal'] - (float)$l['remaining_balance'];
    $totalRepaid += max(0, $repaid);
    if ($l['status'] === 'Active') {
        $activeBalance += (float)$l['remaining_balance'];
        $nextInstallment += (float)$l['installment'];
        $activeCount++;
    }
}

// Fetch repayment history
$repayStmt = $conn->prepare('
    SELECT lr.*, lt.loan_type_name AS loan_name, el.remarks, pp.period_code, p.period_start, p.period_end
    FROM loan_repayments lr
    JOIN employee_loans el ON el.loan_id = lr.loan_id
    JOIN loan_types lt ON lt.loan_type_id = el.loan_type_id
    LEFT JOIN payroll_records p ON p.payroll_id = lr.payroll_id
    LEFT JOIN payroll_periods pp ON pp.period_id = p.period_id
    WHERE el.employee_id = ?
    ORDER BY lr.repayment_date DESC, lr.repayment_id DESC
');
$repayStmt->bind_param('i', $empId);
$repayStmt->execute();
$repayments = $repayStmt->get_result();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Loans</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/employee.css">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
    <div class="app">
        <?php employee_sidebar('loans'); ?>
        <main class="main">
            <?php employee_topbar('Loans'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <h1>Loans <span class="count-pill"><?php echo count($loans); ?></span></h1>
                    </div>
                </div>

                <div class="dashboard-kpis" style="margin-bottom: 1.25rem;">
                    <div class="kpi card">
                        <span class="kpi-label">Remaining balance</span>
                        <strong><?php echo money($activeBalance); ?></strong>
                        <small><?php echo $activeCount; ?> active <?php echo $activeCount === 1 ? 'loan' : 'loans'; ?></small>
                    </div>
                    <div class="kpi card">
                        <span class="kpi-label">Per-payroll deduction</span>
                        <strong style="color: var(--danger, #e11d48);"><?php echo money($nextInstallment); ?></strong>
                        <small>Auto-deducted each period</small>
                    </div>
                    <div class="kpi card">
                        <span class="kpi-label">Repaid to date</span>
                        <strong style="color: var(--success, #059669);"><?php echo money($totalRepaid); ?></strong>
                        <small>Total posted</small>
                    </div>
                    <div class="kpi card">
                        <span class="kpi-label">Total principal</span>
                        <strong><?php echo money($totalBorrowed); ?></strong>
                        <small><?php echo count($loans); ?> loan<?php echo count($loans) === 1 ? '' : 's'; ?></small>
                    </div>
                </div>

                <section class="card" style="margin-bottom: 1.5rem;">
                    <div class="card-head">
                        <div>
                            <h2>Loan Accounts</h2>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Loan Type</th>
                                    <th>Loan Date</th>
                                    <th>Principal</th>
                                    <th>Installment / Period</th>
                                    <th>Remaining Balance</th>
                                    <th>Repayment Progress</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($loans)): ?>
                                    <tr>
                                        <td colspan="7" class="empty">You do not have any active or previous loan accounts on file.</td>
                                    </tr>
                                <?php else: foreach ($loans as $l): 
                                    $tot = (float)$l['principal'];
                                    $bal = (float)$l['remaining_balance'];
                                    $paid = max(0, $tot - $bal);
                                    $pct = $tot > 0 ? min(100, round(($paid / $tot) * 100)) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($l['loan_name']); ?></strong>
                                            <?php if (!empty($l['remarks'])): ?>
                                                <div class="mini" style="color: var(--text-muted, #64748b);"><?php echo e($l['remarks']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($l['loan_date'])); ?></td>
                                        <td><?php echo money($l['principal']); ?></td>
                                        <td>
                                            <strong><?php echo money($l['installment']); ?></strong>
                                            <div class="mini"><?php echo (int)$l['term_periods']; ?> period(s)</div>
                                        </td>
                                        <td>
                                            <strong style="<?php echo $l['status'] === 'Active' ? 'color: var(--danger, #e11d48);' : 'color: var(--text-muted, #64748b);'; ?>">
                                                <?php echo money($l['remaining_balance']); ?>
                                            </strong>
                                        </td>
                                        <td style="min-width: 140px;">
                                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                                                <span><?php echo money($paid); ?></span>
                                                <strong><?php echo $pct; ?>%</strong>
                                            </div>
                                            <div style="background: var(--border-color, #e2e8f0); height: 6px; border-radius: 3px; overflow: hidden;">
                                                <div style="background: <?php echo $pct >= 100 ? 'var(--success, #059669)' : 'var(--accent, #1d4ed8)'; ?>; height: 100%; width: <?php echo $pct; ?>%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($l['status'] === 'Active'): ?>
                                                <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Active</span>
                                            <?php elseif ($l['status'] === 'Paid'): ?>
                                                <span class="badge" style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;">Fully Paid</span>
                                            <?php else: ?>
                                                <span class="badge" style="background: #fff1f2; color: #be123c; border: 1px solid #fecdd3;"><?php echo e($l['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <div class="card-head">
                        <div>
                            <h2>Repayment History</h2>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Payment Date</th>
                                    <th>Loan Facility</th>
                                    <th>Payroll Period</th>
                                    <th>Amount Deducted</th>
                                    <th>Description / Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$repayments || $repayments->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="5" class="empty">No payroll loan repayments recorded yet.</td>
                                    </tr>
                                <?php else: while ($r = $repayments->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($r['repayment_date'])); ?></td>
                                        <td>
                                            <strong><?php echo e($r['loan_name']); ?></strong>
                                            <?php if (!empty($r['remarks'])): ?>
                                                <div class="mini"><?php echo e($r['remarks']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['period_code'])): ?>
                                                <span class="badge" style="font-weight: 600;"><?php echo e($r['period_code']); ?></span>
                                            <?php elseif (!empty($r['period_start'])): ?>
                                                <?php echo date('M d', strtotime($r['period_start'])); ?> – <?php echo date('M d, Y', strtotime($r['period_end'])); ?>
                                            <?php else: ?>
                                                <span class="mini">Direct Payment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong style="color: var(--success, #059669);"><?php echo money($r['amount']); ?></strong></td>
                                        <td><span class="mini"><?php echo e($r['notes'] ?: 'Automated payroll deduction'); ?></span></td>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>

</html>
