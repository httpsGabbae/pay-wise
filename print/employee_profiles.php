<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/pdf.php';

class EmployeeProfilesPDF extends FPDF
{
    public function Footer()
    {
        pdf_footer($this, 'Employee Personal Information');
    }
}

function render_employee_profile(FPDF $pdf, array $employee): void
{
    pdf_header($pdf, 'EMPLOYEE PERSONAL INFORMATION', 'Official employee record');
    pdf_profile_box($pdf, $employee);

    pdf_compact_section($pdf, 'Personal Information');
    pdf_compact_row($pdf, 'Gender', $employee['gender'] ?: 'Not provided', 'Birth Date', pdf_date($employee['birth_date']));
    pdf_compact_row($pdf, 'Civil Status', $employee['civil_status'] ?: 'Not provided', 'Nationality', $employee['nationality'] ?: 'Not provided');
    pdf_compact_row($pdf, 'Religion', $employee['religion'] ?: 'Not provided', 'Contact', $employee['contact_number'] ?: 'Not provided');

    pdf_compact_section($pdf, 'Contact Information');
    pdf_compact_single($pdf, 'Email', $employee['email'] ?: 'Not provided');
    pdf_compact_multiline($pdf, 'Permanent Address', $employee['permanent_address'] ?: 'Not provided');
    pdf_compact_multiline($pdf, 'Present Address', $employee['present_address'] ?: 'Not provided');

    pdf_compact_section($pdf, 'Employment');
    pdf_compact_row($pdf, 'Department', $employee['department'] ?: 'Not assigned', 'Position', $employee['position'] ?: 'Not assigned');
    pdf_compact_row($pdf, 'Status', $employee['employment_status'] ?: 'Not assigned', 'Date Hired', pdf_date($employee['date_hired']));
    pdf_compact_single($pdf, 'Basic Salary', pdf_money($employee['basic_salary']));

    pdf_compact_section($pdf, 'Government IDs');
    pdf_compact_row($pdf, 'SSS No.', $employee['sss_no'] ?: 'Not provided', 'PhilHealth', $employee['philhealth_no'] ?: 'Not provided');
    pdf_compact_row($pdf, 'Pag-IBIG', $employee['pagibig_no'] ?: 'Not provided', 'TIN No.', $employee['tin_no'] ?: 'Not provided');
    pdf_compact_single($pdf, 'ATM No.', $employee['atm_no'] ?: 'Not provided');

    pdf_compact_section($pdf, 'Emergency Contact');
    pdf_compact_row($pdf, 'Full Name', $employee['emergency_contact_name'] ?: 'Not provided', 'Phone', $employee['emergency_contact_phone'] ?: 'Not provided');
    pdf_compact_multiline($pdf, 'Address', $employee['emergency_contact_address'] ?: 'Not provided');

    pdf_compact_section($pdf, 'Dependent');
    pdf_compact_row($pdf, 'Full Name', $employee['dependent_name'] ?: 'Not provided', 'Relationship', $employee['dependent_relationship'] ?: 'Not provided');
    pdf_compact_single($pdf, 'Birth Date', pdf_date($employee['dependent_birth_date']));

    pdf_compact_section($pdf, 'Education and Character Reference');
    pdf_compact_multiline($pdf, 'Education', $employee['education_background'] ?: 'Not provided');
    pdf_compact_multiline($pdf, 'Reference', $employee['character_reference'] ?: 'Not provided');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(100, 115, 120);
    $pdf->Cell(90, 5, pdf_text('Prepared by: __________________________'), 0, 0, 'L');
    $pdf->Cell(90, 5, pdf_text('Date: __________________'), 0, 1, 'R');
}

$pdf = new EmployeeProfilesPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 16);

$result = $conn->query('SELECT * FROM employees ORDER BY last_name,first_name');

if (!$result || $result->num_rows === 0) {
    $pdf->AddPage();
    pdf_header($pdf, 'EMPLOYEE PERSONAL INFORMATION', 'No employee records found');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 10, pdf_text('There are no employee records to print.'), 0, 1, 'C');
} else {
    while ($employee = $result->fetch_assoc()) {
        $pdf->AddPage();
        render_employee_profile($pdf, $employee);
    }
}

$pdf->Output('I', 'all-employee-personal-information.pdf');
