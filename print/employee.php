<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/pdf.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    exit('Employee not found.');
}

$stmt = $conn->prepare(
    'SELECT * FROM employees WHERE employee_id = ? LIMIT 1'
);

$stmt->bind_param('i', $id);
$stmt->execute();

$employee = $stmt->get_result()->fetch_assoc();

if (!$employee) {
    exit('Employee not found.');
}


/*
|--------------------------------------------------------------------------
| Employee PDF
|--------------------------------------------------------------------------
*/

class EmployeePDF extends FPDF
{
    public function Footer()
    {
        pdf_footer(
            $this,
            'Employee Personal Information'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Create PDF
|--------------------------------------------------------------------------
*/

$pdf = new EmployeePDF(
    'P',
    'mm',
    'A4'
);

$pdf->AliasNbPages();

$pdf->SetMargins(
    15,
    10,
    15
);

$pdf->SetAutoPageBreak(
    true,
    16
);

$pdf->AddPage();


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

// Clean FPDF header with Lipa City Colleges seal
pdf_logo($pdf);
$pdf->SetXY(47, 10);
$pdf->SetFont('Arial', 'B', 15);
$pdf->SetTextColor(6, 59, 70);
$pdf->Cell(73, 7, pdf_text('LIPA CITY COLLEGES'), 0, 0, 'L');

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(100, 115, 120);
$pdf->Cell(75, 7, pdf_text('Printed: ' . date('M d, Y h:i A')), 0, 1, 'R');

$pdf->SetX(47);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(15, 127, 123);
$pdf->Cell(0, 5, pdf_text('EMPLOYEE RECORD'), 0, 1, 'L');

$pdf->SetX(47);
$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(105, 119, 125);
$pdf->Cell(0, 5, pdf_text('Official employee record'), 0, 1, 'L');

$pdf->SetDrawColor(15, 159, 154);
$pdf->SetLineWidth(0.7);
$pdf->Line(15, 39, 195, 39);
$pdf->SetLineWidth(0.2);
$pdf->SetTextColor(23, 50, 59);
$pdf->SetY(44);


/*
|--------------------------------------------------------------------------
| Employee Profile Box
|--------------------------------------------------------------------------
*/

$boxX = 15;
$boxY = $pdf->GetY();

$boxW = 180;
$boxH = 55;

$pdf->SetDrawColor(
    210,
    220,
    223
);

$pdf->SetFillColor(
    248,
    250,
    250
);

$pdf->Rect(
    $boxX,
    $boxY,
    $boxW,
    $boxH,
    'DF'
);


/*
|--------------------------------------------------------------------------
| Employee Photo
|--------------------------------------------------------------------------
*/

$photoWidth = 32;
$photoHeight = 40;

$photoX = $boxX + $boxW - $photoWidth - 5;
$photoY = $boxY + 5;

if (!empty($employee['photo_path'])) {

    $photoPath = __DIR__ . '/../' . ltrim(
        $employee['photo_path'],
        '/\\'
    );

    if (file_exists($photoPath)) {

        $pdf->Image(
            $photoPath,
            $photoX,
            $photoY,
            $photoWidth,
            $photoHeight
        );
    }
}


/*
|--------------------------------------------------------------------------
| Employee Name
|--------------------------------------------------------------------------
*/

$textX = $boxX + 7;
$textW = 125;

$fullName = trim(
    $employee['first_name'] . ' ' .
        $employee['middle_name'] . ' ' .
        $employee['last_name']
);

$pdf->SetXY(
    $textX,
    $boxY + 7
);

$pdf->SetFont(
    'Arial',
    'B',
    17
);

$pdf->SetTextColor(
    18,
    60,
    87
);

$pdf->MultiCell(
    $textW,
    8,
    pdf_text($fullName),
    0,
    'L'
);


/*
|--------------------------------------------------------------------------
| Employee ID
|--------------------------------------------------------------------------
*/

$currentY = $pdf->GetY() + 1;

$pdf->SetXY(
    $textX,
    $currentY
);

$pdf->SetFont(
    'Arial',
    'B',
    9
);

$pdf->SetTextColor(
    0,
    139,
    139
);

$pdf->Cell(
    35,
    6,
    'Employee ID',
    0,
    0,
    'L'
);

$pdf->SetFont(
    'Arial',
    '',
    9
);

$pdf->SetTextColor(
    18,
    60,
    87
);

$pdf->Cell(
    80,
    6,
    pdf_text($employee['employee_no']),
    0,
    1,
    'L'
);


/*
|--------------------------------------------------------------------------
| Department
|--------------------------------------------------------------------------
*/

$currentY = $pdf->GetY();

$pdf->SetXY(
    $textX,
    $currentY
);

$pdf->SetFont(
    'Arial',
    'B',
    9
);

$pdf->SetTextColor(
    18,
    60,
    87
);

$pdf->Cell(
    35,
    6,
    'Department',
    0,
    0,
    'L'
);

$pdf->SetFont(
    'Arial',
    '',
    9
);

$department = $employee['department']
    ?: 'Not assigned';

$pdf->MultiCell(
    80,
    6,
    pdf_text($department),
    0,
    'L'
);


/*
|--------------------------------------------------------------------------
| Position
|--------------------------------------------------------------------------
*/

$currentY = $pdf->GetY() + 1;

$pdf->SetXY(
    $textX,
    $currentY
);

$pdf->SetFont(
    'Arial',
    'B',
    9
);

$pdf->Cell(
    35,
    6,
    'Position',
    0,
    0,
    'L'
);

$pdf->SetFont(
    'Arial',
    '',
    9
);

$pdf->Cell(
    80,
    6,
    pdf_text(
        $employee['position']
            ?: 'Not assigned'
    ),
    0,
    1,
    'L'
);


/*
|--------------------------------------------------------------------------
| Move Below Profile Box
|--------------------------------------------------------------------------
*/

$pdf->SetY(
    $boxY + $boxH + 7
);


/*
|--------------------------------------------------------------------------
| Personal Information
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Personal Information'
);

pdf_compact_row(
    $pdf,
    'Gender',
    $employee['gender']
        ?: 'Not provided',
    'Birth Date',
    pdf_date(
        $employee['birth_date']
    )
);

pdf_compact_row(
    $pdf,
    'Civil Status',
    $employee['civil_status']
        ?: 'Not provided',
    'Nationality',
    $employee['nationality']
        ?: 'Not provided'
);

pdf_compact_row(
    $pdf,
    'Religion',
    $employee['religion']
        ?: 'Not provided',
    'Contact',
    $employee['contact_number']
        ?: 'Not provided'
);


/*
|--------------------------------------------------------------------------
| Contact Information
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Contact Information'
);

pdf_compact_single(
    $pdf,
    'Email',
    $employee['email']
        ?: 'Not provided'
);

pdf_compact_multiline(
    $pdf,
    'Permanent Address',
    $employee['permanent_address']
        ?: 'Not provided'
);

pdf_compact_multiline(
    $pdf,
    'Present Address',
    $employee['present_address']
        ?: 'Not provided'
);


/*
|--------------------------------------------------------------------------
| Employment
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Employment'
);


/*
|--------------------------------------------------------------------------
| Position
|--------------------------------------------------------------------------
*/

pdf_compact_single(
    $pdf,
    'Position',
    $employee['position']
        ?: 'Not assigned'
);


/*
|--------------------------------------------------------------------------
| Employment Status and Date Hired
|--------------------------------------------------------------------------
*/

pdf_compact_row(
    $pdf,
    'Status',
    $employee['employment_status']
        ?: 'Not assigned',
    'Date Hired',
    pdf_date(
        $employee['date_hired']
    )
);


/*
|--------------------------------------------------------------------------
| Basic Salary
|--------------------------------------------------------------------------
*/

pdf_compact_single(
    $pdf,
    'Basic Salary',
    pdf_money(
        $employee['basic_salary']
    )
);


/*
|--------------------------------------------------------------------------
| Government IDs
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Government IDs'
);

pdf_compact_row(
    $pdf,
    'SSS No.',
    $employee['sss_no']
        ?: 'Not provided',
    'PhilHealth',
    $employee['philhealth_no']
        ?: 'Not provided'
);

pdf_compact_row(
    $pdf,
    'Pag-IBIG',
    $employee['pagibig_no']
        ?: 'Not provided',
    'TIN No.',
    $employee['tin_no']
        ?: 'Not provided'
);

pdf_compact_single(
    $pdf,
    'ATM No.',
    $employee['atm_no']
        ?: 'Not provided'
);


/*
|--------------------------------------------------------------------------
| Emergency Contact
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Emergency Contact'
);

pdf_compact_row(
    $pdf,
    'Full Name',
    $employee['emergency_contact_name']
        ?: 'Not provided',
    'Phone',
    $employee['emergency_contact_phone']
        ?: 'Not provided'
);

pdf_compact_multiline(
    $pdf,
    'Address',
    $employee['emergency_contact_address']
        ?: 'Not provided'
);


/*
|--------------------------------------------------------------------------
| Dependent
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Dependent'
);

pdf_compact_row(
    $pdf,
    'Full Name',
    $employee['dependent_name']
        ?: 'Not provided',
    'Relationship',
    $employee['dependent_relationship']
        ?: 'Not provided'
);

pdf_compact_single(
    $pdf,
    'Birth Date',
    pdf_date(
        $employee['dependent_birth_date']
    )
);


/*
|--------------------------------------------------------------------------
| Education and Character Reference
|--------------------------------------------------------------------------
*/

pdf_compact_section(
    $pdf,
    'Education and Character Reference'
);

pdf_compact_multiline(
    $pdf,
    'Education',
    $employee['education_background']
        ?: 'Not provided'
);

pdf_compact_multiline(
    $pdf,
    'Reference',
    $employee['character_reference']
        ?: 'Not provided'
);


/*
|--------------------------------------------------------------------------
| Signature
|--------------------------------------------------------------------------
*/

$pdf->Ln(5);

$pdf->SetFont(
    'Arial',
    '',
    7
);

$pdf->SetTextColor(
    100,
    115,
    120
);

$pdf->Cell(
    90,
    5,
    pdf_text(
        'Prepared by: ______________________________'
    ),
    0,
    0,
    'L'
);

$pdf->Cell(
    90,
    5,
    pdf_text(
        'Date: ____________________'
    ),
    0,
    1,
    'R'
);


/*
|--------------------------------------------------------------------------
| END OF FILE
|--------------------------------------------------------------------------
*/

$pdf->Ln(5);

$pdf->SetFont(
    'Arial',
    'B',
    8
);

$pdf->SetTextColor(
    100,
    100,
    100
);

$pdf->Cell(
    0,
    6,
    '--- END OF FILE ---',
    0,
    1,
    'C'
);


/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

$pdf->Output(
    'I',
    'employee-' . $employee['employee_no'] . '.pdf'
);
