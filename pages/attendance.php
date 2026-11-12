<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'update_simple';

    if ($action === 'save_attendance') {
        $id = (int)($_POST['attendance_id'] ?? 0);
        $empId = (int)($_POST['employee_id'] ?? 0);
        $periodId = (int)($_POST['period_id'] ?? 0) ?: null;
        $date = trim($_POST['attendance_date'] ?? date('Y-m-d'));
        $dailyRate = round((float)($_POST['daily_rate'] ?? 0), 2);
        $absences = round((float)($_POST['absences_count'] ?? 0), 2);
        $tardiness = round((float)($_POST['tardiness_hours'] ?? 0), 2);
        $undertime = round((float)($_POST['undertime_hours'] ?? 0), 2);
        $overtime = round((float)($_POST['overtime_hours'] ?? 0), 2);
        $status = trim($_POST['status'] ?? 'Present');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($empId <= 0 || $date === '') {
            $error = 'Employee and date are required.';
        } else {
            // Auto-fetch employee basic salary to derive daily rate if dailyRate is 0
            if ($dailyRate <= 0) {
                $salStmt = $conn->prepare('SELECT basic_salary FROM employees WHERE employee_id=? LIMIT 1');
                $salStmt->bind_param('i', $empId);
                $salStmt->execute();
                $salRow = $salStmt->get_result()->fetch_assoc();
                if ($salRow && (float)$salRow['basic_salary'] > 0) {
                    // Standard 22 working days per month
                    $dailyRate = round((float)$salRow['basic_salary'] / 22, 2);
                }
            }

            $hourly = $dailyRate > 0 ? ($dailyRate / 8.0) : 0;
            $absenceDed = $absences * $dailyRate;
            $tardinessDed = $tardiness * $hourly;
            $undertimeDed = $undertime * $hourly;
            $computedDed = round($absenceDed + $tardinessDed + $undertimeDed, 2);
            $computedOt = round($overtime * $hourly * 1.25, 2);

            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE attendance SET employee_id=?, period_id=?, attendance_date=?, daily_rate=?, absences_count=?, tardiness_hours=?, undertime_hours=?, overtime_hours=?, computed_deduction=?, computed_overtime=?, status=?, remarks=? WHERE attendance_id=?');
                $stmt->bind_param('iisdddddddssi', $empId, $periodId, $date, $dailyRate, $absences, $tardiness, $undertime, $overtime, $computedDed, $computedOt, $status, $remarks, $id);
                if ($stmt->execute()) {
                    header('Location: attendance.php?saved=1');
                    exit;
                }
                $error = 'Unable to update attendance record.';
            } else {
                $stmt = $conn->prepare('INSERT INTO attendance (employee_id, period_id, attendance_date, daily_rate, absences_count, tardiness_hours, undertime_hours, overtime_hours, computed_deduction, computed_overtime, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE daily_rate=VALUES(daily_rate), absences_count=VALUES(absences_count), tardiness_hours=VALUES(tardiness_hours), undertime_hours=VALUES(undertime_hours), overtime_hours=VALUES(overtime_hours), computed_deduction=VALUES(computed_deduction), computed_overtime=VALUES(computed_overtime), status=VALUES(status), remarks=VALUES(remarks)');
                $stmt->bind_param('iisdddddddss', $empId, $periodId, $date, $dailyRate, $absences, $tardiness, $undertime, $overtime, $computedDed, $computedOt, $status, $remarks);
                if ($stmt->execute()) {
                    header('Location: attendance.php?saved=1');
                    exit;
                }
                $error = 'Unable to save attendance record.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['attendance_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM attendance WHERE attendance_id=?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                header('Location: attendance.php?deleted=1');
                exit;
            }
            $error = 'Unable to delete attendance record.';
        }
    }
}

if (isset($_GET['saved'])) $message = 'Attendance record saved.';
if (isset($_GET['deleted'])) $message = 'Attendance record deleted.';

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$department = trim($_GET['department'] ?? '');
$position = trim($_GET['position'] ?? '');
$employeeId = (int)($_GET['employee_id'] ?? 0);
$periodFilter = (int)($_GET['period_id'] ?? 0);

$departments = [];
$dres = $conn->query("SELECT department_name FROM departments ORDER BY department_name ASC");
if ($dres) while ($d = $dres->fetch_assoc()) $departments[] = $d['department_name'];

$positions = [];
$pres = $conn->query("SELECT DISTINCT position FROM employees WHERE position IS NOT NULL AND position <> '' ORDER BY position ASC");
if ($pres) while ($p = $pres->fetch_assoc()) $positions[] = $p['position'];

