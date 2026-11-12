<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin');
require_once __DIR__ . '/../includes/layout.php';

$message = '';
$error = '';
$validRoles = ['superadmin', 'finance', 'hr'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        if ($username === '' || $fullName === '' || $password === '') {
            $error = 'Please complete all account fields.';
        } elseif (!in_array($role, $validRoles, true)) {
            $error = 'Please choose a valid role.';
        } elseif (strlen($password) < 8) {
            $error = 'The password must be at least 8 characters.';
        } else {
            $stmt = $conn->prepare('SELECT admin_id FROM admins WHERE username=? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $error = 'That username is already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO admins(username,password_hash,full_name,role,is_active) VALUES(?,?,?,? ,1)');
                $stmt->bind_param('ssss', $username, $hash, $fullName, $role);
                if ($stmt->execute()) {
                    header('Location: accounts.php?saved=1');
                    exit;
                }
                $error = 'Unable to create the account.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['admin_id'] ?? 0);
        if ($id <= 0) {
            $error = 'Invalid account.';
        } elseif ($id === (int)$_SESSION['admin_id']) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $stmt = $conn->prepare('SELECT role, is_active FROM admins WHERE admin_id=? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            if (!$target) {
                $error = 'Account not found.';
            } elseif ($target['role'] === 'superadmin' && (int)$target['is_active'] === 1) {
                $count = (int)$conn->query("SELECT COUNT(*) total FROM admins WHERE role='superadmin' AND is_active=1")->fetch_assoc()['total'];
                if ($count <= 1) {
                    $error = 'The last active superadmin cannot be deactivated.';
                } else {
                    $conn->query('UPDATE admins SET is_active=0 WHERE admin_id=' . $id);
                    header('Location: accounts.php?updated=1');
                    exit;
                }
            } else {
                $new = (int)$target['is_active'] === 1 ? 0 : 1;
                $stmt = $conn->prepare('UPDATE admins SET is_active=? WHERE admin_id=?');
                $stmt->bind_param('ii', $new, $id);
                $stmt->execute();
                header('Location: accounts.php?updated=1');
                exit;
            }
        }
    } elseif ($action === 'reset') {
        $id = (int)($_POST['admin_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        if ($id <= 0) {
            $error = 'Invalid account.';
        } elseif (strlen($password) < 8) {
            $error = 'The new password must be at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE admins SET password_hash=? WHERE admin_id=?');
            $stmt->bind_param('si', $hash, $id);
            if ($stmt->execute()) {
                header('Location: accounts.php?updated=1');
                exit;
            }
            $error = 'Unable to reset the password.';
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Account created successfully.';
}
if (isset($_GET['updated'])) {
    $message = 'Account updated successfully.';
}

$accounts = $conn->query('SELECT admin_id,username,full_name,role,is_active,created_at FROM admins ORDER BY admin_id ASC');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Accounts</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1">
    <script src="../assets/js/app.js?v=20261112-clean1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>
<body>
<div class="app">
<?php sidebar('accounts'); ?>
<main class="main">
<?php topbar('Accounts'); ?>
<div class="content">
    <div class="page-heading">
        <div>
            <h1>Admin Accounts</h1>
            <p>Manage Superadmin, Finance, and HR access.</p>
        </div>
    </div>

    <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Administrators</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$accounts || $accounts->num_rows === 0): ?>
                    <tr><td colspan="4" class="empty">No accounts found.</td></tr>
                    <?php else: while ($a = $accounts->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($a['full_name']); ?></strong>
                            <div class="mini"><?php echo e($a['username']); ?><?php echo (int)$a['admin_id'] === (int)$_SESSION['admin_id'] ? ' · you' : ''; ?></div>
                        </td>
                        <td><span class="badge"><?php echo e(role_label($a['role'])); ?></span></td>
                        <td><span class="badge <?php echo (int)$a['is_active'] === 1 ? 'badge-ok' : 'badge-neutral'; ?>"><?php echo (int)$a['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <?php if ((int)$a['admin_id'] !== (int)$_SESSION['admin_id']): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                                <form method="post" style="display:inline" onsubmit="return confirm('Toggle this account?')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="admin_id" value="<?php echo (int)$a['admin_id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-small"><?php echo (int)$a['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                                <form method="post" style="display:inline-flex; gap:6px; align-items:center;" onsubmit="return confirm('Reset this password?')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="admin_id" value="<?php echo (int)$a['admin_id']; ?>">
                                    <input type="password" name="new_password" minlength="8" required placeholder="New password" style="width:140px; height:32px;">
                                    <button type="submit" class="btn btn-secondary btn-small">Reset</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="mini">Current session</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card form-card">
        <div class="card-head">
            <div>
                <h2>Create account</h2>
            </div>
        </div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-body">
                <div class="form-grid">
                    <div class="field"><label>Username <span class="required">*</span></label><input type="text" name="username" required autocomplete="off"></div>
                    <div class="field"><label>Full name <span class="required">*</span></label><input type="text" name="full_name" required autocomplete="off"></div>
                    <div class="field"><label>Password <span class="required">*</span></label><input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters"></div>
                    <div class="field"><label>Role <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="">Select role</option>
                            <option value="superadmin">Superadmin — full access</option>
                            <option value="finance">Finance — payroll, salary, loans, reports</option>
                            <option value="hr">HR — employees, attendance, masked payroll</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Create account</button>
            </div>
        </form>
    </section>
</div>
</main>
</div>
</body>
</html>
