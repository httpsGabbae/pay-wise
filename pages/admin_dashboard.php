<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$logged_in_admin_name = $_SESSION['admin_name'] ?? 'Admin';
$current_system_role = admin_role();

// 1. Core Employee and Directory Statistics
$total_employees_query = $conn->query("SELECT COUNT(*) AS total_count FROM employees");
$total_employees_count = (int)$total_employees_query->fetch_assoc()['total_count'];

$active_employees_query = $conn->query("SELECT COUNT(*) AS active_count FROM employees WHERE COALESCE(employment_status, '') NOT IN ('Separated', 'Inactive')");
$active_employees_count = (int)$active_employees_query->fetch_assoc()['active_count'];

// 2. Average Salary Calculation Across Salaried Staff
$salary_stats_query = $conn->query("SELECT COUNT(*) AS salaried_count, COALESCE(AVG(basic_salary), 0) AS average_salary FROM employees WHERE basic_salary > 0");
$salary_stats = $salary_stats_query->fetch_assoc();
$salaried_employees_count = (int)$salary_stats['salaried_count'];
$average_monthly_salary = (float)$salary_stats['average_salary'];

// 3. Outstanding Financial Liabilities (Active loans and draft payroll)
$active_loans_query = $conn->query("SELECT COUNT(*) AS loan_count, COALESCE(SUM(remaining_balance), 0) AS loan_balance FROM employee_loans WHERE status = 'Active'");
$active_loans_data = $active_loans_query->fetch_assoc();
$active_loans_count = (int)$active_loans_data['loan_count'];
$active_loans_balance = (float)$active_loans_data['loan_balance'];

$draft_payroll_query = $conn->query("SELECT COALESCE(SUM(net_pay), 0) AS draft_total FROM payroll_records WHERE status = 'Draft'");
$draft_payroll_liability = (float)$draft_payroll_query->fetch_assoc()['draft_total'];

$total_outstanding_liability = $active_loans_balance + $draft_payroll_liability;

// 4. Pending Employee Requests & Exceptions
$attendance_exceptions_query = $conn->query("SELECT COUNT(*) AS exceptions_count FROM attendance WHERE status = 'Late' OR time_out IS NULL");
$attendance_exceptions_count = (int)$attendance_exceptions_query->fetch_assoc()['exceptions_count'];

$total_pending_requests_count = $attendance_exceptions_count + $active_loans_count;

// 5. Payroll History Summaries
$payout_summary_query = $conn->query("SELECT COALESCE(SUM(CASE WHEN status IN ('Approved', 'Paid') THEN net_pay ELSE 0 END), 0) AS total_paid, COALESCE(SUM(CASE WHEN status = 'Draft' THEN net_pay ELSE 0 END), 0) AS delayed_paid FROM payroll_records");
$payout_summary_data = $payout_summary_query->fetch_assoc();
$total_payroll_payout_amount = (float)$payout_summary_data['total_paid'];
$delayed_payroll_payout_amount = (float)$payout_summary_data['delayed_paid'];

