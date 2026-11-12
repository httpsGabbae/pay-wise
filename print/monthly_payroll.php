<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/pdf.php';

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$stmt = $conn->prepare('SELECT p.*,e.employee_no,e.first_name,e.last_name,e.department,e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id WHERE p.period_start <= ? AND p.period_end >= ? ORDER BY e.last_name,e.first_name,p.period_end');
$stmt->bind_param('ss', $monthEnd, $monthStart);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$gross = 0;
$net = 0;
$deductions = 0;
$paid = 0;
$departments = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $gross += (float)$row['gross_pay'];
    $net += (float)$row['net_pay'];
    $deductions += (float)$row['deductions'];
    if ($row['status'] === 'Paid') $paid++;
    $d = $row['department'] ?: 'Unassigned';
    $departments[$d] = ($departments[$d] ?? 0) + 1;
}
arsort($departments);

// Six-month payroll data used by the printed report chart.
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -{$i} month", strtotime($monthStart)));
    $trend[$key] = ['label' => date('M', strtotime($key . '-01')), 'gross' => 0, 'net' => 0];
}
$trendStart = array_key_first($trend) . '-01';
$trendEnd = date('Y-m-t', strtotime($monthStart));
$trendStmt = $conn->prepare("SELECT DATE_FORMAT(period_end,'%Y-%m') AS ym, COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(net_pay),0) AS net FROM payroll_records WHERE period_end BETWEEN ? AND ? GROUP BY ym ORDER BY ym");
$trendStmt->bind_param('ss', $trendStart, $trendEnd);
$trendStmt->execute();
$trendResult = $trendStmt->get_result();
while ($trendRow = $trendResult->fetch_assoc()) {
    if (isset($trend[$trendRow['ym']])) {
        $trend[$trendRow['ym']]['gross'] = (float)$trendRow['gross'];
        $trend[$trendRow['ym']]['net'] = (float)$trendRow['net'];
    }
}
$trendMax = 1;
foreach ($trend as $point) $trendMax = max($trendMax, $point['gross'], $point['net']);

function pdf_chart_amount(float $value): string
{
    if ($value >= 1000000) return 'PHP ' . number_format($value / 1000000, 1) . 'M';
    if ($value >= 1000) return 'PHP ' . number_format($value / 1000, 0) . 'K';
    return 'PHP ' . number_format($value, 0);
}

