<?php
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($value): string
    {
        return '₱ ' . number_format((float)$value, 2);
    }
}

if (!function_exists('active_employment_statuses')) {
    function active_employment_statuses(): array
    {
        return ['Regular', 'Probationary', 'Contractual', 'Part-Time'];
    }
}

if (!function_exists('is_active_employment_status')) {
    function is_active_employment_status($status): bool
    {
        return in_array((string)$status, active_employment_statuses(), true);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return false;
        }
        if (!is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            exit('Invalid session token. Please go back and try again.');
        }
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$dbName = getenv('DB_NAME') ?: 'paywise_payroll';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($conn->connect_errno) {
    die('Database connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
// Keep database dates/times aligned with the application's Philippines timezone.
@$conn->query("SET time_zone = '+08:00'");

if (!function_exists('dept_color_key')) {
    /* Department identity color key shared by every surface (directory,
       report bars, badges). Codes win; names are the fallback so free-text
       department values still resolve. Unknown => 'default' (brand teal).
       Codes: CCTE, CON, COC, CELA, CBA, CITHM, OPS, FIN, HR, IT, SALES, SUPPORT. */
    function dept_color_key(?string $code, ?string $name): string
    {
        $codeKey = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
        $byCode = [
            'OPS' => 'ops', 'FIN' => 'fin', 'HR' => 'hr',
            'IT' => 'it', 'SALES' => 'sales', 'SUPPORT' => 'support',
            'CCTE' => 'it', 'CON' => 'hr', 'COC' => 'ops',
            'CELA' => 'fin', 'CBA' => 'sales', 'CITHM' => 'support',
            'CCJE' => 'ops',
        ];
        if (isset($byCode[$codeKey])) return $byCode[$codeKey];
        $n = strtoupper((string)$name);
        if (strpos($n, 'COMPUTING') !== false || strpos($n, 'ENGINEERING') !== false) return 'it';
        if (strpos($n, 'NURSING') !== false) return 'hr';
        if (strpos($n, 'CRIMINOLOGY') !== false || strpos($n, 'CRIMINAL') !== false) return 'ops';
        if (strpos($n, 'EDUCATION') !== false || strpos($n, 'LIBERAL') !== false) return 'fin';
        if (strpos($n, 'BUSINESS') !== false || strpos($n, 'ACCOUNTANCY') !== false) return 'sales';
        if (strpos($n, 'TOURISM') !== false || strpos($n, 'HOSPITALITY') !== false) return 'support';
        if (strpos($n, 'OPERATIONS') !== false) return 'ops';
        if (strpos($n, 'FINANCE') !== false || strpos($n, 'ACCOUNTING') !== false) return 'fin';
        if (strpos($n, 'HUMAN RESOURCES') !== false || $n === 'HR') return 'hr';
        if (strpos($n, 'INFORMATION TECHNOLOGY') !== false) return 'it';
        if (strpos($n, 'SALES') !== false || strpos($n, 'MARKETING') !== false) return 'sales';
        if (strpos($n, 'SUPPORT') !== false || strpos($n, 'CUSTOMER') !== false) return 'support';
        return 'default';
    }
}
