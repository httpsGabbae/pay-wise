<?php

require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: ../pages/admin_dashboard.php');
    exit;
}

$error = '';
$loginFailed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $loginFailed = true;
        $error = 'Incorrect username or password.';
    } else {
        $stmt = $conn->prepare('SELECT admin_id,username,password_hash,full_name,role,is_active FROM admins WHERE username=? LIMIT 1');
        if (!$stmt) {
            // Pre-roles database (migrate_roles.sql not run yet): fall back
            // to the legacy columns so existing admins are never locked out.
            $stmt = $conn->prepare('SELECT admin_id,username,password_hash,full_name FROM admins WHERE username=? LIMIT 1');
        }
        $row = null;
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        }

        if ($row && password_verify($password, $row['password_hash']) && (int)($row['is_active'] ?? 1) === 1) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$row['admin_id'];
            $_SESSION['admin_username'] = $row['username'];
            $_SESSION['admin_name'] = $row['full_name'];
            $_SESSION['admin_role'] = in_array($row['role'] ?? 'superadmin', ['superadmin', 'finance', 'hr'], true) ? $row['role'] : 'superadmin';
            header('Location: ../pages/admin_dashboard.php');
            exit;
        }
        $loginFailed = true;
        $error = 'Incorrect username or password.';
    }
}


header('Location: ../login.php?error=1');
exit;