$employees = [];
$eres = $conn->query("SELECT employee_id,employee_no,first_name,last_name,basic_salary FROM employees ORDER BY last_name,first_name");
if ($eres) while ($e = $eres->fetch_assoc()) $employees[] = $e;

$periods = [];
$periodRes = $conn->query("SELECT * FROM payroll_periods ORDER BY start_date DESC");
if ($periodRes) while ($p = $periodRes->fetch_assoc()) $periods[] = $p;

$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$where = ['a.attendance_date BETWEEN ? AND ?'];
$params = [$start, $end];
$types = 'ss';

if ($department !== '') { $where[] = 'e.department = ?'; $params[] = $department; $types .= 's'; }
if ($position !== '') { $where[] = 'e.position = ?'; $params[] = $position; $types .= 's'; }
if ($employeeId > 0) { $where[] = 'e.employee_id = ?'; $params[] = $employeeId; $types .= 'i'; }
if ($periodFilter > 0) { $where[] = 'a.period_id = ?'; $params[] = $periodFilter; $types .= 'i'; }

$sql = 'SELECT a.*, e.employee_no, e.first_name, e.last_name, e.department, e.position, pp.period_code
        FROM attendance a
        JOIN employees e ON e.employee_id=a.employee_id
        LEFT JOIN payroll_periods pp ON pp.period_id=a.period_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.attendance_date DESC, e.last_name, e.first_name';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

$summarySql = 'SELECT
    COUNT(CASE WHEN a.time_in IS NOT NULL THEN 1 END) timed_in,
    COUNT(CASE WHEN a.status="Late" OR a.tardiness_hours > 0 THEN 1 END) late_count,
    COUNT(CASE WHEN a.status="Absent" OR a.absences_count > 0 THEN 1 END) absent_count,
    COALESCE(SUM(a.computed_deduction), 0) total_deductions,
    COALESCE(SUM(a.computed_overtime), 0) total_ot
    FROM attendance a
    JOIN employees e ON e.employee_id=a.employee_id
    WHERE ' . implode(' AND ', $where);
$summary = $conn->prepare($summarySql);
$summary->bind_param($types, ...$params);
$summary->execute();
$summaryRow = $summary->get_result()->fetch_assoc();

$filterLabel = 'All employees';
if ($employeeId > 0) {
    foreach ($employees as $emp) if ((int)$emp['employee_id'] === $employeeId) $filterLabel = $emp['last_name'] . ', ' . $emp['first_name'];
} elseif ($department !== '') $filterLabel = $department;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
<title>Lipa City Colleges · Attendance</title>
<link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
<link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
<script src="../assets/js/app.js?v=20261112-clean1" defer></script>
<script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
<?php sidebar('attendance'); ?>
<main class="main">
<?php topbar('Attendance'); ?>
<div class="content attendance-page">
<div class="page-heading">
    <div>
        <h1>Attendance <span class="count-pill"><?php echo (int)$rows->num_rows; ?></span></h1>
        <p><?php echo e(date('F Y', strtotime($start))); ?> · <?php echo e($filterLabel); ?></p>
    </div>
    <div class="heading-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()"><?php echo ui_icon('printer'); ?> Print</button>
        <button type="button" class="btn btn-primary" onclick="openNewAttendance()">+ Log attendance</button>
    </div>
</div>

