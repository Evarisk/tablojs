<?php
/**
 * tablojs — Wizard d'installation v1.0
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * Étapes :
 *  1 — Vérification des prérequis
 *  2 — Configuration base de données
 *  3 — Test de connexion + Création BDD + Tables
 *  4 — Configuration application + mot de passe admin
 *  5 — Migration des données existantes (JSON → BDD)
 *  6 — Succès + Redirection vers le login
 */

define('TABLOJS_ROOT',  dirname(__DIR__));
define('INSTALL_DIR',   __DIR__);
define('MARIADB_DIR',   __DIR__ . '/mariadb');
define('CONF_DIR',      TABLOJS_ROOT . '/conf');
define('CONF_FILE',     CONF_DIR . '/conf.php');
define('LOCK_FILE',     INSTALL_DIR . '/install.lock');
define('AUTH_CONF',     TABLOJS_ROOT . '/config/auth.php');
define('APP_VERSION',   '1.0.0');

session_name('tablojs_install');
session_start();

// ── Protection : déjà installé ? ────────────────────────────────────────────
if (file_exists(LOCK_FILE) && ($_GET['step'] ?? '') !== 'done') {
    if (($_GET['force'] ?? '') !== '1') {
        // Vérifier si l'installation est réellement complète (tables présentes)
        $installOk = false;
        try {
            if (file_exists(CONF_FILE)) {
                require_once CONF_FILE;
                $chkDsn = "mysql:host={$tablojs_db_host};port={$tablojs_db_port};dbname={$tablojs_db_name};charset=utf8mb4";
                $chkPdo = new PDO($chkDsn, $tablojs_db_user, $tablojs_db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tableCount = $chkPdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
                $installOk = ($tableCount >= 6);
            }
        } catch (Exception $e) {
            $installOk = false;
        }

        if ($installOk) {
            // Redirection relative vers la page de connexion
            header('Location: ../login.php');
            exit;
        }

        // Installation incomplète : supprimer le lock et reprendre
        @unlink(LOCK_FILE);
        // Vider la session pour repartir proprement
        session_destroy();
        session_name('tablojs_install');
        session_start();
        $_SESSION['install_error'] = 'Installation précédente incomplète : la base de données n\'a pas été créée correctement. Veuillez recommencer la configuration.';
        header('Location: ?step=2');
        exit;
    }
}

$step    = (int)($_GET['step'] ?? 1);
$error   = '';
$success = '';

// ── Lire conf.php existant pour pré-remplir les champs ──────────────────────
$existingConf = [];
if (file_exists(CONF_FILE)) {
    $tmp = [];
    include CONF_FILE; // charge $tablojs_db_* dans le scope local
    $existingConf = [
        'host'   => $tablojs_db_host   ?? 'localhost',
        'port'   => $tablojs_db_port   ?? '3306',
        'dbname' => $tablojs_db_name   ?? 'tablojs',
        'user'   => $tablojs_db_user   ?? 'tablojs_user',
        'pass'   => $tablojs_db_pass   ?? '',
    ];
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS POST
// ════════════════════════════════════════════════════════════════════════════

// ── Endpoint AJAX : test de connexion admin ─────────────────────────────────
if (($_GET['action'] ?? '') === 'test_connection') {
    header('Content-Type: application/json');
    $host     = trim($_POST['db_host']     ?? 'localhost');
    $port     = trim($_POST['db_port']     ?? '3306');
    $root     = trim($_POST['db_root']     ?? 'root');
    $rootpass = $_POST['db_rootpass']      ?? '';
    $dbname   = trim($_POST['db_name']      ?? '');

    try {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($dbname !== '') {
            $dsn .= ";dbname={$dbname}";
        }
        $pdo = new PDO($dsn, $root, $rootpass, [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT    => 5,
        ]);
        $row = $pdo->query('SELECT VERSION() AS v')->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'version' => $row['v'] ?? '?']);
    } catch (PDOException $e) {
        $errorCode = $e->errorInfo[1] ?? 0;
        $msg = $e->getMessage();
        // Traduction conviviale en français selon le code d'erreur MariaDB
        if ($errorCode === 1045) {
            $msg = "Identifiant ou mot de passe MariaDB incorrect (Accès refusé).";
        } elseif ($errorCode === 1049) {
            $msg = "La base de données spécifiée n'existe pas ou l'utilisateur n'y a pas accès.";
        } elseif ($errorCode === 2002) {
            $msg = "Hôte ou port MariaDB incorrect (Impossible de se connecter au serveur).";
        } else {
            // Masquer le mot de passe s'il apparaît dans le message brut
            $msg = preg_replace('/using password: \w+/', 'using password: ***', $msg);
        }
        echo json_encode(['ok' => false, 'error' => $msg]);
    }
    exit;
}

