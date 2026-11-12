<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pdf.php';

$isAdmin = !empty($_SESSION['admin_id']);
$sessionEmployeeId = (int)($_SESSION['employee_id'] ?? 0);

if ($isAdmin) {
    require_role('superadmin', 'finance');
} elseif ($sessionEmployeeId <= 0) {
    header('Location: ../employee/login.php');
    exit;
}

if (!function_exists('employee_name')) {
    function employee_name(array $employee): string
    {
        return trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name']);
    }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('Payroll record not found.');
}

if ($isAdmin) {
    $stmt = $conn->prepare('SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position, pp.period_code, pp.pay_date FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? LIMIT 1');
    $stmt->bind_param('i', $id);
} else {
    $stmt = $conn->prepare("SELECT p.*, e.employee_no, e.first_name, e.middle_name, e.last_name, e.department, e.position, pp.period_code, pp.pay_date FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id LEFT JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE p.payroll_id=? AND p.employee_id=? AND p.status IN ('Approved','Paid') LIMIT 1");
    $stmt->bind_param('ii', $id, $sessionEmployeeId);
}
$stmt->execute();
$payroll = $stmt->get_result()->fetch_assoc();

if (!$payroll) {
    exit('Payroll record not found.');
}

// Fetch itemized loan amortizations
$loanRows = [];
$lrStmt = $conn->prepare('SELECT lr.amount, lt.loan_type_name AS loan_name FROM loan_repayments lr JOIN employee_loans el ON el.loan_id=lr.loan_id JOIN loan_types lt ON lt.loan_type_id=el.loan_type_id WHERE lr.payroll_id=?');
$lrStmt->bind_param('i', $id);
$lrStmt->execute();
$lrRes = $lrStmt->get_result();
while ($lr = $lrRes->fetch_assoc()) {
    $loanRows[] = ['Loan: ' . $lr['loan_name'], (float)$lr['amount']];
}
if (empty($loanRows) && (float)$payroll['loan_deductions'] > 0) {
    $elStmt = $conn->prepare('SELECT el.installment as amount, lt.loan_type_name AS loan_name FROM employee_loans el JOIN loan_types lt ON lt.loan_type_id=el.loan_type_id WHERE el.employee_id=? AND el.status="Active"');
    $elStmt->bind_param('i', $payroll['employee_id']);
    $elStmt->execute();
    $elRes = $elStmt->get_result();
    while ($el = $elRes->fetch_assoc()) {
        $loanRows[] = ['Loan: ' . $el['loan_name'], (float)$el['amount']];
    }
}

