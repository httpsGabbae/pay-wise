<?php
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <link rel="icon" type="image/png" href="assets/favicon.png?v=lcc1">
    <title>Lipa City Colleges · Sign in</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/apple-system.css?v=20261112-clean1">
    <script src="assets/js/apple-motion.js?v=20261112-clean1" defer></script>
</head>

<body>
<script>try{if(localStorage.getItem("paywiseTheme")==="dark")document.body.classList.add("dark-mode");}catch(_e){}</script>
    <section class="brand-side">
        <div class="brand-content"><img class="login-logo" src="uploads/logo.png" alt="Lipa City Colleges">
            <h1>Lipa City Colleges</h1>
            <p>Payroll, attendance, and personnel ledger.</p>
        </div>
    </section>
    <section class="login-side">
        <div class="login-card">
            <div class="login-mobile-brand"><img src="uploads/logo.png" alt="LCC"><div><strong>Lipa City Colleges</strong><span>Administrator</span></div></div>
            <h2>Admin sign in</h2>
            <p class="hint">Enter your administrator credentials.</p><?php if (isset($_GET["error"])): ?><div class="error" role="alert">Incorrect username or password.</div><?php endif; ?><form method="post" action="auth/login.php"><label>Username</label><input type="text" name="username" autocomplete="username" required autofocus><label>Password</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Sign in</button></form><a class="portal-link" href="employee/login.php">Employee portal</a>
        </div>
    </section>
</body>

</html>
