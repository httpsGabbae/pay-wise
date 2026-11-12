<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $deleteId = (int)($_POST['employee_id'] ?? 0);
    if ($deleteId > 0) {
    $stmt = $conn->prepare('SELECT photo_path FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $deleteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare('DELETE FROM employees WHERE employee_id=?');
    $stmt->bind_param('i', $deleteId);
    $stmt->execute();

    if ($row) delete_employee_photo($row['photo_path']);
    }

    header('Location: employees.php?deleted=1');
    exit;
}

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'name';
$allowedFilters = ['name', 'position', 'department'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'name';

$where = '';
$params = [];
$types = '';

if ($search !== '') {
    if ($filter === 'name') {
        $where = "WHERE CONCAT(first_name, ' ', middle_name, ' ', last_name) LIKE ? OR CONCAT(last_name, ', ', first_name) LIKE ? OR employee_no LIKE ?";
        $term = '%' . $search . '%';
        $params = [$term, $term, $term];
        $types = 'sss';
    } else {
        $column = $filter === 'position' ? 'position' : 'department';
        $where = "WHERE $column LIKE ?";
        $params[] = '%' . $search . '%';
        $types = 's';
    }
}

$sql = "SELECT employee_id,employee_no,first_name,middle_name,last_name,photo_path,department,position,employment_status,basic_salary,created_at FROM employees $where ORDER BY last_name,first_name";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$employees = [];
while ($row = $result->fetch_assoc()) $employees[] = $row;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Employees</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261112-clean1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('employees'); ?>
    <main class="main">
        <?php topbar('Employees'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Employees <span class="count-pill"><?php echo count($employees); ?></span></h1>
                </div>
                <div class="print-tools">
                    <button type="button" class="btn btn-secondary" onclick="window.print()"><?php echo ui_icon('printer'); ?> Print</button>
                    <a class="btn btn-primary" href="personalinfo.php?mode=new"><?php echo ui_icon('plus'); ?> Add employee</a>
                </div>
            </div>

            <?php if (isset($_GET['deleted'])): ?><div class="notice ok">Employee deleted.</div><?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'notfound'): ?><div class="notice err">Employee not found.</div><?php endif; ?>

            <section class="card" style="overflow:hidden">
                <form class="toolbar" method="get">
                    <div><label>Search</label><div class="input-icon-wrap"><?php echo ui_icon('search'); ?><input name="search" value="<?php echo e($search); ?>" placeholder="Name, ID, position, or department"></div></div>
                    <div><label>Filter by</label><select name="filter">
                        <option value="name" <?php echo $filter === 'name' ? 'selected' : ''; ?>>Name / ID</option>
                        <option value="position" <?php echo $filter === 'position' ? 'selected' : ''; ?>>Position</option>
                        <option value="department" <?php echo $filter === 'department' ? 'selected' : ''; ?>>Department</option>
                    </select></div>
                    <button class="btn btn-primary" type="submit"><?php echo ui_icon('search'); ?> Search</button>
                    <?php if ($search !== ''): ?><a class="btn btn-secondary" href="employees.php">Clear</a><?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table class="table employee-table">
                        <thead><tr><th>Employee</th><th>ID</th><th>Department</th><th>Position</th><th>Status</th><th class="action-col">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$employees): ?>
                            <tr><td colspan="6" class="empty">No matching employees.</td></tr>
                        <?php else: foreach ($employees as $row): ?>
                            <tr>
                                <td><div class="person-cell">
                                    <?php if (!empty($row['photo_path'])): ?><img class="list-photo" src="../<?php echo e(ltrim($row['photo_path'], '/')); ?>" alt="">
                                    <?php else: ?><div class="list-avatar"><?php echo e(strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1))); ?></div><?php endif; ?>
                                    <div><strong><?php echo e(employee_full_name_last_first($row)); ?></strong></div>
                                </div></td>
                                <td><span class="employee-no"><?php echo e($row['employee_no']); ?></span></td>
                                <td><?php echo e($row['department'] ?: '—'); ?></td>
                                <td><?php echo e($row['position'] ?: '—'); ?></td>
                                <td><span class="badge <?php echo ['Regular' => 'badge-ok', 'Probationary' => 'badge-info', 'Contractual' => 'badge-violet', 'Part-Time' => 'badge-neutral'][$row['employment_status']] ?? 'badge-neutral'; ?>"><?php echo e($row['employment_status']); ?></span></td>
                                <td class="action-col">
                                    <div class="icon-actions">
                                        <button type="button" class="icon-action view" title="View record" aria-label="View record" onclick="openEmployeePreview(<?php echo (int)$row['employee_id']; ?>)"><?php echo ui_icon('eye'); ?></button>
                                        <button type="button" class="icon-action salary" title="Compensation" aria-label="Compensation" onclick="openSalaryModal(<?php echo (int)$row['employee_id']; ?>)"><?php echo ui_icon('wallet'); ?></button><form method="post" action="../employee/login.php" target="_blank" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="admin_preview"><input type="hidden" name="employee_no" value="<?php echo e($row['employee_no']); ?>"><button type="submit" class="icon-action" title="Open portal" aria-label="Open portal">↗</button></form>
                                        <a class="icon-action edit" title="Edit" aria-label="Edit" href="personalinfo.php?edit=<?php echo (int)$row['employee_id']; ?>"><?php echo ui_icon('pencil'); ?></a>
                                        <form method="post" action="employees.php" style="display:inline" onsubmit="return confirm('Delete this employee? This cannot be undone.')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="employee_id" value="<?php echo (int)$row['employee_id']; ?>"><button type="submit" class="icon-action delete" title="Delete" aria-label="Delete"><?php echo ui_icon('trash'); ?></button></form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<div id="employeePreviewModal" class="detail-modal-overlay" aria-hidden="true">
    <div class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="employeePreviewTitle">
        <div class="detail-modal-head"><h2 id="employeePreviewTitle">Employee Record</h2><div class="modal-head-actions"><button type="button" class="icon-action print" title="Print" aria-label="Print" onclick="printEmployeePreview()"><?php echo ui_icon('printer'); ?></button><button type="button" class="modal-icon-close" onclick="closeEmployeePreview()" aria-label="Close"><?php echo ui_icon('close'); ?></button></div></div>
        <div id="employeePreviewBody" class="detail-modal-body"><div class="preview-loading"><span class="spinner"></span> Loading…</div></div>
    </div>
