<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('<div class="notice err">Employee not found.</div>'); }

$stmt = $conn->prepare('SELECT employee_id,employee_no,first_name,middle_name,last_name,department,position,employment_status,basic_salary FROM employees WHERE employee_id=? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
if (!$employee) { http_response_code(404); exit('<div class="notice err">Employee not found.</div>'); }

$summary = ['gross' => 0, 'net' => 0, 'count' => 0, 'latest_net' => 0, 'latest_period' => null];
$stmt = $conn->prepare('SELECT COALESCE(SUM(gross_pay),0) gross, COALESCE(SUM(net_pay),0) net, COUNT(*) count FROM payroll_records WHERE employee_id=? AND YEAR(period_end)=YEAR(CURDATE())');
$stmt->bind_param('i', $id); $stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) $summary = array_merge($summary, $r);
$stmt = $conn->prepare('SELECT net_pay,period_end FROM payroll_records WHERE employee_id=? ORDER BY period_end DESC,payroll_id DESC LIMIT 1');
$stmt->bind_param('i', $id); $stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) { $summary['latest_net'] = $r['net_pay']; $summary['latest_period'] = $r['period_end']; }

$name = trim($employee['first_name'].' '.$employee['middle_name'].' '.$employee['last_name']);
?>
<div class="salary-hero"><div class="salary-person"><div class="salary-avatar"><?php echo e(strtoupper(substr($employee['first_name'],0,1).substr($employee['last_name'],0,1))); ?></div><div><h3><?php echo e($name); ?></h3><p><?php echo e($employee['employee_no']); ?> · <?php echo e($employee['position'] ?: 'Unassigned'); ?></p></div></div><div class="salary-amount"><span>Basic salary</span><strong><?php echo money($employee['basic_salary']); ?></strong></div></div>
<div class="salary-grid">
    <div class="salary-stat"><span>Latest net</span><strong><?php echo money($summary['latest_net']); ?></strong><small><?php echo $summary['latest_period'] ? e(date('M d, Y', strtotime($summary['latest_period']))) : 'No payroll yet'; ?></small></div>
    <div class="salary-stat"><span><?php echo date('Y'); ?> gross</span><strong><?php echo money($summary['gross']); ?></strong><small><?php echo (int)$summary['count']; ?> record<?php echo ((int)$summary['count'] === 1 ? '' : 's'); ?></small></div>
    <div class="salary-stat"><span><?php echo date('Y'); ?> net</span><strong><?php echo money($summary['net']); ?></strong><small>Year-to-date</small></div>
</div>
