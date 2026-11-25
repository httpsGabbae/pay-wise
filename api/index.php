<?php
$project_root_directory = realpath(dirname(__DIR__));
$request_uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '/login.php', PHP_URL_PATH) ?: '/login.php';
$relative_script_path = ltrim(str_replace('\\', '/', $request_uri_path), '/');

if ($relative_script_path === '' || $relative_script_path === 'index.html' || $relative_script_path === 'index.php') {
    $relative_script_path = 'login.php';
}

$allowed_entry_pattern = '/^(login\.php|(auth|pages|employee|print)\/[a-zA-Z0-9_-]+\.php)$/';
if (!preg_match($allowed_entry_pattern, $relative_script_path)) {
    http_response_code(404);
    exit('Page not found.');
}

$resolved_script_path = realpath($project_root_directory . '/' . $relative_script_path);
if (
    $resolved_script_path === false ||
    strpos($resolved_script_path, $project_root_directory) !== 0 ||
    !is_file($resolved_script_path)
) {
    http_response_code(404);
    exit('Page not found.');
}

chdir(dirname($resolved_script_path));
$_SERVER['SCRIPT_FILENAME'] = $resolved_script_path;
$_SERVER['SCRIPT_NAME'] = '/' . $relative_script_path;
$_SERVER['PHP_SELF'] = '/' . $relative_script_path;

require $resolved_script_path;
