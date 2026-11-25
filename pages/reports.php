<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$selected_month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
$month_start_date = $selected_month . '-01';
$month_end_date = date('Y-m-t', strtotime($month_start_date));

$month_records_statement = $conn->prepare(
    'SELECT p.*, e.employee_no, e.first_name, e.last_name, e.department, e.position
     FROM payroll_records p
     JOIN employees e ON e.employee_id = p.employee_id
     WHERE p.period_start <= ? AND p.period_end >= ?
     ORDER BY p.period_end DESC, p.payroll_id DESC'
);
$month_records_statement->bind_param('ss', $month_end_date, $month_start_date);
$month_records_statement->execute();
$month_records_result = $month_records_statement->get_result();

$selected_month_records = [];
$selected_month_basic_total = 0.0;
$selected_month_gross_total = 0.0;
$selected_month_deductions_total = 0.0;
$selected_month_net_total = 0.0;
$selected_month_paid_count = 0;
$department_payroll_totals = [];
$department_record_counts = [];

while ($payroll_row = $month_records_result->fetch_assoc()) {
    $selected_month_records[] = $payroll_row;
    $selected_month_basic_total += (float)$payroll_row['basic_salary'];
    $selected_month_gross_total += (float)$payroll_row['gross_pay'];
    $selected_month_deductions_total += (float)$payroll_row['deductions'];
    $selected_month_net_total += (float)$payroll_row['net_pay'];
    if ($payroll_row['status'] === 'Paid') {
        $selected_month_paid_count++;
    }

    $department_label = $payroll_row['department'] ?: 'Unassigned';
    $department_payroll_totals[$department_label] = ($department_payroll_totals[$department_label] ?? 0.0) + (float)$payroll_row['net_pay'];
    $department_record_counts[$department_label] = ($department_record_counts[$department_label] ?? 0) + 1;
}
arsort($department_payroll_totals);

if (isset($_GET['print_sheet_only']) && $_GET['print_sheet_only'] === '1') {
    ?>
    <div class="print-sheet-header">
        <div class="print-sheet-brand">LIPA CITY COLLEGES</div>
        <div class="print-sheet-title">MONTHLY PAYROLL REPORT</div>
        <div class="print-sheet-subtitle"><?php echo e(date('F Y', strtotime($month_start_date))); ?> · Payroll Period Register</div>
    </div>
    <div class="print-sheet-meta">
        <span>Coverage: <?php echo e(date('M 01, Y', strtotime($month_start_date))); ?> – <?php echo e(date('M d, Y', strtotime($month_end_date))); ?></span>
        <span>Generated: <?php echo e(date('M d, Y')); ?></span>
    </div>
    <div class="print-sheet-summary">
        <div><span>Payroll Records</span><strong><?php echo count($selected_month_records); ?></strong></div>
        <div><span>Basic Salary</span><strong><?php echo money($selected_month_basic_total); ?></strong></div>
        <div><span>Gross Payroll</span><strong><?php echo money($selected_month_gross_total); ?></strong></div>
        <div><span>Deductions</span><strong><?php echo money($selected_month_deductions_total); ?></strong></div>
        <div><span>Net Payroll</span><strong><?php echo money($selected_month_net_total); ?></strong></div>
    </div>
    <table class="print-sheet-table">
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Employee</th>
                <th>Department</th>
                <th>Pay Period</th>
                <th>Basic Salary</th>
                <th>Gross</th>
                <th>Deductions</th>
                <th>Net</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$selected_month_records): ?>
            <tr><td colspan="9" class="print-empty">No payroll records overlap <?php echo e(date('F Y', strtotime($month_start_date))); ?>.</td></tr>
        <?php else: foreach ($selected_month_records as $record_item): ?>
            <tr>
                <td><?php echo e($record_item['employee_no']); ?></td>
                <td><?php echo e($record_item['last_name'] . ', ' . $record_item['first_name']); ?></td>
                <td><?php echo e($record_item['department'] ?: 'Unassigned'); ?></td>
                <td><?php echo e(date('M d', strtotime($record_item['period_start'])) . ' – ' . date('M d, Y', strtotime($record_item['period_end']))); ?></td>
                <td class="num"><?php echo money($record_item['basic_salary']); ?></td>
                <td class="num"><?php echo money($record_item['gross_pay']); ?></td>
                <td class="num"><?php echo money($record_item['deductions']); ?></td>
                <td class="num"><?php echo money($record_item['net_pay']); ?></td>
                <td class="status"><?php echo e($record_item['status']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="print-sheet-breakdown">
        <h3>Payroll by Department</h3>
        <div class="print-dept-grid">
            <?php foreach ($department_payroll_totals as $department_name => $department_net_amount): ?>
                <div><span><?php echo e($department_name); ?> (<?php echo (int)($department_record_counts[$department_name] ?? 0); ?>)</span><strong><?php echo money($department_net_amount); ?></strong></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="print-sheet-sign"><span>Prepared by: ______________________________</span><span>Date: <?php echo e(date('M d, Y')); ?></span></div>
    <div class="print-sheet-footer">Private and Confidential · Lipa City Colleges</div>
    <?php
    exit;
}

