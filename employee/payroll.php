<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/layout.php';

$employee = current_employee($conn);
if (!$employee) {
    header('Location: logout.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT p.payroll_id, p.period_id, p.period_start, p.period_end, p.basic_salary, p.allowances, p.other_earnings, p.overtime_pay, p.other_income_amount, p.deductions, p.loan_deductions, p.attendance_deductions, p.other_deductions_amount, p.gross_pay, p.net_pay, p.status, p.notes, pp.period_code, pp.pay_date
    FROM payroll_records p
    LEFT JOIN payroll_periods pp ON pp.period_id = p.period_id
    WHERE p.employee_id = ? AND p.status IN ('Approved', 'Paid')
    ORDER BY p.period_end DESC, p.payroll_id DESC
");
$stmt->bind_param('i', $employee['employee_id']);
$stmt->execute();
$rows = $stmt->get_result();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Payslips</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/employee.css">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
    <div class="app">
        <?php employee_sidebar('payroll'); ?>
        <main class="main">
            <?php employee_topbar('Payslips'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <h1>Payslips <span class="count-pill"><?php echo (int)$rows->num_rows; ?></span></h1>
                    </div>
                </div>

                <section class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Pay Period</th>
                                    <th>Basic Pay</th>
                                    <th>Gross Pay</th>
                                    <th>Total Deductions</th>
                                    <th>Net Take-Home</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="7" class="empty">No payroll records available for your account yet.</td>
                                    </tr>
                                <?php else: while ($r = $rows->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($r['period_code'])): ?>
                                                <div style="font-weight: 700; color: var(--accent, #1d4ed8); font-size: 0.85rem;"><?php echo e($r['period_code']); ?></div>
                                            <?php endif; ?>
                                            <div><?php echo e(date('M d, Y', strtotime($r['period_start'])) . ' – ' . date('M d, Y', strtotime($r['period_end']))); ?></div>
                                            <?php if (!empty($r['pay_date'])): ?>
                                                <div class="mini">Pay Date: <?php echo date('M d, Y', strtotime($r['pay_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo money($r['basic_salary']); ?></td>
                                        <td>
                                            <strong><?php echo money($r['gross_pay']); ?></strong>
                                            <?php if ((float)$r['overtime_pay'] > 0 || (float)$r['other_income_amount'] > 0): ?>
                                                <div class="mini">
                                                    <?php if ((float)$r['overtime_pay'] > 0): ?>+OT <?php echo money($r['overtime_pay']); ?> <?php endif; ?>
                                                    <?php if ((float)$r['other_income_amount'] > 0): ?>+Inc <?php echo money($r['other_income_amount']); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="color: var(--danger, #e11d48);"><?php echo money($r['deductions']); ?></span>
                                            <?php if ((float)$r['loan_deductions'] > 0 || (float)$r['attendance_deductions'] > 0): ?>
                                                <div class="mini">
                                                    <?php if ((float)$r['loan_deductions'] > 0): ?>Loans: <?php echo money($r['loan_deductions']); ?> <?php endif; ?>
                                                    <?php if ((float)$r['attendance_deductions'] > 0): ?>Att: <?php echo money($r['attendance_deductions']); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="font-size: 1.05rem; color: var(--accent, #1d4ed8);">
                                                <?php echo money($r['net_pay']); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if ($r['status'] === 'Paid'): ?>
                                                <span class="badge" style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;">Paid</span>
                                            <?php elseif ($r['status'] === 'Approved'): ?>
                                                <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Approved</span>
                                            <?php else: ?>
                                                <span class="badge"><?php echo e($r['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.35rem;">
                                                <a class="btn btn-secondary btn-small" target="_blank" rel="noopener" href="../print/payroll.php?id=<?php echo (int)$r['payroll_id']; ?>" title="Download printable PDF payslip">
                                                    PDF Payslip
                                                </a>
                                            </div>
                                        </td>
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