// 6. Dynamic Real Payroll History Periods for Bar Chart
$periods_history_query = $conn->query("
    SELECT 
        COALESCE(pp.period_code, CONCAT(DATE_FORMAT(pr.period_start, '%b %d'), ' - ', DATE_FORMAT(pr.period_end, '%b %d'))) AS period_name,
        DATE_FORMAT(pr.period_start, '%d %b') AS short_date,
        COALESCE(SUM(pr.gross_pay), 0) AS total_gross,
        COALESCE(SUM(pr.deductions), 0) AS total_deductions,
        COALESCE(SUM(pr.net_pay), 0) AS total_net,
        COUNT(*) AS record_count
    FROM payroll_records pr
    LEFT JOIN payroll_periods pp ON pr.period_id = pp.period_id
    GROUP BY pr.period_start, pr.period_end
    ORDER BY pr.period_start ASC
    LIMIT 8
");

$payroll_history_bars = [];
$highest_payroll_amount = 1000.0;
if ($periods_history_query) {
    while ($period_record = $periods_history_query->fetch_assoc()) {
        $gross_val = (float)$period_record['total_gross'];
        $net_val = (float)$period_record['total_net'];
        if ($gross_val > $highest_payroll_amount) $highest_payroll_amount = $gross_val;
        if ($net_val > $highest_payroll_amount) $highest_payroll_amount = $net_val;
        $payroll_history_bars[] = $period_record;
    }
}

// 7. Dynamic Real Requests Timeline (7 recorded or recent days)
$latest_attendance_date_query = $conn->query("SELECT MAX(attendance_date) AS max_date FROM attendance");
$latest_recorded_date = $latest_attendance_date_query->fetch_assoc()['max_date'] ?: date('Y-m-d');

$request_timeline_points = [];
$highest_request_daily_count = 1;
for ($day_offset = 6; $day_offset >= 0; $day_offset--) {
    $current_date_str = date('Y-m-d', strtotime("$latest_recorded_date -$day_offset days"));
    $date_short_label = date('d M', strtotime($current_date_str));
    
    $daily_count_stmt = $conn->prepare("SELECT COUNT(*) AS daily_exceptions FROM attendance WHERE attendance_date = ? AND (status = 'Late' OR time_out IS NULL)");
    $daily_count_stmt->bind_param('s', $current_date_str);
    $daily_count_stmt->execute();
    $daily_exceptions_count = (int)$daily_count_stmt->get_result()->fetch_assoc()['daily_exceptions'];
    
    if ($daily_exceptions_count > $highest_request_daily_count) {
        $highest_request_daily_count = $daily_exceptions_count;
    }
    
    $request_timeline_points[] = [
        'date_string' => $current_date_str,
        'date_label' => $date_short_label,
        'count' => $daily_exceptions_count
    ];
}

// 8. Fetch Active Departments for Filter Dropdown
$departments_query = $conn->query("SELECT department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
$active_departments_list = [];
if ($departments_query) {
    while ($department_row = $departments_query->fetch_assoc()) {
        $active_departments_list[] = $department_row['department_name'];
    }
}

// 9. Fetch ONLY Real Employees with 10-per-page Pagination
$total_directory_count = (int)$conn->query("SELECT COUNT(*) AS c FROM employees")->fetch_assoc()['c'];
$employees_per_page = 10;
$current_emp_page = max(1, (int)($_GET['emp_page'] ?? 1));
$total_emp_pages = max(1, (int)ceil($total_directory_count / $employees_per_page));
if ($current_emp_page > $total_emp_pages) $current_emp_page = $total_emp_pages;
$emp_offset = ($current_emp_page - 1) * $employees_per_page;

$real_employees_sql = "
    SELECT 
        employees.employee_id,
        employees.employee_no,
        employees.first_name,
        employees.last_name,
        employees.photo_path,
        employees.department,
        employees.position,
        employees.basic_salary,
        employees.employment_status,
        COALESCE(latest_payroll.gross_pay, employees.basic_salary) AS gross_salary,
        COALESCE(latest_payroll.deductions, 0.00) AS deductions_amount,
        COALESCE(latest_payroll.net_pay, employees.basic_salary) AS net_salary,
        COALESCE(latest_payroll.status, 'Draft') AS payroll_status
    FROM employees
    LEFT JOIN (
        SELECT payroll_records.* 
        FROM payroll_records 
        INNER JOIN (
            SELECT employee_id, MAX(payroll_id) AS max_id 
            FROM payroll_records 
            GROUP BY employee_id
        ) latest_run ON payroll_records.payroll_id = latest_run.max_id
    ) latest_payroll ON employees.employee_id = latest_payroll.employee_id
    ORDER BY employees.employee_id DESC
    LIMIT $employees_per_page OFFSET $emp_offset
";

$real_employees_query = $conn->query($real_employees_sql);
$real_employees_list = [];
if ($real_employees_query) {
    while ($single_employee = $real_employees_query->fetch_assoc()) {
        $real_employees_list[] = $single_employee;
    }
}

$displayed_employee_count = count($real_employees_list);
$formatted_welcome_date = date('l, d F Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
  <title>Lipa City Colleges · Dashboard</title>
  <link rel="stylesheet" href="../assets/css/app.css?v=20261112-clean1">
  <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
  <link rel="stylesheet" href="../assets/css/quickroll_executive_dashboard_styling.css?v=3">
  <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
  <script src="../assets/js/dashboard_privacy_mask_and_charts.js?v=4" defer></script>
</head>
<body>
<div class="app">
  <?php sidebar('dashboard'); ?>
  <main class="main">
    <?php topbar('Dashboard'); ?>
    
    <div class="content quickroll-executive-content">
      
      <!-- Greeting and Controls Bar -->
      <header class="quickroll-header">
        <div>
          <h1 class="quickroll-welcome-title">Welcome, <?php echo e($logged_in_admin_name); ?>!</h1>
          <p class="quickroll-welcome-date"><?php echo e($formatted_welcome_date); ?></p>
        </div>
        
        <div class="quickroll-header-actions">
          <button type="button" class="quickroll-privacy-toggle-button" id="privacyToggleButton" title="Toggle privacy mask">
            Hide Amounts
          </button>
          
          <a href="payroll.php?add=1" class="quickroll-action-button">
            + New Payroll
          </a>
        </div>
      </header>

      <!-- Sub-Bar: Dashboard Title & Filters -->
      <section class="quickroll-sub-bar">
        <h2 class="quickroll-sub-title">Overview</h2>
        
        <div class="quickroll-filter-group">
          <select class="quickroll-select-filter" aria-label="Metric filter">
            <option value="all">All Metrics</option>
            <option value="payroll">Payroll Only</option>
            <option value="attendance">Attendance Only</option>
          </select>
          
          <select class="quickroll-select-filter" aria-label="Date filter">
            <option value="current"><?php echo e(date('F Y')); ?></option>
            <option value="last_month"><?php echo e(date('F Y', strtotime('-1 month'))); ?></option>
          </select>
        </div>
      </section>

      <!-- 4 Top KPI Cards — Clean, Neutral, NO Candy Colors, NO Icons -->
      <section class="quickroll-kpi-grid">
        
        <!-- Card 1: Total Employees -->
        <article class="quickroll-kpi-card">
          <span class="quickroll-kpi-label">Total Employees</span>
          <div class="quickroll-kpi-number"><?php echo number_format($total_employees_count); ?></div>
          <div class="quickroll-kpi-subtext"><?php echo $active_employees_count; ?> active in directory</div>
        </article>

        <!-- Card 2: Average Salary -->
        <article class="quickroll-kpi-card">
          <span class="quickroll-kpi-label">Average Salary</span>
          <div class="quickroll-kpi-number maskable-financial-amount"><?php echo money($average_monthly_salary); ?></div>
          <div class="quickroll-kpi-subtext">Across <?php echo $salaried_employees_count; ?> registered staff</div>
        </article>

        <!-- Card 3: Total Outstanding -->
        <article class="quickroll-kpi-card">
          <span class="quickroll-kpi-label">Total Outstanding</span>
          <div class="quickroll-kpi-number maskable-financial-amount"><?php echo money($total_outstanding_liability); ?></div>
          <div class="quickroll-kpi-subtext"><?php echo $active_loans_count; ?> active employee loan(s)</div>
        </article>

        <!-- Card 4: Total Requests & Exceptions -->
        <article class="quickroll-kpi-card">
          <span class="quickroll-kpi-label">Pending Requests</span>
          <div class="quickroll-kpi-number"><?php echo number_format($total_pending_requests_count); ?></div>
          <div class="quickroll-kpi-subtext"><?php echo $attendance_exceptions_count; ?> attendance exception(s)</div>
        </article>

      </section>

      <!-- Middle Section: Functional, Hoverable Charts -->
      <section class="quickroll-middle-grid">
        
        <!-- Left: Payroll History (Interactive SVG Bar Chart) -->
        <article class="quickroll-white-card">
          <div class="quickroll-card-head">
            <h3 class="quickroll-card-title">Payroll History</h3>
            <p class="quickroll-card-subtitle">Real payout vs deductions across recorded pay periods (Hover bars for details)</p>
          </div>

          <div class="quickroll-payroll-history-body">
            
            <div class="quickroll-payroll-chart-container">
              <?php if (empty($payroll_history_bars)): ?>
                <div style="height:170px; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:13px;">
                  No payroll periods recorded yet.
                </div>
              <?php else: ?>
                <svg class="quickroll-bars-svg" viewBox="0 0 450 170" preserveAspectRatio="none">
                  <!-- Grid Guidelines -->
                  <line x1="30" y1="20" x2="440" y2="20" class="quickroll-grid-line" />
                  <line x1="30" y1="65" x2="440" y2="65" class="quickroll-grid-line" />
                  <line x1="30" y1="110" x2="440" y2="110" class="quickroll-grid-line" />
                  <line x1="30" y1="145" x2="440" y2="145" stroke="#e5e7eb" stroke-width="1" />

                  <!-- Y-Axis Baseline Markers -->
                  <text x="0" y="24" class="quickroll-axis-text"><?php echo round($highest_payroll_amount / 1000); ?>k</text>
                  <text x="0" y="69" class="quickroll-axis-text"><?php echo round(($highest_payroll_amount * 0.66) / 1000); ?>k</text>
                  <text x="0" y="114" class="quickroll-axis-text"><?php echo round(($highest_payroll_amount * 0.33) / 1000); ?>k</text>
                  <text x="0" y="148" class="quickroll-axis-text">0k</text>

                  <!-- Dynamic Bars from Real Data -->
                  <?php 
                  $total_bars = count($payroll_history_bars);
                  $bar_width = 24;
                  $start_x = 55;
                  $spacing = $total_bars > 1 ? min(80, (380 - $start_x) / ($total_bars - 1)) : 80;

                  foreach ($payroll_history_bars as $bar_index => $bar_data): 
                    $x_pos = $start_x + ($bar_index * $spacing);
                    
                    $gross_pay_val = (float)$bar_data['total_gross'];
                    $net_pay_val = (float)$bar_data['total_net'];
                    $deductions_val = (float)$bar_data['total_deductions'];
                    
                    // Scale height between 10px and 125px based on max value
                    $gross_bar_height = max(8, round(($gross_pay_val / $highest_payroll_amount) * 120));
                    $net_bar_height = max(6, round(($net_pay_val / $highest_payroll_amount) * 120));
                    
                    $gross_y = 145 - $gross_bar_height;
                    $net_y = 145 - $net_bar_height;
                  ?>
                    <g class="quickroll-bar-interactive-group"
                       data-period="<?php echo e($bar_data['period_name']); ?>"
                       data-net="<?php echo e(money($net_pay_val)); ?>"
                       data-gross="<?php echo e(money($gross_pay_val)); ?>"
                       data-deductions="<?php echo e(money($deductions_val)); ?>"
                       data-records="<?php echo (int)$bar_data['record_count']; ?>">
                      
                      <!-- Transparent Hit Area for easy hovering -->
                      <rect x="<?php echo $x_pos - 8; ?>" y="10" width="<?php echo $bar_width + 16; ?>" height="145" fill="transparent" />

                      <!-- Background Gross/Requested Bar -->
                      <rect x="<?php echo $x_pos; ?>" y="<?php echo $gross_y; ?>" width="<?php echo $bar_width; ?>" height="<?php echo $gross_bar_height; ?>" rx="4" class="quickroll-bar-requested" />

                      <!-- Foreground Net Payout Bar -->
                      <rect x="<?php echo $x_pos; ?>" y="<?php echo $net_y; ?>" width="<?php echo $bar_width; ?>" height="<?php echo $net_bar_height; ?>" rx="4" class="quickroll-bar-actual" />

                      <!-- X-Axis Label -->
                      <text x="<?php echo $x_pos + ($bar_width / 2); ?>" y="162" text-anchor="middle" class="quickroll-axis-text"><?php echo e($bar_data['short_date']); ?></text>
                    </g>
                  <?php endforeach; ?>
                </svg>
              <?php endif; ?>
            </div>

            <!-- Side Summary Metrics -->
            <div class="quickroll-payroll-summary-panel">
              <div class="quickroll-summary-stat-block">
                <span class="quickroll-summary-stat-amount maskable-financial-amount"><?php echo money($total_payroll_payout_amount); ?></span>
                <span class="quickroll-summary-stat-label">Total Payout</span>
              </div>

              <div class="quickroll-summary-stat-block">
                <span class="quickroll-summary-stat-amount maskable-financial-amount" style="font-size:16px;"><?php echo money($delayed_payroll_payout_amount); ?></span>
                <span class="quickroll-summary-stat-label">Draft / Pending</span>
              </div>

              <a href="reports.php" class="quickroll-report-outline-btn">View Full Report</a>
            </div>

          </div>
        </article>

        <!-- Right: Requests & Exceptions (Interactive SVG Line & Dot Chart) -->
        <article class="quickroll-white-card">
          <div class="quickroll-card-head">
            <h3 class="quickroll-card-title">Requests & Issues</h3>
            <p class="quickroll-card-subtitle">Daily exceptions across the last 7 recorded days (Hover points for count)</p>
          </div>

          <div class="quickroll-requests-chart-wrap">
            <svg class="quickroll-requests-svg" viewBox="0 0 280 160">
              <defs>
                <linearGradient id="neutralChartGradient" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="#0f766e" stop-opacity="0.25" />
                  <stop offset="100%" stop-color="#0f766e" stop-opacity="0.0" />
                </linearGradient>
              </defs>

              <?php
              $point_coordinates = [];
              $total_days = count($request_timeline_points);
              $chart_left = 20;
              $chart_width = 240;
              $chart_bottom = 135;
              $chart_top = 35;
              $chart_usable_height = $chart_bottom - $chart_top;

              foreach ($request_timeline_points as $index => $point_data) {
                  $x = $total_days > 1 ? $chart_left + ($index * ($chart_width / ($total_days - 1))) : $chart_left + 120;
                  $ratio = $point_data['count'] / max(1, $highest_request_daily_count);
                  $y = $chart_bottom - ($ratio * $chart_usable_height);
                  $point_coordinates[] = ['x' => round($x, 1), 'y' => round($y, 1), 'data' => $point_data];
              }

              // Build SVG Path
              $line_path = 'M ' . $point_coordinates[0]['x'] . ' ' . $point_coordinates[0]['y'];
              for ($i = 1; $i < count($point_coordinates); $i++) {
                  $line_path .= ' L ' . $point_coordinates[$i]['x'] . ' ' . $point_coordinates[$i]['y'];
              }
              $area_path = $line_path . ' L ' . end($point_coordinates)['x'] . ' ' . $chart_bottom . ' L ' . $point_coordinates[0]['x'] . ' ' . $chart_bottom . ' Z';
              ?>

              <!-- Subtle Grid Baseline -->
              <line x1="15" y1="<?php echo $chart_bottom; ?>" x2="265" y2="<?php echo $chart_bottom; ?>" stroke="#e5e7eb" stroke-width="1" />

              <!-- Area Fill Under Curve -->
              <path d="<?php echo $area_path; ?>" fill="url(#neutralChartGradient)" />

              <!-- Stroke Curve Line -->
              <path d="<?php echo $line_path; ?>" fill="none" stroke="#0f766e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />

              <!-- Interactive Hoverable Data Points -->
              <?php foreach ($point_coordinates as $coord): ?>
                <circle cx="<?php echo $coord['x']; ?>" cy="<?php echo $coord['y']; ?>" r="4.5"
                        fill="#ffffff" stroke="#0f766e" stroke-width="2.2"
                        class="quickroll-request-point-dot"
                        data-date="<?php echo e($coord['data']['date_label']); ?>"
                        data-count="<?php echo (int)$coord['data']['count']; ?>" />
                
                <text x="<?php echo $coord['x']; ?>" y="152" text-anchor="middle" class="quickroll-axis-text">
                  <?php echo e($coord['data']['date_label']); ?>
                </text>
              <?php endforeach; ?>
            </svg>
          </div>
        </article>

      </section>

      <!-- Bottom Section: 100% Real Employees Table (No Fake Data) -->
      <section class="quickroll-white-card" id="directory-overview">
        
        <div class="quickroll-table-header-row">
          <div class="quickroll-table-left-title">
            <h3 class="quickroll-card-title" style="font-size: 16px;">Directory Overview</h3>
            <span class="quickroll-badge-counter"><?php echo $displayed_employee_count; ?> Staff Member<?php echo $displayed_employee_count === 1 ? '' : 's'; ?></span>
          </div>

          <div>
            <select class="quickroll-select-filter" id="departmentFilterSelect" aria-label="Filter directory by department">
              <option value="all">All Departments</option>
              <?php foreach ($active_departments_list as $single_dept_name): ?>
                <option value="<?php echo e(strtolower($single_dept_name)); ?>"><?php echo e($single_dept_name); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="quickroll-data-table-container">
          <table class="quickroll-data-table">
            <thead>
              <tr>
                <th>Employee Name</th>
                <th>Department & Position</th>
                <th>Gross Pay</th>
                <th>Deductions</th>
                <th>Net Salary</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="recentEmployeesTableBody">
              <?php if (empty($real_employees_list)): ?>
                <tr>
                  <td colspan="7" style="text-align:center; padding:24px; color:#9ca3af;">
                    No employee records found in the database.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($real_employees_list as $employee_record): 
                  $employee_full_name = trim($employee_record['first_name'] . ' ' . $employee_record['last_name']);
                  $employee_initial = strtoupper(substr($employee_record['first_name'], 0, 1) ?: 'E');
                  $employee_department = $employee_record['department'] ?: 'Unassigned';
                  $employee_position = $employee_record['position'] ?: 'Staff';
                  $payroll_status = strtolower($employee_record['payroll_status'] ?: 'draft');
                ?>
                  <tr class="recent-employee-row" data-department-name="<?php echo e(strtolower($employee_department)); ?>">
                    
                    <!-- Employee Cell -->
                    <td>
                      <div class="quickroll-employee-cell">
                        <?php if (!empty($employee_record['photo_path']) && file_exists(__DIR__ . '/../' . $employee_record['photo_path'])): ?>
                          <img class="quickroll-avatar-thumb" src="../<?php echo e($employee_record['photo_path']); ?>" alt="<?php echo e($employee_full_name); ?>">
                        <?php else: ?>
                          <div class="quickroll-avatar-thumb"><?php echo e($employee_initial); ?></div>
                        <?php endif; ?>
                        
                        <div class="quickroll-employee-info">
                          <span class="quickroll-employee-name"><?php echo e($employee_full_name); ?></span>
                          <span class="quickroll-employee-sub"><?php echo e($employee_record['employee_no']); ?></span>
                        </div>
                      </div>
                    </td>

                    <!-- Department & Position -->
                    <td>
                      <div class="quickroll-cell-primary-text"><?php echo e($employee_position); ?></div>
                      <div class="quickroll-cell-secondary-text"><?php echo e($employee_department); ?></div>
                    </td>

                    <!-- Gross Pay -->
                    <td>
                      <div class="quickroll-cell-primary-text maskable-financial-amount"><?php echo money($employee_record['gross_salary']); ?></div>
                      <div class="quickroll-cell-secondary-text">Monthly</div>
                    </td>

                    <!-- Deductions -->
                    <td>
                      <div class="quickroll-cell-primary-text maskable-financial-amount"><?php echo money($employee_record['deductions_amount']); ?></div>
                      <div class="quickroll-cell-secondary-text">Recorded</div>
                    </td>

                    <!-- Net Salary -->
                    <td>
                      <div class="quickroll-cell-primary-text maskable-financial-amount"><?php echo money($employee_record['net_salary']); ?></div>
                      <div class="quickroll-cell-secondary-text">Net payout</div>
                    </td>

                    <!-- Payroll Status -->
                    <td>
                      <?php if ($payroll_status === 'paid' || $payroll_status === 'approved'): ?>
                        <span class="quickroll-status-pill quickroll-status-approved"><?php echo ucfirst($payroll_status); ?></span>
                      <?php else: ?>
                        <span class="quickroll-status-pill quickroll-status-draft">Draft</span>
                      <?php endif; ?>
                    </td>

                    <!-- Action Link -->
                    <td>
                      <a href="personalinfo.php?id=<?php echo (int)$employee_record['employee_id']; ?>" class="quickroll-actions-btn" title="View details">
                        View Profile
                      </a>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- 10 Persons per Page Pagination -->
        <?php if ($total_directory_count > 0): 
            $dash_start = $emp_offset + 1;
            $dash_end = min($total_directory_count, $emp_offset + $employees_per_page);
            $dash_prev = $current_emp_page > 1 ? $current_emp_page - 1 : 1;
            $dash_next = $current_emp_page < $total_emp_pages ? $current_emp_page + 1 : $total_emp_pages;
        ?>
        <div class="quickroll-pagination-wrap" style="margin-top:16px; padding-top:14px; border-top:1px solid var(--dash-border, #e5e7eb);">
            <div style="font-size:12px; color:var(--dash-text-muted, #6b7280);">
                Showing <strong><?php echo $dash_start; ?></strong> to <strong><?php echo $dash_end; ?></strong> of <strong><?php echo $total_directory_count; ?></strong> staff members
            </div>

            <div style="display:flex; align-items:center; gap:8px;">
                <?php if ($current_emp_page > 1): ?>
                    <a href="admin_dashboard.php?emp_page=<?php echo $dash_prev; ?>#directory-overview" class="btn btn-small btn-secondary" style="padding:5px 12px; font-size:12px; text-decoration:none;">
                        &larr; Previous
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-small btn-secondary" disabled style="padding:5px 12px; font-size:12px; opacity:0.5; cursor:not-allowed;">
                        &larr; Previous
                    </button>
                <?php endif; ?>

                <span style="font-size:12px; font-weight:600; padding:0 8px; color:var(--dash-text-main, #111827);">
                    Page <?php echo $current_emp_page; ?> of <?php echo $total_emp_pages; ?>
                </span>

                <?php if ($current_emp_page < $total_emp_pages): ?>
                    <a href="admin_dashboard.php?emp_page=<?php echo $dash_next; ?>#directory-overview" class="btn btn-small btn-secondary" style="padding:5px 12px; font-size:12px; text-decoration:none;">
                        Next &rarr;
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-small btn-secondary" disabled style="padding:5px 12px; font-size:12px; opacity:0.5; cursor:not-allowed;">
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
</body>
</html>
