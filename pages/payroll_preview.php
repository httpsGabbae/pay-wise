<?php
require_once __DIR__ . '/../includes/auth.php';

$isAdmin = !empty($_SESSION['admin_id']);
$sessionEmployeeId = (int)($_SESSION['employee_id'] ?? 0);

if ($isAdmin) {
    require_role('superadmin', 'finance');
} elseif ($sessionEmployeeId <= 0) {
    http_response_code(403);
    exit('<div class="print-error">Access denied.</div>');
}

$id = (int)($_GET['id'] ?? 0);
if ($isAdmin) {
    $stmt = $conn->prepare('SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position, e.employment_status, pp.period_code, pp.pay_date FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? LIMIT 1');
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position, e.employment_status, pp.period_code, pp.pay_date FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? AND p.employee_id=? AND p.status IN ('Approved','Paid') LIMIT 1");
    $stmt->bind_param('ii', $id, $sessionEmployeeId);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit('<div class="print-error">Payroll record not found.</div>');
}

function pp_money($n) {
    return '₱ ' . number_format((float)$n, 2);
}

$name = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);

// Itemized loan amortizations
$loanRows = [];
$lrStmt = $conn->prepare('SELECT lr.amount, lt.loan_type_name AS loan_name FROM loan_repayments lr JOIN employee_loans el ON el.loan_id=lr.loan_id JOIN loan_types lt ON lt.loan_type_id=el.loan_type_id WHERE lr.payroll_id=?');
$lrStmt->bind_param('i', $id);
$lrStmt->execute();
$lrRes = $lrStmt->get_result();
while ($lr = $lrRes->fetch_assoc()) {
    $loanRows[] = $lr;
}
if (empty($loanRows) && (float)$row['loan_deductions'] > 0) {
    $elStmt = $conn->prepare('SELECT el.installment as amount, lt.loan_type_name AS loan_name FROM employee_loans el JOIN loan_types lt ON lt.loan_type_id=el.loan_type_id WHERE el.employee_id=? AND el.status="Active"');
    $elStmt->bind_param('i', $row['employee_id']);
    $elStmt->execute();
    $elRes = $elStmt->get_result();
    while ($el = $elRes->fetch_assoc()) {
        $loanRows[] = $el;
    }
}

