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
for ($month_offset = 5; $month_offset >= 0; $month_offset--) {
    $month_key = date('Y-m', strtotime("first day of -{$month_offset} month", strtotime($monthStart)));
    $trend[$month_key] = [
        'label' => date('M', strtotime($month_key . '-01')),
        'full_name' => date('F Y', strtotime($month_key . '-01')),
        'gross' => 0.0,
        'net' => 0.0
    ];
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

$highest_payroll_amount = 0.0;
foreach ($trend as $trend_point) {
    if ((float)$trend_point['gross'] > $highest_payroll_amount) {
        $highest_payroll_amount = (float)$trend_point['gross'];
    }
    if ((float)$trend_point['net'] > $highest_payroll_amount) {
        $highest_payroll_amount = (float)$trend_point['net'];
    }
}

if ($highest_payroll_amount <= 0.0) {
    $chart_ceiling_amount = 50000.0;
    $chart_step_amount = 10000.0;
} else {
    $raw_target_step = $highest_payroll_amount / 5.0;
    $step_order_of_magnitude = pow(10, floor(log10($raw_target_step)));
    $normalized_step_value = $raw_target_step / $step_order_of_magnitude;

    if ($normalized_step_value <= 1.0) {
        $chart_step_amount = 1.0 * $step_order_of_magnitude;
    } elseif ($normalized_step_value <= 2.0) {
        $chart_step_amount = 2.0 * $step_order_of_magnitude;
    } elseif ($normalized_step_value <= 2.5) {
        $chart_step_amount = 2.5 * $step_order_of_magnitude;
    } elseif ($normalized_step_value <= 5.0) {
        $chart_step_amount = 5.0 * $step_order_of_magnitude;
    } else {
        $chart_step_amount = 10.0 * $step_order_of_magnitude;
    }

    $chart_ceiling_amount = ceil($highest_payroll_amount / $chart_step_amount) * $chart_step_amount;
    $number_of_intervals = (int)round($chart_ceiling_amount / $chart_step_amount);
    if ($number_of_intervals < 4) {
        $chart_step_amount = $chart_step_amount / 2.0;
    }
}

$chart_gridline_amounts = [];
$chart_gridline_labels = [];
$current_step_count = (int)round($chart_ceiling_amount / $chart_step_amount);
for ($step_index = $current_step_count; $step_index >= 0; $step_index--) {
    $gridline_value = $step_index * $chart_step_amount;
    $chart_gridline_amounts[] = $gridline_value;

    if ($gridline_value >= 1000000.0) {
        $formatted_axis_label = '₱' . rtrim(rtrim(number_format($gridline_value / 1000000.0, 1), '0'), '.') . 'M';
    } elseif ($gridline_value >= 1000.0) {
        $formatted_axis_label = '₱' . rtrim(rtrim(number_format($gridline_value / 1000.0, 1), '0'), '.') . 'K';
    } else {
        $formatted_axis_label = '₱' . number_format($gridline_value, 0);
    }
    $chart_gridline_labels[] = $formatted_axis_label;
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1"><title>Lipa City Colleges · Reports</title><link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1"><link rel="stylesheet" href="../assets/css/reports_six_month_trend_chart.css?v=20261123-1"><script src="../assets/js/app.js?v=20261112-clean1" defer></script><script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script><script src="../assets/js/reports_six_month_trend_chart.js?v=20261123-1" defer></script></head>
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

    <section class="card report-trend-card" aria-label="Six-Month Payroll Trend">
        <div class="trend-card-top-header">
            <div>
                <h2 class="trend-card-heading-title">Six-Month Trend</h2>
                <p class="trend-card-heading-subtitle">Gross vs. Net payroll comparison over the past 6 months</p>
            </div>
            <div class="trend-chart-legend">
                <span class="legend-item"><i class="legend-swatch gross-payroll"></i> Gross Payroll</span>
                <span class="legend-item"><i class="legend-swatch net-payroll"></i> Net Payroll</span>
            </div>
        </div>

        <div class="payroll-analytics-chart-container">
            <div class="chart-vertical-axis" aria-hidden="true">
                <?php foreach ($chart_gridline_labels as $axis_label): ?>
                    <span><?php echo e($axis_label); ?></span>
                <?php endforeach; ?>
            </div>

            <div class="chart-main-plot-area">
                <div class="chart-horizontal-gridlines" aria-hidden="true">
                    <?php foreach ($chart_gridline_amounts as $gridline_amount): ?>
                        <div class="horizontal-gridline<?php echo $gridline_amount == 0 ? ' baseline' : ''; ?>"></div>
                    <?php endforeach; ?>
                </div>

                <div class="chart-months-bar-groups">
                    <?php foreach ($trend as $month_key => $point): 
                        $is_zero_gross = ($point['gross'] <= 0);
                        $is_zero_net = ($point['net'] <= 0);
                        $gross_percentage_height = $is_zero_gross ? 0 : max(2, round(($point['gross'] / $chart_ceiling_amount) * 100));
                        $net_percentage_height = $is_zero_net ? 0 : max(2, round(($point['net'] / $chart_ceiling_amount) * 100));
                        $is_both_zero = ($is_zero_gross && $is_zero_net);
                        $is_active_month = ($month_key === $month);
                    ?>
                    <div class="month-bar-group<?php echo $is_both_zero ? ' has-zero-payroll' : ''; ?>"
                         tabindex="0"
                         role="group"
                         aria-label="<?php echo e($point['full_name']); ?>: Gross <?php echo money($point['gross']); ?>, Net <?php echo money($point['net']); ?>"
                         data-month-name="<?php echo e($point['full_name']); ?>"
                         data-gross-formatted="<?php echo money($point['gross']); ?>"
                         data-net-formatted="<?php echo money($point['net']); ?>">
                        <div class="bars-side-by-side-wrapper">
                            <div class="single-payroll-bar gross-payroll-bar<?php echo $is_zero_gross ? ' is-zero-value' : ''; ?>"
                                 style="height:<?php echo $gross_percentage_height; ?>%;"></div>
                            <div class="single-payroll-bar net-payroll-bar<?php echo $is_zero_net ? ' is-zero-value' : ''; ?>"
                                 style="height:<?php echo $net_percentage_height; ?>%;"></div>
                        </div>
                        <span class="chart-month-bottom-label<?php echo $is_active_month ? ' is-current-selected-month' : ''; ?>">
                            <?php echo e($point['label']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="payroll-chart-floating-tooltip" role="tooltip" aria-hidden="true">
                <div class="tooltip-month-title">Month Year</div>
                <div class="tooltip-data-row">
                    <span class="tooltip-label-wrapper"><i class="tooltip-color-indicator gross"></i> Gross Payroll:</span>
                    <strong class="tooltip-amount-value tooltip-gross-value">₱ 0.00</strong>
                </div>
                <div class="tooltip-data-row">
                    <span class="tooltip-label-wrapper"><i class="tooltip-color-indicator net"></i> Net Payroll:</span>
                    <strong class="tooltip-amount-value tooltip-net-value">₱ 0.00</strong>
                </div>
            </div>
        </div>
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
