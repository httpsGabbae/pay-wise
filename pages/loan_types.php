<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';
$editType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['loan_type_id'] ?? 0);
        $name = trim($_POST['loan_type_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Loan type name is required.';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE loan_types SET loan_type_name=?, description=?, is_active=? WHERE loan_type_id=?');
                $stmt->bind_param('ssii', $name, $desc, $active, $id);
                if ($stmt->execute()) {
                    header('Location: loan_types.php?saved=1');
                    exit;
                }
                $error = 'Failed to update loan type.';
            } else {
                $stmt = $conn->prepare('INSERT INTO loan_types (loan_type_name, description, is_active) VALUES (?, ?, ?)');
                $stmt->bind_param('ssi', $name, $desc, $active);
                if ($stmt->execute()) {
                    header('Location: loan_types.php?saved=1');
                    exit;
                }
                $error = 'Failed to create loan type (name may already exist).';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['loan_type_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM employee_loans WHERE loan_type_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];

            if ($cnt > 0) {
                $error = "Cannot delete this loan type: it is currently used by $cnt employee loan record(s).";
            } else {
                $stmt = $conn->prepare('DELETE FROM loan_types WHERE loan_type_id=?');
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    header('Location: loan_types.php?deleted=1');
                    exit;
                }
                $error = 'Unable to delete loan type.';
            }
        }
    }
}

if (isset($_GET['saved'])) $message = 'Loan type saved successfully.';
if (isset($_GET['deleted'])) $message = 'Loan type deleted.';

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM loan_types WHERE loan_type_id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editType = $stmt->get_result()->fetch_assoc();
}

$types = [];
$res = $conn->query('SELECT lt.*, (SELECT COUNT(*) FROM employee_loans el WHERE el.loan_type_id = lt.loan_type_id) AS active_loans_count FROM loan_types lt ORDER BY lt.loan_type_id ASC');
if ($res) {
    while ($r = $res->fetch_assoc()) $types[] = $r;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Loan Types</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
    <?php sidebar('loan_types'); ?>
    <main class="main">
        <?php topbar('Loan Types'); ?>
        <div class="content">
            <div class="page-heading">
                <div>
                    <h1>Loan Types <span class="count-pill"><?php echo count($types); ?></span></h1>
                    <p>Categories for employee loans and institutional salary advances.</p>
                </div>
                <div class="heading-actions">
                    <a href="loans.php" class="btn btn-secondary"><?php echo ui_icon('credit'); ?> Employee Loans</a>
                </div>
            </div>

            <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

            <div class="grid-2-cols" style="display:grid; grid-template-columns: 340px 1fr; gap: 18px; align-items: start;">
                <section class="card">
                    <div class="card-head">
                        <div>
                            <h2><?php echo $editType ? 'Edit loan type' : 'Add loan type'; ?></h2>
                        </div>
                    </div>
                    <form method="post" style="padding: 18px 20px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="loan_type_id" value="<?php echo (int)($editType['loan_type_id'] ?? 0); ?>">

                        <div class="field" style="margin-bottom: 14px;">
                            <label>Name <span class="required">*</span></label>
                            <input type="text" name="loan_type_name" value="<?php echo e($editType['loan_type_name'] ?? ''); ?>" placeholder="e.g. Salary Loan, SSS Loan" required>
                        </div>

                        <div class="field" style="margin-bottom: 14px;">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Optional note or terms"><?php echo e($editType['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="field" style="margin-bottom: 18px;">
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="is_active" value="1" <?php echo (!isset($editType) || !empty($editType['is_active'])) ? 'checked' : ''; ?>>
                                <span>Active</span>
                            </label>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <button type="submit" class="btn btn-primary" style="flex:1;"><?php echo $editType ? 'Update' : 'Save loan type'; ?></button>
                            <?php if ($editType): ?>
                                <a href="loan_types.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <div class="card-head">
                        <div>
                            <h2>Configured types</h2>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Loan Type Name</th>
                                    <th>Description</th>
                                    <th>Active Loans</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($types)): ?>
                                <tr><td colspan="6" class="empty">No loan types defined yet.</td></tr>
                            <?php else: foreach ($types as $t): ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$t['loan_type_id']; ?></strong></td>
                                    <td><strong><?php echo e($t['loan_type_name']); ?></strong></td>
                                    <td><span style="color:var(--text-muted); font-size:13px;"><?php echo e($t['description'] ?: '—'); ?></span></td>
                                    <td>
                                        <span class="badge" style="background:var(--card-sub);"><?php echo (int)$t['active_loans_count']; ?> loans</span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $t['is_active'] ? 'badge-ok' : 'badge-err'; ?>">
                                            <?php echo $t['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="loan_types.php?edit=<?php echo (int)$t['loan_type_id']; ?>" class="btn btn-small btn-secondary" title="Edit">
                                            <?php echo ui_icon('pencil'); ?> Edit
                                        </a>
                                        <?php if ((int)$t['active_loans_count'] === 0): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this loan type?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="loan_type_id" value="<?php echo (int)$t['loan_type_id']; ?>">
                                            <button type="submit" class="btn btn-small btn-danger" title="Delete">
                                                <?php echo ui_icon('trash'); ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
