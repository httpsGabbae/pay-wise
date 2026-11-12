<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';

$error = '';
$message = '';
$editDepartment = null;
$positionError = '';
$positionMessage = '';
$departmentPositions = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $name = trim($_POST['department_name'] ?? '');
    $code = strtoupper(trim($_POST['department_code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if ($action === 'save_position') {
        $positionDepartmentId = (int)($_POST['position_department_id'] ?? 0);
        $positionName = trim($_POST['position_name'] ?? '');
        if ($positionDepartmentId <= 0 || $positionName === '') {
            $positionError = 'Department and position name are required.';
        } else {
            $stmt = $conn->prepare('SELECT position_id FROM positions WHERE department_id=? AND position_name=? LIMIT 1');
            $stmt->bind_param('is', $positionDepartmentId, $positionName);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $positionError = 'That position already exists in this department.';
            } else {
                $stmt = $conn->prepare('INSERT INTO positions (department_id, position_name) VALUES (?, ?)');
                $stmt->bind_param('is', $positionDepartmentId, $positionName);
                if ($stmt->execute()) {
                    header('Location: departments.php?position_saved=1#positions');
                    exit;
                }
                $positionError = 'Unable to save the position. Please try again.';
            }
        }
    }

    if ($action === 'delete_position') {
        $positionId = (int)($_POST['position_id'] ?? 0);
        if ($positionId > 0) {
            $stmt = $conn->prepare('SELECT p.position_name, p.department_id, d.department_name FROM positions p INNER JOIN departments d ON d.department_id=p.department_id WHERE p.position_id=? LIMIT 1');
            $stmt->bind_param('i', $positionId);
            $stmt->execute();
            $position = $stmt->get_result()->fetch_assoc();
            if ($position) {
                $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM employees WHERE department=? AND position=?');
                $stmt->bind_param('ss', $position['department_name'], $position['position_name']);
                $stmt->execute();
                $assigned = (int)$stmt->get_result()->fetch_assoc()['total'];
                if ($assigned > 0) {
                    $positionError = 'This position cannot be deleted because it is assigned to ' . $assigned . ' employee' . ($assigned === 1 ? '' : 's') . '.';
                } else {
                    $stmt = $conn->prepare('DELETE FROM positions WHERE position_id=?');
                    $stmt->bind_param('i', $positionId);
                    if ($stmt->execute()) {
                        header('Location: departments.php?position_deleted=1#positions');
                        exit;
                    }
                    $positionError = 'Unable to delete the position.';
                }
            }
        }
    }

    if ($action === 'save') {
        if ($name === '') {
            $error = 'Department name is required.';
        } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
            $error = 'Please choose a valid department status.';
        } else {
            $stmt = $conn->prepare('SELECT department_id FROM departments WHERE department_name=? AND department_id<>? LIMIT 1');
            $stmt->bind_param('si', $name, $departmentId);
            $stmt->execute();

            if ($stmt->get_result()->fetch_assoc()) {
                $error = 'That department already exists.';
            } else {
                if ($departmentId > 0) {
                    $stmt = $conn->prepare('UPDATE departments SET department_name=?,department_code=?,description=?,status=? WHERE department_id=?');
                    $stmt->bind_param('ssssi', $name, $code, $description, $status, $departmentId);
                    $success = $stmt->execute();
                } else {
                    $stmt = $conn->prepare('INSERT INTO departments(department_name,department_code,description,status) VALUES(?,?,?,?)');
                    $stmt->bind_param('ssss', $name, $code, $description, $status);
                    $success = $stmt->execute();
                }

                if ($success) {
                    header('Location: departments.php?saved=1');
                    exit;
                }

                $error = 'Unable to save the department. Please try again.';
            }
        }

        if ($departmentId > 0) {
            $editDepartment = [
                'department_id' => $departmentId,
                'department_name' => $name,
                'department_code' => $code,
                'description' => $description,
                'status' => $status
            ];
        }
    }

    if ($action === 'delete' && $departmentId > 0) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM employees WHERE department=(SELECT department_name FROM departments WHERE department_id=?)');
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $assigned = (int)$stmt->get_result()->fetch_assoc()['total'];

        if ($assigned > 0) {
            $error = 'This department cannot be deleted because it is assigned to ' . $assigned . ' employee' . ($assigned === 1 ? '' : 's') . '. Set it to Inactive instead.';
        } else {
            $stmt = $conn->prepare('DELETE FROM departments WHERE department_id=?');
            $stmt->bind_param('i', $departmentId);
            if ($stmt->execute()) {
                header('Location: departments.php?deleted=1');
                exit;
            }
            $error = 'Unable to delete the department.';
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare('SELECT department_id,department_name,department_code,description,status FROM departments WHERE department_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editDepartment = $stmt->get_result()->fetch_assoc();
}

$departments = [];
$result = $conn->query("SELECT d.department_id,d.department_name,d.department_code,d.description,d.status,d.created_at,COUNT(e.employee_id) AS employee_count FROM departments d LEFT JOIN employees e ON e.department=d.department_name GROUP BY d.department_id,d.department_name,d.department_code,d.description,d.status,d.created_at ORDER BY d.department_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) $departments[] = $row;
}

$activeCount = 0;
foreach ($departments as $department) {
    if ($department['status'] === 'Active') $activeCount++;
}

$positionResult = $conn->query("SELECT p.position_id, p.position_name, p.department_id, COUNT(e.employee_id) AS employee_count FROM positions p LEFT JOIN departments d ON d.department_id=p.department_id LEFT JOIN employees e ON e.department=d.department_name AND e.position=p.position_name GROUP BY p.position_id,p.position_name,p.department_id ORDER BY p.department_id ASC,p.position_name ASC");
if ($positionResult) {
    while ($row = $positionResult->fetch_assoc()) {
        $departmentPositions[(int)$row['department_id']][] = $row;
    }
}

function department_value(?array $department, string $key): string
{
    return e($department[$key] ?? '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Departments</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
<style>
.department-collapsible{border:1px solid var(--border,#e5e7eb);border-radius:13px;margin-bottom:10px;overflow:hidden;background:var(--card,#fff)}
.department-collapsible:last-child{margin-bottom:0}.department-collapsible-summary{list-style:none;cursor:pointer;padding:15px 16px;display:flex;justify-content:space-between;gap:14px;align-items:center;background:rgba(0,0,0,.018);user-select:none}.department-collapsible-summary::-webkit-details-marker{display:none}.department-collapsible-summary:hover{background:rgba(0,0,0,.035)}.department-summary-main{display:flex;align-items:center;gap:11px;min-width:0}.department-summary-copy{min-width:0}.department-summary-copy strong{display:block;font-size:13px;line-height:1.35}.department-summary-copy span{display:block;margin-top:4px;color:var(--muted);font-size:11px}.department-chevron{width:25px;height:25px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border,#e5e7eb);border-radius:50%;font-size:21px;line-height:1;transition:transform .18s ease;flex:0 0 25px}.department-collapsible[open] .department-chevron{transform:rotate(90deg)}.department-collapsible-body{padding:17px 18px;border-top:1px solid var(--border,#e5e7eb)}.department-detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0 22px}.department-detail-grid>div{padding:10px 0;border-bottom:1px solid var(--line);min-width:0}.department-detail-grid .full{grid-column:1/-1}.department-detail-grid span,.department-positions-head span{display:block;color:var(--muted);font-size:11px;margin-bottom:4px}.department-detail-grid strong{font-size:12.5px;line-height:1.5}.department-actions{display:flex;gap:8px;align-items:center;margin:14px 0 18px}.department-actions form{margin:0}.danger-outline{color:#b14d4d!important;border-color:#e5caca!important}.department-positions-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;padding-top:14px;border-top:1px solid var(--line)}.department-positions-head strong{display:block;font-size:12.5px}.department-positions-head>span{margin:0}.department-inline-position-form{display:flex;gap:8px;margin:11px 0}.department-inline-position-form input{flex:1;min-width:0}.position-chip-list{display:flex;flex-wrap:wrap;gap:8px}.position-chip{display:flex;align-items:center;gap:9px;border:1px solid var(--border,#e5e7eb);border-radius:11px;padding:8px 9px 8px 11px;background:#fff}.position-chip>div{min-width:0}.position-chip strong{display:block;font-size:12px}.position-chip span{display:block;color:var(--muted);font-size:10px;margin-top:2px}.position-chip form{margin:0}.department-empty{margin-top:8px}@media(max-width:700px){.department-collapsible-summary{align-items:flex-start;padding:13px}.department-summary-copy strong{font-size:12px}.department-collapsible-body{padding:14px}.department-detail-grid{grid-template-columns:1fr 1fr}.department-detail-grid .full{grid-column:1/-1}.department-actions{flex-wrap:wrap}.department-actions .btn{flex:1;min-width:120px}.department-inline-position-form{align-items:stretch;flex-direction:column}.department-inline-position-form .btn{width:100%}.department-positions-head{align-items:flex-start;flex-direction:column;gap:3px}.position-chip{width:100%;justify-content:space-between}.department-directory .card-head{padding-bottom:13px}}
</style>
</head>
<body>
<div class="app">
    <?php sidebar('departments'); ?>
    <main class="main">
        <?php topbar('Departments'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Departments <span class="count-pill"><?php echo count($departments); ?></span></h1>
                    <p><?php echo $activeCount; ?> active department<?php echo $activeCount === 1 ? '' : 's'; ?> and positions.</p>
                </div>
            </div>

            <?php if (isset($_GET['saved'])): ?><div class="notice ok">Department saved.</div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice ok">Department deleted.</div><?php endif; ?>
            <?php if (isset($_GET['position_saved'])): ?><div class="notice ok">Position added.</div><?php endif; ?>
            <?php if (isset($_GET['position_deleted'])): ?><div class="notice ok">Position deleted.</div><?php endif; ?>
            <?php if ($positionError !== ''): ?><div class="notice err"><?php echo e($positionError); ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

            <section class="card form-card">
                <div class="card-head">
                    <div>
                        <h2><?php echo $editDepartment ? 'Update department' : 'Add department'; ?></h2>
                    </div>
                </div>
                <div class="form-body">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="department_id" value="<?php echo (int)($editDepartment['department_id'] ?? 0); ?>">
                        <div class="form-grid">
                            <div class="field">
                                <label>Department name <span class="required">*</span></label>
                                <input type="text" name="department_name" maxlength="100" required value="<?php echo department_value($editDepartment, 'department_name'); ?>" placeholder="e.g. College of Computer Studies">
                            </div>
                            <div class="field">
                                <label>Code</label>
                                <input type="text" name="department_code" maxlength="30" value="<?php echo department_value($editDepartment, 'department_code'); ?>" placeholder="e.g. CCS">
                            </div>
                            <div class="field">
                                <label>Status</label>
                                <select name="status">
                                    <option value="Active" <?php echo (($editDepartment['status'] ?? 'Active') === 'Active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo (($editDepartment['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Description</label>
                                <input type="text" name="description" value="<?php echo department_value($editDepartment, 'description'); ?>" placeholder="Optional notes">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit"><?php echo $editDepartment ? 'Update department' : 'Save department'; ?></button>
                            <?php if ($editDepartment): ?><a class="btn btn-secondary" href="departments.php">Cancel</a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card department-directory" style="overflow:hidden;margin-top:16px">
                <div class="card-head">
                    <div>
                        <h2>Directory & Positions</h2>
                    </div>
                </div>
                <div class="department-list">
                    <?php if (!$departments): ?>
                        <div class="empty">No departments added yet.</div>
                    <?php else: foreach ($departments as $department): ?>
                        <?php $deptPositions = $departmentPositions[(int)$department['department_id']] ?? []; $deptKey = dept_color_key($department['department_code'] ?? '', $department['department_name'] ?? ''); ?>
                        <details class="department-collapsible" style="--dept-c:var(--c-<?php echo $deptKey; ?>);--dept-c-soft:var(--c-<?php echo $deptKey; ?>-soft)">
                            <summary class="department-collapsible-summary">
                                <div class="department-summary-main">
                                    <span class="dept-dot" aria-hidden="true"></span>
                                    <span class="department-chevron">›</span>
                                    <div class="department-summary-copy">
                                        <strong><?php echo e($department['department_name']); ?></strong>
                                        <span><?php echo e($department['department_code'] ?: 'No code'); ?> · <?php echo (int)$department['employee_count']; ?> employee<?php echo (int)$department['employee_count'] === 1 ? '' : 's'; ?> · <?php echo count($deptPositions); ?> position<?php echo count($deptPositions) === 1 ? '' : 's'; ?></span>
                                    </div>
                                </div>
                                <span class="badge <?php echo $department['status'] === 'Active' ? 'badge-ok' : 'badge-neutral'; ?>"><?php echo e($department['status']); ?></span>
                            </summary>
                            <div class="department-collapsible-body"><div class="dept-clip">
                                <div class="department-detail-grid">
                                    <div><span>Code</span><strong><?php echo e($department['department_code'] ?: '—'); ?></strong></div>
                                    <div><span>Employees</span><strong><?php echo (int)$department['employee_count']; ?></strong></div>
                                    <div><span>Status</span><strong><?php echo e($department['status']); ?></strong></div>
                                    <?php if (!empty($department['description'])): ?>
                                    <div class="full"><span>Description</span><strong><?php echo e($department['description']); ?></strong></div>
                                    <?php endif; ?>
                                </div>
                                <div class="department-actions">
                                    <a class="btn btn-secondary btn-small" href="departments.php?edit=<?php echo (int)$department['department_id']; ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this department?')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="department_id" value="<?php echo (int)$department['department_id']; ?>">
                                        <button class="btn btn-secondary btn-small danger-outline" type="submit">Delete</button>
                                    </form>
                                </div>
                                <div class="department-positions-head">
                                    <div><strong>Positions (<?php echo count($deptPositions); ?>)</strong></div>
                                </div>
                                <form method="post" class="department-inline-position-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_position">
                                    <input type="hidden" name="position_department_id" value="<?php echo (int)$department['department_id']; ?>">
                                    <input type="text" name="position_name" maxlength="120" required placeholder="Add position (e.g. Instructor I)">
                                    <button class="btn btn-primary btn-small" type="submit">+ Add</button>
                                </form>
                                <?php if (!$deptPositions): ?>
                                    <div class="empty department-empty">No positions configured.</div>
                                <?php else: ?>
                                    <div class="position-chip-list">
                                        <?php foreach ($deptPositions as $position): ?>
                                            <div class="position-chip">
                                                <div><strong><?php echo e($position['position_name']); ?></strong><span><?php echo (int)$position['employee_count']; ?> employee<?php echo (int)$position['employee_count'] === 1 ? '' : 's'; ?></span></div>
                                                <form method="post" onsubmit="return confirm('Delete this position?')">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_position">
                                                    <input type="hidden" name="position_id" value="<?php echo (int)$position['position_id']; ?>">
                                                    <button class="icon-action delete" type="submit" title="Delete position" aria-label="Delete position"><?php echo ui_icon('trash'); ?></button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div></div>
                        </details>
                    <?php endforeach; endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
