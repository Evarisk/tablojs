<?php
/**
 * tablojs — API Informations système
 *
 * GET ?section=server  → infos serveur web (Apache, PHP SAPI, OS…)
 * GET ?section=php     → extensions PHP, directives ini, version
 * GET ?section=db      → version MariaDB, variables, statut
 * GET ?section=tables  → liste des tables tablojs_ avec taille et row count
 * GET ?section=all     → toutes les sections en une seule réponse
 */
require_once __DIR__ . '/../auth.php';
requireAuth(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$section = $_GET['section'] ?? 'all';

/* ── Connexion DB (pattern isolé) ───────────────────────────────────── */
$confFile = __DIR__ . '/../conf/conf.php';
$pdo = null;
if (file_exists($confFile)) {
    try {
        $dbConf = (static function($f) {
            require $f;
            return compact(
                'tablojs_db_host', 'tablojs_db_port',
                'tablojs_db_name', 'tablojs_db_user', 'tablojs_db_pass'
            );
        })($confFile);

        $dsn = "mysql:host={$dbConf['tablojs_db_host']};port={$dbConf['tablojs_db_port']};dbname={$dbConf['tablojs_db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConf['tablojs_db_user'], $dbConf['tablojs_db_pass'], [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT    => 3,
        ]);
    } catch (Exception $e) {
        $pdo = null;
        $dbError = $e->getMessage();
    }
}

/* ── Section : serveur web ──────────────────────────────────────────── */
function getServerInfo(): array {
    // Chemin absolu du répertoire data/ de l'application
    $dataDir = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');

    // Utilisateur/groupe réel du processus serveur
    // posix_* disponible Linux/macOS ; sur Windows on tente exec('id')
    $userGroup = '—';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $uid      = posix_geteuid();
        $gid      = posix_getegid();
        $user     = posix_getpwuid($uid);
        $grp      = posix_getgrgid($gid);
        $userGroup = ($user['name'] ?? $uid) . '(' . $uid . ') / ' . ($grp['name'] ?? $gid) . '(' . $gid . ')';
    } else {
        $output = [];
        $ret    = -1;
        @exec('id 2>&1', $output, $ret);
        if ($ret === 0 && !empty($output[0])) {
            $userGroup = trim($output[0]);
        } else {
            $userGroup = 'Impossible d\'exécuter la commande "id"';
        }
    }

    return [
        'version'         => $_SERVER['SERVER_SOFTWARE'] ?? '—',
        'server_name'     => $_SERVER['SERVER_NAME']     ?? '—',
        'server_addr'     => $_SERVER['SERVER_ADDR']     ?? '—',
        'server_port'     => $_SERVER['SERVER_PORT']     ?? '—',
        'document_root'   => $_SERVER['DOCUMENT_ROOT']  ?? '—',
        'data_dir'        => $dataDir,
        'user_group'      => $userGroup,
        'php_sapi'        => PHP_SAPI,
        'php_version'     => PHP_VERSION,
        'zend_version'    => zend_version(),
        'os'              => PHP_OS_FAMILY . ' — ' . PHP_OS,
        'hostname'        => gethostname() ?: '—',
        'https'           => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Oui' : 'Non',
        'request_scheme'  => $_SERVER['REQUEST_SCHEME'] ?? 'http',
        'timezone'        => date_default_timezone_get(),
        'date_now'        => date('Y-m-d H:i:s'),
        'max_execution'   => ini_get('max_execution_time') . 's',
        'memory_limit'    => ini_get('memory_limit'),
        'upload_max'      => ini_get('upload_max_filesize'),
        'post_max'        => ini_get('post_max_size'),
    ];
}


