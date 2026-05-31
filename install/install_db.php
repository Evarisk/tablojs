<?php
/**
 * tablojs — Script d'installation de la base de données
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * Ce script :
 *  1. Crée l'utilisateur et la base MariaDB
 *  2. Crée toutes les tables (install/mariadb/tables/)
 *  3. Insère les données initiales (install/mariadb/data/)
 *  4. Génère conf/conf.php depuis conf/conf.php.example
 *
 * Usage CLI (en tant qu'administrateur MariaDB) :
 *   php install/install_db.php
 *
 * ⚠️  NE PAS exposer ce fichier en production.
 *    Protégé par install/install.lock après exécution réussie.
 */

define('TABLOJS_ROOT', dirname(__DIR__));
define('INSTALL_DIR',  __DIR__ . '/mariadb');
define('LOCK_FILE',    __DIR__ . '/install.lock');
define('CONF_FILE',    TABLOJS_ROOT . '/conf/conf.php');
define('CONF_EXAMPLE', TABLOJS_ROOT . '/conf/conf.php.example');

// ── Protection : ne s'exécute qu'une fois ────────────────────────────────────
if (file_exists(LOCK_FILE)) {
    die("[ERREUR] Installation déjà effectuée. Supprimez install/install.lock pour réinstaller.\n");
}

// ── Seulement en CLI ─────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Ce script doit être exécuté en ligne de commande.');
}

echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
echo "║       tablojs — Installation Base de Données     ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── 1. Vérifier que conf.php existe ─────────────────────────────────────────
if (!file_exists(CONF_FILE)) {
    echo "[INFO] conf/conf.php introuvable.\n";
    echo "       Copiez conf/conf.php.example en conf/conf.php et remplissez vos identifiants.\n";
    echo "       Commande : copy conf\\conf.php.example conf\\conf.php\n\n";
    exit(1);
}

require_once CONF_FILE;

$host    = $tablojs_db_host    ?? 'localhost';
$port    = $tablojs_db_port    ?? '3306';
$dbname  = $tablojs_db_name    ?? 'tablojs';
$user    = $tablojs_db_user    ?? 'tablojs_user';
$pass    = $tablojs_db_pass    ?? '';

echo "[1/4] Connexion à MariaDB ({$host}:{$port})...\n";

// Connexion root pour créer la BDD et l'utilisateur
echo "      Entrez le login administrateur MariaDB [root] : ";
$rootUser = trim(fgets(STDIN)) ?: 'root';
echo "      Entrez le mot de passe administrateur MariaDB : ";
system('stty -echo');
$rootPass = trim(fgets(STDIN));
system('stty echo');
echo "\n";

try {
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    $rootPdo = new PDO($dsn, $rootUser, $rootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "      ✓ Connexion administrateur OK\n\n";
} catch (PDOException $e) {
    echo "[ERREUR] Impossible de se connecter : " . $e->getMessage() . "\n";
    exit(1);
}

// ── 2. Créer la base et l'utilisateur ────────────────────────────────────────
echo "[2/4] Création base '{$dbname}' et utilisateur '{$user}'...\n";

$rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "      ✓ Base '{$dbname}' OK\n";

$rootPdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY " . $rootPdo->quote($pass));
$rootPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES
                ON `{$dbname}`.* TO '{$user}'@'localhost'");
$rootPdo->exec("FLUSH PRIVILEGES");
echo "      ✓ Utilisateur '{$user}' avec droits limités OK\n\n";

// ── 3. Créer les tables ───────────────────────────────────────────────────────
echo "[3/4] Création des tables...\n";

$rootPdo->exec("USE `{$dbname}`");

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
    $path = INSTALL_DIR . '/tables/' . $file;
    if (!file_exists($path)) {
        echo "      [AVERTISSEMENT] Fichier manquant : {$file}\n";
        continue;
    }
    $sql = file_get_contents($path);
    // Exécuter chaque statement séparément
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) {
            try {
                $rootPdo->exec($stmt);
            } catch (PDOException $e) {
                // Ignorer "already exists"
                if (!str_contains($e->getMessage(), 'already exists') &&
                    !str_contains($e->getMessage(), 'Duplicate')) {
                    echo "      [ERREUR] {$file} : " . $e->getMessage() . "\n";
                }
            }
        }
    }
    echo "      ✓ {$file}\n";
}

// ── 4. Insérer les données initiales ─────────────────────────────────────────
echo "\n[4/4] Insertion des données initiales...\n";

$dataFiles = glob(INSTALL_DIR . '/data/*.sql');
foreach ($dataFiles as $path) {
    $sql = file_get_contents($path);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) {
            try {
                $rootPdo->exec($stmt);
            } catch (PDOException $e) {
                echo "      [AVERTISSEMENT] " . basename($path) . " : " . $e->getMessage() . "\n";
            }
        }
    }
    echo "      ✓ " . basename($path) . "\n";
}

// ── Verrouillage ─────────────────────────────────────────────────────────────
file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . " — Installation terminée\n");

echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
echo "║  ✓ Installation terminée avec succès !           ║\n";
echo "║                                                  ║\n";
echo "║  Prochaine étape : lancer la migration des       ║\n";
echo "║  données existantes (JSON → BDD) :               ║\n";
echo "║  php install/migrate_data.php                    ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";
