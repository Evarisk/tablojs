<?php
/**
 * tablojs — Migration des données existantes vers MariaDB
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * Ce script importe dans MariaDB :
 *  1. config/settings.json → tablojs_settings
 *  2. logs/auth.log        → tablojs_auth_log
 *  3. data/{snapshot}/     → tablojs_dataset + tablojs_product + tablojs_product_stat
 *                            + tablojs_monthly_vente + tablojs_monthly_vente_global
 *
 * Usage CLI :
 *   php install/migrate_data.php
 *
 * Idempotent : relancer le script ne duplique pas les données.
 */

define('TABLOJS_ROOT', dirname(__DIR__));

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Ce script doit être exécuté en ligne de commande.');
}

// Charger conf + connexion BDD
$confFile = TABLOJS_ROOT . '/conf/conf.php';
if (!file_exists($confFile)) {
    die("[ERREUR] conf/conf.php introuvable. Lancez d'abord install/install_db.php\n");
}
require_once $confFile;
require_once TABLOJS_ROOT . '/conf/db.php';

$pdo = DB::get();
if (!$pdo) {
    die("[ERREUR] Impossible de se connecter à la base de données. Vérifiez conf/conf.php\n");
}

$errors   = 0;
$warnings = 0;

echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
echo "║     tablojs — Migration JSON → MariaDB           ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ════════════════════════════════════════════════════════════
// ÉTAPE 1 — settings.json → tablojs_settings
// ════════════════════════════════════════════════════════════
echo "[1/3] Migration config/settings.json → tablojs_settings...\n";

$settingsFile = TABLOJS_ROOT . '/config/settings.json';
if (file_exists($settingsFile)) {
    $s = json_decode(file_get_contents($settingsFile), true);
    if ($s) {
        $stmt = $pdo->prepare(
            'INSERT INTO tablojs_settings (setting_key, value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($s as $key => $val) {
            $encoded = is_array($val) ? json_encode($val) : (string)$val;
            $stmt->execute([$key, $encoded]);
        }
        echo "      ✓ " . count($s) . " paramètre(s) importé(s)\n";
    } else {
        echo "      [AVERTISSEMENT] settings.json vide ou invalide\n";
        $warnings++;
    }
} else {
    echo "      [INFO] config/settings.json absent — ignoré\n";
}

// ════════════════════════════════════════════════════════════
// ÉTAPE 2 — logs/auth.log → tablojs_auth_log
// ════════════════════════════════════════════════════════════
echo "\n[2/3] Migration logs/auth.log → tablojs_auth_log...\n";

$logFile = TABLOJS_ROOT . '/logs/auth.log';
if (file_exists($logFile)) {
    $lines   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $imported = 0;
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO tablojs_auth_log (ts, event, ip, user_login, user_agent, details)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) { $warnings++; continue; }

        $details = [];
        $reserved = ['ts', 'event', 'ip', 'ua', 'user'];
        foreach ($entry as $k => $v) {
            if (!in_array($k, $reserved)) $details[$k] = $v;
        }
        $stmt->execute([
            $entry['ts']    ?? date('Y-m-d H:i:s'),
            $entry['event'] ?? 'unknown',
            $entry['ip']    ?? '',
            $entry['user']  ?? null,
            isset($entry['ua']) ? substr($entry['ua'], 0, 200) : null,
            $details ? json_encode($details) : null,
        ]);
        $imported++;
    }
    echo "      ✓ {$imported} entrée(s) de log importée(s) (" . count($lines) . " lignes)\n";
} else {
    echo "      [INFO] logs/auth.log absent — ignoré\n";
}

// ════════════════════════════════════════════════════════════
// ÉTAPE 3 — data/{snapshot}/ → BDD
// ════════════════════════════════════════════════════════════
echo "\n[3/3] Migration snapshots data/ → BDD...\n";

$dataDir    = TABLOJS_ROOT . '/data';
$activeFile = $dataDir . '/active.txt';
$activeSlug = file_exists($activeFile) ? trim(file_get_contents($activeFile)) : null;

// Lister les snapshots
$snapshots = [];
foreach (scandir($dataDir, SCANDIR_FLAG_ASCENDING) as $dir) {
    if (!str_ends_with($dir, '-dataset')) continue;
    if (!is_dir($dataDir . '/' . $dir)) continue;
    $snapshots[] = $dir;
}

if (empty($snapshots)) {
    echo "      [INFO] Aucun snapshot trouvé dans data/ — ignoré\n";
} else {
    echo "      " . count($snapshots) . " snapshot(s) trouvé(s)\n\n";
}

// Préparer les statements réutilisables
$stmtDataset = $pdo->prepare(
    'INSERT INTO tablojs_dataset (slug, label, generated_at, generated_by, is_active, sources, stats, log_output)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)'
);

$stmtProduct = $pdo->prepare(
    'INSERT INTO tablojs_product (ref, des, is_transport)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE des = VALUES(des), is_transport = VALUES(is_transport)'
);
$stmtGetProduct = $pdo->prepare('SELECT rowid FROM tablojs_product WHERE ref = ?');

