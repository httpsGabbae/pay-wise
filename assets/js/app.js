/* Apple Design System bootstrap — loads after app.css so it wins the cascade. */
(function () {
    function base() {
        var s = document.querySelector('script[src*="assets/js/app.js"]');
        if (s && s.src) return s.src.split('assets/js/app.js')[0] + 'assets/';
        return '../assets/';
    }
    var root = base();
    if (!document.querySelector('link[data-apple-system], link[href*="apple-system.css"]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = root + 'css/apple-system.css?v=20261112-clean1';
        link.setAttribute('data-apple-system', '1');
        document.head.appendChild(link);
    }
    if (!document.querySelector('script[data-apple-motion], script[src*="apple-motion.js"]')) {
        var sc = document.createElement('script');
        sc.src = root + 'js/apple-motion.js?v=20261112-clean1';
        sc.defer = true;
        sc.setAttribute('data-apple-motion', '1');
        document.head.appendChild(sc);
    }
})();

document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    // ID-first with class fallback: the employee portal topbar reuses the
    // same classes without the admin IDs, and its menu must work too.
    const themeToggle = document.getElementById('themeToggle') || document.querySelector('.theme-toggle');
    const themeIcon = document.getElementById('themeIcon') || (themeToggle ? themeToggle.querySelector('#themeIcon, span') : null);
    const adminButton = document.getElementById('adminMenuButton') || document.querySelector('.admin-chip');
    const adminDropdown = document.getElementById('adminDropdown') || document.querySelector('.admin-menu .admin-dropdown');
    const settingsOverlay = document.getElementById('settingsOverlay');
    const openSettings = document.getElementById('openSettings');
    const closeSettings = document.getElementById('closeSettings');
    const cancelSettings = document.getElementById('cancelSettings');
    const passwordForm = document.getElementById('changePasswordForm');
    const passwordMessage = document.getElementById('passwordMessage');

    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const mobileDrawerClose = document.getElementById('mobileDrawerClose');
    const mobileDrawerBackdrop = document.getElementById('mobileDrawerBackdrop');
    const drawerQuery = window.matchMedia('(max-width: 767px)');
    const isDrawerViewport = () => drawerQuery.matches;

    function setMobileDrawerOpen(isOpen) {
        if (!sidebar) return;
        const shouldOpen = Boolean(isOpen) && isDrawerViewport();
        body.classList.toggle('mobile-drawer-open', shouldOpen);
        if (mobileMenuButton) {
            mobileMenuButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        }
        if (mobileDrawerBackdrop) {
            mobileDrawerBackdrop.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        }
        if (shouldOpen && mobileDrawerClose) {
            mobileDrawerClose.focus();
        } else if (!shouldOpen && mobileMenuButton && document.activeElement && sidebar.contains(document.activeElement)) {
            mobileMenuButton.focus();
        }
    }

    mobileMenuButton?.addEventListener('click', function (event) {
        event.stopPropagation();
        setMobileDrawerOpen(!body.classList.contains('mobile-drawer-open'));
    });

    mobileDrawerClose?.addEventListener('click', function (event) {
        event.stopPropagation();
        setMobileDrawerOpen(false);
    });

    mobileDrawerBackdrop?.addEventListener('click', function () {
        setMobileDrawerOpen(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && body.classList.contains('mobile-drawer-open')) {
            setMobileDrawerOpen(false);
        }
    });

    const mobileQuery = window.matchMedia('(max-width: 820px)');
    const isMobile = () => mobileQuery.matches;
    const savedSidebar = localStorage.getItem('paywiseSidebarCollapsed');

    const savedMobileRail = localStorage.getItem('paywiseMobileSidebarExpanded');
    if (isMobile()) {
        if (savedMobileRail !== 'true') body.classList.add('sidebar-collapsed');
    } else if (savedSidebar === 'true') {
        body.classList.add('sidebar-collapsed');
    }

    function syncMobileSidebarState() {
        if (isDrawerViewport()) {
            return;
        }
        const mobile = isMobile();
        const expanded = mobile && !body.classList.contains('sidebar-collapsed');

        document.querySelectorAll('.sidebar, .employee-sidebar').forEach(function (el) {
            el.classList.toggle('mobile-collapsed', mobile && !expanded);
            el.classList.toggle('mobile-expanded', expanded);
        });
    }

    function updateSidebarButton() {
        if (!sidebarToggle) return;
        const collapsed = body.classList.contains('sidebar-collapsed');
        syncMobileSidebarState();
        sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        sidebarToggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        sidebarToggle.innerHTML = collapsed
            ? '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>'
            : '<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>';
    }

    sidebarToggle?.addEventListener('click', function (event) {
        event.stopPropagation();
        body.classList.toggle('sidebar-collapsed');
        if (isMobile()) {
            localStorage.setItem('paywiseMobileSidebarExpanded', body.classList.contains('sidebar-collapsed') ? 'false' : 'true');
        } else {
            localStorage.setItem('paywiseSidebarCollapsed', body.classList.contains('sidebar-collapsed'));
        }
        updateSidebarButton();
    });

    document.querySelectorAll('.sidebar .nav-link, .employee-sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (isDrawerViewport()) {
                setMobileDrawerOpen(false);
                return;
            }
            if (!isMobile()) return;
            const collapsed = body.classList.contains('sidebar-collapsed');
            localStorage.setItem('paywiseMobileSidebarExpanded', collapsed ? 'false' : 'true');
        }, true);
    });

    sidebar?.addEventListener('click', function (event) {
        const link = event.target.closest('.nav-link');
        if (link) event.stopPropagation();
    });

    function handleViewportChange() {
        if (!isDrawerViewport() && body.classList.contains('mobile-drawer-open')) {
            setMobileDrawerOpen(false);
        }
        if (isMobile() && !isDrawerViewport() && localStorage.getItem('paywiseMobileSidebarExpanded') !== 'true') {
            body.classList.add('sidebar-collapsed');
        } else if (!isMobile()) {
            body.classList.toggle('sidebar-collapsed', localStorage.getItem('paywiseSidebarCollapsed') === 'true');
        }
        syncMobileSidebarState();
        updateSidebarButton();
    }

    window.addEventListener('resize', handleViewportChange);
    if (mobileQuery.addEventListener) {
        mobileQuery.addEventListener('change', handleViewportChange);
    } else {
        mobileQuery.addListener(handleViewportChange);
    }

    const savedTheme = localStorage.getItem('paywiseTheme');
    if (savedTheme === 'dark') body.classList.add('dark-mode');

    function updateThemeIcon() {
        if (themeIcon) themeIcon.textContent = body.classList.contains('dark-mode') ? '☀' : '☾';
    }
    updateThemeIcon();

    themeToggle?.addEventListener('click', function () {
        body.classList.toggle('dark-mode');
        localStorage.setItem('paywiseTheme', body.classList.contains('dark-mode') ? 'dark' : 'light');
        updateThemeIcon();
    });

    adminButton?.addEventListener('click', function (event) {
        event.stopPropagation();
        const open = adminDropdown ? adminDropdown.classList.toggle('show') : false;
        adminButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.admin-menu')) {
            adminDropdown?.classList.remove('show');
            adminButton?.setAttribute('aria-expanded', 'false');
        }
    });

    // Mobile app-style More menu. Desktop is unaffected by this behavior.
    const mobileMoreToggle = document.querySelector('.mobile-more-toggle');
    const mobileMoreMenu = document.querySelector('.mobile-more-menu');

    function closeMobileMore() {
        mobileMoreMenu?.classList.remove('show');
        mobileMoreToggle?.setAttribute('aria-expanded', 'false');
    }

    mobileMoreToggle?.addEventListener('click', function (event) {
        event.stopPropagation();
        if (!isMobile()) return;
        const open = mobileMoreMenu?.classList.toggle('show');
        mobileMoreToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.mobile-more-wrap')) closeMobileMore();
    });

    window.addEventListener('resize', function () {
        if (!isMobile()) closeMobileMore();
    });

    // On mobile, tapping outside the drawer closes the expanded navigation.
    document.addEventListener('click', function (event) {
        if (!isMobile()) return;
        if (!body.classList.contains('sidebar-collapsed')) {
            if (!event.target.closest('.sidebar') && !event.target.closest('.employee-sidebar')) {
                body.classList.add('sidebar-collapsed');
                localStorage.setItem('paywiseMobileSidebarExpanded', 'false');
                updateSidebarButton();
            }
        }
    });

    function closeSettingsModal() {
        settingsOverlay?.classList.remove('show');
        settingsOverlay?.setAttribute('aria-hidden', 'true');
        passwordForm?.reset();
        if (passwordMessage) passwordMessage.hidden = true;
    }

    openSettings?.addEventListener('click', function () {
        adminDropdown?.classList.remove('show');
        adminButton?.setAttribute('aria-expanded', 'false');
        settingsOverlay?.classList.add('show');
        settingsOverlay?.setAttribute('aria-hidden', 'false');
        settingsOverlay?.querySelector('input')?.focus();
    });
    closeSettings?.addEventListener('click', closeSettingsModal);
    cancelSettings?.addEventListener('click', closeSettingsModal);
    settingsOverlay?.addEventListener('click', function (event) {
        if (event.target === settingsOverlay) closeSettingsModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSettingsModal();
            adminDropdown?.classList.remove('show');
            adminButton?.setAttribute('aria-expanded', 'false');
            closeMobileMore();
        }
    });

    passwordForm?.addEventListener('submit', function (event) {
        const newPassword = passwordForm.elements.new_password.value;
        const confirmPassword = passwordForm.elements.confirm_password.value;
        if (newPassword !== confirmPassword) {
            event.preventDefault();
            if (passwordMessage) {
                passwordMessage.textContent = 'The new passwords do not match.';
                passwordMessage.className = 'settings-message error';
                passwordMessage.hidden = false;
            }
            return;
        }
        if (newPassword.length < 8) {
            event.preventDefault();
            if (passwordMessage) {
                passwordMessage.textContent = 'The new password must be at least 8 characters.';
                passwordMessage.className = 'settings-message error';
                passwordMessage.hidden = false;
            }
        }
    });

    const params = new URLSearchParams(window.location.search);
    if (params.get('password_changed') === '1') {
        alert('Password changed successfully.');
        history.replaceState({}, '', window.location.pathname);
    }
    if (params.get('password_error')) {
        settingsOverlay?.classList.add('show');
        settingsOverlay?.setAttribute('aria-hidden', 'false');
        if (passwordMessage) {
            passwordMessage.textContent = params.get('password_error');
            passwordMessage.className = 'settings-message error';
            passwordMessage.hidden = false;
        }
        history.replaceState({}, '', window.location.pathname);
    }

    updateSidebarButton();
});

window.printUrlInPopup = function (documentUrl) {
    const existingFrame = document.getElementById('paywiseHiddenPrintFrame');
    if (existingFrame) existingFrame.remove();

    const printFrame = document.createElement('iframe');
    printFrame.id = 'paywiseHiddenPrintFrame';
    printFrame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    printFrame.onload = function () {
        try {
            printFrame.contentWindow.focus();
            printFrame.contentWindow.print();
        } catch (err) {
            alert('Unable to open the print dialog.');
        }
        setTimeout(function () {
            printFrame.remove();
        }, 1500);
    };
    printFrame.src = documentUrl;
    document.body.appendChild(printFrame);
};
