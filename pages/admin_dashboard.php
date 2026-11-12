<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* Role workspaces (5 fixes to AI slop in dashboards):
   - superadmin = System control center (exceptions + accounts, counts only, no peso figures)
   - finance = Payroll pipeline (money owned here: Draft -> Approved -> Paid)
   - hr = People operations (attendance today + record completeness, no money)
   Each workspace has its own H1, primary metric, actions and empty states.
   Every number links to the screen that explains it. */
$dashRole = admin_role();
$canPeople = in_array($dashRole, ['superadmin', 'hr'], true);
$canPay = in_array($dashRole, ['superadmin', 'finance'], true);

$totalEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees")->fetch_assoc()['total'];
$activeEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE COALESCE(employment_status,'') NOT IN ('Separated','Inactive')")->fetch_assoc()['total'];
$regularEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE employment_status='Regular'")->fetch_assoc()['total'];
$totalDepartments = (int)$conn->query("SELECT COUNT(*) total FROM departments WHERE status='Active'")->fetch_assoc()['total'];

$today = date('Y-m-d');
$timedIn = $completed = $late = 0;
if ($r = $conn->query("SELECT COUNT(CASE WHEN time_in IS NOT NULL THEN 1 END) timed_in, COUNT(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN 1 END) completed, COUNT(CASE WHEN status='Late' THEN 1 END) late_count FROM attendance WHERE attendance_date='" . $conn->real_escape_string($today) . "'")) {
    $a = $r->fetch_assoc(); $timedIn=(int)$a['timed_in']; $completed=(int)$a['completed']; $late=(int)$a['late_count'];
}

$payrollCount = $payrollGross = $payrollNet = $payrollDed = $payrollPaid = $payrollDrafts = 0; $payrollLabel = 'No payroll records yet — create the first payroll to start the pipeline';
if ($r = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(gross_pay),0) gross, COALESCE(SUM(net_pay),0) net, COALESCE(SUM(deductions),0) ded, COALESCE(SUM(status='Paid'),0) paid, COALESCE(SUM(status='Draft'),0) drafts FROM payroll_records WHERE period_start >= '" . date('Y-m-01') . "' AND period_start <= '" . date('Y-m-t') . "'")) {
    $p = $r->fetch_assoc(); $payrollCount=(int)$p['total']; $payrollGross=(float)$p['gross']; $payrollNet=(float)$p['net']; $payrollDed=(float)$p['ded']; $payrollPaid=(int)$p['paid']; $payrollDrafts=(int)$p['drafts'];
}
if ($payrollCount) $payrollLabel = $payrollCount . ' payroll record' . ($payrollCount === 1 ? '' : 's') . ' this month';

$missingDept = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE department IS NULL OR TRIM(department)='' ")->fetch_assoc()['total'];
$missingSalary = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE basic_salary IS NULL OR basic_salary<=0")->fetch_assoc()['total'];

$hireTrend = []; $hireLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("first day of -$i month"));
    $hireLabels[] = date('M', strtotime($k . '-01'));
    $hireTrend[$k] = 0;
}
$cut = date('Y-m-01', strtotime('-5 month'));
if ($r = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c FROM employees WHERE created_at IS NOT NULL AND created_at >= '" . $conn->real_escape_string($cut) . "' GROUP BY m")) {
    while ($row = $r->fetch_assoc()) { if (array_key_exists($row['m'], $hireTrend)) $hireTrend[$row['m']] = (int)$row['c']; }
}
$hireVals = array_values($hireTrend);
$hireMax = max(1, max($hireVals));
$hiredThisMonth = end($hireVals);
$pts = []; $n = count($hireVals);
foreach ($hireVals as $i => $v) {
    $x = $n > 1 ? 10 + ($i / ($n - 1)) * 280 : 150;
    $y = 86 - ($v / $hireMax) * 68;
    $pts[] = [round($x, 1), round($y, 1)];
}
$line = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
foreach (array_slice($pts, 1) as $p) $line .= ' L ' . $p[0] . ' ' . $p[1];
$area = $line . ' L ' . $pts[$n-1][0] . ' 96 L ' . $pts[0][0] . ' 96 Z';
$last = $pts[$n-1];

