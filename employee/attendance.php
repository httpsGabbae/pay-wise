<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$employee = current_employee($conn);
if (!$employee) {
    header('Location: logout.php');
    exit;
}

$today = date('Y-m-d');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $stmt = $conn->prepare('SELECT * FROM attendance WHERE employee_id=? AND attendance_date=? LIMIT 1');
    $stmt->bind_param('is', $employee['employee_id'], $today);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();

    if ($action === 'time_in') {
        if ($record && $record['time_in']) {
            $error = 'You have already timed in today.';
        } else {
            $now = date('H:i:s');
            // Calculate tardiness if after 08:00:00 AM
            $tardyMins = 0;
            $schedStart = '08:00:00';
            if ($now > $schedStart) {
                $tardyMins = (int)round((strtotime($now) - strtotime($schedStart)) / 60);
            }
            $tardyHours = round($tardyMins / 60.0, 2);
            $status = $tardyMins > 0 ? 'Late' : 'Present';

            // Daily rate and deduction calculation
            $dailyRate = !empty($employee['basic_salary']) ? round((float)$employee['basic_salary'] / 22, 2) : 0.0;
            $hourlyRate = $dailyRate > 0 ? ($dailyRate / 8.0) : 0.0;
            $computedDed = round($tardyHours * $hourlyRate, 2);

            if ($record) {
                $up = $conn->prepare('UPDATE attendance SET time_in=?, status=?, tardiness_hours=?, daily_rate=?, computed_deduction=? WHERE attendance_id=?');
                $up->bind_param('ssdddi', $now, $status, $tardyHours, $dailyRate, $computedDed, $record['attendance_id']);
            } else {
                $up = $conn->prepare('INSERT INTO attendance (employee_id, attendance_date, time_in, status, tardiness_hours, daily_rate, computed_deduction) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $up->bind_param('isssddd', $employee['employee_id'], $today, $now, $status, $tardyHours, $dailyRate, $computedDed);
            }
            if ($up->execute()) {
                $message = 'Time in recorded at ' . date('h:i A', strtotime($now)) . ($tardyMins > 0 ? " ($tardyMins mins late)" : '') . '.';
            } else {
                $error = 'Unable to record your time in. Please try again.';
            }
        }
    } elseif ($action === 'time_out') {
        if (!$record || !$record['time_in']) {
            $error = 'Please time in first.';
        } elseif ($record['time_out']) {
            $error = 'You have already timed out today.';
        } else {
            $now = date('H:i:s');
            $schedEnd = '17:00:00';
            $utMins = 0;
            $otHours = 0.0;
            $dailyRate = !empty($record['daily_rate']) ? (float)$record['daily_rate'] : (!empty($employee['basic_salary']) ? round((float)$employee['basic_salary'] / 22, 2) : 0.0);
            $hourlyRate = $dailyRate > 0 ? ($dailyRate / 8.0) : 0.0;

            if ($now < $schedEnd) {
                $utMins = (int)round((strtotime($schedEnd) - strtotime($now)) / 60);
            } elseif ($now > $schedEnd) {
                $otHours = round((strtotime($now) - strtotime($schedEnd)) / 3600.0, 2);
            }
            $utHours = round($utMins / 60.0, 2);

            $currentTardyHours = (float)($record['tardiness_hours'] ?? 0);
            $computedDed = round(($currentTardyHours + $utHours) * $hourlyRate, 2);
            $computedOt = round($otHours * $hourlyRate * 1.25, 2);

            $up = $conn->prepare('UPDATE attendance SET time_out=?, undertime_hours=?, overtime_hours=?, computed_deduction=?, computed_overtime=? WHERE attendance_id=?');
            $up->bind_param('sddddi', $now, $utHours, $otHours, $computedDed, $computedOt, $record['attendance_id']);
            if ($up->execute()) {
                $message = 'Time out recorded at ' . date('h:i A', strtotime($now)) . '.';
            } else {
                $error = 'Unable to record your time out. Please try again.';
            }
        }
    }
}

$stmt = $conn->prepare('SELECT * FROM attendance WHERE employee_id=? AND attendance_date=? LIMIT 1');
$stmt->bind_param('is', $employee['employee_id'], $today);
$stmt->execute();
$todayRecord = $stmt->get_result()->fetch_assoc();

$historyMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $historyMonth)) {
    $historyMonth = date('Y-m');
}
$historyStart = $historyMonth . '-01';
$historyEnd = date('Y-m-t', strtotime($historyStart));
$historyStmt = $conn->prepare('SELECT attendance_date, time_in, time_out, status, tardiness_hours, undertime_hours, overtime_hours, computed_deduction, computed_overtime FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC');
$historyStmt->bind_param('iss', $employee['employee_id'], $historyStart, $historyEnd);
$historyStmt->execute();
$history = $historyStmt->get_result();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Attendance</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/employee.css">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
    <div class="app">
        <?php employee_sidebar('attendance'); ?>
        <main class="main">
            <?php employee_topbar('Attendance'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <h1>Attendance</h1>
                    </div>
                </div>

                <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

                <section class="attendance-card card">
                    <div class="attendance-date">
                        <strong><?php echo e(date('l, F d, Y')); ?></strong>
                        <span id="liveClock"><?php echo e(date('h:i:s A')); ?></span>
                    </div>
                    <div class="attendance-action">
                        <?php if (!$todayRecord || !$todayRecord['time_in']): ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="time_in">
                                <button class="attendance-button time-in" type="submit">
                                    <span class="attendance-button-icon">→</span>
                                    <span><b>Time In</b><small>Start shift</small></span>
                                </button>
                            </form>
                        <?php elseif (!$todayRecord['time_out']): ?>
                            <div class="attendance-status done">
                                <span>✓</span>
                                <div>
                                    <b>Timed in at <?php echo e(date('h:i A', strtotime($todayRecord['time_in']))); ?></b>
                                    <small><?php echo e($todayRecord['status']); ?></small>
                                </div>
                            </div>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="time_out">
                                <button class="attendance-button time-out" type="submit">
                                    <span class="attendance-button-icon">←</span>
                                    <span><b>Time Out</b><small>End shift</small></span>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="attendance-status done full">
                                <span>✓</span>
                                <div>
                                    <b>Shift Completed</b>
                                    <small>In: <?php echo e(date('h:i A', strtotime($todayRecord['time_in']))); ?> · Out: <?php echo e(date('h:i A', strtotime($todayRecord['time_out']))); ?></small>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="card employee-attendance-history">
                    <div class="card-head">
                        <div>
                            <h2><?php echo e(date('F Y', strtotime($historyStart))); ?></h2>
                        </div>
                        <div class="attendance-history-tools no-print">
                            <form method="get">
                                <input type="month" name="month" value="<?php echo e($historyMonth); ?>">
                                <button class="btn btn-secondary" type="submit">Filter</button>
                            </form>
                            <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Tardiness / Overtime</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($history->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="5" class="empty">No attendance records found for this month.</td>
                                    </tr>
                                <?php else: while ($r = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo e(date('M d, Y', strtotime($r['attendance_date']))); ?></strong></td>
                                        <td><?php echo $r['time_in'] ? e(date('h:i A', strtotime($r['time_in']))) : '—'; ?></td>
                                        <td><?php echo $r['time_out'] ? e(date('h:i A', strtotime($r['time_out']))) : '—'; ?></td>
                                        <td>
                                            <?php if ((float)$r['tardiness_hours'] > 0): ?>
                                                <span style="color: var(--danger, #e11d48); font-size: 0.85rem; font-weight: 600;">
                                                    Late: <?php echo round((float)$r['tardiness_hours'], 2); ?> hr(s)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((float)$r['undertime_hours'] > 0): ?>
                                                <span style="color: var(--warning, #d97706); font-size: 0.85rem; font-weight: 600; margin-left: 0.5rem;">
                                                    UT: <?php echo round((float)$r['undertime_hours'], 2); ?> hr(s)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((float)$r['overtime_hours'] > 0): ?>
                                                <span style="color: var(--success, #059669); font-size: 0.85rem; font-weight: 600; margin-left: 0.5rem;">
                                                    OT: <?php echo round((float)$r['overtime_hours'], 1); ?> hr(s)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((float)$r['tardiness_hours'] == 0 && (float)$r['undertime_hours'] == 0 && (float)$r['overtime_hours'] == 0): ?>
                                                <span class="mini" style="color: var(--text-muted, #94a3b8);">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['status'] === 'Present'): ?>
                                                <span class="badge" style="background:#f0fdf4; color:#15803d;">Present</span>
                                            <?php elseif ($r['status'] === 'Late'): ?>
                                                <span class="badge" style="background:#fffbeb; color:#b45309;">Late</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fff1f2; color:#be123c;"><?php echo e($r['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <div class="attendance-print-footer print-only">Generated <?php echo e(date('M d, Y h:i A')); ?> · Lipa City Colleges</div>
            </div>
        </main>
    </div>
    <script>
        setInterval(() => {
            const d = new Date();
            const el = document.getElementById('liveClock');
            if (el) el.textContent = new Intl.DateTimeFormat('en-PH', {
                timeZone: 'Asia/Manila',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(d);
        }, 1000);
    </script>
</body>

</html>
