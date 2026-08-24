<?php
/**
 * WHM / cPanel API — addon domains, MySQL databases for child panels.
 */
class WhmProvisioner
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function isConfigured(): bool
    {
        return $this->apiHost() !== '' && $this->whmUser() !== '' && $this->apiToken() !== ''
            && $this->cpanelUser() !== '';
    }

    public function cpanelUser(): string
    {
        $user = trim((string) ($this->db->getSetting('child_panel_cpanel_user') ?? ''));
        if ($user === '' || strtolower($user) === 'root') {
            return '';
        }
        return $user;
    }

    private function apiHost(): string
    {
        $host = trim((string) ($this->db->getSetting('child_panel_whm_host') ?? ''));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        return rtrim($host, '/');
    }

    private function whmUser(): string
    {
        return trim((string) ($this->db->getSetting('child_panel_whm_username') ?? ''));
    }

    private function apiToken(): string
    {
        return trim((string) ($this->db->getSetting('child_panel_whm_api_token') ?? ''));
    }

    private function apiPort(): int
    {
        $port = (int) ($this->db->getSetting('child_panel_whm_port') ?? 2087);
        return $port > 0 ? $port : 2087;
    }

    /** @return array{success: bool, data?: mixed, error?: string, http_code?: int} */
    private function whmCpanelCall(string $module, string $func, array $params = [], int $apiVersion = 2): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'WHM API not configured.'];
        }

        $cpUser = $this->cpanelUser();
        if ($cpUser === '') {
            return [
                'success' => false,
                'error' => 'cPanel user not set — in Admin → Settings → Child Panel set cPanel user to smmturk (not root).',
            ];
        }

        $query = array_merge([
            'cpanel_jsonapi_user' => $cpUser,
            'cpanel_jsonapi_apiversion' => $apiVersion,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $func,
        ], $params);

        $url = 'https://' . $this->apiHost() . ':' . $this->apiPort() . '/json-api/cpanel?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Authorization: whm ' . $this->whmUser() . ':' . $this->apiToken(),
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => 'WHM curl error: ' . $err, 'http_code' => $code];
        }

        $json = json_decode((string) $body, true);
        if ($code < 200 || $code >= 300) {
            return [
                'success' => false,
                'error' => 'WHM HTTP ' . $code . ': ' . substr((string) $body, 0, 300),
                'http_code' => $code,
                'data' => $json,
            ];
        }

        $cpanelResult = $json['cpanelresult'] ?? $json;
        if ($apiVersion === 3) {
            $resultBlock = $cpanelResult['result'] ?? [];
            $status = (int) ($resultBlock['status'] ?? 0);
            if ($status !== 1) {
                $errors = $resultBlock['errors'] ?? $resultBlock['messages'] ?? null;
                $reason = is_array($errors) ? implode('; ', array_map('strval', $errors)) : (string) ($cpanelResult['error'] ?? 'UAPI call failed');
                if (stripos($reason, 'already') !== false) {
                    return ['success' => true, 'data' => $resultBlock['data'] ?? [], 'http_code' => $code];
                }
                return ['success' => false, 'error' => $reason !== '' ? $reason : 'UAPI call failed', 'data' => $json];
            }
            return ['success' => true, 'data' => $resultBlock['data'] ?? [], 'http_code' => $code];
        }

        $event = $cpanelResult['event']['result'] ?? null;
        if ($event !== null && (int) $event !== 1) {
            $reason = $cpanelResult['event']['reason'] ?? $cpanelResult['error'] ?? 'Unknown cPanel error';
            return ['success' => false, 'error' => (string) $reason, 'data' => $json];
        }

        $data = $cpanelResult['data'] ?? $cpanelResult;
        if (isset($data['result']) && (int) $data['result'] === 0) {
            $reason = $data['reason'] ?? $data['error'] ?? 'cPanel API failed';
            if (stripos((string) $reason, 'already') === false) {
                return ['success' => false, 'error' => (string) $reason, 'data' => $json];
            }
        }

        return ['success' => true, 'data' => $data, 'http_code' => $code];
    }

    /** @return array{success: bool, error?: string, document_root?: string, message?: string, panel_url?: string} */
    public function provisionDomain(string $domain): array
    {
        $domain = ChildPanelManager::normalizeDomain($domain);
        if ($domain === '') {
            return ['success' => false, 'error' => 'Invalid domain.'];
        }

        $deployer = new ChildPanelDeployer();
        $expectedDocroot = $deployer->docrootForDomain($domain);
        $relativeDir = 'public_html/' . $domain;

        $existing = $this->findAddonDomain($domain);
        if ($existing !== null) {
            $docroot = $existing['document_root'] !== '' ? $existing['document_root'] : $expectedDocroot;
            $this->restartHttpd();
            return [
                'success' => true,
                'message' => 'Addon domain registered (docroot: ' . $docroot . ').',
                'document_root' => $docroot,
                'panel_url' => 'https://' . $domain,
            ];
        }

        $sub = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0] ?? 'panel')) ?: 'panel';
        if (strlen($sub) < 2) {
            $sub = 'cp' . substr(md5($domain), 0, 6);
        }

        $result = $this->whmCpanelCall('AddonDomain', 'add_addon_domain', [
            'domain' => $domain,
            'subdomain' => $sub,
            'document_root' => $relativeDir,
        ], 3);

        if (!$result['success']) {
            $result = $this->whmCpanelCall('AddonDomain', 'addaddondomain', [
                'newdomain' => $domain,
                'subdomain' => $sub,
                'dir' => $relativeDir,
            ], 2);
        }

        if (!$result['success'] && !$this->isAlreadyExistsError($result['error'] ?? '')) {
            $tryPark = $this->whmCpanelCall('Park', 'park', [
                'domain' => $domain,
                'topdomain' => $this->primaryDomain(),
                'disallowdot' => 0,
            ], 2);
            if (!$tryPark['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'Addon domain failed.'];
            }
            $this->restartHttpd();
            return [
                'success' => true,
                'message' => 'Parked domain on account.',
                'document_root' => $deployer->homePath() . '/public_html',
                'panel_url' => 'https://' . $domain,
            ];
        }

        $existing = $this->findAddonDomain($domain);
        $docroot = $existing['document_root'] ?? $expectedDocroot;
        $this->restartHttpd();

        return [
            'success' => true,
            'message' => $existing !== null ? 'Addon domain verified in cPanel.' : 'Addon domain created.',
            'document_root' => $docroot !== '' ? $docroot : $expectedDocroot,
            'panel_url' => 'https://' . $domain,
        ];
    }

    /** @return array{domain: string, document_root: string, subdomain: string}|null */
    public function findAddonDomain(string $domain): ?array
    {
        $domain = ChildPanelManager::normalizeDomain($domain);
        foreach ($this->listAddonDomains() as $row) {
            if ($row['domain'] === $domain) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<array{domain: string, document_root: string, subdomain: string}> */
    private function listAddonDomains(): array
    {
        $out = [];
        foreach ([['list_addon_domains', 3], ['listaddondomains', 2]] as [$func, $ver]) {
            $res = $this->whmCpanelCall('AddonDomain', $func, [], $ver);
            if (!$res['success']) {
                continue;
            }
            $data = $res['data'] ?? [];
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $domain = (string) ($row['domain'] ?? $row['domainname'] ?? (is_string($key) && !is_numeric($key) ? $key : ''));
                $domain = ChildPanelManager::normalizeDomain($domain);
                if ($domain === '') {
                    continue;
                }
                $docroot = (string) ($row['documentroot'] ?? $row['dir'] ?? $row['document_root'] ?? $row['reldir'] ?? '');
                $out[] = [
                    'domain' => $domain,
                    'document_root' => $this->normalizeDocrootPath($docroot),
                    'subdomain' => (string) ($row['subdomain'] ?? $row['sub'] ?? ''),
                ];
            }
            if ($out !== []) {
                break;
            }
        }
        return $out;
    }

    private function normalizeDocrootPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (!str_starts_with($path, '/')) {
            $home = (new ChildPanelDeployer())->homePath();
            if ($home !== '') {
                return rtrim($home, '/') . '/' . ltrim($path, '/');
            }
        }
        return rtrim($path, '/');
    }

    private function restartHttpd(): void
    {
        $url = 'https://' . $this->apiHost() . ':' . $this->apiPort() . '/json-api/restartsrv?service=httpd';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Authorization: whm ' . $this->whmUser() . ':' . $this->apiToken(),
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public function domainExistsOnAccount(string $domain): bool
    {
        return $this->findAddonDomain(ChildPanelManager::normalizeDomain($domain)) !== null;
    }

    private function primaryDomain(): string
    {
        $d = trim((string) ($this->db->getSetting('child_panel_primary_domain') ?? ''));
        if ($d !== '') {
            return ChildPanelManager::normalizeDomain($d);
        }
        if (defined('SITE_URL')) {
            $host = parse_url(SITE_URL, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        return 'smm-turk.com';
    }

    /**
     * @return array{success: bool, error?: string, db_name?: string, db_user?: string, db_pass?: string}
     */
    public function createDatabase(string $baseName, string $baseUser, string $password): array
    {
        $baseName = preg_replace('/[^a-z0-9_]/', '', strtolower($baseName)) ?: 'cpdb';
        $baseUser = preg_replace('/[^a-z0-9_]/', '', strtolower($baseUser)) ?: $baseName;
        $prefix = $this->cpanelUser() . '_';
        $fullDbName = $this->cpanelPrefixedName($baseName);
        $fullUserName = $this->cpanelPrefixedName($baseUser);

        $dbRes = $this->mysqlUapi('create_database', ['name' => $fullDbName]);
        if (!$dbRes['success'] && !$this->isAlreadyExistsError($dbRes['error'] ?? '')) {
            return ['success' => false, 'error' => 'MySQL create_database: ' . ($dbRes['error'] ?? 'failed')];
        }

        $userRes = $this->mysqlUapi('create_user', [
            'name' => $fullUserName,
            'password' => $password,
        ]);
        if (!$userRes['success'] && $this->isAlreadyExistsError($userRes['error'] ?? '')) {
            $userRes = $this->syncMysqlPassword($fullUserName, $password);
        } elseif ($userRes['success']) {
            $this->syncMysqlPassword($fullUserName, $password);
        }
        if (!$userRes['success']) {
            return ['success' => false, 'error' => 'MySQL create_user: ' . ($userRes['error'] ?? 'failed')];
        }

        $privRes = $this->mysqlUapi('set_privileges_on_database', [
            'user' => $fullUserName,
            'database' => $fullDbName,
            'privileges' => 'ALL',
        ]);
        if (!$privRes['success']) {
            $privRes = $this->mysqlUapi('set_privileges_on_database', [
                'user' => $fullUserName,
                'database' => $fullDbName,
                'privileges' => 'ALL PRIVILEGES',
            ]);
        }
        if (!$privRes['success']) {
            $privRes = $this->mysqlUapi('set_all_privileges_on_database', [
                'user' => $fullUserName,
                'database' => $fullDbName,
            ]);
        }
        if (!$privRes['success'] && !$this->isAlreadyExistsError($privRes['error'] ?? '')) {
            return ['success' => false, 'error' => 'MySQL privileges: ' . ($privRes['error'] ?? 'set_privileges failed')];
        }

        $access = $this->ensureMysqlAccess($fullDbName, $fullUserName, $password);
        if (!$access['success']) {
            return ['success' => false, 'error' => $access['error'] ?? 'MySQL access verification failed'];
        }

        return [
            'success' => true,
            'db_name' => $fullDbName,
            'db_user' => $fullUserName,
            'db_pass' => $password,
        ];
    }

    /** @return array{success: bool, error?: string} */
    private function syncMysqlPassword(string $fullUserName, string $password): array
    {
        $names = array_values(array_unique([
            $fullUserName,
            $this->cpanelUnprefixedName($fullUserName),
        ]));
        $lastError = '';
        foreach ($names as $name) {
            foreach ([['name' => $name], ['user' => $name]] as $params) {
                $params['password'] = $password;
                $uapi = $this->whmCpanelCall('Mysql', 'set_password', $params, 3);
                if ($uapi['success']) {
                    return ['success' => true];
                }
                $lastError = (string) ($uapi['error'] ?? $lastError);
            }
            $legacy = $this->mysqlFeLegacy('set_password', ['name' => $fullUserName, 'password' => $password]);
            if ($legacy['success']) {
                return ['success' => true];
            }
            $lastError = (string) ($legacy['error'] ?? $lastError);
        }
        return ['success' => false, 'error' => $lastError !== '' ? $lastError : 'set_password failed'];
    }

    /** @return array{success: bool, error?: string} */
    private function ensureMysqlAccess(string $dbName, string $dbUser, string $password): array
    {
        $test = $this->testMysqlConnection($dbName, $dbUser, $password);
        if ($test['success']) {
            return $test;
        }

        $sync = $this->syncMysqlPassword($dbUser, $password);
        if (!$sync['success']) {
            return ['success' => false, 'error' => 'MySQL set_password: ' . ($sync['error'] ?? 'failed')];
        }

        usleep(300000);
        $test = $this->testMysqlConnection($dbName, $dbUser, $password);
        if ($test['success']) {
            return $test;
        }

        return [
            'success' => false,
            'error' => 'Child DB connection failed: ' . ($test['error'] ?? 'access denied after password sync'),
        ];
    }

    /** @return array{success: bool, error?: string} */
    private function testMysqlConnection(string $dbName, string $dbUser, string $password): array
    {
        try {
            $pdo = new PDO(
                'mysql:host=localhost;dbname=' . $dbName . ';charset=utf8mb4',
                $dbUser,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query('SELECT 1');
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function cpanelPrefixedName(string $name): string
    {
        $prefix = $this->cpanelUser() . '_';
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?: 'cpdb';
        if (str_starts_with($name, $prefix)) {
            return $name;
        }
        return $prefix . $name;
    }

    private function cpanelUnprefixedName(string $name): string
    {
        $prefix = $this->cpanelUser() . '_';
        if (str_starts_with($name, $prefix)) {
            return substr($name, strlen($prefix));
        }
        return $name;
    }

    /** @return array{success: bool, data?: mixed, error?: string, http_code?: int} */
    private function mysqlUapi(string $func, array $params): array
    {
        $res = $this->whmCpanelCall('Mysql', $func, $params, 3);
        if ($res['success'] || !$this->shouldTryMysqlFeFallback($res['error'] ?? '', $func)) {
            return $res;
        }
        return $this->mysqlFeLegacy($func, $params);
    }

    private function shouldTryMysqlFeFallback(string $error, string $func = ''): bool
    {
        $error = strtolower($error);
        if (str_contains($error, 'could not find function')
            || str_contains($error, 'unknown module')) {
            return true;
        }
        return $func === 'set_password' && $error !== '';
    }

    /** @return array{success: bool, data?: mixed, error?: string, http_code?: int} */
    private function mysqlFeLegacy(string $func, array $params): array
    {
        $map = [
            'create_database' => ['func' => 'createdb', 'params' => ['db' => $this->cpanelUnprefixedName($params['name'] ?? '')]],
            'create_user' => ['func' => 'createdbuser', 'params' => [
                'dbuser' => $this->cpanelUnprefixedName($params['name'] ?? ''),
                'password' => $params['password'] ?? '',
            ]],
            'set_password' => ['func' => 'passwduser', 'params' => [
                'dbuser' => $this->cpanelUnprefixedName($params['name'] ?? ''),
                'password' => $params['password'] ?? '',
            ]],
            'set_privileges_on_database' => ['func' => 'setdbuserprivileges', 'params' => [
                'privileges' => $params['privileges'] ?? 'ALL',
                'dbuser' => $this->cpanelUnprefixedName($params['user'] ?? ''),
                'database' => $this->cpanelUnprefixedName($params['database'] ?? ''),
            ]],
            'set_all_privileges_on_database' => ['func' => 'setdbuserprivileges', 'params' => [
                'privileges' => 'ALL',
                'dbuser' => $this->cpanelUnprefixedName($params['user'] ?? ''),
                'database' => $this->cpanelUnprefixedName($params['database'] ?? ''),
            ]],
        ];
        $entry = $map[$func] ?? null;
        if ($entry === null) {
            return ['success' => false, 'error' => 'No legacy fallback for ' . $func];
        }
        return $this->whmCpanelCall('MysqlFE', $entry['func'], $entry['params'], 2);
    }

    private function isAlreadyExistsError(string $error): bool
    {
        $error = strtolower($error);
        return str_contains($error, 'already')
            || str_contains($error, 'exists')
            || str_contains($error, 'duplicate');
    }

    /** Request AutoSSL for addon domain (best-effort). */
    public function requestAutoSsl(string $domain): void
    {
        $this->whmCpanelCall('SSL', 'installssl', [], 3);
        $this->whmCpanelCall('AutoSSL', 'enable_autossl_for_domains', [
            'domains' => $domain,
        ], 3);
    }
}
