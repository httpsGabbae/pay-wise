<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_income') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $periodId = (int)($_POST['period_id'] ?? 0) ?: null;
        $name = trim($_POST['income_name'] ?? '');
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $payDate = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($empId <= 0 || $name === '' || $amount <= 0) {
            $error = 'Please choose an employee, provide an income description, and enter an amount greater than 0.';
        } else {
            $stmt = $conn->prepare('INSERT INTO other_income (employee_id, period_id, income_name, amount, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iisdss', $empId, $periodId, $name, $amount, $payDate, $notes);
            if ($stmt->execute()) {
                header('Location: other_income.php?saved=1');
                exit;
            }
            $error = 'Failed to add other income.';
        }
    } elseif ($action === 'delete_income') {
        $id = (int)($_POST['income_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM other_income WHERE income_id=?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                header('Location: other_income.php?deleted=1');
                exit;
            }
            $error = 'Unable to delete income record.';
        }
    } elseif ($action === 'add_deduction') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $periodId = (int)($_POST['period_id'] ?? 0) ?: null;
        $name = trim($_POST['deduction_name'] ?? '');
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $payDate = trim($_POST['pay_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($empId <= 0 || $name === '' || $amount <= 0) {
            $error = 'Please choose an employee, provide a deduction description, and enter an amount greater than 0.';
        } else {
            $stmt = $conn->prepare('INSERT INTO other_deductions (employee_id, period_id, deduction_name, amount, pay_date, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iisdss', $empId, $periodId, $name, $amount, $payDate, $notes);
            if ($stmt->execute()) {
                header('Location: other_income.php?saved=1');
                exit;
            }
            $error = 'Failed to add other deduction.';
        }
    } elseif ($action === 'delete_deduction') {
        $id = (int)($_POST['deduction_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM other_deductions WHERE deduction_id=?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                header('Location: other_income.php?deleted=1');
                exit;
            }
            $error = 'Unable to delete deduction record.';
        }
    }
}

if (isset($_GET['saved'])) $message = 'Item saved successfully.';
if (isset($_GET['deleted'])) $message = 'Item deleted successfully.';

$filterPeriod = (int)($_GET['period_id'] ?? 0);
$filterEmployee = (int)($_GET['employee_id'] ?? 0);

// Periods dropdown
$periods = [];
$pRes = $conn->query('SELECT * FROM payroll_periods ORDER BY start_date DESC');
if ($pRes) while ($p = $pRes->fetch_assoc()) $periods[] = $p;

// Employees dropdown
$employees = [];
$eRes = $conn->query('SELECT employee_id, employee_no, first_name, last_name, department FROM employees ORDER BY last_name ASC, first_name ASC');
if ($eRes) while ($e = $eRes->fetch_assoc()) $employees[] = $e;

// Query other income
$incWhere = [];
if ($filterPeriod > 0) $incWhere[] = "oi.period_id = $filterPeriod";
if ($filterEmployee > 0) $incWhere[] = "oi.employee_id = $filterEmployee";
$incWhereSql = $incWhere ? 'WHERE ' . implode(' AND ', $incWhere) : '';