// Itemized other income
$otherIncomeRows = [];
if (!empty($row['period_id'])) {
    $oiStmt = $conn->prepare('SELECT income_name AS income_title, amount FROM other_income WHERE employee_id=? AND period_id=?');
    $oiStmt->bind_param('ii', $row['employee_id'], $row['period_id']);
} else {
    $oiStmt = $conn->prepare('SELECT income_name AS income_title, amount FROM other_income WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
    $oiStmt->bind_param('iss', $row['employee_id'], $row['period_start'], $row['period_end']);
}
$oiStmt->execute();
$oiRes = $oiStmt->get_result();
while ($oi = $oiRes->fetch_assoc()) {
    $otherIncomeRows[] = $oi;
}

// Itemized other deductions
$otherDedRows = [];
if (!empty($row['period_id'])) {
    $odStmt = $conn->prepare('SELECT deduction_name AS deduction_title, amount FROM other_deductions WHERE employee_id=? AND period_id=?');
    $odStmt->bind_param('ii', $row['employee_id'], $row['period_id']);
} else {
    $odStmt = $conn->prepare('SELECT deduction_name AS deduction_title, amount FROM other_deductions WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
    $odStmt->bind_param('iss', $row['employee_id'], $row['period_start'], $row['period_end']);
}
$odStmt->execute();
$odRes = $odStmt->get_result();
while ($od = $odRes->fetch_assoc()) {
    $otherDedRows[] = $od;
}

// Attendance details
$aStmt = $conn->prepare('SELECT COUNT(*) as days_logged, SUM(COALESCE(absences_count, CASE WHEN status="Absent" THEN 1 ELSE 0 END)) as absences, SUM(tardiness_hours) as tardy_hrs, SUM(undertime_hours) as ut_hrs, SUM(overtime_hours) as ot_hrs, SUM(computed_deduction) as att_ded, SUM(computed_overtime) as att_ot FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?');
$aStmt->bind_param('iss', $row['employee_id'], $row['period_start'], $row['period_end']);
$aStmt->execute();
$attMetrics = $aStmt->get_result()->fetch_assoc();
?>
<div class="native-payroll-statement">
  <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f172a; padding-bottom: 0.75rem; margin-bottom: 1rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
      <img src="../uploads/logo.png" alt="LCC Logo" style="height: 48px; width: auto; object-fit: contain;">
      <div>
        <div style="font-size: 1.15rem; font-weight: 800; color: #0f172a; letter-spacing: 0.5px;">LIPA CITY COLLEGES</div>
        <div style="font-size: 0.75rem; color: #475569; text-transform: uppercase; font-weight: 600;">Office of the Treasurer & Finance · Admin Payroll System</div>
      </div>
    </div>
    <div style="text-align: right;">
      <div style="font-size: 0.95rem; font-weight: 800; color: #1d4ed8; text-transform: uppercase;">Employee Payslip</div>
      <div style="font-size: 0.75rem; color: #64748b;">Ref #<?= htmlspecialchars($row['payroll_id']) ?> · <?= htmlspecialchars($row['status']) ?></div>
    </div>
  </div>

  <div class="native-payroll-grid" style="margin-bottom: 1.25rem;">
    <div><span>Employee Name</span><strong><?= htmlspecialchars($name) ?></strong></div>
    <div><span>Employee ID</span><strong><?= htmlspecialchars($row['employee_no']) ?></strong></div>
    <div><span>College / Department</span><strong><?= htmlspecialchars($row['department'] ?: 'Not assigned') ?></strong></div>
    <div><span>Position</span><strong><?= htmlspecialchars($row['position'] ?: 'Not assigned') ?></strong></div>
    <div><span>Payroll Period</span><strong><?= htmlspecialchars(date('M d, Y', strtotime($row['period_start'])) . ' – ' . date('M d, Y', strtotime($row['period_end']))) ?><?= !empty($row['period_code']) ? ' (' . htmlspecialchars($row['period_code']) . ')' : '' ?></strong></div>
    <div><span>Payment Date</span><strong><?= !empty($row['pay_date']) ? htmlspecialchars(date('M d, Y', strtotime($row['pay_date']))) : htmlspecialchars(date('M d, Y', strtotime($row['period_end']))) ?></strong></div>
  </div>

  <div class="native-payroll-columns" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <!-- EARNINGS -->
    <section style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem 1rem;">
      <h3 style="font-size: 0.9rem; font-weight: 700; color: #047857; text-transform: uppercase; margin-top: 0; margin-bottom: 0.5rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.25rem;">Earnings</h3>
      <div class="native-pay-row"><span>Basic Salary</span><strong><?= pp_money($row['basic_salary']) ?></strong></div>
      <?php if ((float)$row['allowances'] > 0): ?>
        <div class="native-pay-row"><span>Allowances</span><strong><?= pp_money($row['allowances']) ?></strong></div>
      <?php endif; ?>
      <?php if ((float)$row['overtime_pay'] > 0): ?>
        <div class="native-pay-row"><span>Overtime Pay <?= !empty($attMetrics['ot_hrs']) && (float)$attMetrics['ot_hrs'] > 0 ? '(' . round((float)$attMetrics['ot_hrs'], 1) . ' hrs)' : '' ?></span><strong><?= pp_money($row['overtime_pay']) ?></strong></div>
      <?php endif; ?>
      <?php if ((float)$row['other_earnings'] > 0): ?>
        <div class="native-pay-row"><span>Other Earnings / Incentives</span><strong><?= pp_money($row['other_earnings']) ?></strong></div>
      <?php endif; ?>
      <?php foreach ($otherIncomeRows as $oi): ?>
        <div class="native-pay-row"><span><?= htmlspecialchars($oi['income_title']) ?></span><strong><?= pp_money($oi['amount']) ?></strong></div>
      <?php endforeach; ?>
      <div class="native-pay-total" style="border-top: 2px solid #cbd5e1; margin-top: 0.5rem; padding-top: 0.5rem;">
        <span>Total Gross Pay</span><strong><?= pp_money($row['gross_pay']) ?></strong>
      </div>
    </section>

    <!-- DEDUCTIONS -->
    <section style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem 1rem;">
      <h3 style="font-size: 0.9rem; font-weight: 700; color: #be123c; text-transform: uppercase; margin-top: 0; margin-bottom: 0.5rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.25rem;">Deductions</h3>
      
      <?php if ((float)$row['attendance_deductions'] > 0): ?>
        <div class="native-pay-row">
          <span>Attendance (Absence/Late) <?= !empty($attMetrics['absences']) && (float)$attMetrics['absences'] > 0 ? '[' . round((float)$attMetrics['absences'], 1) . 'd absent]' : '' ?><?= !empty($attMetrics['tardy_hrs']) && (float)$attMetrics['tardy_hrs'] > 0 ? '[' . round((float)$attMetrics['tardy_hrs'], 2) . 'h late]' : '' ?></span>
          <strong><?= pp_money($row['attendance_deductions']) ?></strong>
        </div>
      <?php endif; ?>

      <?php if (!empty($loanRows)): ?>
        <?php foreach ($loanRows as $loan): ?>
          <div class="native-pay-row">
            <span>Loan: <?= htmlspecialchars($loan['loan_name']) ?></span>
            <strong><?= pp_money($loan['amount']) ?></strong>
          </div>
        <?php endforeach; ?>
      <?php elseif ((float)$row['loan_deductions'] > 0): ?>
        <div class="native-pay-row"><span>Loan Amortizations</span><strong><?= pp_money($row['loan_deductions']) ?></strong></div>
      <?php endif; ?>

      <?php foreach ($otherDedRows as $od): ?>
        <div class="native-pay-row"><span><?= htmlspecialchars($od['deduction_title']) ?></span><strong><?= pp_money($od['amount']) ?></strong></div>
      <?php endforeach; ?>

      <?php 
      // Statutory / remaining base deductions
      $itemizedDedSum = (float)$row['attendance_deductions'] + (float)$row['loan_deductions'] + (float)$row['other_deductions_amount'];
      $statutoryDed = round((float)$row['deductions'] - $itemizedDedSum, 2);
      if ($statutoryDed > 0.01):
      ?>
        <div class="native-pay-row"><span>Statutory / Gov't Deductions</span><strong><?= pp_money($statutoryDed) ?></strong></div>
      <?php endif; ?>

      <div class="native-pay-total" style="border-top: 2px solid #cbd5e1; margin-top: 0.5rem; padding-top: 0.5rem;">
        <span>Total Deductions</span><strong style="color: #be123c;"><?= pp_money($row['deductions']) ?></strong>
      </div>
    </section>
  </div>

  <!-- NET PAY BANNER -->
  <div style="background: linear-gradient(135deg, #1e3a8a, #0f172a); color: #fff; padding: 0.75rem 1.25rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <div>
      <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.85;">Net Take-Home Pay</div>
      <div style="font-size: 1.35rem; font-weight: 800;"><?= pp_money($row['net_pay']) ?></div>
    </div>
    <div style="font-size: 0.8rem; text-align: right; opacity: 0.85;">
      <div>Status: <strong><?= htmlspecialchars($row['status']) ?></strong></div>
      <div>Computed Date: <?= date('M d, Y') ?></div>
    </div>
  </div>

  <?php if (!empty($row['notes'])): ?>
    <div class="native-pay-notes" style="background: #f8fafc; border-left: 3px solid #3b82f6; padding: 0.5rem 0.75rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
      <span style="font-weight: 700; color: #1e3a8a;">Payroll Notes:</span>
      <p style="margin: 0.25rem 0 0 0;"><?= nl2br(htmlspecialchars($row['notes'])) ?></p>
    </div>
  <?php endif; ?>

  <div class="native-pay-sign" style="display: flex; justify-content: space-between; margin-top: 2rem; padding-top: 0.5rem; border-top: 1px dashed #cbd5e1; font-size: 0.85rem; color: #334155;">
    <div>
      <div>Prepared by: __________________________</div>
      <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.2rem;">Payroll In-Charge / Finance Officer</div>
    </div>
    <div>
      <div>Received by: __________________________</div>
      <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.2rem;">Employee Signature & Date</div>
    </div>
  </div>
  <div class="native-print-footer" style="margin-top: 1.5rem; font-size: 0.75rem; color: #94a3b8; text-align: center;">
    Lipa City Colleges · G.A. Solis St., Lipa City, Batangas, Philippines · Private and Confidential
  </div>
</div>