// ── Endpoint AJAX : scanner les installations tabloJS existantes ───────────
if (($_GET['action'] ?? '') === 'detect_installs') {
    header('Content-Type: application/json');
    $host     = trim($_POST['db_host']     ?? 'localhost');
    $port     = trim($_POST['db_port']     ?? '3306');
    $root     = trim($_POST['db_root']     ?? 'root');
    $rootpass = $_POST['db_rootpass']      ?? '';
    $dbname   = trim($_POST['db_name']      ?? '');

    try {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($dbname !== '') {
            $dsn .= ";dbname={$dbname}";
        }
        $pdo = new PDO($dsn, $root, $rootpass, [
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT    => 5,
        ]);

        $instances = [];
        $totalBds  = 0;

        // Lister les bases de données accessibles
        $dbList = [];
        if ($dbname !== '') {
            $dbList[] = $dbname;
        } else {
            $stmt = $pdo->query('SHOW DATABASES');
            while ($db = $stmt->fetchColumn()) {
                if (in_array(strtolower($db), ['information_schema', 'mysql', 'performance_schema', 'sys'])) {
                    continue;
                }
                $dbList[] = $db;
            }
        }

        $totalBds = count($dbList);

        // Pour chaque base, vérifier si c'est une instance tabloJS
        foreach ($dbList as $db) {
            try {
                $pdo->exec("USE `" . str_replace("`", "``", $db) . "`");
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'tablojs_settings'")->fetchColumn();
                if ($tableCheck) {
                    $tableCount = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$db'")->fetchColumn();
                    $company = '';
                    $tagline = '';
                    try {
                        $sStmt = $pdo->query("SELECT setting_key, value FROM tablojs_settings WHERE setting_key IN ('company_name', 'company_tagline')");
                        while ($row = $sStmt->fetch(PDO::FETCH_ASSOC)) {
                            if ($row['setting_key'] === 'company_name') $company = $row['value'];
                            if ($row['setting_key'] === 'company_tagline') $tagline = $row['value'];
                        }
                    } catch (Exception $eSettings) {
                        // Pas critique, ignorer
                    }

                    $users = [$root];
                    $instances[] = [
                        'dbname'  => $db,
                        'tables'  => $tableCount,
                        'company' => $company,
                        'tagline' => $tagline,
                        'users'   => $users,
                    ];
                }
            } catch (Exception $eDb) {
                // Ignorer les bases inaccessibles
            }
        }

        echo json_encode([
            'ok'        => true,
            'instances' => $instances,
            'total'     => $totalBds,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Étape 2 → 3 : Enregistrer les paramètres BDD en session ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $_SESSION['install_db'] = [
        'host'    => trim($_POST['db_host']    ?? 'localhost'),
        'port'    => trim($_POST['db_port']    ?? '3306'),
        'dbname'  => trim($_POST['db_name']    ?? 'tablojs'),
        'user'    => trim($_POST['db_user']    ?? 'tablojs_user'),
        'pass'    => $_POST['db_pass']         ?? '',
        'root'    => trim($_POST['db_root']    ?? 'root'),
        'rootpass'=> $_POST['db_rootpass']     ?? '',
        'create_user' => isset($_POST['create_user']),
    ];
    header('Location: ?step=3');
    exit;
}

// ── Étape 3 : Créer BDD + tables ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $db  = $_SESSION['install_db'] ?? [];
    $log = [];

    try {
        // Connexion root
        $rootDsn = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
        $rootPdo = new PDO($rootDsn, $db['root'], $db['rootpass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $log[] = ['ok', "Connexion administrateur MariaDB réussie ({$db['root']}@{$db['host']})"];

        // Créer la base (IF NOT EXISTS = safe si elle existe déjà)
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $log[] = ['ok', "Base de données <strong>{$db['dbname']}</strong> créée / vérifiée"];

        // Sélectionner la base (root crée toujours les tables)
        $rootPdo->exec("USE `{$db['dbname']}`");

        // Gérer l'utilisateur applicatif
        if ($db['create_user']) {
            $rootPdo->exec("CREATE USER IF NOT EXISTS '{$db['user']}'@'localhost' IDENTIFIED BY " . $rootPdo->quote($db['pass']));
            $rootPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES ON `{$db['dbname']}`.* TO '{$db['user']}'@'localhost'");
            $rootPdo->exec("FLUSH PRIVILEGES");
            $log[] = ['ok', "Utilisateur <strong>{$db['user']}</strong> créé avec droits limités"];
        } else {
            // Utilisateur existant : synchroniser le mot de passe et les droits via root
            try {
                $rootPdo->exec("ALTER USER '{$db['user']}'@'localhost' IDENTIFIED BY " . $rootPdo->quote($db['pass']));
                $rootPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES ON `{$db['dbname']}`.* TO '{$db['user']}'@'localhost'");
                $rootPdo->exec("FLUSH PRIVILEGES");
                $log[] = ['ok', "Utilisateur <strong>{$db['user']}</strong> existant — droits et mot de passe synchronisés"];
            } catch (PDOException $eUser) {
                $log[] = ['warn', "Note : impossible de synchroniser '{$db['user']}' via root — " . htmlspecialchars($eUser->getMessage())];
            }
        }

        // Créer les tables dans l'ordre (dépendances FK) — toujours avec root
        $tableFiles = [
            'tablojs_settings.sql',
            'tablojs_auth_log.sql',
            'tablojs_dataset.sql',
            'tablojs_product_category.sql',
            'tablojs_unit.sql',
            'tablojs_product.sql',
            'tablojs_product_stat.sql',
            'tablojs_monthly_vente.sql',
            'tablojs_indexes.key.sql',
        ];

        foreach ($tableFiles as $file) {
            $path = MARIADB_DIR . '/tables/' . $file;
            if (!file_exists($path)) {
                $log[] = ['warn', "Fichier absent : {$file}"];
                continue;
            }
            executeSqlFile($rootPdo, $path, $log, $file);
        }

        // Données initiales
        $dataFiles = glob(MARIADB_DIR . '/data/*.sql');
        foreach ($dataFiles as $path) {
            executeSqlFile($rootPdo, $path, $log, basename($path));
        }

        $log[] = ['ok', 'Structure de la base de données installée avec succès'];
        $_SESSION['install_log_step3'] = $log;
        $_SESSION['install_step3_ok']  = true;
        header('Location: ?step=4');
        exit;

    } catch (PDOException $e) {
        $errorCode = $e->errorInfo[1] ?? 0;
        $msg = $e->getMessage();
        // Traduction conviviale en français selon le code d'erreur MariaDB
        if ($errorCode === 1045) {
            $msg = "Identifiant ou mot de passe MariaDB incorrect (Accès refusé).";
        } elseif ($errorCode === 1049) {
            $msg = "La base de données spécifiée n'existe pas ou l'utilisateur n'y a pas accès.";
        } elseif ($errorCode === 2002) {
            $msg = "Hôte ou port MariaDB incorrect (Impossible de se connecter au serveur).";
        } else {
            // Masquer le mot de passe s'il apparaît dans le message brut
            $msg = preg_replace('/using password: \w+/', 'using password: ***', $msg);
        }
        $log[] = ['err', 'Erreur MariaDB : ' . htmlspecialchars($msg)];
        $_SESSION['install_log_step3'] = $log;
        $_SESSION['install_step3_ok']  = false;
        // Rester sur step=3 avec les erreurs
        $step = 3;
    }
}

// ── Étape 4 : Config app + mot de passe admin ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4) {
    $db         = $_SESSION['install_db'] ?? [];
    $company    = trim($_POST['company_name']    ?? 'tablojs');
    $tagline    = trim($_POST['company_tagline'] ?? 'Gestion des stocks');
    $adminPass  = $_POST['admin_pass']           ?? '';
    $adminPass2 = $_POST['admin_pass2']          ?? '';
    $urlRoot    = rtrim(trim($_POST['url_root']  ?? 'http://localhost/tablojs'), '/');
    $encryptKey = $_POST['encrypt_key']          ?? bin2hex(random_bytes(24));

    if ($adminPass !== $adminPass2) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif (strlen($adminPass) < 8) {
        $error = 'Le mot de passe doit faire au minimum 8 caractères.';
    } else {
        // Écrire conf/conf.php
        $instanceId = bin2hex(random_bytes(16));
        $confContent = genConfPhp($db, $urlRoot, $encryptKey, $instanceId);

        if (!is_dir(CONF_DIR)) mkdir(CONF_DIR, 0755, true);
        file_put_contents(CONF_FILE, $confContent);

        // Mettre à jour le hash bcrypt dans config/auth.php
        $newHash = password_hash($adminPass, PASSWORD_BCRYPT);
        updateAuthHash($newHash, $adminPass);

        // Mettre à jour les settings en BDD
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare(
                'INSERT INTO tablojs_settings (setting_key, value)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
            $stmt->execute(['company_name',    $company]);
            $stmt->execute(['company_tagline', $tagline]);
        } catch (PDOException $e) {
            // Non bloquant — les settings sont aussi dans settings.json
        }

        // Mettre aussi à jour config/settings.json
        $settingsFile = TABLOJS_ROOT . '/config/settings.json';
        $settings = file_exists($settingsFile)
            ? (json_decode(file_get_contents($settingsFile), true) ?? [])
            : [];
        $settings['company_name']    = $company;
        $settings['company_tagline'] = $tagline;
        file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        header('Location: ?step=5');
        exit;
    }
}

// ── Étape 5 : Migration des données existantes ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 5) {
    $db  = $_SESSION['install_db'] ?? [];
    $log = [];

    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        // ── Vérification critique : les tables doivent exister avant de verrouiller ──
        $tableCount = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
        if ($tableCount < 6) {
            // Les tables ne sont pas là → renvoyer vers l'étape 3 avec erreur
            $_SESSION['install_step3_ok'] = false;
            $_SESSION['install_log_step3'] = [
                ['err', "Installation incomplète : seulement {$tableCount} table(s) trouvée(s) au lieu de 6 minimum."],
                ['err', "Les tables n'ont pas été créées lors de l'étape précédente. Relancez l'installation de la base de données."],
            ];
            header('Location: ?step=3');
            exit;
        }

        $migrated = migrateExistingData($pdo, $log);
        $log[] = ['ok', "Migration terminée — {$migrated} enregistrement(s) importé(s)"];

    } catch (PDOException $e) {
        $log[] = ['warn', 'Migration partielle : ' . htmlspecialchars($e->getMessage())];
    }

    // Créer le fichier de verrouillage SEULEMENT si tout est OK
    file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . ' — Installation tablojs ' . APP_VERSION . "\n");
    $log[] = ['ok', 'Fichier install.lock créé — installation verrouillée'];

    $_SESSION['install_log_step5'] = $log;
    header('Location: ?step=6');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES
// ════════════════════════════════════════════════════════════════════════════

function executeSqlFile(PDO $pdo, string $path, array &$log, string $label): void {
    $sql = file_get_contents($path);

    // Supprimer les commentaires ligne par ligne (-- ...) avant de splitter
    $lines = explode("\n", $sql);
    $cleaned = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--')) continue; // ignorer lignes de commentaire
        $cleaned[] = $line;
    }
    $sql = implode("\n", $cleaned);

    // Supprimer les blocs /* ... */
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Splitter sur ; et exécuter chaque statement non vide
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $ok = 0;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate key name')) {
                $ok++; // Déjà créé = OK lors d'une réinstallation
            } else {
                $log[] = ['warn', "{$label} : " . htmlspecialchars($msg)];
            }
        }
    }
    $log[] = ['ok', "{$label} — {$ok} instruction(s) exécutée(s)"];
}

