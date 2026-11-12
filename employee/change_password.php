<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$employee = current_employee($conn);
if (!$employee) { header('Location: logout.php'); exit; }
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stored = (string)($employee['portal_password'] ?? '');
    $valid = password_verify($current, $stored) || ($stored !== '' && hash_equals($stored, $current));
    if (!$valid) $error = 'The current password is incorrect.';
    elseif (strlen($new) < 8) $error = 'The new password must be at least 8 characters.';
    elseif ($new !== $confirm) $error = 'The new passwords do not match.';
    else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE employees SET portal_password=? WHERE employee_id=?');
        $stmt->bind_param('si', $hash, $employee['employee_id']);
        if ($stmt->execute()) {
            session_regenerate_id(true);
            $message = 'Your password has been changed successfully.';
            $employee = current_employee($conn);
        } else $error = 'The password could not be changed. Please try again.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1"><title>Lipa City Colleges · Password</title><link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4"><link rel="stylesheet" href="../assets/css/employee.css"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1"><script src="../assets/js/app.js?v=20261112-clean1" defer></script><script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script></head><body><div class="app"><?php employee_sidebar('password'); ?><main class="main"><?php employee_topbar('Password'); ?><div class="content"><div class="page-heading"><div><h1>Change Password</h1></div></div><section class="card password-card"><div class="settings-section"><h2>Update password</h2><p>Use at least 8 characters.</p></div><?php if($message): ?><div class="notice ok" role="status"><?php echo e($message); ?></div><?php endif; ?><?php if($error): ?><div class="notice err" role="alert"><?php echo e($error); ?></div><?php endif; ?><form method="post" autocomplete="off" class="portal-form"><?php echo csrf_field(); ?><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label><div class="settings-actions"><button type="submit" class="btn btn-primary">Update password</button></div></form></section></div></main></div></body></html>