// Per-month directory summary across all recorded months (plus selected 6-month window)
$monthly_summaries = [];
for ($month_offset = 5; $month_offset >= 0; $month_offset--) {
    $month_key = date('Y-m', strtotime("first day of -{$month_offset} month", strtotime($month_start_date)));
    $monthly_summaries[$month_key] = [
        'month_key' => $month_key,
        'short_label' => date('M Y', strtotime($month_key . '-01')),
        'full_label' => date('F Y', strtotime($month_key . '-01')),
        'record_count' => 0,
        'paid_count' => 0,
        'basic_total' => 0.0,
        'gross_total' => 0.0,
        'deductions_total' => 0.0,
        'net_total' => 0.0,
    ];
}

$all_months_query = $conn->query(
    "SELECT
        DATE_FORMAT(period_end, '%Y-%m') AS ym,
        COUNT(*) AS record_count,
        SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
        COALESCE(SUM(basic_salary), 0) AS basic_total,
        COALESCE(SUM(gross_pay), 0) AS gross_total,
        COALESCE(SUM(deductions), 0) AS deductions_total,
        COALESCE(SUM(net_pay), 0) AS net_total
     FROM payroll_records
     GROUP BY ym
     ORDER BY ym DESC"
);

if ($all_months_query) {
    while ($summary_row = $all_months_query->fetch_assoc()) {
        $month_key = $summary_row['ym'];
        if (!$month_key || !preg_match('/^\d{4}-\d{2}$/', $month_key)) {
            continue;
        }
        $monthly_summaries[$month_key] = [
            'month_key' => $month_key,
            'short_label' => date('M Y', strtotime($month_key . '-01')),
            'full_label' => date('F Y', strtotime($month_key . '-01')),
            'record_count' => (int)$summary_row['record_count'],
            'paid_count' => (int)$summary_row['paid_count'],
            'basic_total' => (float)$summary_row['basic_total'],
            'gross_total' => (float)$summary_row['gross_total'],
            'deductions_total' => (float)$summary_row['deductions_total'],
            'net_total' => (float)$summary_row['net_total'],
        ];
    }
}
krsort($monthly_summaries);

// Curated harmonious executive colorway for pie graphs
$pie_color_palette = [
    '#0F766E', // Deep Brand Teal
    '#2563EB', // Royal Sapphire
    '#7C3AED', // Muted Violet
    '#D97706', // Warm Amber
    '#059669', // Emerald Green
    '#DC2626', // Crimson Coral
    '#0891B2', // Ocean Cyan
    '#4F46E5', // Indigo Slate
];

