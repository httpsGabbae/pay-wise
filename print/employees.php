<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/pdf.php';

class EmployeeListPDF extends FPDF
{
    public function Header()
    {
        pdf_header($this, 'EMPLOYEE DIRECTORY', 'Employee master list');

        $this->SetFillColor(236, 247, 246);
        $this->SetDrawColor(210, 220, 222);
        $this->SetTextColor(8, 127, 123);
        $this->SetFont('Arial', 'B', 7.5);

        $this->Cell(25, 8, pdf_text('Employee ID'), 1, 0, 'L', true);
        $this->Cell(45, 8, pdf_text('Employee Name'), 1, 0, 'L', true);
        $this->Cell(63, 8, pdf_text('Department'), 1, 0, 'L', true);
        $this->Cell(25, 8, pdf_text('Position'), 1, 0, 'L', true);
        $this->Cell(22, 8, pdf_text('Salary'), 1, 1, 'R', true);

        $this->SetTextColor(23, 50, 59);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor(220, 228, 229);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(110, 123, 128);
        $this->Cell(0, 6, pdf_text('Employee Directory | Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
    }
}

function employee_list_lines(FPDF $pdf, string $text, float $width): int
{
    $text = trim($text);

    if ($text === '') {
        return 1;
    }

    $words = preg_split('/\s+/', $text);
    $lines = 1;
    $line = '';

    foreach ($words as $word) {
        $test = $line === '' ? $word : $line . ' ' . $word;

        if ($pdf->GetStringWidth($test) > $width - 4) {
            $lines++;
            $line = $word;
        } else {
            $line = $test;
        }
    }

    return $lines;
}

function employee_list_row(FPDF $pdf, array $row): void
{
    $id = pdf_text($row['employee_no'] ?: 'Not assigned');
    $name = pdf_text(employee_full_name_last_first($row));
    $department = pdf_text($row['department'] ?: 'Not assigned');
    $position = pdf_text($row['position'] ?: 'Not assigned');
    $salary = pdf_money($row['basic_salary']);

    $widths = [25, 45, 63, 25, 22];
    $lineHeight = 4.5;

    $pdf->SetFont('Arial', '', 7.5);

    $nameLines = employee_list_lines($pdf, $name, $widths[1]);
    $departmentLines = employee_list_lines($pdf, $department, $widths[2]);
    $positionLines = employee_list_lines($pdf, $position, $widths[3]);
    $maxLines = max($nameLines, $departmentLines, $positionLines);
    $rowHeight = max(8, $maxLines * $lineHeight + 2);

    if ($pdf->GetY() + $rowHeight > 277) {
        $pdf->AddPage();
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->SetDrawColor(40, 40, 40);
    $pdf->SetTextColor(20, 20, 20);

    foreach ($widths as $width) {
        $pdf->Rect($x, $y, $width, $rowHeight);
        $x += $width;
    }

    $x = $pdf->GetX();

    $pdf->SetXY($x + 2, $y + 1.5);
    $pdf->Cell($widths[0] - 4, 5, $id, 0, 0, 'L');

    $pdf->SetXY($x + $widths[0] + 2, $y + 1.5);
    $pdf->MultiCell($widths[1] - 4, $lineHeight, $name, 0, 'L');

    $pdf->SetXY($x + $widths[0] + $widths[1] + 2, $y + 1.5);
    $pdf->MultiCell($widths[2] - 4, $lineHeight, $department, 0, 'L');

    $pdf->SetXY($x + $widths[0] + $widths[1] + $widths[2] + 2, $y + 1.5);
    $pdf->MultiCell($widths[3] - 4, $lineHeight, $position, 0, 'L');

    $salaryX = $x + array_sum(array_slice($widths, 0, 4));
    $pdf->SetXY($salaryX + 2, $y + 1.5);
    $pdf->Cell($widths[4] - 4, 5, $salary, 0, 0, 'R');

    $pdf->SetXY($x, $y + $rowHeight);
}

$pdf = new EmployeeListPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 12, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$result = $conn->query('SELECT employee_no,first_name,middle_name,last_name,department,position,basic_salary FROM employees ORDER BY last_name,first_name');

if (!$result || $result->num_rows === 0) {
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(180, 10, pdf_text('No employee records found.'), 1, 1, 'C');
} else {
    while ($row = $result->fetch_assoc()) {
        employee_list_row($pdf, $row);
    }
}

$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(110, 123, 128);
$pdf->Cell(180, 5, pdf_text('END OF FILE'), 0, 1, 'C');

$pdf->Output('I', 'all-employees.pdf');