class MonthlyPayrollPDF extends FPDF
{
    public function Header(): void
    {
        $w = $this->GetPageWidth();
        $logo = __DIR__ . '/../uploads/logo.png';
        if (is_file($logo)) $this->Image($logo, 15, 11, 23, 23);
        $this->SetXY(45, 11);
        $this->SetFont('Arial', 'B', 17);
        $this->SetTextColor(6, 59, 70);
        $this->Cell(0, 7, pdf_text('LIPA CITY COLLEGES'), 0, 1, 'L');
        $this->SetX(45);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(15, 127, 123);
        $this->Cell(0, 5, pdf_text('MONTHLY PAYROLL REPORT'), 0, 1, 'L');
        $this->SetX(45);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(105, 119, 125);
        $this->Cell(0, 5, pdf_text($GLOBALS['reportSubtitle']), 0, 1, 'L');
        $this->SetDrawColor(15, 159, 154);
        $this->SetLineWidth(.7);
        $this->Line(15, 39, $w - 15, 39);
        $this->SetLineWidth(.2);
        $this->SetY(46);
        $this->SetTextColor(23, 50, 59);
    }
    public function Footer(): void
    {
        $w = $this->GetPageWidth();
        $this->SetY(-15);
        $this->SetDrawColor(220, 228, 229);
        $this->Line(15, $this->GetY(), $w - 15, $this->GetY());
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(110, 123, 128);
        $this->Cell(0, 6, pdf_text('Lipa City Colleges · Private and Confidential · Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
    }
}

$reportSubtitle = date('F Y', strtotime($monthStart)) . ' · payroll period register';
$pdf = new MonthlyPayrollPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// Summary band
$summaryY = $pdf->GetY();
$summaryW = $pdf->GetPageWidth() - 24;
$boxW = ($summaryW - 9) / 4;
$summary = [['PAYROLL RECORDS', (string)count($rows)], ['GROSS PAYROLL', pdf_money($gross)], ['DEDUCTIONS', pdf_money($deductions)], ['NET PAYROLL', pdf_money($net)]];
foreach ($summary as $i => $box) {
    $x = 12 + $i * ($boxW + 3);
    $pdf->SetFillColor(242, 248, 248);
    $pdf->SetDrawColor(214, 225, 227);
    $pdf->Rect($x, $summaryY, $boxW, 20, 2, 'DF');
    $pdf->SetXY($x + 4, $summaryY + 3);
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetTextColor(96, 115, 121);
    $pdf->Cell($boxW - 8, 4, pdf_text($box[0]), 0, 1, 'L');
    $pdf->SetX($x + 4);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(6, 59, 70);
    $pdf->Cell($boxW - 8, 7, pdf_text($box[1]), 0, 0, 'L');
}
$pdf->SetY($summaryY + 27);
$pdf->SetFont('Arial', '', 7.5);
$pdf->SetTextColor(95, 110, 116);
$pdf->Cell(0, 5, pdf_text('Coverage: ' . date('M 01, Y', strtotime($monthStart)) . ' - ' . date('M d, Y', strtotime($monthEnd)) . ' · Paid records: ' . $paid . ' · Printed: ' . date('M d, Y h:i A')), 0, 1, 'L');
$pdf->Ln(2);

// Compact six-month bar graph.
$chartX = 12;
$chartY = $pdf->GetY();
$chartW = $summaryW;
$chartH = 34;
$pdf->SetFillColor(248, 250, 250);
$pdf->SetDrawColor(218, 227, 229);
$pdf->Rect($chartX, $chartY, $chartW, $chartH, 2, 'DF');
$pdf->SetXY($chartX + 4, $chartY + 3);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetTextColor(6, 59, 70);
$pdf->Cell(0, 4, pdf_text('SIX-MONTH PAYROLL OVERVIEW'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 6.2);
$pdf->SetTextColor(105, 119, 125);
$pdf->SetX($chartX + 4);
$pdf->Cell(0, 4, pdf_text('Gross and net payroll totals by month'), 0, 1, 'L');

$plotX = $chartX + 24;
$plotY = $chartY + 11;
$plotW = $chartW - 30;
$plotH = 18;
$pdf->SetDrawColor(226, 233, 234);
$pdf->Line($plotX, $plotY, $plotX + $plotW, $plotY);
$pdf->Line($plotX, $plotY + $plotH, $plotX + $plotW, $plotY + $plotH);
$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(120, 132, 137);
$pdf->SetXY($chartX + 2, $plotY - 2);
$pdf->Cell(20, 4, pdf_text(pdf_chart_amount($trendMax)), 0, 0, 'R');
$pdf->SetXY($chartX + 2, $plotY + $plotH - 2);
$pdf->Cell(20, 4, pdf_text('PHP 0'), 0, 0, 'R');

$groupW = $plotW / count($trend);
foreach (array_values($trend) as $idx => $point) {
    $centerX = $plotX + $idx * $groupW + ($groupW / 2);
    $grossH = $point['gross'] > 0 ? max(1.2, ($point['gross'] / $trendMax) * $plotH) : 0;
    $netH = $point['net'] > 0 ? max(1.2, ($point['net'] / $trendMax) * $plotH) : 0;
    $barW = 4.5;
    $gap = 1.5;
    if ($grossH > 0) {
        $pdf->SetFillColor(15, 159, 154);
        $pdf->Rect($centerX - $barW - $gap / 2, $plotY + $plotH - $grossH, $barW, $grossH, 'F');
    }
    if ($netH > 0) {
        $pdf->SetFillColor(6, 95, 105);
        $pdf->Rect($centerX + $gap / 2, $plotY + $plotH - $netH, $barW, $netH, 'F');
    }
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(105, 119, 125);
    $pdf->SetXY($centerX - 10, $plotY + $plotH + 1);
    $pdf->Cell(20, 4, pdf_text($point['label']), 0, 0, 'C');
}
$pdf->SetFont('Arial', '', 5.5);
$pdf->SetTextColor(95, 110, 116);
$pdf->SetFillColor(15, 159, 154);
$pdf->Rect($chartX + $chartW - 72, $chartY + 4, 2.5, 2.5, 'F');
$pdf->SetXY($chartX + $chartW - 68, $chartY + 3);
$pdf->Cell(18, 4, pdf_text('Gross'), 0, 0, 'L');
$pdf->SetFillColor(6, 95, 105);
$pdf->Rect($chartX + $chartW - 46, $chartY + 4, 2.5, 2.5, 'F');
$pdf->SetXY($chartX + $chartW - 42, $chartY + 3);
$pdf->Cell(18, 4, pdf_text('Net'), 0, 0, 'L');
$pdf->SetY($chartY + $chartH + 2);

$widths = [28, 61, 48, 48, 29, 29, 24];
$headers = ['Employee ID', 'Employee', 'Department', 'Pay Period', 'Gross', 'Net', 'Status'];
$pdf->SetFillColor(6, 59, 70);
$pdf->SetDrawColor(6, 59, 70);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 7.2);
foreach ($headers as $i => $h) $pdf->Cell($widths[$i], 8, pdf_text($h), 1, $i === count($headers) - 1 ? 1 : 0, 'L', true);
$pdf->SetTextColor(23, 50, 59);
$pdf->SetFont('Arial', '', 7.1);
if (!$rows) {
    $pdf->Cell(array_sum($widths), 10, pdf_text('No payroll records overlap this month.'), 1, 1, 'C');
} else foreach ($rows as $idx => $row) {
    if ($idx % 2 === 0) $pdf->SetFillColor(248, 251, 251);
    else $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(28, 7.5, pdf_text($row['employee_no']), 1, 0, 'L', true);
    $pdf->Cell(61, 7.5, pdf_text($row['last_name'] . ', ' . $row['first_name']), 1, 0, 'L', true);
    $pdf->Cell(48, 7.5, pdf_text($row['department'] ?: 'Unassigned'), 1, 0, 'L', true);
    $pdf->Cell(48, 7.5, pdf_text(pdf_date($row['period_start']) . ' - ' . pdf_date($row['period_end'])), 1, 0, 'L', true);
    $pdf->Cell(29, 7.5, pdf_money($row['gross_pay']), 1, 0, 'R', true);
    $pdf->Cell(29, 7.5, pdf_money($row['net_pay']), 1, 0, 'R', true);
    $pdf->Cell(24, 7.5, pdf_text($row['status']), 1, 1, 'C', true);
}

$pdf->Ln(7);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(6, 59, 70);
$pdf->Cell(0, 6, pdf_text('PAYROLL BY DEPARTMENT'), 0, 1, 'L');
$pdf->SetFont('Arial', '', 7.2);
$pdf->SetTextColor(80, 96, 102);
$colW = ($summaryW - 12) / 4;
$i = 0;
foreach (array_slice($departments, 0, 8, true) as $department => $count) {
    $x = 12 + ($i % 4) * ($colW + 4);
    $y = $pdf->GetY() + intdiv($i, 4) * 13;
    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(220, 228, 229);
    $pdf->Rect($x, $y, $colW, 10, 1.5, 'DF');
    $pdf->SetXY($x + 3, $y + 2);
    $pdf->Cell($colW - 22, 5, pdf_text($department), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7.2);
    $pdf->SetTextColor(6, 59, 70);
    $pdf->Cell(18, 5, (string)$count, 0, 0, 'R');
    $pdf->SetFont('Arial', '', 7.2);
    $pdf->SetTextColor(80, 96, 102);
    $i++;
}
if ($i > 0) $pdf->SetY($pdf->GetY() + intdiv($i - 1, 4) * 13 + 16);

$pdf->Ln(2);
$pdf->SetFont('Arial', '', 7.2);
$pdf->SetTextColor(100, 113, 118);
$pdf->Cell(0, 5, pdf_text('Prepared by: ________________________________    Date: ' . date('M d, Y')), 0, 1, 'L');
$pdf->Output('I', 'monthly-payroll-' . $month . '.pdf');
