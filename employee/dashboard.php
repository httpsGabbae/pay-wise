<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$employee = current_employee($conn);
if (!$employee) {
    header('Location: logout.php');
    exit;
}

$employee_id = (int)$employee['employee_id'];

// 1. Payslips summary and latest net payout
$payslips_count_query = $conn->prepare("
    SELECT COUNT(*) AS total_count, 
           MAX(period_end) AS latest_period 
    FROM payroll_records 
    WHERE employee_id = ? AND status IN ('Approved', 'Paid')
");
$payslips_count_query->bind_param('i', $employee_id);
$payslips_count_query->execute();
$payslips_summary = $payslips_count_query->get_result()->fetch_assoc();
$total_approved_payslips = (int)($payslips_summary['total_count'] ?? 0);
$latest_payroll_period_end = $payslips_summary['latest_period'] ?? null;

$latest_net_pay_amount = 0.0;
if ($total_approved_payslips > 0) {
    $latest_pay_record_query = $conn->prepare("
        SELECT net_pay 
        FROM payroll_records 
        WHERE employee_id = ? AND status IN ('Approved', 'Paid') 
        ORDER BY period_end DESC, payroll_id DESC 
        LIMIT 1
    ");
    $latest_pay_record_query->bind_param('i', $employee_id);
    $latest_pay_record_query->execute();
    $latest_pay_record = $latest_pay_record_query->get_result()->fetch_assoc();
    $latest_net_pay_amount = (float)($latest_pay_record['net_pay'] ?? 0);
}

// 2. Active loans summary
$loan_summary_query = $conn->prepare("
    SELECT COUNT(*) AS active_count, 
           COALESCE(SUM(remaining_balance), 0) AS total_balance,
           COALESCE(SUM(installment), 0) AS total_installment 
    FROM employee_loans 
    WHERE employee_id = ? AND status = 'Active'
");
$loan_summary_query->bind_param('i', $employee_id);
$loan_summary_query->execute();
$loan_summary = $loan_summary_query->get_result()->fetch_assoc();
$active_loans_count = (int)($loan_summary['active_count'] ?? 0);
$total_remaining_loan_balance = (float)($loan_summary['total_balance'] ?? 0);
$monthly_loan_installment = (float)($loan_summary['total_installment'] ?? 0);

// 3. Attendance this month summary
$month_attendance_query = $conn->prepare("
    SELECT COUNT(*) AS total_logged, 
           SUM(status = 'Present') AS present_count, 
           SUM(status = 'Late') AS late_count, 
           SUM(status = 'Absent') AS absent_count 
    FROM attendance 
    WHERE employee_id = ? 
      AND attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') 
      AND attendance_date <= LAST_DAY(CURDATE())
");
$month_attendance_query->bind_param('i', $employee_id);
$month_attendance_query->execute();
$month_attendance = $month_attendance_query->get_result()->fetch_assoc();
$present_days_count = (int)($month_attendance['present_count'] ?? 0);
$late_days_count = (int)($month_attendance['late_count'] ?? 0);
$absent_days_count = (int)($month_attendance['absent_count'] ?? 0);
$total_logged_days = (int)($month_attendance['total_logged'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
<title>Lipa City Colleges · Employee Portal</title>
<link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
<link rel="stylesheet" href="../assets/css/employee.css">
<link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
<script src="../assets/js/app.js?v=20261112-clean1" defer></script>
<script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
<?php employee_sidebar('dashboard'); ?>
<main class="main">
<?php employee_topbar(''); ?>
<div class="content">

<div class="dash-grid employee-dash-grid">
  <!-- Card 1: Payslips & Earnings -->
  <a class="exec-card" href="payroll.php">
    <div class="exec-card-header">
      <div class="exec-badge-wrap">
        <span class="exec-badge-icon exec-icon-emerald"><?php echo emp_icon('wallet'); ?></span>
        <span class="exec-card-label">Payslips & Earnings</span>
      </div>
      <span class="exec-status-pill"><?php echo $latest_payroll_period_end ? e(date('M Y', strtotime($latest_payroll_period_end))) : 'No payouts'; ?></span>
    </div>
    <div class="exec-card-body">
      <div class="exec-hero-metric"><?php echo $total_approved_payslips > 0 ? money($latest_net_pay_amount) : '₱ 0.00'; ?></div>
      <div class="exec-hero-sub">Latest net take-home pay</div>
    </div>
    <div class="exec-card-footer">
      <span class="exec-meta"><?php echo $total_approved_payslips; ?> approved payslip<?php echo $total_approved_payslips === 1 ? '' : 's'; ?></span>
      <span class="exec-action-link">View history ›</span>
    </div>
  </a>

  <!-- Card 2: Loan Obligations -->
  <a class="exec-card" href="loans.php">
    <div class="exec-card-header">
      <div class="exec-badge-wrap">
        <span class="exec-badge-icon exec-icon-indigo"><?php echo emp_icon('credit'); ?></span>
        <span class="exec-card-label">Loan Obligations</span>
      </div>
      <span class="exec-status-pill <?php echo $active_loans_count > 0 ? 'exec-pill-active' : ''; ?>">
        <?php echo $active_loans_count > 0 ? $active_loans_count . ' Active' : 'Cleared'; ?>
      </span>
    </div>
    <div class="exec-card-body">
      <div class="exec-hero-metric"><?php echo money($total_remaining_loan_balance); ?></div>
      <div class="exec-hero-sub">Total remaining balance</div>
    </div>
    <div class="exec-card-footer">
      <span class="exec-meta">
        <?php echo $active_loans_count > 0 ? money($monthly_loan_installment) . ' / period' : 'No active loans'; ?>
      </span>
      <span class="exec-action-link">Loan details ›</span>
    </div>
  </a>

  <!-- Card 3: Monthly Attendance -->
  <a class="exec-card" href="attendance.php">
    <div class="exec-card-header">
      <div class="exec-badge-wrap">
        <span class="exec-badge-icon exec-icon-teal"><?php echo emp_icon('calendar'); ?></span>
        <span class="exec-card-label">Attendance · <?php echo date('M Y'); ?></span>
      </div>
      <span class="exec-status-pill"><?php echo $total_logged_days; ?> Logged</span>
    </div>
    <div class="exec-card-body">
      <div class="exec-hero-metric"><?php echo $present_days_count; ?> <span class="exec-metric-unit">Days</span></div>
      <div class="exec-hero-sub">Present this month</div>
    </div>
    <div class="exec-card-footer">
      <span class="exec-meta">
        <strong style="color: #059669;"><?php echo $present_days_count; ?> present</strong> · 
        <span style="color: #d97706;"><?php echo $late_days_count; ?> late</span> · 
        <span style="color: #dc2626;"><?php echo $absent_days_count; ?> absent</span>
      </span>
      <span class="exec-action-link">View logs ›</span>
    </div>
  </a>

  <!-- Card 4: Executive Employee ID -->
  <a class="exec-card exec-card-profile" href="profile.php">
    <div class="exec-card-header">
      <div class="exec-badge-wrap">
        <span class="exec-badge-icon exec-icon-amber"><?php echo emp_icon('user'); ?></span>
        <span class="exec-card-label">Employee ID</span>
      </div>
      <span class="exec-status-pill exec-pill-active"><?php echo e($employee['employment_status'] ?: 'Regular'); ?></span>
    </div>
    <div class="exec-card-body exec-profile-body">
      <div class="exec-avatar-wrap">
        <?php if ($employee['photo_path']): ?>
          <img src="<?php echo e(employee_photo_url($employee['photo_path'])); ?>" class="exec-avatar-img" alt="Employee photo">
        <?php else: ?>
          <div class="exec-avatar-fallback"><?php echo e(strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1))); ?></div>
        <?php endif; ?>
        <span class="exec-online-dot" title="Active Account"></span>
      </div>
      <div class="exec-profile-info">
        <div class="exec-profile-name"><?php echo e(trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name'])); ?></div>
        <div class="exec-profile-meta">
          <span class="exec-emp-number"><?php echo e($employee['employee_no']); ?></span>
          <span class="exec-profile-role"><?php echo e($employee['position'] ?: ($employee['department'] ?: 'Unassigned')); ?></span>
        </div>
      </div>
    </div>
    <div class="exec-card-footer">
      <span class="exec-meta"><?php echo e($employee['department'] ?: 'College Staff'); ?></span>
      <span class="exec-action-link">View profile ›</span>
    </div>
  </a>
</div>

</div>
</main>
</div>
</body>
</html>
