<?php
function emp_icon(string $name): string {
    $p = [
        'dashboard' => '<path d="M3 13h8V3H3v10Zm10 8h8V3h-8v18ZM3 21h8v-6H3v6Z"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'wallet'    => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H20a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5.5A2.5 2.5 0 0 1 3 17.5v-10Z"/><path d="M3 8h17M16 13h6"/>',
        'lock'      => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'logout'    => '<path d="M10 17l5-5-5-5M15 12H3M21 19V5a2 2 0 0 0-2-2h-5"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'credit'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'
    ];
    $path = $p[$name] ?? $p['dashboard'];
    return '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

function employee_sidebar(string $active): void {
    echo '<script>try{var _b=document.body,_m=window.matchMedia("(max-width: 820px)").matches;if(_m){if(localStorage.getItem("paywiseMobileSidebarExpanded")!=="true")_b.classList.add("sidebar-collapsed");}else if(localStorage.getItem("paywiseSidebarCollapsed")==="true")_b.classList.add("sidebar-collapsed");if(localStorage.getItem("paywiseTheme")==="dark")_b.classList.add("dark-mode");}catch(_e){}</script>';
    $items = [
        ['dashboard', 'dashboard', 'Overview', 'dashboard.php'],
        ['attendance', 'calendar', 'Attendance', 'attendance.php'],
        ['payroll', 'wallet', 'Payslips', 'payroll.php'],
        ['loans', 'credit', 'Loans', 'loans.php'],
        ['profile', 'user', 'Profile', 'profile.php'],
    ];
    echo '<aside class="sidebar employee-sidebar" id="appSidebar"><button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">←</button><a class="brand" href="dashboard.php"><img class="brand-logo" src="../uploads/logo.png" alt="LCC"><div class="brand-text"><div class="brand-title">Lipa City Colleges</div><div class="brand-sub">Employee Portal</div></div></a><nav class="nav">';
    foreach ($items as $item) {
        $a = $active === $item[0] ? ' active' : '';
        echo '<a class="nav-link mobile-priority' . $a . '" href="' . e($item[3]) . '" title="' . e($item[2]) . '" data-nav-title="' . e($item[2]) . '"><span class="nav-icon">' . emp_icon($item[1]) . '</span><span class="nav-label">' . e($item[2]) . '</span></a>';
    }
    echo '</nav><div class="employee-side-footer"><a class="nav-link mobile-secondary" href="logout.php" title="Sign out" data-nav-title="Sign out"><span class="nav-icon">' . emp_icon('logout') . '</span><span class="nav-label">Sign out</span></a></div>';
    echo '<div class="mobile-more-wrap employee-more-wrap">';
    echo '<button type="button" class="mobile-more-toggle" aria-expanded="false" aria-label="More navigation"><span class="nav-icon">⋯</span><span class="nav-label">More</span></button>';
    echo '<div class="mobile-more-menu" role="menu"><a href="loans.php" role="menuitem"><span>' . emp_icon('credit') . '</span>Loans</a><a href="change_password.php" role="menuitem"><span>' . emp_icon('lock') . '</span>Password</a><a href="logout.php" role="menuitem"><span>' . emp_icon('logout') . '</span>Sign out</a></div></div></aside>';
}

function employee_topbar(string $title): void {
    $name = $_SESSION['employee_name'] ?? 'Employee';
    $initial = strtoupper(substr(trim($name), 0, 1)) ?: 'E';
    echo '<header class="topbar"><div class="topbar-left"><div class="topbar-title">' . e($title) . '</div></div><div class="topbar-actions"><button type="button" class="icon-btn theme-toggle" id="themeToggle" aria-label="Toggle theme"><span id="themeIcon">☾</span></button><div class="admin-menu"><button type="button" class="admin-chip" id="adminMenuButton" aria-expanded="false"><span class="avatar">' . e($initial) . '</span><span class="admin-name">' . e($name) . '</span><span class="admin-chevron">⌄</span></button><div class="admin-dropdown" id="adminDropdown"><div class="admin-dropdown-name">' . e($name) . '</div><a class="dropdown-item" href="profile.php"><span>◉</span> Profile</a><a class="dropdown-item" href="payroll.php"><span>💰</span> Payslips</a><a class="dropdown-item" href="loans.php"><span>💳</span> Loans</a><a class="dropdown-item" href="attendance.php"><span>◷</span> Attendance</a><a class="dropdown-item" href="change_password.php"><span>⚙</span> Password</a><a class="dropdown-item danger-item" href="logout.php"><span>↪</span> Sign out</a></div></div></div></header>';
}