</div>

<div id="salaryModal" class="detail-modal-overlay" aria-hidden="true">
    <div class="detail-modal salary-modal" role="dialog" aria-modal="true" aria-labelledby="salaryModalTitle">
        <div class="detail-modal-head"><h2 id="salaryModalTitle">Compensation</h2><button type="button" class="modal-icon-close" onclick="closeSalaryModal()" aria-label="Close"><?php echo ui_icon('close'); ?></button></div>
        <div id="salaryModalBody" class="detail-modal-body"><div class="preview-loading"><span class="spinner"></span> Loading…</div></div>
    </div>
</div>

<script>
async function openEmployeePreview(id) {
    const overlay = document.getElementById('employeePreviewModal');
    const body = document.getElementById('employeePreviewBody');
    overlay.classList.add('show'); overlay.setAttribute('aria-hidden','false');
    body.innerHTML = '<div class="preview-loading"><span class="spinner"></span> Loading employee record…</div>';
    try {
        const response = await fetch('employee_preview.php?id=' + encodeURIComponent(id), {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok) throw new Error('Request failed');
        body.innerHTML = await response.text();
    } catch (error) {
        body.innerHTML = '<div class="notice err">Unable to load this employee record. Please try again.</div>';
    }
}
function closeEmployeePreview(){ const el=document.getElementById('employeePreviewModal'); el.classList.remove('show'); el.setAttribute('aria-hidden','true'); }
async function openSalaryModal(id) {
    const overlay = document.getElementById('salaryModal');
    const body = document.getElementById('salaryModalBody');
    overlay.classList.add('show'); overlay.setAttribute('aria-hidden','false');
    body.innerHTML = '<div class="preview-loading"><span class="spinner"></span> Loading compensation details…</div>';
    try {
        const response = await fetch('salary_info.php?id=' + encodeURIComponent(id), {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok) throw new Error('Request failed');
        body.innerHTML = await response.text();
    } catch (error) {
        body.innerHTML = '<div class="notice err">Unable to load salary information. Please try again.</div>';
    }
}
function closeSalaryModal(){ const el=document.getElementById('salaryModal'); el.classList.remove('show'); el.setAttribute('aria-hidden','true'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeEmployeePreview(); closeSalaryModal(); } });
document.querySelectorAll('.detail-modal-overlay').forEach(function(el){ el.addEventListener('click', function(e){ if(e.target===el){ el.classList.remove('show'); el.setAttribute('aria-hidden','true'); } }); });
function printEmployeePreview(){
    const overlay=document.getElementById('employeePreviewModal');
    if(!overlay.classList.contains('show')) return;
    window.print();
}
</script>
</body>
</html>
