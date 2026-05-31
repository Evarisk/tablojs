<?php
// Définir les fonctions de compatibilité PHP 8.0 pour PHP 7.4
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool {
        return strncmp($h, $n, strlen($n)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool {
        return $n === '' || substr($h, -strlen($n)) === $n;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool {
        return $n === '' || strpos($h, $n) !== false;
    }
}
/**
 * tablojs — Guard d'authentification v2
 * Inclure en tête de chaque fichier PHP protégé :
 *   require_once __DIR__ . '/auth.php';
 */

require_once __DIR__ . '/config/auth.php';

/* ── Chargement des settings dynamiques ────────────────────────────── */
function loadSettings(): array {
    $f = __DIR__ . '/config/settings.json';
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}

function saveSettings(array $s): bool {
    $f = __DIR__ . '/config/settings.json';
    return (bool) file_put_contents($f,
        json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/* ── Logging ────────────────────────────────────────────────────────── */
function logAuth(string $event, array $extra = []): void {
    $settings = loadSettings();
    if (empty($settings['log_enabled'])) return;

    $ip         = getClientIp();
    $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
    $ts         = date('Y-m-d H:i:s');
    $userLogin  = $extra['user'] ?? $extra['user_try'] ?? null;
    $details    = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

    // 1. Écriture fichier log (fallback)
    $logFile = __DIR__ . '/logs/auth.log';
    $entry   = array_merge(['ts' => $ts, 'event' => $event, 'ip' => $ip, 'ua' => $ua], $extra);
    file_put_contents($logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );

    // 2. Insertion en base de données
    try {
        $confFile = __DIR__ . '/conf/conf.php';
        if (!file_exists($confFile)) return;

        // require_once saute le fichier s'il est déjà chargé (cas de login.php)
        // On isole l'inclusion dans un scope dédié pour récupérer les variables proprement
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
        $stmt = $pdo->prepare(
            "INSERT INTO tablojs_auth_log (ts, event, ip, user_agent, user_login, details)
             VALUES (:ts, :event, :ip, :ua, :user_login, :details)"
        );
        $stmt->execute([
            ':ts'         => $ts,
            ':event'      => $event,
            ':ip'         => $ip,
            ':ua'         => $ua,
            ':user_login' => $userLogin,
            ':details'    => $details,
        ]);
    } catch (Exception $e) {
        // Silencieux : le fichier log reste la source de vérité en cas d'erreur DB
        file_put_contents($logFile,
            json_encode(['ts' => $ts, 'event' => 'db_log_error', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

/* ── IP du client (proxy-aware sécurisé) ─────────────────────────────── */
/**
 * Retourne l'IP réelle du client.
 *
 * Ne lit les headers de proxy (X-Forwarded-For, X-Real-IP, CF-Connecting-IP)
 * que si REMOTE_ADDR appartient à la liste `trusted_proxies` dans settings.json.
 * Sans cette liste, REMOTE_ADDR est toujours utilisé — ce qui empêche un
 * attaquant de forger son IP via un header HTTP pour bypasser la whitelist.
 *
 * @return string IPv4 ou IPv6 validé par filter_var, ou '0.0.0.0'
 */
function getClientIp(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Charger la liste des proxies de confiance depuis settings.json
    $settings       = loadSettings();
    $trustedProxies = $settings['trusted_proxies'] ?? [];

    // Si REMOTE_ADDR n'est pas un proxy de confiance, retourner directement
    if (empty($trustedProxies) || !in_array($remoteAddr, $trustedProxies, true)) {
        $ip = filter_var($remoteAddr, FILTER_VALIDATE_IP);
        return $ip !== false ? $ip : '0.0.0.0';
    }

    // REMOTE_ADDR est un proxy de confiance : lire les headers dans l'ordre de priorité
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
        if (!empty($_SERVER[$k])) {
            // X-Forwarded-For peut contenir une chaîne : "client, proxy1, proxy2"
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }

    return $remoteAddr;
}

/* ── Vérification whitelist IP ──────────────────────────────────────── */
function checkIpAllowed(): bool {
    $settings = loadSettings();
    $allowed  = $settings['allowed_ips'] ?? [];
    if (empty($allowed)) return true; // liste vide = tous autorisés

    $ip = getClientIp();
    foreach ($allowed as $range) {
        $range = trim($range);
        if ($range === $ip) return true;
        // Support CIDR notation (ex: 192.168.1.0/24)
        if (strpos($range, '/') !== false) {
            [$subnet, $bits] = explode('/', $range);
            $mask = -1 << (32 - (int)$bits);
            if ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask)) return true;
        }
        // Support wildcard (ex: 192.168.*)
        if (strpos($range, '*') !== false) {
            $pattern = str_replace(['.',  '*'], ['\.', '.*'], $range);
            if (preg_match('/^' . $pattern . '$/', $ip)) return true;
        }
    }
    return false;
}

/* ── Session ────────────────────────────────────────────────────────── */
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ── Vérification de session ────────────────────────────────────────── */
function isAuthenticated(): bool {
    if (empty($_SESSION['auth_user']) || empty($_SESSION['auth_time'])) return false;

    $settings = loadSettings();
    $duration = $settings['session_duration'] ?? SESSION_DURATION;

    if (time() - $_SESSION['auth_time'] > $duration) {
        logAuth('session_expired', ['user' => $_SESSION['auth_user']]);
        session_destroy();
        return false;
    }
    $_SESSION['auth_time'] = time();
    return true;
}

/* ── Guard principal ────────────────────────────────────────────────── */
function requireAuth(bool $jsonMode = false): void {
    // 1. Vérifier whitelist IP
    if (!checkIpAllowed()) {
        logAuth('ip_blocked');
        if ($jsonMode) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Accès refusé (IP non autorisée)']);
            exit;
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Accès refusé</title>'
           . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;'
           . 'min-height:100vh;margin:0;background:#0b0e1a;color:#e2e8f0;}</style></head>'
           . '<body><div style="text-align:center"><h1 style="color:#ef4444">⛔ Accès refusé</h1>'
           . '<p>Votre adresse IP n\'est pas autorisée.<br><small>' . htmlspecialchars(getClientIp()) . '</small></p>'
           . '</div></body></html>';
        exit;
    }

    // 2. Vérifier la session
    if (!isAuthenticated()) {
        if ($jsonMode) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Non authentifié', 'redirect' => 'login.php']);
            exit;
        }
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        // Déterminer le chemin relatif vers la racine
        $relativeRoot = '';
        $appDir = realpath(__DIR__);
        $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
        if ($appDir !== false && $scriptDir !== false && str_starts_with($scriptDir, $appDir)) {
            $diff = substr($scriptDir, strlen($appDir));
            $levels = substr_count($diff, DIRECTORY_SEPARATOR);
            $relativeRoot = str_repeat('../', $levels);
        }
        header('Location: ' . $relativeRoot . 'login.php?next=' . $redirect);
        exit;
    }
}

/* ── CSRF ───────────────────────────────────────────────────────────── */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/* ── Chiffrement / Déchiffrement des fichiers ───────────────────────── */

/**
 * Récupère la clé de chiffrement configurée dans conf/conf.php de manière isolée.
 *
 * @return string|null Clé de chiffrement ou null si non disponible
 */
function getEncryptionKey(): ?string {
    $confFile = __DIR__ . '/conf/conf.php';
    if (!file_exists($confFile)) {
        return null;
    }
    // Utilisation d'un IIFE pour ne pas polluer l'espace de noms global ou local
    return (static function($f) {
        require $f;
        return $tablojs_upload_encrypt_key ?? null;
    })($confFile);
}

/**
 * Chiffre une chaîne de caractères à l'aide de la clé configurée.
 *
 * Utilise l'algorithme AES-256-CBC avec un IV aléatoire de 16 octets.
 * Ajoute un préfixe de détection "TABLOJS_ENC:" pour une compatibilité
 * transparente avec les fichiers non chiffrés.
 *
 * @param string $plainData Données en clair
 * @return string Données chiffrées avec préfixe et IV
 */
function encryptData(string $plainData): string {
    $key = getEncryptionKey();
    if (empty($key)) {
        return $plainData;
    }
    $iv = openssl_random_pseudo_bytes(16);
    $binKey = hash('sha256', $key, true);
    $ciphertext = openssl_encrypt($plainData, 'aes-256-cbc', $binKey, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return $plainData;
    }
    return 'TABLOJS_ENC:' . $iv . $ciphertext;
}

/**
 * Déchiffre une chaîne de caractères à l'aide de la clé configurée si elle est chiffrée.
 *
 * Détecte la présence du préfixe "TABLOJS_ENC:" pour déchiffrer. Si le préfixe
 * est absent ou si le déchiffrement échoue, retourne les données brutes.
 *
 * @param string $data Données potentiellement chiffrées
 * @return string Données déchiffrées ou d'origine
 */
function decryptData(string $data): string {
    if (!str_starts_with($data, 'TABLOJS_ENC:')) {
        return $data;
    }
    $key = getEncryptionKey();
    if (empty($key)) {
        return $data;
    }
    $iv = substr($data, 12, 16);
    $ciphertext = substr($data, 28);
    $binKey = hash('sha256', $key, true);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $binKey, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : $data;
}

/**
 * Chiffre un fichier sur le disque si la clé est configurée.
 *
 * @param string $filePath Chemin du fichier
 * @return bool True en cas de succès, false sinon
 */
function encryptFile(string $filePath): bool {
    if (!file_exists($filePath)) {
        return false;
    }
    $content = file_get_contents($filePath);
    if ($content === false) {
        return false;
    }
    // Évite le double chiffrement
    if (str_starts_with($content, 'TABLOJS_ENC:')) {
        return true;
    }
    $encrypted = encryptData($content);
    return file_put_contents($filePath, $encrypted) !== false;
}

/**
 * Déchiffre un fichier sur le disque.
 *
 * @param string $filePath Chemin du fichier
 * @return string|false Contenu déchiffré ou false en cas d'erreur
 */
function decryptFileContent(string $filePath) {
    if (!file_exists($filePath)) {
        return false;
    }
    $content = file_get_contents($filePath);
    if ($content === false) {
        return false;
    }
    return decryptData($content);
}
