<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';

$editId = (int)($_GET['edit'] ?? 0);
$employee = null;
$message = '';
$error = '';

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    if (!$employee) {
        header('Location: employees.php?error=notfound');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['employee_id'] ?? 0);
    $first = trim($_POST['first_name'] ?? '');
    $middle = trim($_POST['middle_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $gender = $_POST['gender'] ?? 'Other';
    $birth = trim($_POST['birth_date'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $civil = trim($_POST['civil_status'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $permanent = trim($_POST['permanent_address'] ?? '');
    $present = trim($_POST['present_address'] ?? '');
    $sss = trim($_POST['sss_no'] ?? '');
    $phil = trim($_POST['philhealth_no'] ?? '');
    $pagibig = trim($_POST['pagibig_no'] ?? '');
    $tin = trim($_POST['tin_no'] ?? '');
    $atm = trim($_POST['atm_no'] ?? '');
    $portalInput = trim($_POST['portal_password'] ?? '');
    $portal = '';
    if ($portalInput !== '') {
        $portal = password_hash($portalInput, PASSWORD_DEFAULT);
    }
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $status = $_POST['employment_status'] ?? 'Regular';
    $salary = (float)($_POST['basic_salary'] ?? 0);
    $dateHired = trim($_POST['date_hired'] ?? '') ?: null;
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergencyAddress = trim($_POST['emergency_contact_address'] ?? '');
    $dependentName = trim($_POST['dependent_name'] ?? '');
    $dependentRelationship = trim($_POST['dependent_relationship'] ?? '');
    $dependentBirth = trim($_POST['dependent_birth_date'] ?? '') ?: null;
    $education = trim($_POST['education_background'] ?? '');
    $reference = trim($_POST['character_reference'] ?? '');

    if ($first === '' || $last === '') {
        $error = 'First name and last name are required.';
    } elseif ($salary < 0) {
        $error = 'Salary cannot be negative.';
    } elseif (!in_array($status, ['Regular', 'Probationary', 'Contractual', 'Part-Time'], true)) {
        $error = 'Please choose a valid employment status.';
    }

    $currentPhoto = null;
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT photo_path FROM employees WHERE employee_id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $currentPhoto = $stmt->get_result()->fetch_assoc()['photo_path'] ?? null;
    }

    if ($id > 0 && $portalInput === '') {
        $existingPortal = $conn->prepare('SELECT portal_password FROM employees WHERE employee_id=? LIMIT 1');
        $existingPortal->bind_param('i', $id);
        $existingPortal->execute();
        $portal = $existingPortal->get_result()->fetch_assoc()['portal_password'] ?? null;
    }

    if ($error === '') {
        $photo = upload_employee_photo($_FILES['employee_photo'] ?? null, $currentPhoto);
        if (is_array($photo)) {
            $error = $photo['error'];
        } else {
            $photoPath = $photo;
        }
    }

    if ($error === '') {
        if ($id > 0) {
            $sql = 'UPDATE employees SET portal_password=?,photo_path=?,first_name=?,middle_name=?,last_name=?,gender=?,birth_date=?,email=?,contact_number=?,civil_status=?,nationality=?,religion=?,permanent_address=?,present_address=?,sss_no=?,philhealth_no=?,pagibig_no=?,tin_no=?,atm_no=?,department=?,position=?,employment_status=?,basic_salary=?,date_hired=?,emergency_contact_name=?,emergency_contact_phone=?,emergency_contact_address=?,dependent_name=?,dependent_relationship=?,dependent_birth_date=?,education_background=?,character_reference=? WHERE employee_id=?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssssssssssssdssssssssi', $portal, $photoPath, $first, $middle, $last, $gender, $birth, $email, $contact, $civil, $nationality, $religion, $permanent, $present, $sss, $phil, $pagibig, $tin, $atm, $department, $position, $status, $salary, $dateHired, $emergencyName, $emergencyPhone, $emergencyAddress, $dependentName, $dependentRelationship, $dependentBirth, $education, $reference, $id);
        } else {
            $employeeNo = next_employee_no($conn);
            $sql = 'INSERT INTO employees(employee_no,portal_password,photo_path,first_name,middle_name,last_name,gender,birth_date,email,contact_number,civil_status,nationality,religion,permanent_address,present_address,sss_no,philhealth_no,pagibig_no,tin_no,atm_no,department,position,employment_status,basic_salary,date_hired,emergency_contact_name,emergency_contact_phone,emergency_contact_address,dependent_name,dependent_relationship,dependent_birth_date,education_background,character_reference) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssssssssssssdsssssssss', $employeeNo, $portal, $photoPath, $first, $middle, $last, $gender, $birth, $email, $contact, $civil, $nationality, $religion, $permanent, $present, $sss, $phil, $pagibig, $tin, $atm, $department, $position, $status, $salary, $dateHired, $emergencyName, $emergencyPhone, $emergencyAddress, $dependentName, $dependentRelationship, $dependentBirth, $education, $reference);
        }

        if ($stmt->execute()) {
            $savedId = $id > 0 ? $id : $conn->insert_id;
            header('Location: personalinfo.php?edit=' . $savedId . '&saved=1');
            exit;
        }

        $error = 'Unable to save the employee. Please check the information and try again.';
    }
}

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
}

if (isset($_GET['saved'])) {
    $message = 'Employee information saved successfully.';
}

function field_value(?array $employee, string $key): string
{
    return e($employee[$key] ?? '');
}

$disabled = '';
$isNew = !$employee;

$departments = [];
$departmentResult = $conn->query("SELECT department_name FROM departments WHERE status='Active' ORDER BY department_name ASC");
if ($departmentResult) {
    while ($row = $departmentResult->fetch_assoc()) {
        $departments[] = $row['department_name'];
    }
}

if (!$departments) {
    $departments = [
        'Operations',
        'Finance & Accounting',
        'Human Resources',
        'Information Technology',
        'Sales & Marketing',
        'Customer Support'
    ];
}

$positions = [];
$positionResult = $conn->query("SELECT d.department_name, p.position_name FROM positions p INNER JOIN departments d ON d.department_id=p.department_id WHERE d.status='Active' ORDER BY d.department_name ASC, p.position_name ASC");
if ($positionResult) {
    while ($row = $positionResult->fetch_assoc()) {
        $positions[$row['department_name']][] = $row['position_name'];
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · <?php echo $employee ? 'Edit Employee' : 'New Employee'; ?></title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261112-clean1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
    <div class="app">
        <?php sidebar('employees'); ?>
        <main class="main">
            <?php topbar($employee ? 'Edit Employee' : 'New Employee'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <h1><?php echo $employee ? e(employee_name($employee)) : 'New Employee'; ?></h1>
                        <div class="employee-id-summary"><span>ID</span><strong><?php echo $employee ? e($employee['employee_no']) : e(next_employee_no($conn)); ?></strong></div>
                    </div>
                    <div class="print-tools">
                        <a class="btn btn-secondary" href="employees.php"><?php echo ui_icon('arrow-left'); ?> Back</a>
                        <?php if ($employee): ?><button type="button" class="btn btn-secondary" onclick="printEmployeeInformation(<?php echo (int)$employee['employee_id']; ?>)"><?php echo ui_icon('printer'); ?> Print</button><?php endif; ?>
                    </div>
                </div>

                <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

                <form id="employeeForm" class="form-card" method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="employee_id" value="<?php echo (int)($employee['employee_id'] ?? 0); ?>">
                    <div class="form-body">
                        <div class="section-title">Personal</div>
                        <div class="form-grid">
                            <div class="field"><label>First name <span class="required">*</span></label><input name="first_name" value="<?php echo field_value($employee, 'first_name'); ?>" required></div>
                            <div class="field"><label>Middle name</label><input name="middle_name" value="<?php echo field_value($employee, 'middle_name'); ?>"></div>
                            <div class="field"><label>Last name <span class="required">*</span></label><input name="last_name" value="<?php echo field_value($employee, 'last_name'); ?>" required></div>
                            <div class="field"><label>Gender</label><select name="gender">
                                    <option value="Male" <?php echo ($employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo ($employee['gender'] ?? 'Other') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select></div>
                            <div class="field"><label>Birth date</label><input type="date" name="birth_date" value="<?php echo field_value($employee, 'birth_date'); ?>"></div>
                            <div class="field"><label>Civil status</label><input name="civil_status" value="<?php echo field_value($employee, 'civil_status'); ?>"></div>
                            <div class="field"><label>Nationality</label><input name="nationality" value="<?php echo field_value($employee, 'nationality'); ?>"></div>
                            <div class="field"><label>Religion</label><input name="religion" value="<?php echo field_value($employee, 'religion'); ?>"></div>
                        </div>

                        <div class="section-title">Employment & Compensation</div>
                        <div class="form-grid">
                            <div class="field"><label>Department</label><select name="department">
                                    <option value="">Select department</option><?php foreach ($departments as $d): ?><option value="<?php echo e($d); ?>" <?php echo ($employee['department'] ?? '') === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="field">
                                <label>Position</label>
                                <select name="position" id="position">
                                    <option value="">Select position</option>
                                </select>
                            </div>
                            <div class="field"><label>Status</label><select name="employment_status"><?php foreach (['Regular', 'Probationary', 'Contractual', 'Part-Time'] as $status): ?><option value="<?php echo $status; ?>" <?php echo ($employee['employment_status'] ?? 'Regular') === $status ? 'selected' : ''; ?>><?php echo $status; ?></option><?php endforeach; ?></select></div>
                            <div class="field"><label>Basic salary (₱)</label><input type="number" min="0" step="0.01" name="basic_salary" value="<?php echo field_value($employee, 'basic_salary'); ?>"></div>
                            <div class="field"><label>Date hired</label><input type="date" name="date_hired" value="<?php echo field_value($employee, 'date_hired'); ?>"></div>
                            <div class="field"><label>Photo <span class="mini">JPG/PNG, max 2MB</span></label><input type="file" name="employee_photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></div>
                        </div>
                        <?php if ($employee && !empty($employee['photo_path'])): ?><div style="margin-top:8px"><img class="profile-photo" src="../<?php echo e(ltrim($employee['photo_path'], '/')); ?>" alt="Employee photo"></div><?php endif; ?>

                        <div class="section-title">Contact & Portal Access</div>
                        <div class="form-grid">
                            <div class="field"><label>Phone</label><input name="contact_number" value="<?php echo field_value($employee, 'contact_number'); ?>"></div>
                            <div class="field"><label>Email</label><input type="email" name="email" value="<?php echo field_value($employee, 'email'); ?>"></div>
                            <div class="field"><label>Portal password</label><input type="password" name="portal_password" value="" placeholder="<?php echo $employee ? 'Leave blank to keep current' : 'Set portal password'; ?>"></div>
                            <div class="field full"><label>Present address</label><textarea name="present_address" rows="2"><?php echo field_value($employee, 'present_address'); ?></textarea></div>
                            <div class="field full"><label>Permanent address</label><textarea name="permanent_address" rows="2"><?php echo field_value($employee, 'permanent_address'); ?></textarea></div>
                        </div>

                        <div class="section-title">Government & Bank IDs</div>
                        <div class="form-grid">
                            <div class="field"><label>SSS</label><input name="sss_no" value="<?php echo field_value($employee, 'sss_no'); ?>"></div>
                            <div class="field"><label>PhilHealth</label><input name="philhealth_no" value="<?php echo field_value($employee, 'philhealth_no'); ?>"></div>
                            <div class="field"><label>Pag-IBIG</label><input name="pagibig_no" value="<?php echo field_value($employee, 'pagibig_no'); ?>"></div>
                            <div class="field"><label>TIN</label><input name="tin_no" value="<?php echo field_value($employee, 'tin_no'); ?>"></div>
                            <div class="field"><label>ATM / Bank account</label><input name="atm_no" value="<?php echo field_value($employee, 'atm_no'); ?>"></div>
                        </div>

                        <div class="section-title">Emergency Contact & Dependent</div>
                        <div class="form-grid">
                            <div class="field"><label>Emergency contact</label><input name="emergency_contact_name" value="<?php echo field_value($employee, 'emergency_contact_name'); ?>"></div>
                            <div class="field"><label>Emergency phone</label><input name="emergency_contact_phone" value="<?php echo field_value($employee, 'emergency_contact_phone'); ?>"></div>
                            <div class="field"><label>Emergency address</label><input name="emergency_contact_address" value="<?php echo field_value($employee, 'emergency_contact_address'); ?>"></div>
                            <div class="field"><label>Dependent name</label><input name="dependent_name" value="<?php echo field_value($employee, 'dependent_name'); ?>"></div>
                            <div class="field"><label>Relationship</label><input name="dependent_relationship" value="<?php echo field_value($employee, 'dependent_relationship'); ?>"></div>
                            <div class="field"><label>Dependent birth date</label><input type="date" name="dependent_birth_date" value="<?php echo field_value($employee, 'dependent_birth_date'); ?>"></div>
                        </div>

                        <div class="section-title">Background & References</div>
                        <div class="form-grid">
                            <div class="field full"><label>Education</label><textarea name="education_background" rows="2"><?php echo field_value($employee, 'education_background'); ?></textarea></div>
                            <div class="field full"><label>Character reference</label><textarea name="character_reference" rows="2"><?php echo field_value($employee, 'character_reference'); ?></textarea></div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button id="saveButton" class="btn btn-primary" type="submit">Save employee</button>
                        <a class="btn btn-secondary" href="employees.php">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <script>
        const positions = <?php echo json_encode($positions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
        const departmentSelect = document.querySelector('select[name="department"]');
        const positionSelect = document.getElementById('position');
        const currentPosition = <?php echo json_encode($employee['position'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

        function loadPositions(keepSelected) {
            const selectedDepartment = departmentSelect.value;
            positionSelect.innerHTML = '<option value="">Select position</option>';
            if (positions[selectedDepartment]) {
                positions[selectedDepartment].forEach(function(position) {
                    const option = document.createElement('option');
                    option.value = position;
                    option.textContent = position;
                    if (keepSelected && position === currentPosition) {
                        option.selected = true;
                    }
                    positionSelect.appendChild(option);
                });
            }
        }
        departmentSelect.addEventListener('change', function() { loadPositions(false); });
        loadPositions(true);
    </script>
<div id="nativeEmployeePrintSheet" class="native-print-sheet" aria-hidden="true"></div>
<script>
async function printEmployeeInformation(id){
    const sheet=document.getElementById('nativeEmployeePrintSheet');
    sheet.innerHTML='<div class="print-loading">Preparing employee record…</div>';
    sheet.classList.add('active');
    try{
        const response=await fetch('employee_preview.php?id='+encodeURIComponent(id),{headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!response.ok) throw new Error('Request failed');
        sheet.innerHTML=await response.text();
        window.print();
    }catch(error){
        sheet.innerHTML='<div class="print-error">Unable to prepare the employee record.</div>';
        window.print();
    }
    window.addEventListener('afterprint',function(){sheet.classList.remove('active');sheet.innerHTML='';},{once:true});
}
</script>

</body>

</html>