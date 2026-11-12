<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$stmt = $conn->prepare('SELECT p.*,e.employee_no,e.first_name,e.last_name,e.department,e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id WHERE p.period_start <= ? AND p.period_end >= ? ORDER BY p.period_end DESC,p.payroll_id DESC');
$stmt->bind_param('ss', $monthEnd, $monthStart);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$totalGross = 0; $totalNet = 0; $totalDeductions = 0; $paidCount = 0;
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $totalGross += (float)$row['gross_pay'];
    $totalNet += (float)$row['net_pay'];
    $totalDeductions += (float)$row['deductions'];
    if ($row['status'] === 'Paid') $paidCount++;
}
$departments = [];
foreach ($rows as $row) { $d = $row['department'] ?: 'Unassigned'; $departments[$d] = ($departments[$d] ?? 0) + 1; }
arsort($departments);

$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -{$i} month", strtotime($monthStart)));
    $trend[$key] = ['label'=>date('M', strtotime($key . '-01')), 'gross'=>0, 'net'=>0];
}
$trendStart = array_key_first($trend) . '-01';
$trendEnd = date('Y-m-t', strtotime($monthStart));
$stmt = $conn->prepare("SELECT DATE_FORMAT(period_end,'%Y-%m') AS ym, COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(net_pay),0) AS net FROM payroll_records WHERE period_end BETWEEN ? AND ? GROUP BY ym ORDER BY ym");
$stmt->bind_param('ss', $trendStart, $trendEnd);
$stmt->execute();
$trendResult = $stmt->get_result();
while ($row = $trendResult->fetch_assoc()) {
    if (isset($trend[$row['ym']])) {
        $trend[$row['ym']]['gross'] = (float)$row['gross'];
        $trend[$row['ym']]['net'] = (float)$row['net'];
    }
}
$trendMax = 1;
foreach ($trend as $point) $trendMax = max($trendMax, $point['gross'], $point['net']);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1"><title>Lipa City Colleges · Reports</title><link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1"><script src="../assets/js/app.js?v=20261112-clean1" defer></script><script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script></head>
<body><div class="app">
<?php sidebar('reports'); ?>
<main class="main">
<?php topbar('Reports'); ?>
<div class="content">
    <div class="page-heading">
        <div><h1>Monthly Payroll Report</h1><p><?php echo e(date('F Y', strtotime($monthStart))); ?> · <?php echo count($rows); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?></p></div>
        <div class="print-tools"><button type="button" class="btn btn-primary" onclick="window.open('../print/monthly_payroll.php?month=<?php echo e($month); ?>','_blank')"><?php echo ui_icon('printer'); ?> Print Register</button></div>
    </div>

    <section class="card report-filter-card"><form class="report-filter" method="get"><div><label>Month</label><div class="input-icon-wrap"><?php echo ui_icon('calendar'); ?><input type="month" name="month" value="<?php echo e($month); ?>"></div></div><button class="btn btn-secondary" type="submit">Apply</button></form><div class="report-filter-note"><?php echo e(date('F Y', strtotime($monthStart))); ?> · <?php echo count($rows); ?> payroll record<?php echo count($rows) === 1 ? '' : 's'; ?></div></section>

    <section class="report-stats">
        <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('users'); ?></span><div><small>Records</small><strong><?php echo count($rows); ?></strong></div></div>
        <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('trend'); ?></span><div><small>Gross Payroll</small><strong><?php echo money($totalGross); ?></strong></div></div>
        <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('wallet'); ?></span><div><small>Net Payroll</small><strong><?php echo money($totalNet); ?></strong></div></div>
        <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('shield'); ?></span><div><small>Paid</small><strong><?php echo $paidCount; ?></strong></div></div>
    </section>

    <section class="card report-trend-card">
        <div class="card-head report-trend-head">
            <div><h2>Six-Month Trend</h2></div>
        </div>
        <div class="compact-bar-chart">
            <div class="compact-bar-axis"><span><?php echo money($trendMax); ?></span><span>0</span></div>
            <div class="compact-bars">
                <?php foreach ($trend as $point): $grossH=max(2,round(($point['gross']/$trendMax)*100)); $netH=max(2,round(($point['net']/$trendMax)*100)); ?>
                <div class="compact-bar-group" title="<?php echo e($point['label']); ?> — Gross: <?php echo money($point['gross']); ?> · Net: <?php echo money($point['net']); ?>">
                    <div class="compact-bar-columns"><i class="gross" style="height:<?php echo $grossH; ?>%"></i><i class="net" style="height:<?php echo $netH; ?>%"></i></div>
                    <span><?php echo e($point['label']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="compact-bar-legend"><span><i class="gross"></i>Gross</span><span><i class="net"></i>Net</span></div>
    </section>

    <section class="report-layout">
        <div class="card report-table-card"><div class="card-head"><div><h2><?php echo e(date('F Y', strtotime($monthStart))); ?> Register</h2></div><button type="button" class="icon-action print" title="Print monthly report" aria-label="Print monthly report" onclick="window.open('../print/monthly_payroll.php?month=<?php echo e($month); ?>','_blank')"><?php echo ui_icon('printer'); ?></button></div>
            <div class="table-wrap"><table class="table report-table"><thead><tr><th>Employee</th><th>Department</th><th>Period</th><th>Gross</th><th>Net</th><th>Status</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">No payroll records overlap this month.</td></tr><?php else: foreach ($rows as $row): ?><tr><td><strong><?php echo e($row['last_name'].', '.$row['first_name']); ?></strong><div class="mini"><?php echo e($row['employee_no']); ?></div></td><td><?php echo e($row['department'] ?: 'Unassigned'); ?></td><td><?php echo e(date('M d',strtotime($row['period_start'])) . ' – ' . date('M d, Y',strtotime($row['period_end']))); ?></td><td><?php echo money($row['gross_pay']); ?></td><td><strong><?php echo money($row['net_pay']); ?></strong></td><td><span class="badge"><?php echo e($row['status']); ?></span></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <aside class="card report-side-card"><div class="card-head"><div><h2>By Department</h2></div></div><div class="department-bars">
            <?php if (!$departments): ?><div class="empty">No department data.</div><?php else: foreach (array_slice($departments,0,6,true) as $department=>$count): $width=max(8,round(($count/max($departments))*100)); ?><div class="department-row"><div><span><?php echo e($department); ?></span><strong><?php echo (int)$count; ?></strong></div><div class="bar"><i style="width:<?php echo $width; ?>%;--dept-c:var(--c-<?php echo dept_color_key('', (string)$department); ?>)"></i></div></div><?php endforeach; endif; ?>
        </div></aside>
    </section>
</div></main></div>

<div id="reportPrintSheet" class="report-print-sheet">
    <div class="print-sheet-header">
        <div class="print-sheet-brand">LIPA CITY COLLEGES</div>
        <div class="print-sheet-title">MONTHLY PAYROLL REPORT</div>
        <div class="print-sheet-subtitle"><?php echo e(date('F Y', strtotime($monthStart))); ?> · Payroll Period Register</div>
    </div>
    <div class="print-sheet-meta"><span>Coverage: <?php echo e(date('M 01, Y', strtotime($monthStart))); ?> – <?php echo e(date('M d, Y', strtotime($monthEnd))); ?></span><span>Generated: <?php echo e(date('M d, Y')); ?></span></div>
    <div class="print-sheet-summary">
        <div><span>Payroll Records</span><strong><?php echo count($rows); ?></strong></div>
        <div><span>Gross Payroll</span><strong><?php echo money($totalGross); ?></strong></div>
        <div><span>Deductions</span><strong><?php echo money($totalDeductions); ?></strong></div>
        <div><span>Net Payroll</span><strong><?php echo money($totalNet); ?></strong></div>
    </div>
    <table class="print-sheet-table">
        <thead><tr><th>Employee ID</th><th>Employee</th><th>Department</th><th>Pay Period</th><th>Gross</th><th>Net</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="print-empty">No payroll records overlap this month.</td></tr><?php else: foreach ($rows as $row): ?>
            <tr><td><?php echo e($row['employee_no']); ?></td><td><?php echo e($row['last_name'].', '.$row['first_name']); ?></td><td><?php echo e($row['department'] ?: 'Unassigned'); ?></td><td><?php echo e(date('M d', strtotime($row['period_start'])) . ' – ' . date('M d, Y', strtotime($row['period_end']))); ?></td><td class="num"><?php echo money($row['gross_pay']); ?></td><td class="num"><?php echo money($row['net_pay']); ?></td><td class="status"><?php echo e($row['status']); ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="print-sheet-breakdown">
        <h3>Payroll by Department</h3>
        <div class="print-dept-grid">
        <?php foreach ($departments as $department=>$count): ?><div><span><?php echo e($department); ?></span><strong><?php echo (int)$count; ?></strong></div><?php endforeach; ?>
        </div>
    </div>
    <div class="print-sheet-sign"><span>Prepared by: ______________________________</span><span>Date: <?php echo e(date('M d, Y')); ?></span></div>
    <div class="print-sheet-footer">Private and Confidential · Lipa City Colleges</div>
</div>
</body></html>