// Fetch itemized other income
$otherIncomeRows = [];
if (!empty($payroll['period_id'])) {
    $oiStmt = $conn->prepare('SELECT income_name, amount FROM other_income WHERE employee_id=? AND period_id=?');
    $oiStmt->bind_param('ii', $payroll['employee_id'], $payroll['period_id']);
} else {
    $oiStmt = $conn->prepare('SELECT income_name, amount FROM other_income WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
    $oiStmt->bind_param('iss', $payroll['employee_id'], $payroll['period_start'], $payroll['period_end']);
}
$oiStmt->execute();
$oiRes = $oiStmt->get_result();
while ($oi = $oiRes->fetch_assoc()) {
    $otherIncomeRows[] = [$oi['income_name'], (float)$oi['amount']];
}

// Fetch itemized other deductions
$otherDedRows = [];
if (!empty($payroll['period_id'])) {
    $odStmt = $conn->prepare('SELECT deduction_name, amount FROM other_deductions WHERE employee_id=? AND period_id=?');
    $odStmt->bind_param('ii', $payroll['employee_id'], $payroll['period_id']);
} else {
    $odStmt = $conn->prepare('SELECT deduction_name, amount FROM other_deductions WHERE employee_id=? AND pay_date BETWEEN ? AND ?');
    $odStmt->bind_param('iss', $payroll['employee_id'], $payroll['period_start'], $payroll['period_end']);
}
$odStmt->execute();
$odRes = $odStmt->get_result();
while ($od = $odRes->fetch_assoc()) {
    $otherDedRows[] = [$od['deduction_name'], (float)$od['amount']];
}

// Attendance summary
$aStmt = $conn->prepare('SELECT SUM(tardiness_hours) as tardy_hrs, SUM(COALESCE(absences_count, CASE WHEN status="Absent" THEN 1 ELSE 0 END)) as absences, SUM(overtime_hours) as ot_hrs FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?');
$aStmt->bind_param('iss', $payroll['employee_id'], $payroll['period_start'], $payroll['period_end']);
$aStmt->execute();
$attInfo = $aStmt->get_result()->fetch_assoc();

if (!class_exists('PayslipPDF')) {
    class PayslipPDF extends FPDF
    {
        public function Footer()
        {
            pdf_footer($this, 'Lipa City Colleges · Payroll Statement - Private and Confidential');
        }
    }
}

$pdf = new PayslipPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 12, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

pdf_header($pdf, 'OFFICIAL PAYSLIP', 'Lipa City Colleges · Office of the Treasurer & Finance');

$pdf->SetFillColor(30, 58, 138);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(180, 9, pdf_text('EMPLOYEE PAYROLL STATEMENT'), 0, 1, 'C', true);
$pdf->SetTextColor(23, 50, 59);

$pdf->Ln(4);
pdf_field_row($pdf, 'Employee ID', $payroll['employee_no'], 'Status', $payroll['status']);
pdf_field_row($pdf, 'Employee Name', employee_name($payroll), 'Date Prepared', pdf_date($payroll['created_at']));
pdf_field_row($pdf, 'Department', $payroll['department'] ?: 'Not assigned', 'Position', $payroll['position'] ?: 'Not assigned');
$periodText = pdf_date($payroll['period_start']) . ' - ' . pdf_date($payroll['period_end']);
if (!empty($payroll['period_code'])) {
    $periodText .= ' (' . $payroll['period_code'] . ')';
}
$payDateText = !empty($payroll['pay_date']) ? pdf_date($payroll['pay_date']) : pdf_date($payroll['period_end']);
pdf_field_row($pdf, 'Pay Period', $periodText, 'Payment Date', $payDateText);

$pdf->Ln(6);

$pdf->SetFillColor(236, 247, 246);
$pdf->SetTextColor(15, 118, 110);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(90, 8, pdf_text('EARNINGS'), 1, 0, 'L', true);
$pdf->SetTextColor(190, 18, 60);
$pdf->Cell(90, 8, pdf_text('DEDUCTIONS'), 1, 1, 'L', true);
$pdf->SetTextColor(23, 50, 59);

// Build earnings (left)
$leftRows = [
    ['Basic Salary', (float)$payroll['basic_salary']],
];
if ((float)$payroll['allowances'] > 0) {
    $leftRows[] = ['Allowances', (float)$payroll['allowances']];
}
if ((float)$payroll['overtime_pay'] > 0) {
    $otLabel = 'Overtime Pay';
    if (!empty($attInfo['ot_hrs']) && (float)$attInfo['ot_hrs'] > 0) {
        $otLabel .= ' (' . round((float)$attInfo['ot_hrs'], 1) . ' hrs)';
    }
    $leftRows[] = [$otLabel, (float)$payroll['overtime_pay']];
}
if ((float)$payroll['other_earnings'] > 0) {
    $leftRows[] = ['Other Earnings', (float)$payroll['other_earnings']];
}
foreach ($otherIncomeRows as $oi) {
    $leftRows[] = [$oi[0], $oi[1]];
}

// Build deductions (right)
$rightRows = [];
if ((float)$payroll['attendance_deductions'] > 0) {
    $attLabel = 'Attendance / Tardiness';
    if (!empty($attInfo['absences']) && (int)$attInfo['absences'] > 0) {
        $attLabel .= ' (' . (int)$attInfo['absences'] . 'd absent)';
    }
    $rightRows[] = [$attLabel, (float)$payroll['attendance_deductions']];
}
foreach ($loanRows as $lr) {
    $rightRows[] = [$lr[0], $lr[1]];
}
if (empty($loanRows) && (float)$payroll['loan_deductions'] > 0) {
    $rightRows[] = ['Loan Amortization', (float)$payroll['loan_deductions']];
}
foreach ($otherDedRows as $od) {
    $rightRows[] = [$od[0], $od[1]];
}

// Statutory / remainder deductions
$sumItemizedDeductions = (float)$payroll['attendance_deductions'] + (float)$payroll['loan_deductions'] + (float)$payroll['other_deductions_amount'];
$statutory = round((float)$payroll['deductions'] - $sumItemizedDeductions, 2);
if ($statutory > 0.01) {
    $rightRows[] = ['Govt / Statutory Deductions', $statutory];
} elseif (empty($rightRows) && (float)$payroll['deductions'] > 0) {
    $rightRows[] = ['Total Deductions', (float)$payroll['deductions']];
}

$maxRows = max(count($leftRows), count($rightRows));
for ($i = 0; $i < $maxRows; $i++) {
    $left = $leftRows[$i] ?? ['', null];
    $right = $rightRows[$i] ?? ['', null];

    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Cell(62, 7.5, pdf_text($left[0]), 1, 0, 'L');
    $pdf->Cell(28, 7.5, $left[1] === null ? '' : pdf_money($left[1]), 1, 0, 'R');
    $pdf->Cell(62, 7.5, pdf_text($right[0]), 1, 0, 'L');
    $pdf->Cell(28, 7.5, $right[1] === null ? '' : pdf_money($right[1]), 1, 1, 'R');
}

$pdf->SetFillColor(247, 249, 249);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell(62, 8, pdf_text('TOTAL GROSS PAY'), 1, 0, 'L', true);
$pdf->Cell(28, 8, pdf_money($payroll['gross_pay']), 1, 0, 'R', true);
$pdf->Cell(62, 8, pdf_text('TOTAL DEDUCTIONS'), 1, 0, 'L', true);
$pdf->Cell(28, 8, pdf_money($payroll['deductions']), 1, 1, 'R', true);

$pdf->Ln(6);
$pdf->SetFillColor(30, 58, 138);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(120, 12, pdf_text('NET TAKE-HOME PAY'), 1, 0, 'L', true);
$pdf->Cell(60, 12, pdf_money($payroll['net_pay']), 1, 1, 'R', true);
$pdf->SetTextColor(23, 50, 59);

if (!empty($payroll['notes'])) {
    $pdf->Ln(6);
    pdf_compact_section($pdf, 'Notes');
    pdf_compact_multiline($pdf, 'Payroll Notes', $payroll['notes']);
}

$pdf->Ln(18);
$pdf->SetFont('Arial', '', 8);
$pdf->SetDrawColor(160, 170, 173);
$pdf->Cell(82, 6, pdf_text('Prepared by: __________________________'), 0, 0, 'L');
$pdf->Cell(16, 6, '', 0, 0);
$pdf->Cell(82, 6, pdf_text('Received by: __________________________'), 0, 1, 'L');
$pdf->Cell(82, 4, pdf_text('Payroll In-Charge / Finance Officer'), 0, 0, 'L');
$pdf->Cell(16, 4, '', 0, 0);
$pdf->Cell(82, 4, pdf_text('Employee Signature & Date'), 0, 1, 'L');

$pdf->Ln(6);
$pdf->SetFont('Arial', '', 7.5);
$pdf->SetTextColor(110, 123, 128);
$pdf->Cell(0, 5, pdf_text('Lipa City Colleges · G.A. Solis St., Lipa City, Batangas · Private and Confidential'), 0, 1, 'C');

$pdf->Output('I', 'payslip-' . $payroll['employee_no'] . '.pdf');
