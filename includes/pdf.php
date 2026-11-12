<?php

require_once __DIR__ . '/../vendor/autoload.php';

function pdf_text($value): string
{
    $text = (string)$value;
    $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
    return $converted === false ? $text : $converted;
}

function pdf_money($value): string
{
    return 'PHP ' . number_format((float)$value, 2);
}

function pdf_date($value): string
{
    if (!$value) {
        return 'Not provided';
    }

    $time = strtotime((string)$value);
    return $time ? date('M d, Y', $time) : 'Not provided';
}

function pdf_logo(FPDF $pdf): void
{
    $logo = __DIR__ . '/../uploads/logo.png';

    if (is_file($logo)) {
        $pdf->Image($logo, 15, 12, 25, 25);
    }
}

function pdf_header(FPDF $pdf, string $title, string $subtitle = ''): void
{
    pdf_logo($pdf);

    $pdf->SetXY(47, 12);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(6, 59, 70);
    $pdf->Cell(0, 7, pdf_text('LIPA CITY COLLEGES'), 0, 1, 'L');

    $pdf->SetX(47);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(15, 127, 123);
    $pdf->Cell(0, 5, pdf_text($title), 0, 1, 'L');

    if ($subtitle !== '') {
        $pdf->SetX(47);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(105, 119, 125);
        $pdf->Cell(0, 5, pdf_text($subtitle), 0, 1, 'L');
    }

    $pdf->SetDrawColor(15, 159, 154);
    $pdf->SetLineWidth(0.7);
    $pdf->Line(15, 40, 195, 40);
    $pdf->SetLineWidth(0.2);
    $pdf->SetTextColor(23, 50, 59);
    $pdf->SetY(46);
}

function pdf_field(FPDF $pdf, string $label, string $value, float $labelWidth = 35, float $valueWidth = 55): void
{
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Cell($labelWidth, 7, pdf_text($label), 1, 0, 'L', true);

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($valueWidth, 7, pdf_text($value), 1, 0, 'L');
}

function pdf_field_row(FPDF $pdf, string $leftLabel, string $leftValue, string $rightLabel, string $rightValue): void
{
    pdf_field($pdf, $leftLabel, $leftValue, 32, 58);
    pdf_field($pdf, $rightLabel, $rightValue, 32, 58);
    $pdf->Ln();
}

function pdf_multiline(FPDF $pdf, string $label, string $value, float $labelWidth = 35, float $valueWidth = 145): void
{
    $value = $value !== '' ? $value : 'Not provided';
    $text = pdf_text($value);
    $lineHeight = 5;
    $lines = 1;
    $words = preg_split('/\s+/', $text);
    $line = '';

    foreach ($words as $word) {
        $test = $line === '' ? $word : $line . ' ' . $word;
        if ($pdf->GetStringWidth($test) > $valueWidth - 5) {
            $lines++;
            $line = $word;
        } else {
            $line = $test;
        }
    }

    $height = max(10, min(30, $lines * $lineHeight + 4));
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Rect($x, $y, $labelWidth, $height, 'DF');
    $pdf->Rect($x + $labelWidth, $y, $valueWidth, $height);

    $pdf->SetXY($x + 2, $y + 2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($labelWidth - 4, 5, pdf_text($label), 0, 0, 'L');

    $pdf->SetXY($x + $labelWidth + 2, $y + 2);
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell($valueWidth - 4, $lineHeight, $text, 0, 'L');

    $pdf->SetXY($x, $y + $height);
}

function pdf_compact_section(FPDF $pdf, string $title): void
{
    $pdf->Ln(2);
    $pdf->SetFillColor(236, 247, 246);
    $pdf->SetTextColor(8, 127, 123);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(180, 6, pdf_text(strtoupper($title)), 0, 1, 'L', true);
    $pdf->SetTextColor(23, 50, 59);
}

function pdf_compact_row(FPDF $pdf, string $leftLabel, string $leftValue, string $rightLabel, string $rightValue): void
{
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Cell(30, 6, pdf_text($leftLabel), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(60, 6, pdf_text($leftValue), 1, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(30, 6, pdf_text($rightLabel), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(60, 6, pdf_text($rightValue), 1, 1, 'L');
}

function pdf_compact_single(FPDF $pdf, string $label, string $value): void
{
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Cell(30, 6, pdf_text($label), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(150, 6, pdf_text($value), 1, 1, 'L');
}

function pdf_compact_multiline(FPDF $pdf, string $label, string $value): void
{
    $value = $value !== '' ? $value : 'Not provided';
    $text = pdf_text($value);
    $lineHeight = 4;
    $height = 8;
    if ($pdf->GetStringWidth($text) > 140) {
        $height = 12;
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(215, 225, 227);
    $pdf->Rect($x, $y, 30, $height, 'DF');
    $pdf->Rect($x + 30, $y, 150, $height);
    $pdf->SetXY($x + 2, $y + 1);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(26, 5, pdf_text($label), 0, 0, 'L');
    $pdf->SetXY($x + 32, $y + 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(146, $lineHeight, $text, 0, 'L');
    $pdf->SetXY($x, $y + $height);
}

function pdf_profile_box(FPDF $pdf, array $employee): void
{
    $startY = $pdf->GetY();

    $pdf->SetFillColor(248, 250, 250);
    $pdf->SetDrawColor(210, 220, 222);
    $pdf->Rect(15, $startY, 180, 43, 'DF');

    $photo = !empty($employee['photo_path']) ? __DIR__ . '/../' . ltrim($employee['photo_path'], '/') : '';
    if (is_file($photo)) {
        $pdf->Image($photo, 163, $startY + 4, 27, 34);
        $pdf->Rect(163, $startY + 4, 27, 34);
    } else {
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(120, 135, 140);
        $pdf->Rect(163, $startY + 4, 27, 34);
        $pdf->SetXY(164, $startY + 18);
        $pdf->Cell(25, 5, pdf_text('PHOTO'), 0, 0, 'C');
    }

    $pdf->SetXY(20, $startY + 6);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetTextColor(23, 50, 59);
    $pdf->Cell(135, 7, pdf_text(employee_name($employee)), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(15, 127, 123);
    $pdf->Cell(30, 6, pdf_text('Employee ID'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(23, 50, 59);
    $pdf->Cell(55, 6, pdf_text($employee['employee_no'] ?: 'Not assigned'), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(30, 6, pdf_text('Department'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(45, 6, pdf_text($employee['department'] ?: 'Not assigned'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(25, 6, pdf_text('Position'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(35, 6, pdf_text($employee['position'] ?: 'Not assigned'), 0, 1, 'L');

    $pdf->SetY($startY + 48);
    $pdf->SetTextColor(23, 50, 59);
}

function pdf_footer(FPDF $pdf, string $text): void
{
    $pdf->SetY(-15);
    $pdf->SetDrawColor(220, 228, 229);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->SetY(-12);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(110, 123, 128);
    $pdf->Cell(0, 6, pdf_text($text . ' | Page ' . $pdf->PageNo() . ' of {nb}'), 0, 0, 'C');
    $pdf->SetTextColor(23, 50, 59);
}
