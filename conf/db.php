<?php
/**
 * tablojs — Connexion PDO à MariaDB (singleton)
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * Usage :
 *   $pdo = DB::get();
 *   $stmt = $pdo->prepare('SELECT * FROM tablojs_settings WHERE setting_key = ?');
 *   $stmt->execute([$key]);
 *
 * En cas d'échec de connexion (mode dégradé), retourne null et
 * l'application continue en lisant les fichiers JSON de secours.
 */

class DB
{
    private static ?PDO $instance = null;

    /**
     * Retourne l'instance PDO singleton.
     * Retourne null si la BDD est indisponible (mode dégradé).
     */
    public static function get(): ?PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $confFile = __DIR__ . '/../conf/conf.php';
        if (!file_exists($confFile)) {
            // conf.php absent → mode dégradé (fichiers JSON)
            return null;
        }

        // Charger la configuration de manière isolée pour éviter que require_once
        // ne saute l'inclusion si conf.php a déjà été chargé par un script appelant
        $dbConf = (static function($f) {
            require $f;
            return compact(
                'tablojs_db_host', 'tablojs_db_port',
                'tablojs_db_name', 'tablojs_db_user', 'tablojs_db_pass', 'tablojs_db_charset'
            );
        })($confFile);

        $host    = $dbConf['tablojs_db_host']    ?? 'localhost';
        $port    = $dbConf['tablojs_db_port']    ?? '3306';
        $dbname  = $dbConf['tablojs_db_name']    ?? 'tablojs';
        $user    = $dbConf['tablojs_db_user']    ?? '';
        $pass    = $dbConf['tablojs_db_pass']    ?? '';
        $charset = $dbConf['tablojs_db_charset'] ?? 'utf8mb4';


        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            // BDD inaccessible → mode dégradé silencieux
            // En mode dev, décommenter la ligne suivante pour voir l'erreur :
            // error_log('[tablojs] DB::get() failed: ' . $e->getMessage());
            return null;
        }

        return self::$instance;
    }

    /**
     * Teste si la connexion BDD est disponible.
     */
    public static function isAvailable(): bool
    {
        return self::get() !== null;
    }

    /** Empêche l'instanciation directe */
    private function __construct() {}
    private function __clone() {}
}
