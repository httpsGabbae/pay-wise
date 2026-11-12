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

// Load .env file from project root if present
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
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

/**
 * Lightweight mysqli-compatible PDO adapter for Neon PostgreSQL (DATABASE_URL).
 * Allows the entire codebase ($conn->prepare, bind_param, get_result, fetch_assoc, etc.)
 * to work seamlessly against Neon Serverless Postgres without rewriting pages.
 */
if (!class_exists('NeonPgResult')) {
    class NeonPgResult
    {
        private array $rows;
        private int $pos = 0;
        public int $num_rows = 0;

        public function __construct(array $rows)
        {
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc(): ?array
        {
            return $this->rows[$this->pos++] ?? null;
        }
    }

    class NeonPgStmt
    {
        private PDO $pdo;
        private NeonPgConnection $conn;
        private string $sql;
        private array $params = [];
        private ?PDOStatement $stmt = null;
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(PDO $pdo, NeonPgConnection $conn, string $sql)
        {
            $this->pdo = $pdo;
            $this->conn = $conn;
            $this->sql = $sql;
        }

        public function bind_param(string $types, &...$vars): bool
        {
            $this->params = [];
            foreach ($vars as $i => $val) {
                $t = $types[$i] ?? 's';
                if ($val === null) {
                    $this->params[] = null;
                } elseif ($t === 'i') {
                    $this->params[] = (int)$val;
                } elseif ($t === 'd') {
                    $this->params[] = (float)$val;
                } else {
                    $this->params[] = (string)$val;
                }
            }
            return true;
        }

        public function execute(): bool
        {
            $this->stmt = $this->pdo->prepare($this->sql);
            $ok = $this->stmt->execute($this->params);
            $this->affected_rows = $this->stmt->rowCount();
            if (preg_match('/^\s*INSERT\s+INTO\s+/i', $this->sql)) {
                try {
                    $last = (int)$this->pdo->lastInsertId();
                    $this->insert_id = $last;
                    $this->conn->insert_id = $last;
                } catch (Throwable $e) {
                }
            }
            return $ok;
        }

        public function get_result(): NeonPgResult
        {
            if (!$this->stmt) {
                return new NeonPgResult([]);
            }
            return new NeonPgResult($this->stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        public function close(): void
        {
            $this->stmt = null;
        }
    }

    class NeonPgConnection
    {
        private PDO $pdo;
        public int $connect_errno = 0;
        public string $connect_error = '';
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(string $databaseUrl)
        {
            $parts = parse_url($databaseUrl);
            $host = $parts['host'] ?? 'localhost';
            $port = $parts['port'] ?? 5432;
            $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
            $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
            $db   = ltrim($parts['path'] ?? '/neondb', '/');
            parse_str($parts['query'] ?? '', $query);
            $sslmode = $query['sslmode'] ?? 'require';

            $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode={$sslmode}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec("SET TIME ZONE 'Asia/Manila'");
        }

        public static function translateSql(string $sql): string
        {
            // Translate MySQL double-quoted status literals to standard SQL single-quoted literals
            $sql = preg_replace('/\bstatus\s*=\s*"([^"]+)"/i', "status = '$1'", $sql);
            // Translate MySQL boolean SUM(status='X') to PostgreSQL SUM(CASE WHEN status='X' THEN 1 ELSE 0 END)
            $sql = preg_replace("/SUM\(\s*status\s*=\s*'([^']+)'\s*\)/i", "SUM(CASE WHEN status='$1' THEN 1 ELSE 0 END)", $sql);
            // Translate DATE_FORMAT(col, '%Y-%m-01') and DATE_FORMAT(col, '%Y-%m')
            $sql = preg_replace("/DATE_FORMAT\(\s*CURDATE\(\)\s*,\s*'%Y-%m-01'\s*\)/i", "DATE_TRUNC('month', CURRENT_DATE)::date", $sql);
            $sql = preg_replace("/DATE_FORMAT\(\s*([a-zA-Z0-9_.]+)\s*,\s*'%Y-%m'\s*\)/i", "TO_CHAR($1, 'YYYY-MM')", $sql);
            // Translate LAST_DAY(CURDATE()) and CURDATE() and YEAR(...)
            $sql = preg_replace("/LAST_DAY\(\s*CURDATE\(\)\s*\)/i", "(DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month - 1 day')::date", $sql);
            $sql = preg_replace("/\bYEAR\(\s*CURDATE\(\)\s*\)/i", "EXTRACT(YEAR FROM CURRENT_DATE)", $sql);
            $sql = preg_replace("/\bYEAR\(\s*([a-zA-Z0-9_.]+)\s*\)/i", "EXTRACT(YEAR FROM $1)", $sql);
            $sql = preg_replace("/\bCURDATE\(\)/i", "CURRENT_DATE", $sql);
            // Translate attendance ON DUPLICATE KEY UPDATE to PostgreSQL ON CONFLICT
            if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false && stripos($sql, 'INTO attendance') !== false) {
                $sql = preg_replace(
                    '/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/is',
                    'ON CONFLICT (employee_id, attendance_date) DO UPDATE SET period_id=EXCLUDED.period_id, daily_rate=EXCLUDED.daily_rate, absences_count=EXCLUDED.absences_count, tardiness_hours=EXCLUDED.tardiness_hours, undertime_hours=EXCLUDED.undertime_hours, overtime_hours=EXCLUDED.overtime_hours, computed_deduction=EXCLUDED.computed_deduction, computed_overtime=EXCLUDED.computed_overtime, status=EXCLUDED.status, remarks=EXCLUDED.remarks',
                    $sql
                );
            }
            return $sql;
        }

        public function prepare(string $sql): NeonPgStmt
        {
            return new NeonPgStmt($this->pdo, $this, self::translateSql($sql));
        }

        public function query(string $sql)
        {
            if (preg_match('/^\s*SET\s+time_zone/i', $sql)) {
                return true;
            }
            $pgSql = self::translateSql($sql);
            $stmt = $this->pdo->query($pgSql);
            if ($stmt === false) {
                return false;
            }
            $this->affected_rows = $stmt->rowCount();
            if (preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $pgSql)) {
                return new NeonPgResult($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            return true;
        }

        public function real_escape_string(string $str): string
        {
            return substr($this->pdo->quote($str), 1, -1);
        }

        public function set_charset(string $charset): bool
        {
            return true;
        }

        public function begin_transaction(): bool
        {
            return $this->pdo->beginTransaction();
        }

        public function commit(): bool
        {
            return $this->pdo->commit();
        }

        public function rollback(): bool
        {
            return $this->pdo->rollBack();
        }
    }
}

$databaseUrl = trim((string)(getenv('DATABASE_URL') ?: getenv('NEON_DATABASE_URL') ?: ''));

if ($databaseUrl !== '' && preg_match('/^postgres(?:ql)?:\/\//i', $databaseUrl)) {
    try {
        $conn = new NeonPgConnection($databaseUrl);
    } catch (Throwable $e) {
        die('Neon database connection failed: ' . $e->getMessage());
    }
} else {
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
}

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
