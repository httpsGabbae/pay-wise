<?php
date_default_timezone_set('Asia/Manila');

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
            if ($envKey !== '' && strpos($envVal, '[YOUR-') === false) {
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

if (!function_exists('employee_photo_url')) {
    function employee_photo_url(?string $path, string $relative_prefix = '../'): string
    {
        $cleaned_path = trim((string)$path);
        if ($cleaned_path === '') {
            return '';
        }
        if (strpos($cleaned_path, 'data:image/') === 0 || preg_match('/^https?:\/\//i', $cleaned_path)) {
            return $cleaned_path;
        }
        return $relative_prefix . ltrim($cleaned_path, '/\\');
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

        public function data_seek(int $offset = 0): bool
        {
            if ($offset < 0 || $offset > $this->num_rows) {
                return false;
            }
            $this->row_cursor_position = $offset;
            return true;
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

        public function getPdo(): PDO
        {
            return $this->pdo_instance;
        }

        public static function translateSql(string $sql): string
        {
            $sql = preg_replace_callback('/"([^"]*)"/', function (array $matches): string {
                return "'" . str_replace("'", "''", $matches[1]) . "'";
            }, $sql);
            $sql = preg_replace("/SUM\(\s*status\s*=\s*'([^']+)'\s*\)/i", "SUM(CASE WHEN status='$1' THEN 1 ELSE 0 END)", $sql);
            $sql = preg_replace("/DATE_FORMAT\(\s*CURDATE\(\)\s*,\s*'%Y-%m-01'\s*\)/i", "DATE_TRUNC('month', CURRENT_DATE)::date", $sql);
            $sql = preg_replace_callback(
                "/DATE_FORMAT\(\s*([^,]+?)\s*,\s*'([^']+)'\s*\)/i",
                function (array $matches): string {
                    $pg_format = strtr($matches[2], [
                        '%Y' => 'YYYY',
                        '%y' => 'YY',
                        '%m' => 'MM',
                        '%b' => 'Mon',
                        '%M' => 'Month',
                        '%d' => 'DD',
                        '%H' => 'HH24',
                        '%i' => 'MI',
                        '%s' => 'SS',
                    ]);
                    return "TO_CHAR({$matches[1]}, '{$pg_format}')";
                },
                $sql
            );
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

    class SupabaseHttpRpcStatement
    {
        private SupabaseHttpRpcConnection $connection_instance;
        private string $sql_query;
        private array $bound_parameters = [];
        private array $bound_types = [];
        private array $last_rows = [];
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(SupabaseHttpRpcConnection $conn, string $sql)
        {
            $this->connection_instance = $conn;
            $this->sql_query = $sql;
        }

        public function bind_param(string $type_definitions, &...$bound_variables): bool
        {
            $this->bound_parameters = [];
            $this->bound_types = [];
            foreach ($bound_variables as $index => $variable_value) {
                $type_char = $type_definitions[$index] ?? 's';
                $this->bound_types[] = $type_char;
                $this->bound_parameters[] = $variable_value;
            }
            return true;
        }

        public function execute(): bool
        {
            $param_index = 0;
            $parameters = $this->bound_parameters;
            $types = $this->bound_types;
            $interpolated_sql = preg_replace_callback('/\?/', function () use (&$param_index, $parameters, $types): string {
                $val = $parameters[$param_index] ?? null;
                $type = $types[$param_index] ?? 's';
                $param_index++;
                if ($val === null) {
                    return 'NULL';
                }
                if ($type === 'i') {
                    return (string)(int)$val;
                }
                if ($type === 'd') {
                    return (string)(float)$val;
                }
                return "'" . str_replace("'", "''", (string)$val) . "'";
            }, $this->sql_query);

            $rpc_result = $this->connection_instance->executeRpc($interpolated_sql);
            $this->last_rows = $rpc_result['rows'];
            $this->affected_rows = $rpc_result['affected_rows'];
            $this->insert_id = $rpc_result['insert_id'];
            $this->connection_instance->affected_rows = $this->affected_rows;
            if ($this->insert_id > 0) {
                $this->connection_instance->insert_id = $this->insert_id;
            }
            return true;
        }

        public function get_result(): SupabasePgResult
        {
            return new SupabasePgResult($this->last_rows);
        }

        public function close(): void
        {
            $this->last_rows = [];
        }
    }

    class SupabaseHttpRpcConnection
    {
        private string $supabase_url;
        private string $api_key;
        private $curl_handle = null;
        public int $connect_errno = 0;
        public string $connect_error = '';
        public int $insert_id = 0;
        public int $affected_rows = 0;

        public function __construct(string $supabase_url, string $api_key)
        {
            $this->supabase_url = rtrim($supabase_url, '/');
            $this->api_key = $api_key;
            $endpoint = $this->supabase_url . '/rest/v1/rpc/paywise_exec_sql';
            $this->curl_handle = curl_init($endpoint);
            curl_setopt_array($this->curl_handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'apikey: ' . $this->api_key,
                    'Authorization: Bearer ' . $this->api_key,
                    'Connection: keep-alive',
                ],
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TIMEOUT => 15,
            ]);
            if (defined('CURL_HTTP_VERSION_2_0')) {
                curl_setopt($this->curl_handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            }
        }

        public function executeRpc(string $sql): array
        {
            $payload = json_encode(['sql_query' => $sql]);
            curl_setopt($this->curl_handle, CURLOPT_POSTFIELDS, $payload);
            $raw_response = curl_exec($this->curl_handle);
            $http_status = (int)curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($this->curl_handle);

            if ($raw_response === false || $http_status >= 400) {
                $decoded_error = json_decode((string)$raw_response, true);
                $error_message = $decoded_error['message'] ?? ($curl_error ?: "HTTP $http_status");
                if (stripos($error_message, 'paywise_exec_sql') !== false) {
                    throw new RuntimeException(
                        'Supabase HTTPS SQL bridge (paywise_exec_sql) is not installed yet. Please paste and run the updated supabase_schema.sql in Supabase SQL Editor, or set DATABASE_URL in Vercel Environment Variables.'
                    );
                }
                throw new RuntimeException('Supabase query error: ' . $error_message);
            }

            $decoded = json_decode((string)$raw_response, true);
            return [
                'rows' => is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [],
                'affected_rows' => (int)($decoded['affected_rows'] ?? 0),
                'insert_id' => (int)($decoded['insert_id'] ?? 0),
            ];
        }

        public function prepare(string $sql): SupabaseHttpRpcStatement
        {
            return new SupabaseHttpRpcStatement($this, SupabasePgConnection::translateSql($sql));
        }

        public function query(string $sql)
        {
            if (preg_match('/^\s*SET\s+time_zone/i', $sql)) {
                return true;
            }
            $postgres_sql = SupabasePgConnection::translateSql($sql);
            $rpc_result = $this->executeRpc($postgres_sql);
            $this->affected_rows = $rpc_result['affected_rows'];
            if ($rpc_result['insert_id'] > 0) {
                $this->insert_id = $rpc_result['insert_id'];
            }
            if (preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $postgres_sql)) {
                return new SupabasePgResult($rpc_result['rows']);
            }
            return true;
        }

        public function real_escape_string(string $string_to_escape): string
        {
            return str_replace("'", "''", $string_to_escape);
        }

        public function set_charset(string $charset_name): bool
        {
            return true;
        }

        public function begin_transaction(): bool
        {
            return true;
        }

        public function commit(): bool
        {
            return true;
        }

        public function rollback(): bool
        {
            return true;
        }
    }

    class SupabaseSessionHandler implements SessionHandlerInterface
    {
        private $connection_instance;
        private ?string $last_read_session_id = null;
        private ?string $last_read_session_data = null;

        public function __construct($connection)
        {
            $this->connection_instance = $connection;
        }

        public function open(string $save_path, string $session_name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $session_id): string|false
        {
            try {
                $statement = $this->connection_instance->prepare('SELECT session_data FROM public.app_sessions WHERE session_id = ? LIMIT 1');
                $statement->bind_param('s', $session_id);
                $statement->execute();
                $row = $statement->get_result()->fetch_assoc();
                $data = $row ? (string)$row['session_data'] : '';
                $this->last_read_session_id = $session_id;
                $this->last_read_session_data = $data;
                return $data;
            } catch (Throwable $error) {
                try {
                    $this->connection_instance->query("CREATE TABLE IF NOT EXISTS public.app_sessions (session_id VARCHAR(128) PRIMARY KEY, session_data TEXT NOT NULL DEFAULT '', updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
                } catch (Throwable $ignored) {
                }
                return '';
            }
        }

        public function write(string $session_id, string $session_data): bool
        {
            if ($this->last_read_session_id === $session_id && $this->last_read_session_data === $session_data) {
                return true;
            }
            try {
                $statement = $this->connection_instance->prepare(
                    'INSERT INTO public.app_sessions (session_id, session_data, updated_at) VALUES (?, ?, NOW())
                     ON CONFLICT (session_id) DO UPDATE SET session_data = EXCLUDED.session_data, updated_at = NOW()'
                );
                $statement->bind_param('ss', $session_id, $session_data);
                $this->last_read_session_id = $session_id;
                $this->last_read_session_data = $session_data;
                return $statement->execute();
            } catch (Throwable $error) {
                return false;
            }
        }

        public function destroy(string $session_id): bool
        {
            try {
                $statement = $this->connection_instance->prepare('DELETE FROM public.app_sessions WHERE session_id = ?');
                $statement->bind_param('s', $session_id);
                return $statement->execute();
            } catch (Throwable $error) {
                return false;
            }
        }

        public function gc(int $max_lifetime): int|false
        {
            return 0;
        }
    }
}

if (!function_exists('read_env_var')) {
    function read_env_var(string $variable_name): string
    {
        $value = getenv($variable_name);
        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }
        if (isset($_ENV[$variable_name]) && trim((string)$_ENV[$variable_name]) !== '') {
            return trim((string)$_ENV[$variable_name]);
        }
        if (isset($_SERVER[$variable_name]) && trim((string)$_SERVER[$variable_name]) !== '') {
            return trim((string)$_SERVER[$variable_name]);
        }
        return '';
    }
}

$cloudDatabaseUrl = (
    read_env_var('DATABASE_URL')
    ?: read_env_var('POSTGRES_URL')
    ?: read_env_var('POSTGRES_PRISMA_URL')
    ?: read_env_var('POSTGRES_URL_NON_POOLING')
    ?: read_env_var('SUPABASE_DATABASE_URL')
    ?: read_env_var('SUPABASE_DB_URL')
    ?: read_env_var('NEON_DATABASE_URL')
);

if ($cloudDatabaseUrl === '' && (read_env_var('SUPABASE_DB_HOST') !== '' || read_env_var('POSTGRES_HOST') !== '')) {
    $postgres_host = read_env_var('SUPABASE_DB_HOST') ?: read_env_var('POSTGRES_HOST');
    $postgres_port = read_env_var('SUPABASE_DB_PORT') ?: '5432';
    $postgres_user = rawurlencode(read_env_var('SUPABASE_DB_USER') ?: read_env_var('POSTGRES_USER') ?: 'postgres');
    $postgres_pass = rawurlencode(read_env_var('SUPABASE_DB_PASS') ?: read_env_var('POSTGRES_PASSWORD'));
    $postgres_name = ltrim(read_env_var('SUPABASE_DB_NAME') ?: read_env_var('POSTGRES_DATABASE') ?: 'postgres', '/');
    $cloudDatabaseUrl = "postgresql://{$postgres_user}:{$postgres_pass}@{$postgres_host}:{$postgres_port}/{$postgres_name}?sslmode=require";
}

$isRunningOnVercel = read_env_var('VERCEL') !== '' || is_dir('/var/task');
$supabaseProjectUrl = (
    read_env_var('SUPABASE_URL')
    ?: read_env_var('NEXT_PUBLIC_SUPABASE_URL')
    ?: read_env_var('VITE_SUPABASE_URL')
);
$supabaseApiKey = (
    read_env_var('SUPABASE_SERVICE_ROLE_KEY')
    ?: read_env_var('SUPABASE_SECRET_KEY')
    ?: read_env_var('SUPABASE_ANON_KEY')
    ?: read_env_var('NEXT_PUBLIC_SUPABASE_ANON_KEY')
    ?: read_env_var('NEXT_PUBLIC_SUPABASE_PUBLISHABLE_DEFAULT_KEY')
    ?: read_env_var('NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY')
    ?: read_env_var('VITE_SUPABASE_ANON_KEY')
);

if ($cloudDatabaseUrl !== '' && preg_match('/^postgres(?:ql)?:\/\//i', $cloudDatabaseUrl)) {
    try {
        $conn = new SupabasePgConnection($cloudDatabaseUrl);
    } catch (Throwable $connection_exception) {
        die('Supabase PostgreSQL connection failed: ' . $connection_exception->getMessage());
    }
} elseif (($isRunningOnVercel || read_env_var('SUPABASE_USE_HTTP') === '1') && $supabaseProjectUrl !== '' && $supabaseApiKey !== '') {
    $conn = new SupabaseHttpRpcConnection($supabaseProjectUrl, $supabaseApiKey);
} elseif ($isRunningOnVercel) {
    $allEnvKeys = array_unique(array_merge(array_keys($_ENV), array_keys($_SERVER)));
    $detectedDbKeys = array_values(array_filter($allEnvKeys, function (string $key): bool {
        return (bool)preg_match('/(SUPABASE|POSTGRES|DATABASE|DB_)/i', $key);
    }));
    $detectedSummary = $detectedDbKeys ? implode(', ', $detectedDbKeys) : 'None found';
    http_response_code(500);
    die(
        '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:48px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;color:#0f172a;">'
        . '<h2 style="margin-top:0;color:#dc2626;">Vercel Database Environment Variables Missing</h2>'
        . '<p>Pay-wise is running on Vercel, but no Supabase database credentials were found in Vercel Environment Variables.</p>'
        . '<p style="font-size:13px;color:#475569;"><strong>Detected DB-related env variable names:</strong> <code>' . e($detectedSummary) . '</code></p>'
        . '<p>Go to <strong>Vercel Dashboard &rarr; Project Settings &rarr; Environment Variables</strong> and add either:</p>'
        . '<ol style="line-height:1.7;">'
        . '<li><code>DATABASE_URL</code> (from Supabase Dashboard &rarr; Connect &rarr; Transaction Pooler URI), <strong>OR</strong></li>'
        . '<li><code>SUPABASE_URL</code> + <code>SUPABASE_SERVICE_ROLE_KEY</code> (and run the latest <code>supabase_schema.sql</code> in Supabase SQL Editor).</li>'
        . '</ol>'
        . '<p style="margin-bottom:0;font-size:13px;color:#475569;">After saving the environment variables in Vercel, trigger a <strong>Redeploy</strong>.</p>'
        . '</div>'
    );
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

if (session_status() === PHP_SESSION_NONE) {
    if ($conn instanceof SupabasePgConnection || $conn instanceof SupabaseHttpRpcConnection) {
        session_set_save_handler(new SupabaseSessionHandler($conn), true);
    }
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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

if (!function_exists('calculate_period_basic_salary')) {
    function calculate_period_basic_salary(float $monthly_basic_salary, string $period_start, string $period_end, string $pay_cycle = 'auto'): array
    {
        $monthly_basic = round(max(0.0, $monthly_basic_salary), 2);
        $start_timestamp = $period_start !== '' ? strtotime($period_start) : false;
        $end_timestamp = $period_end !== '' ? strtotime($period_end) : false;
        $days_covered = ($start_timestamp !== false && $end_timestamp !== false && $end_timestamp >= $start_timestamp)
            ? (int)round(($end_timestamp - $start_timestamp) / 86400) + 1
            : 30;

        if ($pay_cycle === 'semi_monthly') {
            $resolved_cycle = 'semi_monthly';
        } elseif ($pay_cycle === 'monthly') {
            $resolved_cycle = 'monthly';
        } else {
            $resolved_cycle = ($days_covered <= 16) ? 'semi_monthly' : 'monthly';
        }

        $cutoff_basic_salary = ($resolved_cycle === 'semi_monthly')
            ? round($monthly_basic / 2, 2)
            : $monthly_basic;

        return [
            'monthly_basic_salary' => $monthly_basic,
            'cutoff_basic_salary' => $cutoff_basic_salary,
            'pay_cycle' => $resolved_cycle,
            'pay_cycle_label' => $resolved_cycle === 'semi_monthly'
                ? 'Semi-Monthly (Every 15th · 50%)'
                : 'Monthly (Full Month · 100%)',
            'days_covered' => $days_covered,
        ];
    }
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
