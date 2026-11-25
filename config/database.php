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

$candidateEnvironmentFiles = [
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/.env.local',
    dirname(__DIR__) . '/env.local',
];
foreach ($candidateEnvironmentFiles as $envFilePath) {
    if (is_file($envFilePath) && is_readable($envFilePath)) {
        foreach (file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$envKey, $envVal] = explode('=', $line, 2);
            $envKey = trim($envKey);
            $envVal = trim(trim($envVal), "\"'");
            if ($envKey !== '') {
                putenv("$envKey=$envVal");
                $_ENV[$envKey] = $envVal;
            }
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

if (!class_exists('SupabasePgResult')) {
    class SupabasePgResult
    {
        private array $database_rows;
        private int $row_cursor_position = 0;
        public int $num_rows = 0;

        public function __construct(array $rows)
        {
            $this->database_rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc(): ?array
        {
            return $this->database_rows[$this->row_cursor_position++] ?? null;
        }
    }

    class SupabasePgStatement
    {
        private PDO $pdo_instance;
        private SupabasePgConnection $connection_instance;
        private string $sql_query;
        private array $bound_parameters = [];
        private ?PDOStatement $pdo_statement = null;
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(PDO $pdo, SupabasePgConnection $conn, string $sql)
        {
            $this->pdo_instance = $pdo;
            $this->connection_instance = $conn;
            $this->sql_query = $sql;
        }

        public function bind_param(string $type_definitions, &...$bound_variables): bool
        {
            $this->bound_parameters = [];
            foreach ($bound_variables as $index => $variable_value) {
                $type_char = $type_definitions[$index] ?? 's';
                if ($variable_value === null) {
                    $this->bound_parameters[] = null;
                } elseif ($type_char === 'i') {
                    $this->bound_parameters[] = (int)$variable_value;
                } elseif ($type_char === 'd') {
                    $this->bound_parameters[] = (float)$variable_value;
                } else {
                    $this->bound_parameters[] = (string)$variable_value;
                }
            }
            return true;
        }

        public function execute(): bool
        {
            $this->pdo_statement = $this->pdo_instance->prepare($this->sql_query);
            $execution_success = $this->pdo_statement->execute($this->bound_parameters);
            $this->affected_rows = $this->pdo_statement->rowCount();
            if (preg_match('/^\s*INSERT\s+INTO\s+/i', $this->sql_query)) {
                try {
                    $last_inserted_id = (int)$this->pdo_instance->lastInsertId();
                    $this->insert_id = $last_inserted_id;
                    $this->connection_instance->insert_id = $last_inserted_id;
                } catch (Throwable $ignored_error) {
                }
            }
            return $execution_success;
        }

        public function get_result(): SupabasePgResult
        {
            if (!$this->pdo_statement) {
                return new SupabasePgResult([]);
            }
            return new SupabasePgResult($this->pdo_statement->fetchAll(PDO::FETCH_ASSOC));
        }

        public function close(): void
        {
            $this->pdo_statement = null;
        }
    }

    class SupabasePgConnection
    {
        private PDO $pdo_instance;
        public int $connect_errno = 0;
        public string $connect_error = '';
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(string $database_url)
        {
            $parsed_url = parse_url($database_url);
            $host = $parsed_url['host'] ?? 'localhost';
            $port = $parsed_url['port'] ?? 5432;
            $user = isset($parsed_url['user']) ? rawurldecode($parsed_url['user']) : '';
            $pass = isset($parsed_url['pass']) ? rawurldecode($parsed_url['pass']) : '';
            $database_name = ltrim($parsed_url['path'] ?? '/postgres', '/');
            parse_str($parsed_url['query'] ?? '', $query_parameters);
            $ssl_mode = $query_parameters['sslmode'] ?? 'require';

            $dsn = "pgsql:host={$host};port={$port};dbname={$database_name};sslmode={$ssl_mode}";
            $this->pdo_instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo_instance->exec("SET TIME ZONE 'Asia/Manila'");
        }

        public static function translateSql(string $sql): string
        {
            $sql = preg_replace('/\bstatus\s*=\s*"([^"]+)"/i', "status = '$1'", $sql);
            $sql = preg_replace("/SUM\(\s*status\s*=\s*'([^']+)'\s*\)/i", "SUM(CASE WHEN status='$1' THEN 1 ELSE 0 END)", $sql);
            $sql = preg_replace("/DATE_FORMAT\(\s*CURDATE\(\)\s*,\s*'%Y-%m-01'\s*\)/i", "DATE_TRUNC('month', CURRENT_DATE)::date", $sql);
            $sql = preg_replace("/DATE_FORMAT\(\s*([a-zA-Z0-9_.]+)\s*,\s*'%Y-%m'\s*\)/i", "TO_CHAR($1, 'YYYY-MM')", $sql);
            $sql = preg_replace("/LAST_DAY\(\s*CURDATE\(\)\s*\)/i", "(DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month - 1 day')::date", $sql);
            $sql = preg_replace("/\bYEAR\(\s*CURDATE\(\)\s*\)/i", "EXTRACT(YEAR FROM CURRENT_DATE)", $sql);
            $sql = preg_replace("/\bYEAR\(\s*([a-zA-Z0-9_.]+)\s*\)/i", "EXTRACT(YEAR FROM $1)", $sql);
            $sql = preg_replace("/\bCURDATE\(\)/i", "CURRENT_DATE", $sql);

            if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false && stripos($sql, 'INTO attendance') !== false) {
                $sql = preg_replace(
                    '/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/is',
                    'ON CONFLICT (employee_id, attendance_date) DO UPDATE SET period_id=EXCLUDED.period_id, daily_rate=EXCLUDED.daily_rate, absences_count=EXCLUDED.absences_count, tardiness_hours=EXCLUDED.tardiness_hours, undertime_hours=EXCLUDED.undertime_hours, overtime_hours=EXCLUDED.overtime_hours, computed_deduction=EXCLUDED.computed_deduction, computed_overtime=EXCLUDED.computed_overtime, status=EXCLUDED.status, remarks=EXCLUDED.remarks',
                    $sql
                );
            }
            return $sql;
        }

        public function prepare(string $sql): SupabasePgStatement
        {
            return new SupabasePgStatement($this->pdo_instance, $this, self::translateSql($sql));
        }

        public function query(string $sql)
        {
            if (preg_match('/^\s*SET\s+time_zone/i', $sql)) {
                return true;
            }
            $postgres_sql = self::translateSql($sql);
            $statement = $this->pdo_instance->query($postgres_sql);
            if ($statement === false) {
                return false;
            }
            $this->affected_rows = $statement->rowCount();
            if (preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $postgres_sql)) {
                return new SupabasePgResult($statement->fetchAll(PDO::FETCH_ASSOC));
            }
            return true;
        }

        public function real_escape_string(string $string_to_escape): string
        {
            return substr($this->pdo_instance->quote($string_to_escape), 1, -1);
        }

        public function set_charset(string $charset_name): bool
        {
            return true;
        }

        public function begin_transaction(): bool
        {
            return $this->pdo_instance->beginTransaction();
        }

        public function commit(): bool
        {
            return $this->pdo_instance->commit();
        }

        public function rollback(): bool
        {
            return $this->pdo_instance->rollBack();
        }
    }
}

$cloudDatabaseUrl = trim((string)(getenv('DATABASE_URL') ?: getenv('SUPABASE_DATABASE_URL') ?: getenv('NEON_DATABASE_URL') ?: ''));

if ($cloudDatabaseUrl === '' && getenv('SUPABASE_DB_HOST')) {
    $postgres_host = getenv('SUPABASE_DB_HOST');
    $postgres_port = getenv('SUPABASE_DB_PORT') ?: 5432;
    $postgres_user = rawurlencode(getenv('SUPABASE_DB_USER') ?: 'postgres');
    $postgres_pass = rawurlencode(getenv('SUPABASE_DB_PASS') ?: '');
    $postgres_name = ltrim(getenv('SUPABASE_DB_NAME') ?: 'postgres', '/');
    $cloudDatabaseUrl = "postgresql://{$postgres_user}:{$postgres_pass}@{$postgres_host}:{$postgres_port}/{$postgres_name}?sslmode=require";
}

if ($cloudDatabaseUrl !== '' && preg_match('/^postgres(?:ql)?:\/\//i', $cloudDatabaseUrl)) {
    try {
        $conn = new SupabasePgConnection($cloudDatabaseUrl);
    } catch (Throwable $connection_exception) {
        die('Cloud database connection failed: ' . $connection_exception->getMessage());
    }
} else {
    $database_host = getenv('DB_HOST') ?: 'localhost';
    $database_user = getenv('DB_USER') ?: 'root';
    $database_password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $database_name = getenv('DB_NAME') ?: 'paywise_payroll';
    $database_port = (int)(getenv('DB_PORT') ?: 3306);

    $conn = new mysqli($database_host, $database_user, $database_password, $database_name, $database_port);
    if ($conn->connect_errno) {
        die('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
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
