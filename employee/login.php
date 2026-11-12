<?php
require_once __DIR__ . '/../config/database.php';
if (isset($_SESSION['employee_id'])) { header('Location: dashboard.php'); exit; }
$error = '';

// Secure admin preview: POST-only + CSRF, never via GET link.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_preview') {
    if (!isset($_SESSION['admin_id']) || !verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid session token.');
    }
    $adminEmployeeNo = trim($_POST['employee_no'] ?? '');
    if ($adminEmployeeNo !== '') {
        $quick = $conn->prepare('SELECT employee_id,employee_no,first_name,last_name,employment_status FROM employees WHERE employee_no=? LIMIT 1');
        $quick->bind_param('s', $adminEmployeeNo); $quick->execute(); $quickRow = $quick->get_result()->fetch_assoc();
        if ($quickRow && is_active_employment_status($quickRow['employment_status'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['employee_id']=(int)$quickRow['employee_id'];
            $_SESSION['employee_no']=$quickRow['employee_no'];
            $_SESSION['employee_name']=trim($quickRow['first_name'].' '.$quickRow['last_name']);
            header('Location: dashboard.php'); exit;
        }
        $error = 'That employee account is not active.';
    }
}

$employeeNo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'admin_preview') {
    $employeeNo = trim($_POST['employee_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare('SELECT employee_id,employee_no,portal_password,first_name,last_name,employment_status FROM employees WHERE employee_no=? LIMIT 1');
    $stmt->bind_param('s', $employeeNo); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
    $valid = false;
    if ($row && is_active_employment_status($row['employment_status'] ?? '')) {
        $stored=(string)($row['portal_password']??'');
        $valid=$stored!=='' && password_verify($password,$stored);
        // Legacy plaintext migration: only for active accounts, then immediately hash.
        if(!$valid && $stored!=='' && hash_equals($stored,$password)){$valid=true;$hash=password_hash($password,PASSWORD_DEFAULT);$up=$conn->prepare('UPDATE employees SET portal_password=? WHERE employee_id=?');$up->bind_param('si',$hash,$row['employee_id']);$up->execute();}
    }
    if($valid){session_regenerate_id(true);$_SESSION['employee_id']=(int)$row['employee_id'];$_SESSION['employee_no']=$row['employee_no'];$_SESSION['employee_name']=trim($row['first_name'].' '.$row['last_name']);header('Location: dashboard.php');exit;}
    $error='Incorrect employee ID or password.';
}

if (isset($_GET['error']) && $_GET['error'] === 'inactive' && $error === '') {
    $error = 'Your account is no longer active. Please contact the administrator.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png?v=lcc1"><title>Lipa City Colleges · Employee Portal</title><link rel="stylesheet" href="../assets/css/login.css"><link rel="stylesheet" href="../assets/css/employee.css"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261112-clean1"><script src="../assets/js/apple-motion.js?v=20261112-clean1" defer></script></head><body class="employee-login"><script>try{if(localStorage.getItem("paywiseTheme")==="dark")document.body.classList.add("dark-mode");}catch(_e){}</script>
<section class="brand-side"><div class="brand-content"><img class="login-logo" src="../uploads/logo.png" alt="Lipa City Colleges"><h1>Lipa City Colleges</h1><p>Employee Portal · Daily time-in, payslips, and loan balances.</p></div></section>
<section class="login-side"><div class="login-card"><div class="login-mobile-brand"><img src="../uploads/logo.png" alt="LCC"><div><strong>Lipa City Colleges</strong><span>Employee Portal</span></div></div><h2>Employee sign in</h2><p class="hint">Use your employee ID and portal password.</p><?php if($error): ?><div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?><form method="post" autocomplete="on"><label for="employee_no">Employee ID</label><input id="employee_no" type="text" name="employee_no" value="<?php echo htmlspecialchars($employeeNo); ?>" placeholder="25-0001" autocomplete="username" required autofocus><label for="password">Password</label><input id="password" type="password" name="password" placeholder="Password" autocomplete="current-password" required><button type="submit">Sign in</button></form><a class="portal-link" href="../login.php">Admin sign in</a></div></section></body></html>