function genConfPhp(array $db, string $urlRoot, string $encryptKey, string $instanceId): string {
    $pass = addslashes($db['pass']);
    $ts   = date('Y-m-d H:i:s');
    return <<<PHP
<?php
// tablojs — Configuration générée automatiquement le {$ts}
// NE PAS MODIFIER manuellement — utiliser le wizard d'installation.

\$tablojs_db_host    = '{$db['host']}';
\$tablojs_db_port    = '{$db['port']}';
\$tablojs_db_name    = '{$db['dbname']}';
\$tablojs_db_user    = '{$db['user']}';
\$tablojs_db_pass    = '{$pass}';
\$tablojs_db_charset = 'utf8mb4';

\$tablojs_upload_encrypt_key = '{$encryptKey}';
\$tablojs_prod_mode          = 1;
\$tablojs_url_root           = '{$urlRoot}';
\$tablojs_instance_id        = '{$instanceId}';
\$tablojs_version            = '1.0.0';
PHP;
}

function updateAuthHash(string $hash, string $plainPass): void {
    if (!file_exists(AUTH_CONF)) return;
    $content = file_get_contents(AUTH_CONF);
    // Remplacer le hash bcrypt
    $content = preg_replace(
        "/define\('AUTH_PASS_HASH',\s*'[^']+'\);/",
        "define('AUTH_PASS_HASH', '{$hash}');",
        $content
    );
    // Mettre à jour le commentaire du mot de passe par défaut
    $content = preg_replace(
        "/\/\/ Hash bcrypt du mot de passe par défaut : .*/",
        '// Hash bcrypt du mot de passe défini lors de l\'installation',
        $content
    );
    file_put_contents(AUTH_CONF, $content);
}

function migrateExistingData(PDO $pdo, array &$log): int {
    $total = 0;

    // 1. settings.json → tablojs_settings
    $settingsFile = TABLOJS_ROOT . '/config/settings.json';
    if (file_exists($settingsFile)) {
        $s = json_decode(file_get_contents($settingsFile), true) ?? [];
        $stmt = $pdo->prepare(
            'INSERT INTO tablojs_settings (setting_key, value)
             VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($s as $k => $v) {
            $stmt->execute([$k, is_array($v) ? json_encode($v) : (string)$v]);
            $total++;
        }
        $log[] = ['ok', count($s) . " paramètre(s) importé(s) depuis settings.json"];
    }

    // 2. auth.log → tablojs_auth_log
    $logFile = TABLOJS_ROOT . '/logs/auth.log';
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stmt  = $pdo->prepare(
            'INSERT IGNORE INTO tablojs_auth_log (ts, event, ip, user_login, user_agent, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $nb = 0;
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (!$e) continue;
            $details = array_diff_key($e, array_flip(['ts','event','ip','ua','user']));
            $stmt->execute([
                $e['ts']    ?? date('Y-m-d H:i:s'),
                $e['event'] ?? 'unknown',
                $e['ip']    ?? '',
                $e['user']  ?? null,
                isset($e['ua']) ? substr($e['ua'], 0, 200) : null,
                $details ? json_encode($details) : null,
            ]);
            $nb++; $total++;
        }
        $log[] = ['ok', "{$nb} entrée(s) de log importée(s)"];
    }

    // 3. Snapshots data/ → BDD
    $dataDir    = TABLOJS_ROOT . '/data';
    $activeFile = $dataDir . '/active.txt';
    $activeSlug = file_exists($activeFile) ? trim(file_get_contents($activeFile)) : null;

    $snapshots = [];
    if (is_dir($dataDir)) {
        foreach (scandir($dataDir) as $dir) {
            if (str_ends_with($dir, '-dataset') && is_dir($dataDir . '/' . $dir)) {
                $snapshots[] = $dir;
            }
        }
    }

    $stmtDs  = $pdo->prepare(
        'INSERT INTO tablojs_dataset (slug, label, generated_at, generated_by, is_active, sources, stats, log_output)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)'
    );
    $stmtProd = $pdo->prepare(
        'INSERT INTO tablojs_product (ref, des, is_transport)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE des = VALUES(des)'
    );
    $stmtGetP = $pdo->prepare('SELECT rowid FROM tablojs_product WHERE ref = ?');
    $stmtStat = $pdo->prepare(
        'INSERT INTO tablojs_product_stat
           (fk_dataset, fk_product, abc, stock_qty, mois_stock, vente_montant, vente_nb, achat_montant, achat_nb, wilson)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE abc = VALUES(abc), stock_qty = VALUES(stock_qty),
           vente_montant = VALUES(vente_montant), wilson = VALUES(wilson)'
    );
    $stmtMv  = $pdo->prepare(
        'INSERT INTO tablojs_monthly_vente (fk_dataset, fk_product, mois, montant)
         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE montant = VALUES(montant)'
    );
    $stmtMvg = $pdo->prepare(
        'INSERT INTO tablojs_monthly_vente_global (fk_dataset, mois, montant)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE montant = VALUES(montant)'
    );

    foreach ($snapshots as $slug) {
        $dir  = $dataDir . '/' . $slug;
        $meta = file_exists($dir . '/_meta.json')
              ? (json_decode(file_get_contents($dir . '/_meta.json'), true) ?? [])
              : [];

        $stmtDs->execute([
            $slug,
            $meta['label']        ?? ('Export ' . substr($slug, 0, 13)),
            $meta['generated_at'] ?? date('Y-m-d H:i:s'),
            $meta['generated_by'] ?? 'import',
            ($slug === $activeSlug) ? 1 : 0,
            json_encode($meta['sources'] ?? []),
            json_encode($meta['stats']   ?? []),
            isset($meta['log']) ? substr($meta['log'], 0, 65535) : null,
        ]);
        $datasetId = $pdo->lastInsertId();
        if (!$datasetId) {
            $s = $pdo->prepare('SELECT rowid FROM tablojs_dataset WHERE slug = ?');
            $s->execute([$slug]);
            $datasetId = $s->fetchColumn();
        }

        $produitsFile = $dir . '/produits.json';
        if (!file_exists($produitsFile) || !$datasetId) continue;

        $produits = json_decode(file_get_contents($produitsFile), true) ?? [];
        $nbProd = 0;
        foreach ($produits as $p) {
            $ref = trim($p['ref'] ?? '');
            if (!$ref) continue;
            $stmtProd->execute([$ref, $p['des'] ?? '', (int)($p['is_transport'] ?? 0)]);
            $stmtGetP->execute([$ref]);
            $pid = $stmtGetP->fetchColumn();
            if (!$pid) continue;
            $stmtStat->execute([
                $datasetId, $pid,
                $p['abc']           ?? 'C',
                $p['stock_qty']     ?? 0,
                $p['mois_stock']    ?? null,
                $p['vente_montant'] ?? 0,
                $p['vente_nb']      ?? 0,
                $p['achat_montant'] ?? 0,
                $p['achat_nb']      ?? 0,
                isset($p['wilson']) ? json_encode($p['wilson']) : null,
            ]);
            if (!empty($p['monthly_ventes'])) {
                foreach ($p['monthly_ventes'] as $mois => $montant) {
                    $stmtMv->execute([$datasetId, $pid, $mois, $montant]);
                }
            }
            $nbProd++; $total++;
        }

        $mvFile = $dir . '/monthly_ventes.json';
        if (file_exists($mvFile)) {
            $mv = json_decode(file_get_contents($mvFile), true) ?? [];
            foreach ($mv as $mois => $montant) {
                $stmtMvg->execute([$datasetId, $mois, $montant]);
                $total++;
            }
        }
        $log[] = ['ok', "Snapshot <strong>{$slug}</strong> — {$nbProd} produit(s)"];
    }

    return $total;
}