<?php if ($message): ?><div class="notice ok no-print"><?php echo e($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice err no-print"><?php echo e($error); ?></div><?php endif; ?>

<div class="dashboard-kpis attendance-kpis no-print">
    <div class="kpi card"><span class="kpi-label">Checked in</span><strong><?php echo (int)($summaryRow['timed_in'] ?? 0); ?></strong><small><?php echo e(date('F Y', strtotime($start))); ?></small></div>
    <div class="kpi card"><span class="kpi-label">Late</span><strong><?php echo (int)($summaryRow['late_count'] ?? 0); ?></strong><small>Flagged records</small></div>
    <div class="kpi card"><span class="kpi-label">Absences</span><strong><?php echo (int)($summaryRow['absent_count'] ?? 0); ?></strong><small>Days logged</small></div>
    <div class="kpi card"><span class="kpi-label">Computed deductions</span><strong style="color:var(--err-text);"><?php echo money($summaryRow['total_deductions'] ?? 0); ?></strong><small>Applied to payroll</small></div>
</div>

<section class="card attendance-list-card">
    <form method="get" class="attendance-filters no-print">
        <div><label>Month</label><input type="month" name="month" value="<?php echo e($month); ?>"></div>
        <div><label>Department</label><select name="department"><option value="">All departments</option><?php foreach ($departments as $d): ?><option value="<?php echo e($d); ?>" <?php echo $department === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option><?php endforeach; ?></select></div>
        <div><label>Employee</label><select name="employee_id"><option value="0">All employees</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['employee_id']; ?>" <?php echo $employeeId === (int)$emp['employee_id'] ? 'selected' : ''; ?>><?php echo e($emp['last_name'] . ', ' . $emp['first_name'] . ' · ' . $emp['employee_no']); ?></option><?php endforeach; ?></select></div>
        <div><label>Pay period</label><select name="period_id"><option value="0">All periods</option><?php foreach ($periods as $p): ?><option value="<?php echo (int)$p['period_id']; ?>" <?php echo $periodFilter === (int)$p['period_id'] ? 'selected' : ''; ?>><?php echo e($p['period_code']); ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-secondary" href="attendance.php">Reset</a>
    </form>
    <div class="attendance-report-meta print-only"><strong>LIPA CITY COLLEGES</strong><span>Attendance Report · <?php echo e(date('F Y', strtotime($start))); ?></span><span><?php echo e($filterLabel); ?></span></div>
    <div class="table-wrap">
    <table class="table attendance-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Employee</th>
                <th>Daily rate</th>
                <th>Absences</th>
                <th>Late</th>
                <th>Undertime</th>
                <th>Overtime</th>
                <th style="text-align:right;">Deduction</th>
                <th style="text-align:right;">OT pay</th>
                <th>Status</th>
                <th class="no-print" style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows->num_rows): ?><tr><td colspan="11" class="empty">No attendance records match the selected filters.</td></tr>
        <?php else: while ($r = $rows->fetch_assoc()): ?>
        <tr>
            <td>
                <strong><?php echo e(date('M d, Y', strtotime($r['attendance_date']))); ?></strong>
                <?php if ($r['period_code']): ?>
                    <div style="font-size:11px; color:var(--text-muted);"><?php echo e($r['period_code']); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo e($r['last_name'] . ', ' . $r['first_name']); ?></strong>
                <small class="table-sub"><?php echo e($r['employee_no']); ?> · <?php echo e($r['department']); ?></small>
            </td>
            <td><?php echo (float)$r['daily_rate'] > 0 ? money($r['daily_rate']) : '—'; ?></td>
            <td><?php echo (float)$r['absences_count'] > 0 ? e($r['absences_count']) . ' d' : '—'; ?></td>
            <td><?php echo (float)$r['tardiness_hours'] > 0 ? e($r['tardiness_hours']) . ' h' : '—'; ?></td>
            <td><?php echo (float)$r['undertime_hours'] > 0 ? e($r['undertime_hours']) . ' h' : '—'; ?></td>
            <td><?php echo (float)$r['overtime_hours'] > 0 ? e($r['overtime_hours']) . ' h' : '—'; ?></td>
            <td style="text-align:right; font-weight:600; color:<?php echo (float)$r['computed_deduction'] > 0 ? 'var(--err-text)' : 'inherit'; ?>;">
                <?php echo (float)$r['computed_deduction'] > 0 ? '-' . money($r['computed_deduction']) : '—'; ?>
            </td>
            <td style="text-align:right; font-weight:600; color:<?php echo (float)$r['computed_overtime'] > 0 ? 'var(--ok-text)' : 'inherit'; ?>;">
                <?php echo (float)$r['computed_overtime'] > 0 ? '+' . money($r['computed_overtime']) : '—'; ?>
            </td>
            <td><span class="badge attendance-badge-<?php echo strtolower(str_replace(' ', '-', $r['status'])); ?>"><?php echo e($r['status']); ?></span></td>
            <td class="no-print" style="text-align:right;">
                <button type="button" class="btn btn-small btn-secondary" title="Edit attendance" onclick='editAttendance(<?php echo json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>Edit</button>
            </td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
    </table>
    </div>
    <div class="attendance-print-footer print-only">Generated <?php echo e(date('M d, Y h:i A')); ?> · Lipa City Colleges</div>
</section>
</div></main></div>

