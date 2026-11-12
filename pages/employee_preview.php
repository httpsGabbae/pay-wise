<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('<div class="notice err">Employee not found.</div>'); }
$stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
if (!$employee) { http_response_code(404); exit('<div class="notice err">Employee not found.</div>'); }

function record_value(string $label, $value, string $class=''): string {
    $value = trim((string)$value);
    return '<div class="record-field '.e($class).'" ><span>'.e($label).'</span><strong>'.e($value !== '' ? $value : 'Not provided').'</strong></div>';
}
$fullName = trim($employee['first_name'].' '.$employee['middle_name'].' '.$employee['last_name']);
$photo = trim((string)$employee['photo_path']);
?>
<div class="employee-dossier">
    <div class="dossier-top">
        <div class="dossier-person">
            <?php if ($photo): ?><img src="../<?php echo e(ltrim($photo, '/')); ?>" class="dossier-photo" alt="Employee photo"><?php else: ?><div class="dossier-photo dossier-photo-empty"><?php echo e(strtoupper(substr($employee['first_name'],0,1).substr($employee['last_name'],0,1))); ?></div><?php endif; ?>
            <div><h1><?php echo e($fullName); ?></h1><div class="dossier-id"><?php echo e($employee['employee_no']); ?> · <?php echo e($employee['employment_status']); ?></div></div>
        </div>
    </div>
    <div class="dossier-grid">
        <section class="dossier-section"><div class="dossier-section-head"><div><h3>Employment</h3></div></div><div class="dossier-list">
            <?php echo record_value('Department',$employee['department']); echo record_value('Position',$employee['position']); echo record_value('Status',$employee['employment_status']); echo record_value('Date Hired',$employee['date_hired'] ? date('M d, Y',strtotime($employee['date_hired'])) : ''); ?>
        </div></section>
        <section class="dossier-section"><div class="dossier-section-head"><div><h3>Personal</h3></div></div><div class="dossier-list">
            <?php echo record_value('First Name',$employee['first_name']); echo record_value('Middle Name',$employee['middle_name']); echo record_value('Last Name',$employee['last_name']); echo record_value('Gender',$employee['gender']); echo record_value('Birth Date',$employee['birth_date'] ? date('M d, Y',strtotime($employee['birth_date'])) : ''); echo record_value('Civil Status',$employee['civil_status']); echo record_value('Contact',$employee['contact_number']); echo record_value('Email',$employee['email']); ?>
        </div></section>
        <section class="dossier-section full"><div class="dossier-section-head"><div><h3>Addresses</h3></div></div><div class="dossier-list dossier-addresses">
            <?php echo record_value('Permanent Address',$employee['permanent_address'],'full'); echo record_value('Present Address',$employee['present_address'],'full'); ?>
        </div></section>
        <section class="dossier-section full"><div class="dossier-section-head"><div><h3>Government IDs</h3></div></div><div class="dossier-list">
            <?php echo record_value('SSS',$employee['sss_no']); echo record_value('PhilHealth',$employee['philhealth_no']); echo record_value('Pag-IBIG',$employee['pagibig_no']); echo record_value('TIN',$employee['tin_no']); echo record_value('ATM',$employee['atm_no']); ?>
        </div></section>
    </div>
    <div class="dossier-footer"><span>Lipa City Colleges</span><span>Updated <?php echo e(date('M d, Y',strtotime($employee['updated_at'] ?: $employee['created_at']))); ?></span></div>
</div>