$accountCount = 0;
if ($dashRole === 'superadmin' && ($r = $conn->query('SELECT COUNT(*) total FROM admins'))) {
    $accountCount = (int)$r->fetch_assoc()['total'];
}

$issues = [];
if ($late) $issues[] = ['attendance.php', $late . ' late today'];
if ($missingDept) $issues[] = ['employees.php', $missingDept . ' missing department'];
if ($missingSalary) $issues[] = ['employees.php', $missingSalary . ' missing salary'];
$attentionTarget = $issues ? $issues[0][0] : ($dashRole === 'hr' ? 'attendance.php' : 'reports.php');
$attentionText = $issues ? implode(' · ', array_column($issues, 1)) : 'No pending exceptions';
if ($dashRole === 'finance') {
    $attentionTarget = 'payroll.php';
}

if ($dashRole === 'finance') {
    $workspaceKicker = date('F Y');
    $workspaceTitle = 'Finance overview';
    $workspaceSub = money($payrollNet) . ' net · ' . $payrollPaid . ' paid · ' . $payrollDrafts . ' drafts';
} elseif ($dashRole === 'hr') {
    $workspaceKicker = date('l, F j');
    $workspaceTitle = 'HR overview';
    $workspaceSub = $activeEmployees . ' active · ' . $timedIn . ' timed in · ' . $late . ' late';
} else {
    $workspaceKicker = date('l, F j');
    $workspaceTitle = 'Overview';
    $workspaceSub = $activeEmployees . ' active staff · ' . $timedIn . ' in today · ' . $payrollCount . ' payslips this month';
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1"><title>Lipa City Colleges · Dashboard</title>
<link rel="stylesheet" href="../assets/css/app.css?v=20261112-clean1"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1"><link rel="stylesheet" href="../assets/css/dashboard-polish.css?v=20261112-clean1"><script src="../assets/js/app.js?v=20261112-clean1" defer></script><script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head><body><div class="app">
<?php sidebar('dashboard'); ?><main class="main"><?php topbar('Dashboard'); ?><div class="content">
<section class="dash-greet">
  <div class="dash-greet-text"><p class="dash-kicker"><?php echo e($workspaceKicker); ?></p><h1><?php echo e($workspaceTitle); ?></h1><p><?php echo e($workspaceSub); ?></p></div>
  <div class="dash-greet-actions"><?php if ($canPeople): ?><a class="btn btn-primary" href="personalinfo.php?mode=new"><?php echo ui_icon('plus'); ?> New employee</a><?php endif; ?><?php if ($canPay): ?><a class="btn <?php echo $canPeople ? 'btn-secondary' : 'btn-primary'; ?>" href="payroll.php?add=1">New payroll</a><?php endif; ?></div>
</section>

<div class="dash-grid">
<?php if ($dashRole === 'finance'): ?>
<section class="card dash-employees">
  <p class="dash-kicker">Net payroll · <?php echo e(date('F Y')); ?></p>
  <div class="dash-big"><?php echo money($payrollNet); ?></div>
  <div class="dash-sub">Gross <?php echo money($payrollGross); ?> · Deductions <?php echo money($payrollDed); ?></div>
  <?php if (!$payrollCount): ?><div class="dash-empty">No records this month — <a href="payroll_periods.php">open pay periods</a>.</div><?php endif; ?>
  <div class="dash-months"><span><?php echo $payrollPaid; ?> paid</span><span><?php echo $payrollDrafts; ?> drafts</span><span><?php echo $payrollCount; ?> total</span></div>
  <a class="dash-link" href="reports.php">Monthly report</a>
</section>
<?php elseif ($dashRole === 'hr'): ?>
<section class="card dash-employees">
  <p class="dash-kicker">Checked in today</p>
  <div class="dash-big"><?php echo $timedIn; ?></div>
  <div class="dash-sub"><?php echo $late; ?> late · <?php echo $completed; ?> completed · <?php echo $activeEmployees; ?> active</div>
  <?php if (!$timedIn): ?><div class="dash-empty">No check-ins recorded today — <a href="attendance.php">log attendance</a>.</div><?php endif; ?>
  <div class="dash-months"><?php foreach ($hireLabels as $l): ?><span><?php echo e($l); ?></span><?php endforeach; ?></div>
  <?php if ($canPeople): ?><a class="dash-link" href="employees.php">All employees</a><?php endif; ?>
</section>
<?php else: ?>
<section class="card dash-employees">
  <p class="dash-kicker">Active workforce</p>
  <div class="dash-big"><?php echo $activeEmployees; ?></div>
  <div class="dash-sub"><?php echo $regularEmployees; ?> regular · <?php echo $totalDepartments; ?> departments</div>
  <svg class="dash-spark" viewBox="0 0 300 96" role="img" aria-label="6-month hires" preserveAspectRatio="none"><path class="spark-area" d="<?php echo e($area); ?>"/><path class="spark-line" d="<?php echo e($line); ?>"/><circle class="spark-dot" cx="<?php echo $last[0]; ?>" cy="<?php echo $last[1]; ?>" r="4"/></svg>
  <div class="dash-months"><?php foreach ($hireLabels as $l): ?><span><?php echo e($l); ?></span><?php endforeach; ?></div>
  <?php if ($canPeople): ?><a class="dash-link" href="employees.php">All employees</a><?php endif; ?>
</section>
<?php endif; ?>

<div class="dash-rows">
  <?php if ($dashRole === 'finance'): ?>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon <?php echo $payrollDrafts ? 'warn' : 'ok'; ?>"><?php echo ui_icon('eye'); ?></span><span class="dash-row-text"><strong>Drafts to review</strong><small><?php echo $payrollDrafts; ?> pending approval</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll_periods.php"><span class="dash-row-icon"><?php echo ui_icon('calendar'); ?></span><span class="dash-row-text"><strong>Pay periods</strong><small><?php echo $payrollPaid; ?> paid · <?php echo money($payrollNet); ?> net</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="loans.php"><span class="dash-row-icon <?php echo $missingSalary ? 'warn' : 'ok'; ?>"><?php echo ui_icon('credit'); ?></span><span class="dash-row-text"><strong>Loans & advances</strong><small><?php echo $missingSalary ? $missingSalary . ' staff missing base salary' : 'Manage active amortizations'; ?></small></span><span class="dash-chev">›</span></a>
  <?php elseif ($dashRole === 'hr'): ?>
  <a class="card dash-row" href="attendance.php"><span class="dash-row-icon"><?php echo ui_icon('clock'); ?></span><span class="dash-row-text"><strong>Attendance today</strong><small><?php echo $timedIn; ?> in · <?php echo $late; ?> late · <?php echo $completed; ?> out</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="<?php echo e($attentionTarget); ?>"><span class="dash-row-icon <?php echo $issues ? 'warn' : 'ok'; ?>"><?php echo ui_icon($issues ? 'eye' : 'shield'); ?></span><span class="dash-row-text"><strong>Exceptions</strong><small><?php echo e($attentionText); ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon"><?php echo ui_icon('wallet'); ?></span><span class="dash-row-text"><strong>Pay period attendance</strong><small><?php echo $payrollCount; ?> records this month</small></span><span class="dash-chev">›</span></a>
  <?php else: ?>
  <a class="card dash-row" href="attendance.php"><span class="dash-row-icon"><?php echo ui_icon('clock'); ?></span><span class="dash-row-text"><strong>Attendance today</strong><small><?php echo $timedIn; ?> in · <?php echo $late; ?> late · <?php echo $completed; ?> out</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon"><?php echo ui_icon('wallet'); ?></span><span class="dash-row-text"><strong>Payroll</strong><small><?php echo $payrollDrafts; ?> drafts · <?php echo $payrollPaid; ?> paid · <?php echo money($payrollNet); ?> net</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="<?php echo e($attentionTarget); ?>"><span class="dash-row-icon <?php echo $issues ? 'warn' : 'ok'; ?>"><?php echo ui_icon($issues ? 'eye' : 'shield'); ?></span><span class="dash-row-text"><strong>Exceptions</strong><small><?php echo e($attentionText); ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="accounts.php"><span class="dash-row-icon"><?php echo ui_icon('shield'); ?></span><span class="dash-row-text"><strong>Accounts</strong><small><?php echo $accountCount; ?> admin account<?php echo $accountCount === 1 ? '' : 's'; ?></small></span><span class="dash-chev">›</span></a>
  <?php endif; ?>
</div>
</div>
</div></main></div></body></html>
