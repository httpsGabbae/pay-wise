<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/calculate_and_automate_employee_salary_payouts.php';

if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($value): string { return '₱ ' . number_format((float)$value, 2); }
}
if (!function_exists('employee_photo_url')) {
    function employee_photo_url(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (strpos($path, 'data:image/') === 0 || preg_match('/^https?:\/\//i', $path)) return $path;
        return '../' . ltrim($path, '/\\');
    }
}
function current_employee($conn): ?array {
    $id = (int)($_SESSION['employee_id'] ?? 0);
    if ($id <= 0) return null;
    $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    if ($row && !is_active_employment_status($row['employment_status'] ?? '')) {
        return null;
    }
    return $row;
}

$__employee_session_id = (int)($_SESSION['employee_id'] ?? 0);
if ($__employee_session_id > 0) {
    $stmt = $conn->prepare('SELECT employment_status FROM employees WHERE employee_id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $__employee_session_id);
        $stmt->execute();
        $statusRow = $stmt->get_result()->fetch_assoc();
        if (!$statusRow || !is_active_employment_status($statusRow['employment_status'] ?? '')) {
            unset($_SESSION['employee_id'], $_SESSION['employee_no'], $_SESSION['employee_name']);
            header('Location: login.php?error=inactive');
            exit;
        }
    }
}
unset($__employee_session_id, $statusRow);