$incomes = [];
$incRes = $conn->query("SELECT oi.*, e.employee_no, e.first_name, e.last_name, pp.period_code
    FROM other_income oi
    JOIN employees e ON e.employee_id = oi.employee_id
    LEFT JOIN payroll_periods pp ON pp.period_id = oi.period_id
    $incWhereSql
    ORDER BY oi.income_id DESC");
if ($incRes) while ($r = $incRes->fetch_assoc()) $incomes[] = $r;

// Query other deductions
$dedWhere = [];
if ($filterPeriod > 0) $dedWhere[] = "od.period_id = $filterPeriod";
if ($filterEmployee > 0) $dedWhere[] = "od.employee_id = $filterEmployee";
$dedWhereSql = $dedWhere ? 'WHERE ' . implode(' AND ', $dedWhere) : '';

$deductions = [];
$dedRes = $conn->query("SELECT od.*, e.employee_no, e.first_name, e.last_name, pp.period_code
    FROM other_deductions od
    JOIN employees e ON e.employee_id = od.employee_id
    LEFT JOIN payroll_periods pp ON pp.period_id = od.period_id
    $dedWhereSql
    ORDER BY od.deduction_id DESC");
if ($dedRes) while ($r = $dedRes->fetch_assoc()) $deductions[] = $r;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Adjustments</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('other_income'); ?>
    <main class="main">
        <?php topbar('Adjustments'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Other Income & Deductions</h1>
                    <p>Honoraria, overload teaching, bonuses, and itemized payroll deductions.</p>
                </div>
            </div>

            <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

            <div class="card" style="margin-bottom: 18px; padding: 12px 16px;">
                <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="font-size:12.5px; font-weight:600; color:var(--text-muted);">Pay period</label>
                        <select name="period_id" onchange="this.form.submit()" style="padding:6px 12px; font-size:13px;">
                            <option value="0">All periods</option>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?php echo (int)$p['period_id']; ?>" <?php echo $filterPeriod === (int)$p['period_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($p['period_code'] . ' (' . date('M d', strtotime($p['start_date'])) . ' – ' . date('M d', strtotime($p['end_date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="font-size:12.5px; font-weight:600; color:var(--text-muted);">Employee</label>
                        <select name="employee_id" onchange="this.form.submit()" style="padding:6px 12px; font-size:13px; max-width:260px;">
                            <option value="0">All employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp['employee_id']; ?>" <?php echo $filterEmployee === (int)$emp['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($emp['last_name'] . ', ' . $emp['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($filterPeriod > 0 || $filterEmployee > 0): ?>
                        <a href="other_income.php" class="btn btn-small btn-secondary" style="margin-left:auto;">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Two-Column Side by Side Layout Matching Photos -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(480px, 1fr)); gap: 24px; align-items:start;">

                <!-- LEFT COLUMN: OTHER INCOME -->
                <div>
                    <section class="card" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <div>
                                <h2 style="color:var(--ok-text);">+ Add Other Income</h2>
                                <p>Overload pay, teaching honorarium, incentives</p>
                            </div>
                        </div>
                        <form method="post" style="padding: 18px 20px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_income">

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Payroll Period</label>
                                    <select name="period_id">
                                        <option value="">(None / General)</option>
                                        <?php foreach ($periods as $p): ?>
                                            <option value="<?php echo (int)$p['period_id']; ?>">
                                                <?php echo e($p['period_code']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Pay Date</label>
                                    <input type="date" name="pay_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:12px;">
                                <label>Employee <span class="required">*</span></label>
                                <select name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['employee_id']; ?>">
                                            <?php echo e($emp['employee_no'] . ' — ' . $emp['last_name'] . ', ' . $emp['first_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Income Type / Name <span class="required">*</span></label>
                                    <input type="text" name="income_name" placeholder="e.g. Overload Teaching, Honorarium" required>
                                </div>
                                <div class="field">
                                    <label>Amount (₱) <span class="required">*</span></label>
                                    <input type="number" step="0.01" min="1" name="amount" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:16px;">
                                <label>Notes</label>
                                <input type="text" name="notes" placeholder="Optional reference notes">
                            </div>

                            <button type="submit" class="btn btn-primary" style="width:100%;">Add Income Entry</button>
                        </form>
                    </section>

                    <!-- Table of Other Incomes -->
                    <section class="card">
                        <div class="card-head">
                            <div>
                                <h2>Other Income Records</h2>
                                <p><?php echo count($incomes); ?> itemized addition(s)</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period / Date</th>
                                        <th>Income Description</th>
                                        <th style="text-align:right;">Amount</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($incomes)): ?>
                                    <tr><td colspan="5" class="empty">No other income records found.</td></tr>
                                <?php else: foreach ($incomes as $inc): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($inc['last_name'] . ', ' . $inc['first_name']); ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?php echo e($inc['employee_no']); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size:12px;"><?php echo e($inc['period_code'] ?: date('M d, Y', strtotime($inc['pay_date']))); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo e($inc['income_name']); ?></strong>
                                            <?php if ($inc['notes']): ?>
                                                <div style="font-size:11px; color:var(--text-muted);"><?php echo e($inc['notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:700; color:var(--ok-text);">
                                            +<?php echo money($inc['amount']); ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this income item?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_income">
                                                <input type="hidden" name="income_id" value="<?php echo (int)$inc['income_id']; ?>">
                                                <button type="submit" class="btn btn-small btn-danger"><?php echo ui_icon('trash'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <!-- RIGHT COLUMN: OTHER DEDUCTIONS -->
                <div>
                    <section class="card" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <div>
                                <h2 style="color:var(--err-text);">- Add Other Deduction</h2>
                                <p>Faculty club dues, uniforms, cash advances</p>
                            </div>
                        </div>
                        <form method="post" style="padding: 18px 20px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_deduction">

                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Payroll Period</label>
                                    <select name="period_id">
                                        <option value="">(None / General)</option>
                                        <?php foreach ($periods as $p): ?>
                                            <option value="<?php echo (int)$p['period_id']; ?>">
                                                <?php echo e($p['period_code']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Pay Date</label>
                                    <input type="date" name="pay_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:12px;">
                                <label>Employee <span class="required">*</span></label>
                                <select name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['employee_id']; ?>">
                                            <?php echo e($emp['employee_no'] . ' — ' . $emp['last_name'] . ', ' . $emp['first_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px; margin-bottom:12px;">
                                <div class="field">
                                    <label>Deduction Type / Name <span class="required">*</span></label>
                                    <input type="text" name="deduction_name" placeholder="e.g. Uniform, Faculty Club Due" required>
                                </div>
                                <div class="field">
                                    <label>Amount (₱) <span class="required">*</span></label>
                                    <input type="number" step="0.01" min="1" name="amount" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:16px;">
                                <label>Notes</label>
                                <input type="text" name="notes" placeholder="Optional reference notes">
                            </div>

                            <button type="submit" class="btn btn-primary" style="width:100%; background:var(--err-text);">Add Deduction Entry</button>
                        </form>
                    </section>

                    <!-- Table of Other Deductions -->
                    <section class="card">
                        <div class="card-head">
                            <div>
                                <h2>Other Deduction Records</h2>
                                <p><?php echo count($deductions); ?> itemized deduction(s)</p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period / Date</th>
                                        <th>Deduction Description</th>
                                        <th style="text-align:right;">Amount</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($deductions)): ?>
                                    <tr><td colspan="5" class="empty">No other deductions recorded.</td></tr>
                                <?php else: foreach ($deductions as $ded): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($ded['last_name'] . ', ' . $ded['first_name']); ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?php echo e($ded['employee_no']); ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size:12px;"><?php echo e($ded['period_code'] ?: date('M d, Y', strtotime($ded['pay_date']))); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo e($ded['deduction_name']); ?></strong>
                                            <?php if ($ded['notes']): ?>
                                                <div style="font-size:11px; color:var(--text-muted);"><?php echo e($ded['notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:700; color:var(--err-text);">
                                            -<?php echo money($ded['amount']); ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this deduction item?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_deduction">
                                                <input type="hidden" name="deduction_id" value="<?php echo (int)$ded['deduction_id']; ?>">
                                                <button type="submit" class="btn btn-small btn-danger"><?php echo ui_icon('trash'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