/* ── Section : PHP ──────────────────────────────────────────────────── */
function getPhpInfo(): array {
    $extensions = get_loaded_extensions();
    sort($extensions);

    // Lignes de support — style phpinfo() natif
    $yes = static fn(string $msg): array => ['ok' => true,  'msg' => $msg];
    $no  = static fn(string $msg): array => ['ok' => false, 'msg' => $msg];

    $supports = [
        'get_post'    => $yes('Ce PHP prend en charge les variables POST et GET.'),
        'sessions'    => extension_loaded('session')
                            ? $yes('Ce PHP prend en charge les sessions.')
                            : $no('Le support des sessions n\'est pas disponible.'),
        'utf8'        => extension_loaded('mbstring')
                            ? $yes('Ce PHP prend en charge les fonctions UTF-8 (mbstring).')
                            : $no('L\'extension mbstring (UTF-8) n\'est pas chargée.'),
        'pdo_mysql'   => extension_loaded('pdo_mysql')
                            ? $yes('Ce PHP prend en charge PDO MySQL / MariaDB.')
                            : $no('PDO MySQL n\'est pas disponible.'),
        'json'        => extension_loaded('json')
                            ? $yes('Ce PHP prend en charge le format JSON.')
                            : $no('L\'extension JSON n\'est pas disponible.'),
        'file_upload' => (bool) ini_get('file_uploads')
                            ? $yes('Ce PHP prend en charge l\'upload de fichiers.')
                            : $no('L\'upload de fichiers est désactivé (file_uploads=Off).'),
        'openssl'     => extension_loaded('openssl')
                            ? $yes('Ce PHP prend en charge le chiffrement OpenSSL.')
                            : $no('L\'extension OpenSSL n\'est pas chargée.'),
        'gd'          => extension_loaded('gd')
                            ? $yes('Ce PHP prend en charge les images (GD).')
                            : $no('L\'extension GD (images) n\'est pas chargée.'),
        'fileinfo'    => extension_loaded('fileinfo')
                            ? $yes('Ce PHP prend en charge la détection de type MIME (fileinfo).')
                            : $no('L\'extension fileinfo n\'est pas chargée.'),
        'zip'         => extension_loaded('zip')
                            ? $yes('Ce PHP prend en charge les archives ZIP.')
                            : $no('L\'extension ZIP n\'est pas chargée.'),
        'opcache'     => (bool) ini_get('opcache.enable')
                            ? $yes('OPcache est activé (accélération bytecode).')
                            : $no('OPcache est désactivé.'),
    ];

    // Extensions clés pour tablojs
    $keyExt = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'session', 'fileinfo', 'zip', 'gd'];
    $extStatus = [];
    foreach ($keyExt as $ext) {
        $extStatus[$ext] = extension_loaded($ext);
    }

    $directives = [
        'display_errors'        => ini_get('display_errors') ? 'On' : 'Off',
        'error_reporting'       => ini_get('error_reporting'),
        'log_errors'            => ini_get('log_errors') ? 'On' : 'Off',
        'error_log'             => ini_get('error_log') ?: '(défaut système)',
        'session.save_handler'  => ini_get('session.save_handler'),
        'session.gc_maxlifetime'=> ini_get('session.gc_maxlifetime') . 's',
        'file_uploads'          => ini_get('file_uploads') ? 'On' : 'Off',
        'allow_url_fopen'       => ini_get('allow_url_fopen') ? 'On' : 'Off',
        'opcache.enable'        => ini_get('opcache.enable') ? 'On' : 'Off',
        'short_open_tag'        => ini_get('short_open_tag') ? 'On' : 'Off',
    ];

    return [
        'version'     => PHP_VERSION,
        'version_id'  => PHP_VERSION_ID,
        'sapi'        => PHP_SAPI,
        'int_size'    => PHP_INT_SIZE * 8 . ' bits',
        'extensions'  => $extensions,
        'ext_count'   => count($extensions),
        'key_ext'     => $extStatus,
        'supports'    => $supports,
        'directives'  => $directives,
        'include_path'=> ini_get('include_path'),
    ];
}