function build_svg_pie_slices(array $items, array $palette): array
{
    $total_value = 0.0;
    foreach ($items as $item) {
        $total_value += max(0.0, (float)$item['value']);
    }
    if ($total_value <= 0.0) {
        return ['total' => 0.0, 'slices' => []];
    }

    $slices = [];
    $cumulative_angle = -90.0;
    $center_x = 110.0;
    $center_y = 110.0;
    $radius = 96.0;
    $palette_count = count($palette);

    $positive_items = array_values(array_filter($items, function ($item) {
        return (float)$item['value'] > 0.0;
    }));

    foreach ($positive_items as $index => $item) {
        $value = (float)$item['value'];
        $percentage = ($value / $total_value) * 100.0;
        $sweep_angle = ($value / $total_value) * 360.0;
        $color = $palette[$index % $palette_count];

        $is_full_circle = count($positive_items) === 1 || $sweep_angle >= 359.99;
        $path_data = '';
        if (!$is_full_circle) {
            $start_rad = deg2rad($cumulative_angle);
            $end_rad = deg2rad($cumulative_angle + $sweep_angle);
            $x1 = round($center_x + $radius * cos($start_rad), 3);
            $y1 = round($center_y + $radius * sin($start_rad), 3);
            $x2 = round($center_x + $radius * cos($end_rad), 3);
            $y2 = round($center_y + $radius * sin($end_rad), 3);
            $large_arc_flag = $sweep_angle > 180.0 ? 1 : 0;
            $path_data = "M {$center_x} {$center_y} L {$x1} {$y1} A {$radius} {$radius} 0 {$large_arc_flag} 1 {$x2} {$y2} Z";
        }

        $slices[] = [
            'label' => $item['label'],
            'sublabel' => $item['sublabel'] ?? '',
            'href' => $item['href'] ?? '',
            'value' => $value,
            'percentage' => round($percentage, 1),
            'color' => $color,
            'is_full_circle' => $is_full_circle,
            'path' => $path_data,
        ];
        $cumulative_angle += $sweep_angle;
    }

    return ['total' => $total_value, 'slices' => $slices];
}

// Build Pie Chart 1: Six-Month Net Payroll Share
$six_month_pie_inputs = [];
for ($month_offset = 5; $month_offset >= 0; $month_offset--) {
    $month_key = date('Y-m', strtotime("first day of -{$month_offset} month", strtotime($month_start_date)));
    $entry = $monthly_summaries[$month_key] ?? null;
    if ($entry && $entry['net_total'] > 0) {
        $six_month_pie_inputs[] = [
            'label' => $entry['full_label'],
            'sublabel' => $entry['record_count'] . ' record' . ($entry['record_count'] === 1 ? '' : 's'),
            'href' => 'reports.php?month=' . urlencode($month_key) . '#month-register',
            'value' => $entry['net_total'],
        ];
    }
}
$monthly_pie_chart = build_svg_pie_slices($six_month_pie_inputs, $pie_color_palette);