$stmtStat = $pdo->prepare(
    'INSERT INTO tablojs_product_stat
       (fk_dataset, fk_product, abc, stock_qty, mois_stock, vente_montant, vente_nb, achat_montant, achat_nb, wilson)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE abc = VALUES(abc), stock_qty = VALUES(stock_qty),
       vente_montant = VALUES(vente_montant), achat_montant = VALUES(achat_montant),
       wilson = VALUES(wilson)'
);

$stmtMonthly = $pdo->prepare(
    'INSERT INTO tablojs_monthly_vente (fk_dataset, fk_product, mois, montant)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE montant = VALUES(montant)'
);

$stmtMonthlyGlobal = $pdo->prepare(
    'INSERT INTO tablojs_monthly_vente_global (fk_dataset, mois, montant)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE montant = VALUES(montant)'
);

// ── Compatibilité PHP 7.x ─────────────────────────────────────────────────
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool {
        return $n === '' || substr($h, -strlen($n)) === $n;
    }
}

foreach ($snapshots as $slug) {
    $snapshotDir = $dataDir . '/' . $slug;
    $metaFile    = $snapshotDir . '/_meta.json';
    $meta        = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

    // Auto-label depuis le slug si absent
    $label = $meta['label'] ?? ('Export ' . substr($slug, 0, 13));
    $genAt = $meta['generated_at'] ?? date('Y-m-d H:i:s');

    echo "      ► {$slug} ({$label})\n";

    // Insérer dataset
    $stmtDataset->execute([
        $slug,
        $label,
        $genAt,
        $meta['generated_by'] ?? 'migrate_data.php',
        ($slug === $activeSlug) ? 1 : 0,
        json_encode($meta['sources'] ?? []),
        json_encode($meta['stats']   ?? []),
        isset($meta['log']) ? substr($meta['log'], 0, 65535) : null,
    ]);
    $datasetId = $pdo->lastInsertId() ?: getDatasetId($pdo, $slug);

    // Lire produits.json du snapshot
    $produitsFile = $snapshotDir . '/produits.json';
    if (!file_exists($produitsFile)) {
        echo "        [AVERTISSEMENT] produits.json absent dans {$slug}\n";
        $warnings++;
        continue;
    }

    $produits = json_decode(file_get_contents($produitsFile), true);
    if (!$produits) {
        echo "        [AVERTISSEMENT] produits.json invalide dans {$slug}\n";
        $warnings++;
        continue;
    }

    $nbProd = 0;
    foreach ($produits as $p) {
        $ref = trim($p['ref'] ?? '');
        if (!$ref) continue;

        // Upsert produit
        $stmtProduct->execute([$ref, $p['des'] ?? '', (int)($p['is_transport'] ?? 0)]);
        $stmtGetProduct->execute([$ref]);
        $productId = $stmtGetProduct->fetchColumn();
        if (!$productId) continue;

        // Stat par dataset
        $stmtStat->execute([
            $datasetId,
            $productId,
            $p['abc']          ?? 'C',
            $p['stock_qty']    ?? 0,
            $p['mois_stock']   ?? null,
            $p['vente_montant']?? 0,
            $p['vente_nb']     ?? 0,
            $p['achat_montant']?? 0,
            $p['achat_nb']     ?? 0,
            isset($p['wilson']) ? json_encode($p['wilson']) : null,
        ]);

        // Ventes mensuelles par produit
        if (!empty($p['monthly_ventes']) && is_array($p['monthly_ventes'])) {
            foreach ($p['monthly_ventes'] as $mois => $montant) {
                $stmtMonthly->execute([$datasetId, $productId, $mois, $montant]);
            }
        }

        $nbProd++;
    }
    echo "        ✓ {$nbProd} produit(s) importé(s)\n";

    // Ventes mensuelles globales depuis monthly_ventes.json
    $mvFile = $snapshotDir . '/monthly_ventes.json';
    if (file_exists($mvFile)) {
        $mv = json_decode(file_get_contents($mvFile), true);
        if ($mv) {
            foreach ($mv as $mois => $montant) {
                $stmtMonthlyGlobal->execute([$datasetId, $mois, $montant]);
            }
            echo "        ✓ " . count($mv) . " mois de ventes globales\n";
        }
    }
}

// ── Helper ───────────────────────────────────────────────────────────────────
function getDatasetId(PDO $pdo, string $slug): ?int {
    $s = $pdo->prepare('SELECT rowid FROM tablojs_dataset WHERE slug = ?');
    $s->execute([$slug]);
    return $s->fetchColumn() ?: null;
}

// ── Rapport final ─────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
if ($errors > 0) {
    echo "║  ⚠ Migration terminée avec {$errors} erreur(s)          ║\n";
} else {
    echo "║  ✓ Migration terminée avec succès !           ║\n";
}
if ($warnings > 0) {
    echo "║    {$warnings} avertissement(s) — voir ci-dessus         ║\n";
}
echo "║                                                  ║\n";
echo "║  Les fichiers JSON originaux sont conservés.     ║\n";
echo "║  Vous pouvez les archiver une fois validé.       ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";
