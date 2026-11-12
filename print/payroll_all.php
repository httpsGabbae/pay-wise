<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/pdf.php';

class PayrollListPDF extends FPDF
{
    public function Header()
    {
        pdf_logo($this);
        $this->SetXY(47, 12);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(6, 59, 70);
        $this->Cell(0, 7, pdf_text('LIPA CITY COLLEGES'), 0, 1, 'L');
        $this->SetX(47);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(15, 127, 123);
        $this->Cell(0, 5, pdf_text('ALL PAYROLL RECORDS'), 0, 1, 'L');
        $this->SetX(47);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(105, 119, 125);
        $this->Cell(0, 5, pdf_text('Payroll master list'), 0, 1, 'L');
        $this->SetDrawColor(15, 159, 154);
        $this->SetLineWidth(0.7);
        $this->Line(15, 40, 282, 40);
        $this->SetLineWidth(0.2);
        $this->SetY(46);

        $this->SetFillColor(236, 247, 246);
        $this->SetDrawColor(210, 220, 222);
        $this->SetTextColor(8, 127, 123);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell(28, 8, pdf_text('Employee ID'), 1, 0, 'L', true);
        $this->Cell(50, 8, pdf_text('Employee'), 1, 0, 'L', true);
        $this->Cell(34, 8, pdf_text('Department'), 1, 0, 'L', true);
        $this->Cell(32, 8, pdf_text('Position'), 1, 0, 'L', true);
        $this->Cell(43, 8, pdf_text('Pay Period'), 1, 0, 'L', true);
        $this->Cell(32, 8, pdf_text('Gross Pay'), 1, 0, 'R', true);
        $this->Cell(32, 8, pdf_text('Net Pay'), 1, 0, 'R', true);
        $this->Cell(24, 8, pdf_text('Status'), 1, 1, 'C', true);
        $this->SetTextColor(23, 50, 59);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor(220, 228, 229);
        $this->Line(10, $this->GetY(), 287, $this->GetY());
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(110, 123, 128);
        $this->Cell(0, 6, pdf_text('Payroll Records | Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
    }
}

$pdf = new PayrollListPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 12, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();
$pdf->SetFont('Arial', '', 7.5);

$result = $conn->query('SELECT p.employee_id,e.employee_no,e.first_name,e.last_name,e.department,e.position,p.period_start,p.period_end,p.gross_pay,p.net_pay,p.status FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id ORDER BY p.payroll_id DESC');

if (!$result || $result->num_rows === 0) {
    $pdf->Cell(275, 10, pdf_text('No payroll records found.'), 1, 1, 'C');
} else {
    while ($row = $result->fetch_assoc()) {
        $period = pdf_date($row['period_start']) . ' - ' . pdf_date($row['period_end']);
        $pdf->Cell(28, 8, pdf_text($row['employee_no']), 1, 0, 'L');
        $pdf->Cell(50, 8, pdf_text($row['last_name'] . ', ' . $row['first_name']), 1, 0, 'L');
        $pdf->Cell(34, 8, pdf_text($row['department'] ?: 'Not assigned'), 1, 0, 'L');
        $pdf->Cell(32, 8, pdf_text($row['position'] ?: 'Not assigned'), 1, 0, 'L');
        $pdf->Cell(43, 8, pdf_text($period), 1, 0, 'L');
        $pdf->Cell(32, 8, pdf_money($row['gross_pay']), 1, 0, 'R');
        $pdf->Cell(32, 8, pdf_money($row['net_pay']), 1, 0, 'R');
        $pdf->Cell(24, 8, pdf_text($row['status']), 1, 1, 'C');
    }
}

$pdf->Output('I', 'all-payroll.pdf');