// ── Prérequis ─────────────────────────────────────────────────────────────────
function checkPrerequisites(): array {
    $checks = [];

    // PHP version
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = ['PHP ≥ 7.4', $phpOk, PHP_VERSION];

    // Extensions
    foreach (['pdo', 'pdo_mysql', 'json', 'openssl', 'mbstring'] as $ext) {
        $checks[] = ["Extension {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'OK' : 'MANQUANT'];
    }

    // Dossiers inscriptibles
    $dirs = ['conf', 'config', 'logs'];
    foreach ($dirs as $d) {
        $path  = TABLOJS_ROOT . '/' . $d;
        $writable = is_writable($path);
        $checks[] = ["Dossier /{$d} inscriptible", $writable, $writable ? 'OK' : 'NON INSCRIPTIBLE'];
    }

    // Fichiers tables SQL présents
    $sqlOk = file_exists(MARIADB_DIR . '/tables/tablojs_settings.sql');
    $checks[] = ['Scripts SQL install/', $sqlOk, $sqlOk ? 'OK' : 'ABSENT'];

    return $checks;
}

$prereqs     = checkPrerequisites();
$prereqsOk   = !in_array(false, array_column($prereqs, 1), true);
$dbConf      = $_SESSION['install_db'] ?? [];
$logStep3    = $_SESSION['install_log_step3'] ?? [];
$logStep5    = $_SESSION['install_log_step5'] ?? [];
$step3Ok     = $_SESSION['install_step3_ok']  ?? null; // null=jamais tenté, false=échec, true=succès
$installError = $_SESSION['install_error'] ?? '';
unset($_SESSION['install_error']);

// Valeur par défaut URL
$guessedUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/tablojs';

// Si étapes précédentes non faites, rediriger
if ($step > 2 && empty($dbConf)) { header('Location: ?step=2'); exit; }
if ($step > 3 && $step3Ok !== true) { header('Location: ?step=3'); exit; }

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Installation — tablojs <?= APP_VERSION ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:      #0b0e1a;
      --bg2:     #111827;
      --bg3:     #1a2236;
      --border:  #1e2a3a;
      --primary: #4f80ff;
      --primary-h:#3b6ee8;
      --green:   #22c55e;
      --red:     #ef4444;
      --orange:  #f59e0b;
      --text:    #e2e8f0;
      --text2:   #94a3b8;
      --text3:   #475569;
    }
    body {
      font-family: -apple-system, 'Segoe UI', sans-serif;
      background: var(--bg); color: var(--text);
      min-height: 100vh; display: flex; flex-direction: column; align-items: center;
      padding: 32px 16px 48px;
      background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(79,128,255,.12), transparent),
        radial-gradient(ellipse 50% 30% at 80% 90%,  rgba(34,197,94,.06),  transparent);
    }

    /* ── Header ── */
    .header { text-align: center; margin-bottom: 36px; }
    .logo-box {
      width: 60px; height: 60px;
      background: linear-gradient(135deg, var(--primary), #7c3aed);
      border-radius: 18px; display: inline-flex; align-items: center;
      justify-content: center; font-size: 28px; margin-bottom: 12px;
      box-shadow: 0 8px 32px rgba(79,128,255,.4);
    }
    .header h1 { font-size: 26px; font-weight: 700; letter-spacing: .03em; }
    .header p  { font-size: 13px; color: var(--text2); margin-top: 4px; }

    /* ── Stepper ── */
    .stepper {
      display: flex; gap: 0; margin-bottom: 36px; max-width: 680px; width: 100%;
    }
    .step-item {
      flex: 1; display: flex; flex-direction: column; align-items: center; position: relative;
    }
    .step-item:not(:last-child)::after {
      content: ''; position: absolute; top: 18px; left: 50%; width: 100%;
      height: 2px; background: var(--border); z-index: 0;
    }
    .step-item.done::after   { background: var(--green); }
    .step-item.active::after { background: linear-gradient(90deg, var(--primary), var(--border)); }
    .step-num {
      width: 36px; height: 36px; border-radius: 50%; border: 2px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 600; background: var(--bg2);
      position: relative; z-index: 1; transition: all .3s;
    }
    .step-item.done   .step-num { background: var(--green);   border-color: var(--green);   color: #fff; }
    .step-item.active .step-num { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 0 0 4px rgba(79,128,255,.2); }
    .step-label { font-size: 10px; color: var(--text3); margin-top: 6px; text-align: center; white-space: nowrap; }
    .step-item.active .step-label { color: var(--primary); font-weight: 600; }
    .step-item.done   .step-label { color: var(--green); }

    /* ── Card ── */
    .card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: 20px; padding: 32px 36px; width: 100%; max-width: 680px;
      box-shadow: 0 24px 64px rgba(0,0,0,.5);
      animation: fadeUp .35s ease;
    }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
    .card h2 { font-size: 20px; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
    .card .sub { font-size: 13px; color: var(--text2); margin-bottom: 28px; }

    /* ── Form ── */
    .field       { margin-bottom: 18px; }
    .field label { display: block; font-size: 12px; font-weight: 600; color: var(--text2); margin-bottom: 6px; letter-spacing: .04em; text-transform: uppercase; }
    .field input, .field select {
      width: 100%; background: var(--bg3); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 14px; font-size: 14px; color: var(--text);
      font-family: inherit; outline: none;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none; appearance: none;
    }
    .field input:focus, .field select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(79,128,255,.15);
    }
    .field input::placeholder { color: var(--text3); }
    .field-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .field-row-3 { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; }
    .field .hint { font-size: 11px; color: var(--text3); margin-top: 5px; line-height: 1.5; }

    .checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; }
    .checkbox-label input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }

    /* ── Checks ── */
    .check-list  { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
    .check-item  {
      display: flex; align-items: center; gap: 12px; padding: 10px 14px;
      background: var(--bg3); border-radius: 10px; border: 1px solid var(--border);
      font-size: 13px;
    }
    .check-icon  { font-size: 16px; flex-shrink: 0; }
    .check-name  { flex: 1; font-weight: 500; }
    .check-val   { font-size: 12px; color: var(--text3); font-family: monospace; }
    .check-item.ok   { border-color: rgba(34,197,94,.2);   background: rgba(34,197,94,.05); }
    .check-item.fail { border-color: rgba(239,68,68,.3);   background: rgba(239,68,68,.06); }

    /* ── Log ── */
    .log-list  { display: flex; flex-direction: column; gap: 6px; margin-bottom: 24px; max-height: 280px; overflow-y: auto; }
    .log-item  { display: flex; align-items: flex-start; gap: 10px; font-size: 12px; padding: 7px 12px; border-radius: 8px; }
    .log-item.ok   { background: rgba(34,197,94,.08);  color: #86efac; }
    .log-item.warn { background: rgba(245,158,11,.08); color: #fde68a; }
    .log-item.err  { background: rgba(239,68,68,.1);   color: #fca5a5; }
    .log-icon { flex-shrink: 0; }

    /* ── Alert ── */
    .alert {
      border-radius: 10px; padding: 12px 16px; font-size: 13px;
      margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px;
    }
    .alert-err  { background: rgba(239,68,68,.1);   border: 1px solid rgba(239,68,68,.3);  color: #fca5a5; }
    .alert-ok   { background: rgba(34,197,94,.1);   border: 1px solid rgba(34,197,94,.3);  color: #86efac; }
    .alert-info { background: rgba(79,128,255,.1);  border: 1px solid rgba(79,128,255,.3); color: #93c5fd; }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 11px 24px; border-radius: 10px; border: none; font-size: 14px;
      font-weight: 600; font-family: inherit; cursor: pointer;
      transition: background .2s, transform .1s, box-shadow .2s;
    }
    .btn-primary {
      background: var(--primary); color: #fff;
      box-shadow: 0 4px 16px rgba(79,128,255,.4);
    }
    .btn-primary:hover { background: var(--primary-h); box-shadow: 0 6px 20px rgba(79,128,255,.5); }
    .btn-primary:active { transform: scale(.98); }
    .btn-secondary { background: var(--bg3); color: var(--text); border: 1px solid var(--border); }
    .btn-secondary:hover { background: var(--border); }
    .btn-success { background: var(--green); color: #fff; box-shadow: 0 4px 16px rgba(34,197,94,.4); }
    .btn-success:hover { background: #16a34a; }
    .btn:disabled { opacity: .4; cursor: not-allowed; }
    .btn-row { display: flex; gap: 12px; justify-content: flex-end; margin-top: 8px; flex-wrap: wrap; }

    /* ── Success ── */
    .success-icon {
      width: 80px; height: 80px; background: rgba(34,197,94,.15);
      border-radius: 50%; display: flex; align-items: center;
      justify-content: center; font-size: 40px; margin: 0 auto 24px;
      border: 2px solid rgba(34,197,94,.3);
      animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.3)} 50%{box-shadow:0 0 0 12px rgba(34,197,94,0)} }
    .success-title { text-align:center; font-size:24px; font-weight:700; color: var(--green); margin-bottom:8px; }
    .success-sub   { text-align:center; font-size:14px; color: var(--text2); margin-bottom:32px; }
    .countdown-bar {
      height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; margin-bottom: 24px;
    }
    .countdown-bar-fill {
      height: 100%; background: var(--green); border-radius: 2px;
      transition: width .1s linear;
    }

    .divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
    .section-title { font-size: 11px; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 14px; }

    /* ── Password strength ── */
    .pwd-strength { height: 3px; border-radius: 2px; margin-top: 6px; transition: all .3s; background: var(--border); }

    /* ── Summary ── */
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
    .summary-item { background: var(--bg3); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; }
    .summary-key  { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text3); margin-bottom: 4px; }
    .summary-val  { font-size: 14px; font-weight: 600; }

    .skip-link { font-size: 12px; color: var(--text3); text-decoration: none; }
    .skip-link:hover { color: var(--text2); }
  </style>
</head>
<body>

<div class="header">
  <div class="logo-box">📦</div>
  <h1>tablojs</h1>
  <p>Assistant d'installation — version <?= APP_VERSION ?></p>
</div>

<!-- Stepper -->
<div class="stepper">
  <?php
  $steps = ['Prérequis', 'Base de données', 'Installation', 'Configuration', 'Migration', 'Terminé'];
  foreach ($steps as $i => $label):
    $num = $i + 1;
    $cls = $step > $num ? 'done' : ($step === $num ? 'active' : '');
  ?>
  <div class="step-item <?= $cls ?>">
    <div class="step-num"><?= $step > $num ? '✓' : $num ?></div>
    <div class="step-label"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">

<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 1 — Prérequis
// ════════════════════════════════════════════════════════════════════ ?>
<?php if ($step === 1): ?>
  <h2>🔍 Vérification des prérequis</h2>
  <p class="sub">Avant de commencer, vérifions que votre environnement est compatible.</p>

  <div class="check-list">
    <?php foreach ($prereqs as [$label, $ok, $val]): ?>
    <div class="check-item <?= $ok ? 'ok' : 'fail' ?>">
      <span class="check-icon"><?= $ok ? '✅' : '❌' ?></span>
      <span class="check-name"><?= htmlspecialchars($label) ?></span>
      <span class="check-val"><?= htmlspecialchars($val) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$prereqsOk): ?>
  <div class="alert alert-err">
    ⚠️ Certains prérequis ne sont pas satisfaits. Corrigez-les avant de continuer.
  </div>
  <?php else: ?>
  <div class="alert alert-ok">
    ✓ Tous les prérequis sont satisfaits. Vous pouvez continuer.
  </div>
  <?php endif; ?>

  <div class="btn-row">
    <a href="?step=2" class="btn btn-primary <?= !$prereqsOk ? 'btn-disabled' : '' ?>"
       <?= !$prereqsOk ? 'onclick="return false;"' : '' ?>>
      Continuer →
    </a>
  </div>


<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 2 — Configuration BDD
// ════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($step === 2): ?>
  <h2>🗄️ Configuration base de données</h2>
  <p class="sub">Commencez par tester la connexion administrateur, puis configurez votre base.</p>

  <?php if ($installError): ?>
  <div class="alert alert-err" style="margin-bottom:20px;">
    ⚠️ <?= htmlspecialchars($installError) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="?step=2" id="dbForm">
    <!-- ── Section 1 : Connexion admin ─────────────────────────────── -->
    <p class="section-title">1 — Connexion administrateur MariaDB</p>

    <div class="field-row">
      <div class="field">
        <label>Login admin MariaDB</label>
        <input type="text" name="db_root" id="dbRoot" value="root" placeholder="root" required
               oninput="resetTest()">
        <p class="hint">Utilisateur avec droits CREATE DATABASE / CREATE USER</p>
      </div>
      <div class="field">
        <label>Mot de passe admin</label>
        <input type="password" name="db_rootpass" id="dbRootpass" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="new-password" oninput="resetTest()">
        <p class="hint">Laissez vide si pas de mot de passe (WAMP local)</p>
      </div>
    </div>

    <div class="field">
      <label>Nom de la base de données (Optionnel / Requis si l'utilisateur n'a accès qu'à une base spécifique)</label>
      <input type="text" id="dbTestName" value="<?= htmlspecialchars($existingConf['dbname'] ?? '') ?>" placeholder="Laisser vide ou saisir le nom de la base (ex: sc3mala2249_tablojs)" oninput="resetTest()">
      <p class="hint">Indiquez le nom de la base si vous vous connectez à une base de données existante (ex. hébergement o2switch).</p>
    </div>

    <!-- Champs host/port soumis via hidden, affichés en interactif -->
    <input type="hidden" name="db_host" id="dbHostHidden" value="<?= htmlspecialchars($existingConf['host'] ?? 'localhost') ?>">
    <input type="hidden" name="db_port" id="dbPortHidden" value="<?= htmlspecialchars($existingConf['port'] ?? '3306') ?>">

    <div class="field-row-3">
      <div class="field">
        <label>H&ocirc;te MariaDB</label>
        <input type="text" id="dbHost" value="<?= htmlspecialchars($existingConf['host'] ?? 'localhost') ?>" placeholder="localhost"
               oninput="resetTest();document.getElementById('dbHostHidden').value=this.value">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="number" id="dbPort" value="<?= htmlspecialchars($existingConf['port'] ?? '3306') ?>" placeholder="3306"
               oninput="resetTest();document.getElementById('dbPortHidden').value=this.value">
      </div>
    </div>

    <!-- Bouton test + feedback -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap;">
      <button type="button" id="btnTest" class="btn btn-secondary" onclick="testConnection()" style="flex-shrink:0;">
        &#128268; Tester la connexion
      </button>
      <div id="testResult" style="font-size:13px;line-height:1.4;"></div>
    </div>

    <!-- Boîte résultats scan instances -->
    <div id="scanBox" style="display:none;margin-bottom:0;">
      <hr class="divider">
      <p class="section-title">📋 Instances tabloJS détectées sur ce serveur</p>
      <div id="instanceList"></div>
    </div>

    <!-- Section 2 — paramètres BDD -->
    <?php $confExists = !empty($existingConf); ?>
    <div id="section2" style="<?= $confExists ? '' : 'display:none;' ?>">
      <hr class="divider">
      <p class="section-title">2 &mdash; Param&egrave;tres de la base tablojs</p>

      <?php if ($confExists): ?>
      <div class="alert alert-info" style="margin-bottom:16px;">
        ℹ️ Configuration existante détectée dans <code>conf/conf.php</code> &mdash; champs pré-remplis.
      </div>
      <?php endif; ?>

      <div class="field">
        <label>Nom de la base de donn&eacute;es</label>
        <input type="text" name="db_name" id="dbName" value="<?= htmlspecialchars($existingConf['dbname'] ?? 'tablojs') ?>" placeholder="tablojs" required>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Utilisateur d&eacute;di&eacute;</label>
          <input type="text" name="db_user" id="dbUser" value="<?= htmlspecialchars($existingConf['user'] ?? 'tablojs_user') ?>" placeholder="tablojs_user" required>
        </div>
        <div class="field">
          <label>Mot de passe utilisateur</label>
          <input type="password" name="db_pass" id="dbPass" value="<?= htmlspecialchars($existingConf['pass'] ?? '') ?>" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="new-password">
          <p class="hint">Laissez vide si pas de mot de passe</p>
        </div>
      </div>
      <div class="field">
        <label class="checkbox-label">
          <input type="checkbox" name="create_user" value="1" <?= $confExists ? '' : 'checked' ?>>
          Cr&eacute;er automatiquement l'utilisateur d&eacute;di&eacute; avec droits limit&eacute;s
        </label>
      </div>
      <div class="btn-row">
        <a href="?step=1" class="btn btn-secondary">&larr; Retour</a>
        <button type="submit" class="btn btn-primary">Cr&eacute;er la base &amp; Continuer &rarr;</button>
      </div>
    </div>
  </form>

  <script>
  let testPassed = <?= $confExists ? 'true' : 'false' ?>;
  function resetTest() {
    testPassed = false;
    document.getElementById('testResult').innerHTML = '';
    const btn = document.getElementById('btnTest');
    btn.textContent = '🔌 Tester la connexion';
    btn.className = 'btn btn-secondary';
    btn.disabled  = false;
    document.getElementById('scanBox').style.display  = 'none';
    if (!<?= $confExists ? 'true' : 'false' ?>) document.getElementById('section2').style.display = 'none';
  }
  async function testConnection() {
    const btn = document.getElementById('btnTest');
    const res = document.getElementById('testResult');
    btn.disabled = true;
    btn.textContent = '↻ Test en cours…';
    res.innerHTML = '';
    document.getElementById('scanBox').style.display = 'none';
    const body = new FormData();
    body.append('db_host',     document.getElementById('dbHost').value);
    body.append('db_port',     document.getElementById('dbPort').value);
    body.append('db_root',     document.getElementById('dbRoot').value);
    body.append('db_rootpass', document.getElementById('dbRootpass').value);
    body.append('db_name',     document.getElementById('dbTestName').value);
    try {
      const r    = await fetch('?action=test_connection', { method: 'POST', body });
      const json = await r.json();
      btn.disabled = false;
      if (json.ok) {
        testPassed = true;
        btn.textContent = '✅ Connecté';
        btn.className = 'btn btn-success';
        res.innerHTML = '<span style="color:#86efac">✓ MariaDB ' + json.version + ' &mdash; Connexion réussie !</span>';
        
        // Copier le nom de la base de test vers la base cible
        const testDb = document.getElementById('dbTestName').value;
        if (testDb) {
          const dbNameField = document.getElementById('dbName');
          if (dbNameField) dbNameField.value = testDb;
        }
        
        // Copier l'utilisateur et mot de passe s'ils ne sont pas root
        const rootUser = document.getElementById('dbRoot').value;
        if (rootUser !== 'root') {
          const dbUserField = document.getElementById('dbUser');
          const dbPassField = document.getElementById('dbPass');
          if (dbUserField) dbUserField.value = rootUser;
          if (dbPassField) dbPassField.value = document.getElementById('dbRootpass').value;
          // Décocher la création automatique de l'utilisateur dédié
          const createUserCheckbox = document.querySelector('[name="create_user"]');
          if (createUserCheckbox) createUserCheckbox.checked = false;
        }

        const s2 = document.getElementById('section2');
        s2.style.display = 'block';
        s2.style.animation = 'fadeUp .35s ease';
        s2.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        detectInstances(body);
      } else {
        testPassed = false;
        btn.textContent = '❌ Erreur &mdash; Relancer';
        btn.className = 'btn btn-secondary';
        res.innerHTML = '<span style="color:#fca5a5">✗ ' + json.error + '</span>';
      }
    } catch(e) {
      btn.disabled = false;
      btn.textContent = '❌ Erreur réseau';
      btn.className = 'btn btn-secondary';
      res.innerHTML = '<span style="color:#fca5a5">Erreur : ' + e.message + '</span>';
    }
  }
  async function detectInstances(formBody) {
    const box  = document.getElementById('scanBox');
    const list = document.getElementById('instanceList');
    box.style.display = 'block';
    list.innerHTML = '<div style="color:#94a3b8;font-size:12px;padding:8px 0;">↻ Scan en cours…</div>';
    try {
      const r    = await fetch('?action=detect_installs', { method: 'POST', body: formBody });
      const json = await r.json();
      if (!json.ok) { list.innerHTML = '<div style="color:#fca5a5;font-size:12px;">⚠ ' + json.error + '</div>'; return; }
      const inst = json.instances;
      if (inst.length === 0) {
        list.innerHTML = '<div style="color:#94a3b8;font-size:12px;padding:6px 0;">Aucune installation tabloJS trouvée (' + json.total + ' base(s) scannée(s)).</div>';
        return;
      }
      list.innerHTML = inst.map(i => `
        <div class="instance-card">
          <div class="instance-card-main">
            <div class="instance-db">🗄 <strong>${i.dbname}</strong></div>
            ${i.company ? '<div class="instance-meta">' + escHtml(i.company) + (i.tagline ? ' &mdash; ' + escHtml(i.tagline) : '') + '</div>' : ''}
          </div>
          <div class="instance-card-right">
            <span class="instance-badge">${i.tables} tables</span>
            ${i.users.map(u => '<span class="instance-user">' + escHtml(u.split('@')[0]) + '</span>').join('')}
            <button class="btn btn-primary" style="padding:5px 14px;font-size:12px;" onclick="event.stopPropagation();selectInstance('${i.dbname}','${(i.users[0]||'')}')">✓ Sélectionner</button>
          </div>
        </div>
      `).join('');
    } catch(e) {
      list.innerHTML = '<div style="color:#fca5a5;font-size:12px;">Erreur scan : ' + e.message + '</div>';
    }
  }

  function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function selectInstance(dbname, userHint) {
    // Remplir les champs section 2 avec les valeurs détectées
    const nameField = document.querySelector('[name="db_name"]');
    const userField = document.querySelector('[name="db_user"]');
    if (nameField) nameField.value = dbname;
    if (userField && userHint) userField.value = userHint.split("'@'")[0].replace(/^'/, '');
    // Scroll vers la section 2
    document.getElementById('section2').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    // Feedback visuel
    const cards = document.querySelectorAll('.instance-card');
    cards.forEach(c => c.style.border = '1px solid #1e2a3a');
    event.currentTarget && (event.currentTarget.style.border = '1px solid #22c55e');
  }

  document.getElementById('dbForm').addEventListener('submit', function(e) {
    if (!testPassed) {
      e.preventDefault();
      document.getElementById('testResult').innerHTML =
        '<span style="color:#fca5a5">⚠ Testez d\'abord la connexion administrateur.</span>';
    }
  });
  </script>
  <style>
  @keyframes spin { to { transform: rotate(360deg); } }
  .instance-card {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 10px 14px;
    background: var(--bg3); border: 1px solid var(--border);
    border-radius: 10px; cursor: pointer;
    transition: border-color .2s, background .2s;
    margin-bottom: 8px;
  }
  .instance-card:hover { border-color: var(--primary); background: rgba(79,128,255,.06); }
  .instance-card-main { flex:1; min-width:0; }
  .instance-card-right { display:flex; align-items:center; gap:8px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
  .instance-db   { font-size:14px; font-weight:600; }
  .instance-meta { font-size:11px; color:var(--text2); margin-top:2px; }
  .instance-badge { font-size:10px; background:rgba(79,128,255,.15); color:var(--primary); border-radius:20px; padding:2px 8px; white-space:nowrap; }
  .instance-user  { font-size:10px; background:rgba(34,197,94,.12); color:#86efac; border-radius:20px; padding:2px 8px; font-family:monospace; white-space:nowrap; }
  </style>



<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 3 — Installation BDD
// ════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($step === 3): ?>
  <h2>⚙️ Création de la base de données</h2>
  <p class="sub">Connexion à MariaDB, création des tables et données initiales.</p>

  <?php if ($step3Ok === null): // ── Premier passage : pas encore tenté ──────── ?>

  <div class="summary-grid" style="margin-bottom:24px;">
    <div class="summary-item">
      <div class="summary-key">Hôte</div>
      <div class="summary-val"><?= htmlspecialchars($dbConf['host'] ?? 'localhost') ?>:<?= htmlspecialchars($dbConf['port'] ?? '3306') ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Base de données</div>
      <div class="summary-val"><?= htmlspecialchars($dbConf['dbname'] ?? 'tablojs') ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Admin MariaDB</div>
      <div class="summary-val"><?= htmlspecialchars($dbConf['root'] ?? 'root') ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Utilisateur dédié</div>
      <div class="summary-val"><?= htmlspecialchars($dbConf['user'] ?? 'tablojs_user') ?></div>
    </div>
  </div>

  <div class="alert alert-info">
    ℹ️ Cliquez sur <strong>Lancer l'installation</strong> pour créer la base, les tables et insérer les données initiales.
  </div>

  <form method="POST" action="?step=3">
    <div class="btn-row">
      <a href="?step=2" class="btn btn-secondary">← Modifier la config</a>
      <button type="submit" class="btn btn-primary">🚀 Lancer l'installation</button>
    </div>
  </form>

  <?php elseif ($step3Ok === false): // ── Échec ────────────────────────────── ?>

  <?php
  // Compter les erreurs dans le log
  $errCount  = count(array_filter($logStep3, fn($e) => $e[0] === 'err'));
  $warnCount = count(array_filter($logStep3, fn($e) => $e[0] === 'warn'));
  ?>

  <div class="alert alert-err">
    ❌ <strong><?= $errCount ?> erreur(s)</strong><?= $warnCount ? ", {$warnCount} avertissement(s)" : '' ?> — Consultez le détail ci-dessous, corrigez votre configuration et relancez.
  </div>

  <div class="log-list" style="max-height:340px;">
    <?php foreach ($logStep3 as [$type, $msg]): ?>
    <div class="log-item <?= $type ?>">
      <span class="log-icon"><?= $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗') ?></span>
      <span><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="alert alert-info" style="font-size:12px;">
    <div>
      <strong>Paramètres utilisés :</strong><br>
      DSN : <code>mysql:host=<?= htmlspecialchars($dbConf['host'] ?? '') ?>;port=<?= htmlspecialchars($dbConf['port'] ?? '') ?></code><br>
      Admin : <code><?= htmlspecialchars($dbConf['root'] ?? '') ?></code> —
      Base cible : <code><?= htmlspecialchars($dbConf['dbname'] ?? '') ?></code>
    </div>
  </div>

  <form method="POST" action="?step=3">
    <div class="btn-row">
      <a href="?step=2" class="btn btn-secondary">← Modifier la config</a>
      <button type="submit" class="btn btn-primary">🔄 Relancer l'installation</button>
    </div>
  </form>

  <?php else: // ── Succès ─────────────────────────────────────────────────── ?>

  <div class="log-list" style="max-height:340px;">
    <?php foreach ($logStep3 as [$type, $msg]): ?>
    <div class="log-item <?= $type ?>">
      <span class="log-icon"><?= $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗') ?></span>
      <span><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="alert alert-ok">
    ✅ Base de données créée avec succès. Passez à la configuration de l'application.
  </div>
  <div class="btn-row">
    <a href="?step=4" class="btn btn-primary">Configurer l'application →</a>
  </div>

  <?php endif; ?>


<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 4 — Configuration application
// ════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($step === 4): ?>
  <h2>🏢 Configuration de l'application</h2>
  <p class="sub">Personnalisez l'application et définissez le mot de passe administrateur.</p>

  <?php if ($error): ?>
  <div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="?step=4">

    <p class="section-title">Identité de la société</p>
    <div class="field-row">
      <div class="field">
        <label>Nom de la société</label>
        <input type="text" name="company_name" value="tablojs" placeholder="ex: tablojs SAS" maxlength="60">
      </div>
      <div class="field">
        <label>Sous-titre / accroche</label>
        <input type="text" name="company_tagline" value="Gestion des stocks" placeholder="ex: Gestion des stocks" maxlength="120">
      </div>
    </div>

    <div class="field">
      <label>URL racine de l'application</label>
      <input type="url" name="url_root" value="<?= htmlspecialchars($guessedUrl) ?>" placeholder="http://localhost/tablojs">
      <p class="hint">Sans slash final. Utilisée pour les redirections et les liens.</p>
    </div>

    <hr class="divider">
    <p class="section-title">Mot de passe administrateur</p>
    <div class="alert alert-info" style="margin-bottom:18px;">
      ℹ️ Login : <strong>admin</strong> — Le hash bcrypt sera mis à jour dans <code>config/auth.php</code>.
    </div>

    <div class="field-row">
      <div class="field">
        <label>Nouveau mot de passe</label>
        <div style="position:relative;">
          <input type="password" name="admin_pass" id="adminPass" placeholder="••••••••" required
                 autocomplete="new-password" oninput="checkPwd()" style="padding-right:42px;width:100%;">
          <button type="button" onclick="togglePwd('adminPass','eyeBtn1')" id="eyeBtn1"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                         background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;padding:0;line-height:1;"
                  title="Afficher / Masquer">👁</button>
        </div>
        <!-- Barre de force -->
        <div style="height:3px;border-radius:2px;margin-top:6px;background:#1e2a3a;">
          <div id="pwdBar" style="height:100%;border-radius:2px;width:0;transition:width .3s,background .3s;"></div>
        </div>
        <p class="hint" id="pwdHint" style="margin-top:4px;">Minimum 8 caractères</p>
      </div>
      <div class="field">
        <label>Confirmer le mot de passe</label>
        <div style="position:relative;">
          <input type="password" name="admin_pass2" id="adminPass2" placeholder="••••••••" required
                 autocomplete="new-password" oninput="checkPwd()" style="padding-right:42px;width:100%;">
          <button type="button" onclick="togglePwd('adminPass2','eyeBtn2')" id="eyeBtn2"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                         background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;padding:0;line-height:1;"
                  title="Afficher / Masquer">👁</button>
        </div>
        <p class="hint" id="matchHint" style="margin-top:10px;font-size:12px;"></p>
      </div>
    </div>

    <hr class="divider">
    <p class="section-title">Sécurité</p>
    <div class="field">
      <label>Clé de chiffrement des fichiers uploadés</label>
      <input type="text" name="encrypt_key" value="<?= bin2hex(random_bytes(24)) ?>"
             placeholder="Clé aléatoire 48 caractères" required>
      <p class="hint">⚠️ Conservez cette clé précieusement — elle sera nécessaire pour déchiffrer les fichiers Excel importés.</p>
    </div>

    <div class="btn-row">
      <a href="?step=3" class="btn btn-secondary">← Retour</a>
      <button type="submit" id="btnSubmitStep4" class="btn btn-primary">Enregistrer &amp; Continuer →</button>
    </div>
  </form>

  <script>
  function togglePwd(inputId, btnId) {
    const inp = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if (inp.type === 'password') {
      inp.type = 'text';
      btn.textContent = '🙈';
    } else {
      inp.type = 'password';
      btn.textContent = '👁';
    }
  }

  function checkPwd() {
    const p1  = document.getElementById('adminPass').value;
    const p2  = document.getElementById('adminPass2').value;
    const bar = document.getElementById('pwdBar');
    const hint     = document.getElementById('pwdHint');
    const matchHint = document.getElementById('matchHint');
    const submitBtn = document.getElementById('btnSubmitStep4');

    // Barre de force
    let score = 0;
    if (p1.length >= 8)           score++;
    if (p1.length >= 12)          score++;
    if (/[A-Z]/.test(p1))        score++;
    if (/[0-9]/.test(p1))        score++;
    if (/[^A-Za-z0-9]/.test(p1)) score++;

    const colors = ['#ef4444','#f59e0b','#f59e0b','#22c55e','#22c55e'];
    const widths  = ['15%','35%','55%','75%','100%'];
    const labels  = ['Très faible','Faible','Moyen','Fort','Très fort'];
    if (p1.length === 0) {
      bar.style.width = '0'; bar.style.background = '';
      hint.textContent = 'Minimum 8 caractères'; hint.style.color = '#94a3b8';
    } else {
      bar.style.width      = widths[score-1]  || '5%';
      bar.style.background = colors[score-1]  || '#ef4444';
      hint.textContent     = labels[score-1]  || 'Trop court';
      hint.style.color     = colors[score-1]  || '#ef4444';
    }

    // Vérification correspondance
    if (p2.length === 0) {
      matchHint.textContent = ''; submitBtn.disabled = false;
    } else if (p1 === p2) {
      matchHint.textContent = '✓ Les mots de passe correspondent';
      matchHint.style.color = '#22c55e';
      document.getElementById('adminPass2').style.borderColor = '#22c55e';
      submitBtn.disabled = false;
    } else {
      matchHint.textContent = '✗ Les mots de passe ne correspondent pas';
      matchHint.style.color = '#ef4444';
      document.getElementById('adminPass2').style.borderColor = '#ef4444';
      submitBtn.disabled = true;
    }
  }

  // Bloquer le submit si non correspondants
  document.querySelector('form[action="?step=4"]').addEventListener('submit', function(e) {
    const p1 = document.getElementById('adminPass').value;
    const p2 = document.getElementById('adminPass2').value;
    if (p1 !== p2) {
      e.preventDefault();
      document.getElementById('matchHint').textContent = '✗ Les mots de passe ne correspondent pas';
      document.getElementById('matchHint').style.color = '#ef4444';
    }
  });
  </script>


<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 5 — Migration des données existantes
// ════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($step === 5): ?>
  <h2>📦 Migration des données existantes</h2>
  <p class="sub">Import des fichiers JSON actuels vers la base de données MariaDB.</p>

  <?php
  $hasData = file_exists(TABLOJS_ROOT . '/config/settings.json')
          || file_exists(TABLOJS_ROOT . '/logs/auth.log')
          || !empty(glob(TABLOJS_ROOT . '/data/*-dataset', GLOB_ONLYDIR));
  ?>

  <?php if (!empty($logStep5)): ?>
  <div class="log-list">
    <?php foreach ($logStep5 as [$type, $msg]): ?>
    <div class="log-item <?= $type ?>">
      <span class="log-icon"><?= $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗') ?></span>
      <span><?= $msg ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>

  <?php if ($hasData): ?>
  <div class="alert alert-info">
    📂 Des données existantes ont été détectées (settings.json, auth.log, snapshots data/).
    Elles peuvent être importées automatiquement dans MariaDB.
  </div>

  <div class="summary-grid">
    <div class="summary-item">
      <div class="summary-key">settings.json</div>
      <div class="summary-val"><?= file_exists(TABLOJS_ROOT . '/config/settings.json') ? '✅ Présent' : '— Absent' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">auth.log</div>
      <div class="summary-val"><?= file_exists(TABLOJS_ROOT . '/logs/auth.log') ? '✅ Présent' : '— Absent' ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Snapshots data/</div>
      <div class="summary-val"><?= count(glob(TABLOJS_ROOT . '/data/*-dataset', GLOB_ONLYDIR)) ?> trouvé(s)</div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Migration</div>
      <div class="summary-val" style="color:var(--orange)">En attente</div>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-info">
    ℹ️ Aucune donnée existante à migrer. Vous démarrez sur une base vide.
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <form method="POST" action="?step=5">
    <div class="btn-row">
      <a href="?step=6" class="skip-link">Passer cette étape →</a>
      <button type="submit" class="btn btn-primary">
        <?= $hasData ? '📥 Importer les données & Terminer' : '✓ Terminer l\'installation' ?>
      </button>
    </div>
  </form>


<?php // ════════════════════════════════════════════════════════════════════
// ÉTAPE 6 — Succès
// ════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($step === 6): ?>

  <div class="success-icon">✅</div>
  <div class="success-title">Installation réussie !</div>
  <div class="success-sub">tablojs <?= APP_VERSION ?> est prêt à l'emploi.</div>

  <div class="countdown-bar">
    <div class="countdown-bar-fill" id="cbar" style="width:100%"></div>
  </div>

  <div class="summary-grid">
    <div class="summary-item">
      <div class="summary-key">Base de données</div>
      <div class="summary-val" style="color:var(--green)">✓ <?= htmlspecialchars($dbConf['dbname'] ?? 'tablojs') ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">Utilisateur BDD</div>
      <div class="summary-val" style="color:var(--green)">✓ <?= htmlspecialchars($dbConf['user'] ?? 'tablojs_user') ?></div>
    </div>
    <div class="summary-item">
      <div class="summary-key">conf/conf.php</div>
      <div class="summary-val" style="color:var(--green)">✓ Généré</div>
    </div>
    <div class="summary-item">
      <div class="summary-key">install.lock</div>
      <div class="summary-val" style="color:var(--green)">✓ Créé</div>
    </div>
  </div>

  <div class="alert alert-info" style="margin-bottom:24px;">
    🔒 Le wizard est maintenant <strong>verrouillé</strong>. Supprimez <code>install/install.lock</code>
    uniquement pour réinstaller.
  </div>

  <div class="btn-row" style="justify-content:center;">
    <a href="../login.php" class="btn btn-success" id="loginBtn" style="font-size:16px;padding:14px 32px;">
      🚀 Accéder à l'application →
    </a>
  </div>

  <script>
  // Décompte 5 secondes puis redirection automatique
  const bar    = document.getElementById('cbar');
  const btn    = document.getElementById('loginBtn');
  const total  = 5000;
  const start  = Date.now();
  const timer  = setInterval(() => {
    const elapsed = Date.now() - start;
    const pct     = Math.max(0, 100 - (elapsed / total * 100));
    bar.style.width = pct + '%';
    if (elapsed >= total) {
      clearInterval(timer);
      // Redirection automatique vers la page de connexion
      window.location.href = '../login.php';
    }
  }, 50);
  </script>

<?php endif; ?>

</div><!-- /.card -->

<p style="font-size:11px;color:var(--text3);margin-top:24px;">
  tablojs <?= APP_VERSION ?> — Installation wizard
  <?php if ($step < 6): ?>
  · Étape <?= $step ?>/6
  <?php endif; ?>
</p>

</body>
</html>