<div class="detail-modal-overlay" id="attendanceModal" aria-hidden="true">
    <div class="detail-modal" role="dialog" aria-modal="true" style="max-width: 560px;">
        <div class="detail-modal-head">
            <div>
                <h2 id="modalTitle">Log attendance</h2>
            </div>
            <button type="button" class="modal-icon-close" onclick="closeAttendance()">×</button>
        </div>
        <div class="detail-modal-body">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="attendance_id" id="attId" value="0">

                <div class="form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="field">
                        <label>Date <span class="required">*</span></label>
                        <input type="date" name="attendance_date" id="attDate" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="field">
                        <label>Pay period</label>
                        <select name="period_id" id="attPeriodId">
                            <option value="">None</option>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?php echo (int)$p['period_id']; ?>"><?php echo e($p['period_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Employee <span class="required">*</span></label>
                    <select name="employee_id" id="attEmpId" required onchange="handleEmpChange(this)">
                        <option value="">Select employee</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo (int)$emp['employee_id']; ?>" data-salary="<?php echo (float)$emp['basic_salary']; ?>">
                                <?php echo e($emp['employee_no'] . ' — ' . $emp['last_name'] . ', ' . $emp['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="field">
                        <label>Daily rate (₱)</label>
                        <input type="number" step="0.01" min="0" name="daily_rate" id="attDaily" placeholder="Auto from basic salary">
                    </div>
                    <div class="field">
                        <label>Absences (days)</label>
                        <input type="number" step="0.5" min="0" name="absences_count" id="attAbsences" value="0">
                    </div>
                    <div class="field">
                        <label>Tardiness (hours)</label>
                        <input type="number" step="0.25" min="0" name="tardiness_hours" id="attTardiness" value="0">
                    </div>
                    <div class="field">
                        <label>Undertime (hours)</label>
                        <input type="number" step="0.25" min="0" name="undertime_hours" id="attUndertime" value="0">
                    </div>
                    <div class="field">
                        <label>Overtime (hours)</label>
                        <input type="number" step="0.5" min="0" name="overtime_hours" id="attOvertime" value="0">
                    </div>
                    <div class="field">
                        <label>Status</label>
                        <select name="status" id="attStatus">
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                            <option value="On Leave">On Leave</option>
                            <option value="Half Day">Half Day</option>
                        </select>
                    </div>
                </div>

                <div class="field" style="margin-bottom: 16px;">
                    <label>Remarks</label>
                    <textarea name="remarks" id="attRemarks" rows="2" placeholder="Optional note"></textarea>
                </div>

                <div class="settings-actions" style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeAttendance()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitAtt">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openNewAttendance() {
    document.getElementById('attId').value = 0;
    document.getElementById('modalTitle').textContent = 'Add New Attendance';
    document.getElementById('attDate').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('attPeriodId').value = '';
    document.getElementById('attEmpId').value = '';
    document.getElementById('attDaily').value = '';
    document.getElementById('attAbsences').value = '0';
    document.getElementById('attTardiness').value = '0';
    document.getElementById('attUndertime').value = '0';
    document.getElementById('attOvertime').value = '0';
    document.getElementById('attStatus').value = 'Present';
    document.getElementById('attRemarks').value = '';
    document.getElementById('attendanceModal').classList.add('show');
    document.getElementById('attendanceModal').setAttribute('aria-hidden', 'false');
}

function handleEmpChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const sal = parseFloat(opt.getAttribute('data-salary')) || 0;
    if (sal > 0) {
        document.getElementById('attDaily').value = (sal / 22).toFixed(2);
    }
}

function editAttendance(r) {
    document.getElementById('attId').value = r.attendance_id;
    document.getElementById('modalTitle').textContent = 'Edit Attendance Record';
    document.getElementById('attDate').value = r.attendance_date;
    document.getElementById('attPeriodId').value = r.period_id || '';
    document.getElementById('attEmpId').value = r.employee_id;
    document.getElementById('attDaily').value = r.daily_rate > 0 ? r.daily_rate : '';
    document.getElementById('attAbsences').value = r.absences_count || '0';
    document.getElementById('attTardiness').value = r.tardiness_hours || '0';
    document.getElementById('attUndertime').value = r.undertime_hours || '0';
    document.getElementById('attOvertime').value = r.overtime_hours || '0';
    document.getElementById('attStatus').value = r.status;
    document.getElementById('attRemarks').value = r.remarks || '';
    document.getElementById('attendanceModal').classList.add('show');
    document.getElementById('attendanceModal').setAttribute('aria-hidden', 'false');
}

function closeAttendance() {
    document.getElementById('attendanceModal').classList.remove('show');
    document.getElementById('attendanceModal').setAttribute('aria-hidden', 'true');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAttendance(); });
</script>
</body>
</html>