/* ── Section : base de données ──────────────────────────────────────── */
function getDbInfo(?PDO $pdo, array $dbConf = [], ?string $dbError = null): array {
    if (!$pdo) {
        return ['connected' => false, 'error' => $dbError ?? 'Connexion non disponible'];
    }

    try {
        $version   = $pdo->query('SELECT VERSION()')->fetchColumn();
        $charset   = $pdo->query('SELECT @@character_set_database')->fetchColumn();
        $collation = $pdo->query('SELECT @@collation_database')->fetchColumn();
        $dbName    = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $uptime    = $pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? '—';
        $queries   = $pdo->query("SHOW STATUS LIKE 'Queries'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? '—';
        $threads   = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_ASSOC)['Value'] ?? '—';

        // Convertir uptime en lisible
        $uptimeSec = (int)$uptime;
        $uptimeFmt = sprintf('%dj %dh %dmin',
            floor($uptimeSec / 86400),
            floor(($uptimeSec % 86400) / 3600),
            floor(($uptimeSec % 3600) / 60)
        );

        // Variables utiles
        $vars = [];
        $rows = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('max_connections','wait_timeout','max_allowed_packet','innodb_buffer_pool_size','sql_mode')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) {
            if ($k === 'innodb_buffer_pool_size') {
                $v = round((int)$v / 1024 / 1024) . ' Mo';
            } elseif ($k === 'max_allowed_packet') {
                $v = round((int)$v / 1024 / 1024) . ' Mo';
            }
            $vars[$k] = $v;
        }

        return [
            'connected'   => true,
            'version'     => $version,
            'database'    => $dbName,
            'host'        => $dbConf['tablojs_db_host'] ?? '—',
            'port'        => $dbConf['tablojs_db_port'] ?? '—',
            'user'        => $dbConf['tablojs_db_user'] ?? '—',
            'charset'     => $charset,
            'collation'   => $collation,
            'uptime'      => $uptimeFmt,
            'queries'     => number_format((int)$queries, 0, ',', ' '),
            'threads'     => $threads,
            'variables'   => $vars,
        ];
    } catch (Exception $e) {
        return ['connected' => false, 'error' => $e->getMessage()];
    }
}

/* ── Section : tables ───────────────────────────────────────────────── */
function getTablesInfo(?PDO $pdo): array {
    if (!$pdo) {
        return ['connected' => false, 'tables' => []];
    }

    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                TABLE_NAME              AS name,
                TABLE_ROWS              AS row_count,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 1) AS size_ko,
                ROUND(DATA_LENGTH / 1024, 1)                  AS data_ko,
                ROUND(INDEX_LENGTH / 1024, 1)                 AS index_ko,
                ENGINE,
                TABLE_COLLATION         AS collation,
                CREATE_TIME             AS created_at,
                UPDATE_TIME             AS updated_at,
                TABLE_COMMENT           AS comment
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME LIKE 'tablojs_%'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$dbName]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer le nombre de colonnes pour chaque table
        foreach ($tables as &$table) {
            $cols = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ");
            $cols->execute([$dbName, $table['name']]);
            $table['col_count'] = (int)$cols->fetchColumn();

            // Obtenir le vrai count (TABLE_ROWS est approximatif pour InnoDB)
            try {
                $cnt = $pdo->query("SELECT COUNT(*) FROM `{$table['name']}`")->fetchColumn();
                $table['row_count'] = (int)$cnt;
            } catch (Exception $e) {
                // Garder la valeur approximative si accès refusé
            }

            $table['row_count'] = (int)$table['row_count'];
            $table['size_ko']   = (float)$table['size_ko'];
            $table['data_ko']   = (float)$table['data_ko'];
            $table['index_ko']  = (float)$table['index_ko'];
        }
        unset($table);

        $totalRows = array_sum(array_column($tables, 'row_count'));
        $totalKo   = array_sum(array_column($tables, 'size_ko'));

        return [
            'connected'  => true,
            'database'   => $dbName,
            'tables'     => $tables,
            'total'      => count($tables),
            'total_rows' => $totalRows,
            'total_ko'   => $totalKo,
        ];
    } catch (Exception $e) {
        return ['connected' => false, 'tables' => [], 'error' => $e->getMessage()];
    }
}

/* ── Dispatcher ─────────────────────────────────────────────────────── */
$dbConf  = [];
$dbError = null;

// Charger $dbConf pour getDbInfo si disponible
if (file_exists($confFile)) {
    try {
        $dbConf = (static function($f) {
            require $f;
            return compact(
                'tablojs_db_host', 'tablojs_db_port',
                'tablojs_db_name', 'tablojs_db_user', 'tablojs_db_pass'
            );
        })($confFile);
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

$result = ['ok' => true];

if ($section === 'server' || $section === 'all') {
    $result['server'] = getServerInfo();
}
if ($section === 'php' || $section === 'all') {
    $result['php'] = getPhpInfo();
}
if ($section === 'db' || $section === 'all') {
    $result['db'] = getDbInfo($pdo, $dbConf, $dbError ?? null);
}
if ($section === 'tables' || $section === 'all') {
    $result['tables'] = getTablesInfo($pdo);
}

if (!in_array($section, ['server', 'php', 'db', 'tables', 'all'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Section inconnue : {$section}"]);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
