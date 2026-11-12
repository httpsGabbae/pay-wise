<?php
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

/* Role-based access. Roles: superadmin (everything + accounts),
   finance (payroll, salary, reports), hr (people + attendance, payroll
   masked). Unknown/missing role => least privilege (deny). */
if (!function_exists('admin_role')) {
    function admin_role(): string
    {
        $role = $_SESSION['admin_role'] ?? '';
        if (in_array($role, ['superadmin', 'finance', 'hr'], true)) {
            return $role;
        }
        // Backfill for sessions created before roles existed.
        global $conn;
        if (isset($conn) && isset($_SESSION['admin_id'])) {
            $stmt = $conn->prepare('SELECT role, is_active FROM admins WHERE admin_id=? LIMIT 1');
            if ($stmt) {
                $id = (int)$_SESSION['admin_id'];
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && (int)$row['is_active'] === 1 && in_array($row['role'], ['superadmin', 'finance', 'hr'], true)) {
                    $_SESSION['admin_role'] = $row['role'];
                    return $row['role'];
                }
            }
        }
        return '';
    }
}

if (!function_exists('require_role')) {
    function require_role(string ...$roles): void
    {
        $role = admin_role();
        if ($role === '' || !in_array($role, $roles, true)) {
            http_response_code(403);
            exit('Access denied for your account role.');
        }
    }
}

if (!function_exists('is_superadmin')) {
    function is_superadmin(): bool
    {
        return admin_role() === 'superadmin';
    }
}

if (!function_exists('role_label')) {
    function role_label(?string $role = null): string
    {
        $map = ['superadmin' => 'Superadmin', 'finance' => 'Finance', 'hr' => 'HR'];
        return $map[$role ?? admin_role()] ?? 'Unknown';
    }
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

function employee_name(array $employee): string
{
    return trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name']);
}

function employee_full_name_last_first(array $employee): string
{
    return trim($employee['last_name'] . ', ' . $employee['first_name'] . ' ' . ($employee['middle_name'] ?? ''));
}

function setting(mysqli $conn, string $key, string $default = ''): string
{
    $stmt = $conn->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['setting_value'] ?? $default;
}

function next_employee_no(mysqli $conn): string
{
    $prefix = preg_replace('/[^0-9A-Za-z]/', '', setting($conn, 'employee_number_prefix', '25'));
    $digits = max(1, min(8, (int)setting($conn, 'employee_number_digits', '4')));
    $like = $prefix . '-%';

    $stmt = $conn->prepare('SELECT employee_no FROM employees WHERE employee_no LIKE ? ORDER BY employee_no DESC LIMIT 1');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $number = 1;
    if ($row) {
        $parts = explode('-', $row['employee_no']);
        if (count($parts) === 2) {
            $number = (int)$parts[1] + 1;
        }
    }

    return $prefix . '-' . str_pad((string)$number, $digits, '0', STR_PAD_LEFT);
}

function upload_employee_photo(?array $file, ?string $current = null)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }

    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['error' => 'The photo could not be uploaded.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['error' => 'The photo must be 2MB or smaller.'];
    }

    if (@getimagesize($file['tmp_name']) === false) {
        return ['error' => 'Please upload a JPG or PNG image.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return ['error' => 'Only JPG, JPEG, and PNG photos are allowed.'];
    }

    $directory = __DIR__ . '/../uploads/employee_photos';
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        return ['error' => 'The employee photo folder could not be created.'];
    }

    $filename = 'emp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $directory . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return ['error' => 'The employee photo could not be saved.'];
    }

    if ($current) {
        $cleaned = ltrim(str_replace('\\', '/', $current), '/');
        if (strpos($cleaned, 'uploads/employee_photos/') === 0) {
            $old = __DIR__ . '/../' . $cleaned;
            if (is_file($old)) {
                @unlink($old);
            }
        }
    }

    return 'uploads/employee_photos/' . $filename;
}

function delete_employee_photo(?string $path): void
{
    if (!$path) {
        return;
    }

    $cleaned = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($cleaned, 'uploads/employee_photos/') !== 0) {
        return;
    }

    $file = __DIR__ . '/../' . $cleaned;
    if (is_file($file)) {
        @unlink($file);
    }
}