// Build Pie Chart 2: Selected Month Payroll by Department
$department_pie_inputs = [];
foreach ($department_payroll_totals as $department_name => $department_net_amount) {
    if ($department_net_amount > 0) {
        $dept_count = (int)($department_record_counts[$department_name] ?? 0);
        $department_pie_inputs[] = [
            'label' => $department_name,
            'sublabel' => $dept_count . ' employee' . ($dept_count === 1 ? '' : 's'),
            'value' => $department_net_amount,
        ];
    }
}
$department_pie_chart = build_svg_pie_slices($department_pie_inputs, $pie_color_palette);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Reports</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
    <style>
        .pie-analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .pie-card-body {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 24px;
            padding: 18px 22px 22px;
        }
        .pie-svg-wrap {
            position: relative;
            width: 220px;
            height: 220px;
            flex: 0 0 220px;
            margin: 0 auto;
        }
        .pie-svg-wrap svg {
            width: 100%;
            height: 100%;
            overflow: visible;
        }
        .pie-slice {
            cursor: pointer;
            transition: transform 0.18s ease, opacity 0.18s ease, filter 0.18s ease;
            transform-origin: 110px 110px;
        }
        .pie-slice:hover {
            transform: scale(1.035);
            filter: drop-shadow(0 4px 10px rgba(15, 23, 42, 0.18));
        }
        .pie-center-callout {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            text-align: center;
            padding: 36px;
        }
        .pie-center-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.45px;
            color: var(--text-muted, #64748b);
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .pie-center-value {
            font-size: 15px;
            font-weight: 800;
            color: var(--text, #0f172a);
            margin-top: 2px;
        }
        .pie-center-pct {
            font-size: 11px;
            font-weight: 600;
            color: #0F766E;
            margin-top: 1px;
        }
        .pie-legend-list {
            flex: 1;
            min-width: 190px;
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .pie-legend-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 8px;
            border-radius: 8px;
            transition: background 0.15s ease;
            text-decoration: none;
            color: inherit;
        }
        .pie-legend-row:hover {
            background: var(--bg-hover, #f1f5f9);
        }
        .pie-legend-left {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
        }
        .pie-swatch {
            width: 11px;
            height: 11px;
            border-radius: 3px;
            flex: 0 0 11px;
        }
        .pie-legend-name {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text, #0f172a);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pie-legend-sub {
            font-size: 11px;
            color: var(--text-muted, #64748b);
        }
        .pie-legend-right {
            text-align: right;
            white-space: nowrap;
        }
        .pie-legend-amount {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text, #0f172a);
        }
        .pie-legend-pct {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted, #64748b);
        }
        .month-row-active {
            background: rgba(15, 118, 110, 0.06);
        }
        .print-sheet-summary {
            grid-template-columns: repeat(5, 1fr) !important;
        }
        @media print {
            body .app {
                display: none !important;
            }
            #reportPrintSheet {
                display: block !important;
                position: static !important;
                visibility: visible !important;
                width: 100% !important;
            }
            #reportPrintSheet * {
                visibility: visible !important;
            }
        }
    </style>
</head>
<body>
<div class="app">
    <?php sidebar('reports'); ?>
    <main class="main">
        <?php topbar('Reports'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Monthly Payroll Reports</h1>
                    <p>Viewing <?php echo e(date('F Y', strtotime($month_start_date))); ?> · <?php echo count($selected_month_records); ?> record<?php echo count($selected_month_records) === 1 ? '' : 's'; ?></p>
                </div>
                <div class="print-tools">
                    <button type="button" class="btn btn-primary" onclick="printMonthlyReport('<?php echo e($selected_month); ?>')">
                        <?php echo ui_icon('printer'); ?> Print <?php echo e(date('M Y', strtotime($month_start_date))); ?>
                    </button>
                </div>
            </div>

            <section class="card report-filter-card">
                <form class="report-filter" method="get">
                    <div>
                        <label>Jump to Month</label>
                        <div class="input-icon-wrap">
                            <?php echo ui_icon('calendar'); ?>
                            <input type="month" name="month" value="<?php echo e($selected_month); ?>">
                        </div>
                    </div>
                    <button class="btn btn-secondary" type="submit">View Month</button>
                </form>
                <div class="report-filter-note">
                    <?php echo e(date('F Y', strtotime($month_start_date))); ?> · <?php echo count($selected_month_records); ?> payroll record<?php echo count($selected_month_records) === 1 ? '' : 's'; ?>
                </div>
            </section>

            <section class="report-stats">
                <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('users'); ?></span><div><small>Records</small><strong><?php echo count($selected_month_records); ?></strong></div></div>
                <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('trend'); ?></span><div><small>Gross Payroll</small><strong><?php echo money($selected_month_gross_total); ?></strong></div></div>
                <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('wallet'); ?></span><div><small>Net Payroll</small><strong><?php echo money($selected_month_net_total); ?></strong></div></div>
                <div class="card report-stat"><span class="report-stat-icon"><?php echo ui_icon('shield'); ?></span><div><small>Paid</small><strong><?php echo $selected_month_paid_count; ?></strong></div></div>
            </section>

            <!-- Pie Graph Analytics Section (Replaces Bar Graphs) -->
            <section class="pie-analytics-grid" aria-label="Payroll Distribution Pie Graphs">
                <!-- Pie Chart 1: 6-Month Payroll Share -->
                <article class="card">
                    <div class="card-head">
                        <div>
                            <h2>Six-Month Payroll Share</h2>
                            <p>Net payroll distribution across recent months (click a month to view)</p>
                        </div>
                    </div>
                    <div class="pie-card-body">
                        <?php if (empty($monthly_pie_chart['slices'])): ?>
                            <div class="empty" style="width:100%;">No payroll totals recorded across this 6-month window.</div>
                        <?php else: ?>
                            <div class="pie-svg-wrap" data-pie-container="monthly">
                                <svg viewBox="0 0 220 220" role="img" aria-label="Six-month payroll pie graph">
                                    <?php foreach ($monthly_pie_chart['slices'] as $slice): ?>
                                        <?php if ($slice['is_full_circle']): ?>
                                            <circle class="pie-slice" cx="110" cy="110" r="96"
                                                    fill="<?php echo e($slice['color']); ?>"
                                                    stroke="#ffffff" stroke-width="2"
                                                    data-label="<?php echo e($slice['label']); ?>"
                                                    data-amount="<?php echo e(money($slice['value'])); ?>"
                                                    data-pct="<?php echo e($slice['percentage']); ?>%"
                                                    data-href="<?php echo e($slice['href']); ?>" />
                                        <?php else: ?>
                                            <path class="pie-slice" d="<?php echo e($slice['path']); ?>"
                                                  fill="<?php echo e($slice['color']); ?>"
                                                  stroke="#ffffff" stroke-width="2"
                                                  data-label="<?php echo e($slice['label']); ?>"
                                                  data-amount="<?php echo e(money($slice['value'])); ?>"
                                                  data-pct="<?php echo e($slice['percentage']); ?>%"
                                                  data-href="<?php echo e($slice['href']); ?>" />
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <circle cx="110" cy="110" r="56" fill="var(--surface-1, #ffffff)" />
                                </svg>
                                <div class="pie-center-callout" data-default-label="6-Mo Net" data-default-amount="<?php echo e(money($monthly_pie_chart['total'])); ?>" data-default-pct="100%">
                                    <span class="pie-center-label">6-Mo Net</span>
                                    <span class="pie-center-value"><?php echo e(money($monthly_pie_chart['total'])); ?></span>
                                    <span class="pie-center-pct">100%</span>
                                </div>
                            </div>
                            <div class="pie-legend-list">
                                <?php foreach ($monthly_pie_chart['slices'] as $slice): ?>
                                    <a class="pie-legend-row" href="<?php echo e($slice['href']); ?>"
                                       data-pie-target="monthly"
                                       data-label="<?php echo e($slice['label']); ?>"
                                       data-amount="<?php echo e(money($slice['value'])); ?>"
                                       data-pct="<?php echo e($slice['percentage']); ?>%">
                                        <div class="pie-legend-left">
                                            <span class="pie-swatch" style="background:<?php echo e($slice['color']); ?>;"></span>
                                            <div style="min-width:0;">
                                                <div class="pie-legend-name"><?php echo e($slice['label']); ?></div>
                                                <div class="pie-legend-sub"><?php echo e($slice['sublabel']); ?></div>
                                            </div>
                                        </div>
                                        <div class="pie-legend-right">
                                            <div class="pie-legend-amount"><?php echo money($slice['value']); ?></div>
                                            <div class="pie-legend-pct"><?php echo e($slice['percentage']); ?>%</div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- Pie Chart 2: Selected Month Payroll by Department -->
                <article class="card">
                    <div class="card-head">
                        <div>
                            <h2><?php echo e(date('F Y', strtotime($month_start_date))); ?> by Department</h2>
                            <p>Net payroll share per college / department</p>
                        </div>
                    </div>
                    <div class="pie-card-body">
                        <?php if (empty($department_pie_chart['slices'])): ?>
                            <div class="empty" style="width:100%;">No department payroll data for <?php echo e(date('F Y', strtotime($month_start_date))); ?>.</div>
                        <?php else: ?>
                            <div class="pie-svg-wrap" data-pie-container="department">
                                <svg viewBox="0 0 220 220" role="img" aria-label="Department payroll pie graph">
                                    <?php foreach ($department_pie_chart['slices'] as $slice): ?>
                                        <?php if ($slice['is_full_circle']): ?>
                                            <circle class="pie-slice" cx="110" cy="110" r="96"
                                                    fill="<?php echo e($slice['color']); ?>"
                                                    stroke="#ffffff" stroke-width="2"
                                                    data-label="<?php echo e($slice['label']); ?>"
                                                    data-amount="<?php echo e(money($slice['value'])); ?>"
                                                    data-pct="<?php echo e($slice['percentage']); ?>%" />
                                        <?php else: ?>
                                            <path class="pie-slice" d="<?php echo e($slice['path']); ?>"
                                                  fill="<?php echo e($slice['color']); ?>"
                                                  stroke="#ffffff" stroke-width="2"
                                                  data-label="<?php echo e($slice['label']); ?>"
                                                  data-amount="<?php echo e(money($slice['value'])); ?>"
                                                  data-pct="<?php echo e($slice['percentage']); ?>%" />
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <circle cx="110" cy="110" r="56" fill="var(--surface-1, #ffffff)" />
                                </svg>
                                <div class="pie-center-callout" data-default-label="Month Net" data-default-amount="<?php echo e(money($department_pie_chart['total'])); ?>" data-default-pct="100%">
                                    <span class="pie-center-label">Month Net</span>
                                    <span class="pie-center-value"><?php echo e(money($department_pie_chart['total'])); ?></span>
                                    <span class="pie-center-pct">100%</span>
                                </div>
                            </div>
                            <div class="pie-legend-list">
                                <?php foreach ($department_pie_chart['slices'] as $slice): ?>
                                    <div class="pie-legend-row"
                                         data-pie-target="department"
                                         data-label="<?php echo e($slice['label']); ?>"
                                         data-amount="<?php echo e(money($slice['value'])); ?>"
                                         data-pct="<?php echo e($slice['percentage']); ?>%">
                                        <div class="pie-legend-left">
                                            <span class="pie-swatch" style="background:<?php echo e($slice['color']); ?>;"></span>
                                            <div style="min-width:0;">
                                                <div class="pie-legend-name" title="<?php echo e($slice['label']); ?>"><?php echo e($slice['label']); ?></div>
                                                <div class="pie-legend-sub"><?php echo e($slice['sublabel']); ?></div>
                                            </div>
                                        </div>
                                        <div class="pie-legend-right">
                                            <div class="pie-legend-amount"><?php echo money($slice['value']); ?></div>
                                            <div class="pie-legend-pct"><?php echo e($slice['percentage']); ?>%</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>

            <!-- Per-Month Summary Directory with Per-Month View and Per-Month Print -->
            <section class="card" style="margin-bottom: 20px;">
                <div class="card-head">
                    <div>
                        <h2>Per-Month Reports</h2>
                        <p>View or print any month's payroll register directly</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Records</th>
                                <th>Basic Salary</th>
                                <th>Gross Payroll</th>
                                <th>Deductions</th>
                                <th>Net Payroll</th>
                                <th>Paid</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($monthly_summaries as $month_key => $month_summary): ?>
                            <?php $is_current_month = ($month_key === $selected_month); ?>
                            <tr class="<?php echo $is_current_month ? 'month-row-active' : ''; ?>">
                                <td>
                                    <strong><?php echo e($month_summary['full_label']); ?></strong>
                                    <?php if ($is_current_month): ?>
                                        <span class="badge" style="margin-left:6px;background:rgba(15,118,110,0.12);color:#0F766E;">Viewing</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$month_summary['record_count']; ?></td>
                                <td><?php echo money($month_summary['basic_total']); ?></td>
                                <td><?php echo money($month_summary['gross_total']); ?></td>
                                <td style="color:var(--err-text,#dc2626);"><?php echo money($month_summary['deductions_total']); ?></td>
                                <td><strong><?php echo money($month_summary['net_total']); ?></strong></td>
                                <td><?php echo (int)$month_summary['paid_count']; ?> / <?php echo (int)$month_summary['record_count']; ?></td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <a href="reports.php?month=<?php echo e($month_key); ?>#month-register" class="btn btn-small <?php echo $is_current_month ? 'btn-primary' : 'btn-secondary'; ?>">
                                        <?php echo ui_icon('eye'); ?> View
                                    </a>
                                    <button type="button" class="btn btn-small btn-secondary" onclick="printMonthlyReport('<?php echo e($month_key); ?>')" title="Print <?php echo e($month_summary['full_label']); ?> report">
                                        <?php echo ui_icon('printer'); ?> Print
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Selected Month Detailed Register -->
            <section class="card report-table-card" id="month-register">
                <div class="card-head">
                    <div>
                        <h2><?php echo e(date('F Y', strtotime($month_start_date))); ?> Detailed Register</h2>
                        <p>Itemized employee payroll records overlapping this month</p>
                    </div>
                    <button type="button" class="btn btn-small btn-secondary" title="Print monthly report" aria-label="Print monthly report" onclick="printMonthlyReport('<?php echo e($selected_month); ?>')">
                        <?php echo ui_icon('printer'); ?> Print Month
                    </button>
                </div>
                <div class="table-wrap">
                    <table class="table report-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Basic Salary</th>
                                <th>Gross</th>
                                <th>Deductions</th>
                                <th>Net</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$selected_month_records): ?>
                            <tr><td colspan="8" class="empty">No payroll records overlap <?php echo e(date('F Y', strtotime($month_start_date))); ?>.</td></tr>
                        <?php else: foreach ($selected_month_records as $record_item): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($record_item['last_name'] . ', ' . $record_item['first_name']); ?></strong>
                                    <div class="mini"><?php echo e($record_item['employee_no']); ?></div>
                                </td>
                                <td><?php echo e($record_item['department'] ?: 'Unassigned'); ?></td>
                                <td><?php echo e(date('M d', strtotime($record_item['period_start'])) . ' – ' . date('M d, Y', strtotime($record_item['period_end']))); ?></td>
                                <td><?php echo money($record_item['basic_salary']); ?></td>
                                <td><?php echo money($record_item['gross_pay']); ?></td>
                                <td style="color:var(--err-text,#dc2626);"><?php echo money($record_item['deductions']); ?></td>
                                <td><strong><?php echo money($record_item['net_pay']); ?></strong></td>
                                <td><span class="badge"><?php echo e($record_item['status']); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<div id="reportPrintSheet" class="report-print-sheet">
    <div class="print-sheet-header">
        <div class="print-sheet-brand">LIPA CITY COLLEGES</div>
        <div class="print-sheet-title">MONTHLY PAYROLL REPORT</div>
        <div class="print-sheet-subtitle"><?php echo e(date('F Y', strtotime($month_start_date))); ?> · Payroll Period Register</div>
    </div>
    <div class="print-sheet-meta">
        <span>Coverage: <?php echo e(date('M 01, Y', strtotime($month_start_date))); ?> – <?php echo e(date('M d, Y', strtotime($month_end_date))); ?></span>
        <span>Generated: <?php echo e(date('M d, Y')); ?></span>
    </div>
    <div class="print-sheet-summary">
        <div><span>Payroll Records</span><strong><?php echo count($selected_month_records); ?></strong></div>
        <div><span>Basic Salary</span><strong><?php echo money($selected_month_basic_total); ?></strong></div>
        <div><span>Gross Payroll</span><strong><?php echo money($selected_month_gross_total); ?></strong></div>
        <div><span>Deductions</span><strong><?php echo money($selected_month_deductions_total); ?></strong></div>
        <div><span>Net Payroll</span><strong><?php echo money($selected_month_net_total); ?></strong></div>
    </div>
    <table class="print-sheet-table">
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Employee</th>
                <th>Department</th>
                <th>Pay Period</th>
                <th>Basic Salary</th>
                <th>Gross</th>
                <th>Deductions</th>
                <th>Net</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$selected_month_records): ?>
            <tr><td colspan="9" class="print-empty">No payroll records overlap this month.</td></tr>
        <?php else: foreach ($selected_month_records as $record_item): ?>
            <tr>
                <td><?php echo e($record_item['employee_no']); ?></td>
                <td><?php echo e($record_item['last_name'] . ', ' . $record_item['first_name']); ?></td>
                <td><?php echo e($record_item['department'] ?: 'Unassigned'); ?></td>
                <td><?php echo e(date('M d', strtotime($record_item['period_start'])) . ' – ' . date('M d, Y', strtotime($record_item['period_end']))); ?></td>
                <td class="num"><?php echo money($record_item['basic_salary']); ?></td>
                <td class="num"><?php echo money($record_item['gross_pay']); ?></td>
                <td class="num"><?php echo money($record_item['deductions']); ?></td>
                <td class="num"><?php echo money($record_item['net_pay']); ?></td>
                <td class="status"><?php echo e($record_item['status']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="print-sheet-breakdown">
        <h3>Payroll by Department</h3>
        <div class="print-dept-grid">
            <?php foreach ($department_payroll_totals as $department_name => $department_net_amount): ?>
                <div><span><?php echo e($department_name); ?> (<?php echo (int)($department_record_counts[$department_name] ?? 0); ?>)</span><strong><?php echo money($department_net_amount); ?></strong></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="print-sheet-sign"><span>Prepared by: ______________________________</span><span>Date: <?php echo e(date('M d, Y')); ?></span></div>
    <div class="print-sheet-footer">Private and Confidential · Lipa City Colleges</div>
</div>

<script>
const activeReportMonth = <?php echo json_encode($selected_month); ?>;

async function printMonthlyReport(targetMonth) {
    const printSheet = document.getElementById('reportPrintSheet');
    if (!printSheet) return;

    if (!targetMonth || targetMonth === activeReportMonth) {
        window.print();
        return;
    }

    const originalHtml = printSheet.innerHTML;
    try {
        const response = await fetch('reports.php?month=' + encodeURIComponent(targetMonth) + '&print_sheet_only=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Unable to load monthly report');
        printSheet.innerHTML = await response.text();
        window.addEventListener('afterprint', function () {
            printSheet.innerHTML = originalHtml;
        }, { once: true });
        window.print();
    } catch (error) {
        printSheet.innerHTML = originalHtml;
        alert('Unable to prepare the monthly payroll report for printing.');
    }
}

document.querySelectorAll('.pie-svg-wrap').forEach(function (container) {
    const callout = container.querySelector('.pie-center-callout');
    if (!callout) return;
    const labelEl = callout.querySelector('.pie-center-label');
    const valueEl = callout.querySelector('.pie-center-value');
    const pctEl = callout.querySelector('.pie-center-pct');
    const pieName = container.dataset.pieContainer;

    function showDetails(el) {
        labelEl.textContent = el.dataset.label || callout.dataset.defaultLabel;
        valueEl.textContent = el.dataset.amount || callout.dataset.defaultAmount;
        pctEl.textContent = el.dataset.pct || callout.dataset.defaultPct;
    }

    function resetDetails() {
        labelEl.textContent = callout.dataset.defaultLabel;
        valueEl.textContent = callout.dataset.defaultAmount;
        pctEl.textContent = callout.dataset.defaultPct;
    }

    container.querySelectorAll('.pie-slice').forEach(function (slice) {
        slice.addEventListener('mouseenter', function () { showDetails(slice); });
        slice.addEventListener('mouseleave', resetDetails);
        slice.addEventListener('click', function () {
            if (slice.dataset.href) {
                window.location.href = slice.dataset.href;
            }
        });
    });

    document.querySelectorAll('[data-pie-target="' + pieName + '"]').forEach(function (legendRow) {
        legendRow.addEventListener('mouseenter', function () { showDetails(legendRow); });
        legendRow.addEventListener('mouseleave', resetDetails);
    });
});
</script>
</body>
</html>
