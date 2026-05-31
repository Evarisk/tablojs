<?php
/**
 * upload.php — Backend d'import et gestion des datasets tablojs
 *
 * Structure data/ :
 *   data/Upload/                    ← zone de dépôt temporaire
 *   data/YYYYMMDD-HHMMSS-dataset/  ← snapshots horodatés
 *   data/active.txt                 ← nom du snapshot actif
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ─── Authentification ─────────────────────────────────────────────────────
// Charger auth.php systématiquement pour appliquer la configuration des erreurs PHP
// et les fonctions de compatibilité globales (polyfills).
require_once __DIR__ . '/auth.php';

// L'action 'active' est accessible sans session (appelée par JS au démarrage).
// Toutes les autres actions nécessitent une session valide.
$publicActions = ['active'];
$currentAction = $_GET['action'] ?? '';
if (!in_array($currentAction, $publicActions, true)) {
    requireAuth(true); // répond JSON 401 si non authentifié
}
// ─────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/conf/db.php';

$DATA_DIR  = __DIR__ . '/data/';
$UPLOAD_DIR = $DATA_DIR . 'Upload/';
$ACTIVE_FILE = $DATA_DIR . 'active.txt';

// Fichiers attendus dans Upload/
$EXPECTED = [
    'stock'  => 'stock.xlsx',
    'achats' => 'achats.xlsx',
    'ventes' => 'ventes.xlsx',
];

/* ── Utilitaires ─────────────────────────────────────────────────── */

function jsonOk(array $data): void  { echo json_encode(array_merge(['ok' => true],  $data)); exit; }
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Lit le nom du dataset actif depuis active.txt */
function getActiveDataset(): ?string {
    global $ACTIVE_FILE, $DATA_DIR;
    if (file_exists($ACTIVE_FILE)) {
        $v = trim(file_get_contents($ACTIVE_FILE));
        if ($v !== '' && is_dir($DATA_DIR . $v)) return $v;
    }
    return null;
}

/** Écrit le nom du dataset actif dans active.txt */
function setActiveDataset(string $name): void {
    global $ACTIVE_FILE;
    file_put_contents($ACTIVE_FILE, $name);
}

/** Lit les métadonnées d'un snapshot */
function readMeta(string $datasetPath): ?array {
    $f = $datasetPath . '/_meta.json';
    if (!file_exists($f)) return null;
    // Déchiffrement transparent de la méta si configuré
    $content = decryptFileContent($f);
    return json_decode($content, true);
}

/** Formate un label auto depuis un timestamp de dossier (YYYYMMDD-HHMMSS) */
function autoLabel(string $ts): string {
    $moisFr = ['','Janvier','Février','Mars','Avril','Mai','Juin',
                'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    $dt = DateTime::createFromFormat('Ymd-His', $ts);
    if (!$dt) return 'Export ' . $ts;
    return 'Export ' . $moisFr[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

/** Supprime récursivement un dossier */
function rmdirAll(string $dir): bool {
    if (!is_dir($dir)) return false;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? rmdirAll($p) : unlink($p);
    }
    return rmdir($dir);
}

/** Calcule la taille totale d'un dossier (Ko) */
function dirSizeKo(string $dir): int {
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $size += $f->getSize();
    }
    return (int)round($size / 1024);
}

/** Compatibilité PHP 7.x */
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool {
        return $n === '' || substr($h, -strlen($n)) === $n;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool {
        return strncmp($h, $n, strlen($n)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool {
        return $n === '' || strpos($h, $n) !== false;
    }
}

/**
 * Importe un dataset (généré) depuis ses fichiers JSON vers la BDD.
 *
 * @param PDO $pdo Connexion PDO
 * @param string $datasetDir Chemin absolu du dossier du dataset
 * @param string $slug Identifiant du dataset (slug)
 * @param bool $isActive Détermine si le dataset doit être marqué comme actif
 * @return bool True en cas de succès, false sinon
 */
function importDatasetToDb(PDO $pdo, string $datasetDir, string $slug, bool $isActive): bool {
    $metaFile = $datasetDir . '/_meta.json';
    $produitsFile = $datasetDir . '/produits.json';
    $monthlyFile = $datasetDir . '/monthly_ventes.json';

    // Déterminer si le dataset doit être généré depuis les XLSX
    $needsGeneration = !file_exists($metaFile) || !file_exists($produitsFile) || !file_exists($monthlyFile);

    if ($needsGeneration) {
        $xlsxFiles = ['stock.xlsx', 'achats.xlsx', 'ventes.xlsx'];
        // Vérifier la présence des fichiers Excel sources
        foreach ($xlsxFiles as $f) {
            if (!file_exists($datasetDir . '/' . $f)) {
                error_log("[tablojs] Replay failed: Fichier $f manquant et dataset non généré dans $datasetDir");
                return false;
            }
        }

        // Décrypter temporairement les fichiers Excel
        $decryptedStates = [];
        foreach ($xlsxFiles as $f) {
            $p = $datasetDir . '/' . $f;
            $content = file_get_contents($p);
            if ($content !== false && str_starts_with($content, 'TABLOJS_ENC:')) {
                $decrypted = decryptData($content);
                file_put_contents($p, $decrypted);
                $decryptedStates[$p] = true;
            }
        }

        // Charger et exécuter le générateur
        require_once __DIR__ . '/generate_data.php';
        ob_start();
        try {
            $result = generateData($datasetDir, $datasetDir);
            $output = ob_get_clean();
        } catch (Exception $e) {
            $output = ob_get_clean();
            // Restaurer l'état chiffré en cas d'erreur
            foreach ($decryptedStates as $p => $state) {
                encryptFile($p);
            }
            error_log("[tablojs] Replay generation failed: " . $e->getMessage() . "\n" . $output);
            return false;
        }

        // Créer les métadonnées _meta.json
        $ts = basename($datasetDir);
        $tsClean = str_replace('-dataset', '', $ts);
        $meta = [
            'id'           => $ts,
            'timestamp'    => date('Y-m-d\TH:i:s'),
            'label'        => autoLabel($tsClean),
            'generated_by' => 'generate_data.php v3 (rejoué)',
            'generated_at' => date('Y-m-d H:i:s'),
            'sources'      => $result['sources'] ?? [],
            'stats'        => $result['stats']   ?? [],
            'log'          => $output,
        ];
        file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Chiffrer tous les fichiers générés et restaurés
        foreach (scandir($datasetDir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            encryptFile($datasetDir . '/' . $f);
        }
    }

    $meta = json_decode(decryptFileContent($metaFile), true);
    if (!$meta) {
        return false;
    }

    $label = $meta['label'] ?? ('Export ' . substr($slug, 0, 13));
    $genAt = $meta['generated_at'] ?? date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        if ($isActive) {
            $pdo->exec('UPDATE tablojs_dataset SET is_active = 0');
        }

        // Insérer ou mettre à jour le dataset
        $stmtDataset = $pdo->prepare(
            'INSERT INTO tablojs_dataset (slug, label, generated_at, generated_by, is_active, sources, stats, log_output)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
                label = VALUES(label),
                is_active = VALUES(is_active),
                sources = VALUES(sources),
                stats = VALUES(stats),
                log_output = VALUES(log_output)'
        );
        $stmtDataset->execute([
            $slug,
            $label,
            $genAt,
            $meta['generated_by'] ?? 'upload.php',
            $isActive ? 1 : 0,
            json_encode($meta['sources'] ?? []),
            json_encode($meta['stats']   ?? []),
            isset($meta['log']) ? substr($meta['log'], 0, 65535) : null,
        ]);

        // Récupérer l'identifiant du dataset inséré/mis à jour
        $stmtGetDataset = $pdo->prepare('SELECT rowid FROM tablojs_dataset WHERE slug = ?');
        $stmtGetDataset->execute([$slug]);
        $datasetId = $stmtGetDataset->fetchColumn();
        if (!$datasetId) {
            throw new Exception("Impossible de récupérer l'identifiant du dataset.");
        }

        // Nettoyer les dépendances préexistantes pour éviter les doublons lors d'un ré-import
        $stmtDelStats = $pdo->prepare('DELETE FROM tablojs_product_stat WHERE fk_dataset = ?');
        $stmtDelStats->execute([$datasetId]);
        
        $stmtDelMonthly = $pdo->prepare('DELETE FROM tablojs_monthly_vente WHERE fk_dataset = ?');
        $stmtDelMonthly->execute([$datasetId]);

        $stmtDelMonthlyGlobal = $pdo->prepare('DELETE FROM tablojs_monthly_vente_global WHERE fk_dataset = ?');
        $stmtDelMonthlyGlobal->execute([$datasetId]);

        // Lire produits.json du snapshot
        $produitsFile = $datasetDir . '/produits.json';
        if (file_exists($produitsFile)) {
            $produits = json_decode(decryptFileContent($produitsFile), true);
            if ($produits && is_array($produits)) {
                $stmtProduct = $pdo->prepare(
                    'INSERT INTO tablojs_product (ref, des, is_transport)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE des = VALUES(des), is_transport = VALUES(is_transport)'
                );
                $stmtGetProduct = $pdo->prepare('SELECT rowid FROM tablojs_product WHERE ref = ?');
                
                $stmtStat = $pdo->prepare(
                    'INSERT INTO tablojs_product_stat
                       (fk_dataset, fk_product, abc, stock_qty, mois_stock, vente_montant, vente_nb, achat_montant, achat_nb, wilson)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                
                $stmtMonthly = $pdo->prepare(
                    'INSERT INTO tablojs_monthly_vente (fk_dataset, fk_product, mois, montant)
                     VALUES (?, ?, ?, ?)'
                );

                foreach ($produits as $p) {
                    $ref = trim($p['ref'] ?? '');
                    if ($ref === '') {
                        continue;
                    }

                    $stmtProduct->execute([
                        $ref,
                        $p['des'] ?? '',
                        (int)($p['is_transport'] ?? 0)
                    ]);

                    $stmtGetProduct->execute([$ref]);
                    $productId = $stmtGetProduct->fetchColumn();
                    if (!$productId) {
                        continue;
                    }

                    $stmtStat->execute([
                        $datasetId,
                        $productId,
                        $p['abc']           ?? 'C',
                        $p['stock_qty']     ?? 0,
                        $p['mois_stock']    ?? null,
                        $p['vente_montant'] ?? 0,
                        $p['vente_nb']      ?? 0,
                        $p['achat_montant'] ?? 0,
                        $p['achat_nb']      ?? 0,
                        isset($p['wilson']) ? json_encode($p['wilson']) : null,
                    ]);

                    if (!empty($p['monthly_ventes']) && (is_array($p['monthly_ventes']) || is_object($p['monthly_ventes']))) {
                        foreach ($p['monthly_ventes'] as $mois => $montant) {
                            $stmtMonthly->execute([
                                $datasetId,
                                $productId,
                                $mois,
                                $montant
                            ]);
                        }
                    }
                }
            }
        }

        // Lire les ventes mensuelles globales
        $mvFile = $datasetDir . '/monthly_ventes.json';
        if (file_exists($mvFile)) {
            $mv = json_decode(decryptFileContent($mvFile), true);
            if ($mv && is_array($mv)) {
                $stmtMonthlyGlobal = $pdo->prepare(
                    'INSERT INTO tablojs_monthly_vente_global (fk_dataset, mois, montant)
                     VALUES (?, ?, ?)'
                );
                foreach ($mv as $mois => $montant) {
                    $stmtMonthlyGlobal->execute([
                        $datasetId,
                        $mois,
                        $montant
                    ]);
                }
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[tablojs] importDatasetToDb failed: ' . $e->getMessage());
        return false;
    }
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : status — Statut des fichiers dans Upload/
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    @mkdir($UPLOAD_DIR, 0755, true);
    $status = [];
    foreach ($EXPECTED as $key => $filename) {
        $path = $UPLOAD_DIR . $filename;
        $status[$key] = file_exists($path)
            ? ['present' => true,  'size' => filesize($path), 'modified' => date('Y-m-d H:i:s', filemtime($path))]
            : ['present' => false];
    }
    $active = null;
    $pdo = DB::get();
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $active = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            error_log('[tablojs] status action active dataset query failed: ' . $e->getMessage());
        }
    }
    if (!$active) {
        $active = getActiveDataset();
    }
    $lastGen = null;
    if ($active) {
        $meta = readMeta($DATA_DIR . $active);
        $lastGen = $meta['generated_at'] ?? null;
    }
    jsonOk(['files' => $status, 'active' => $active, 'last_gen' => $lastGen, 'json_ok' => (bool)$active]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : upload — Sauvegarde un xlsx dans Upload/
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    @mkdir($UPLOAD_DIR, 0755, true);
    $key = $_GET['file'] ?? '';
    if (!array_key_exists($key, $EXPECTED)) jsonErr("Clé de fichier inconnue: $key");
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonErr('Erreur upload: ' . ($_FILES['file']['error'] ?? 'aucun fichier'));
    }

    // Validation MIME réelle via magic bytes (pas la seule extension)
    if (extension_loaded('fileinfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['file']['tmp_name']);
        // Les XLSX sont des ZIP contenant XML ; finfo retourne l'un ou l'autre selon la version
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream', // WAMP parfois retourne ce type générique pour les XLSX
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            jsonErr("Type de fichier non autorisé : $mime. Seuls les fichiers .xlsx sont acceptés.");
        }
    }

    $target = $UPLOAD_DIR . $EXPECTED[$key];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        jsonErr("Impossible d'écrire $target", 500);
    }
    jsonOk(['file' => $EXPECTED[$key], 'size' => filesize($target), 'modified' => date('Y-m-d H:i:s', filemtime($target))]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : generate — Crée un snapshot horodaté complet
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'generate') {
    @mkdir($UPLOAD_DIR, 0755, true);

    // Vérifier les fichiers dans Upload/
    $missing = [];
    foreach ($EXPECTED as $key => $filename) {
        if (!file_exists($UPLOAD_DIR . $filename)) $missing[] = $filename;
    }
    if ($missing) jsonErr('Fichiers manquants dans Upload/ : ' . implode(', ', $missing));

    // Créer le dossier horodaté
    $ts = date('Ymd-His');
    $datasetName = $ts . '-dataset';
    $datasetDir  = $DATA_DIR . $datasetName;
    if (!mkdir($datasetDir, 0755, true)) jsonErr("Impossible de créer $datasetDir", 500);

    // Copier les xlsx dans le dossier (on garde Upload/ propre après)
    foreach ($EXPECTED as $filename) {
        copy($UPLOAD_DIR . $filename, $datasetDir . '/' . $filename);
    }

    // Générer les JSON dans le dossier snapshot
    ob_start();
    try {
        require_once __DIR__ . '/generate_data.php';
        $result = generateData($datasetDir, $datasetDir);
        $output = ob_get_clean();
    } catch (Throwable $e) {
        $output = ob_get_clean();
        rmdirAll($datasetDir); // rollback
        jsonErr('Erreur génération : ' . $e->getMessage() . "\n" . $output, 500);
    }

    // Écrire _meta.json
    $labelTs = substr($ts, 0, 13); // "YYYYMMDD-HHMM" → pour autoLabel
    $meta = [
        'id'           => $datasetName,
        'timestamp'    => date('Y-m-d\TH:i:s'),
        'label'        => autoLabel($ts),
        'generated_by' => 'generate_data.php v3',
        'generated_at' => date('Y-m-d H:i:s'),
        'sources'      => $result['sources'] ?? [],
        'stats'        => $result['stats']   ?? [],
        'log'          => $output,
    ];
    file_put_contents($datasetDir . '/_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // Chiffrement de tous les fichiers du snapshot généré
    foreach (scandir($datasetDir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        encryptFile($datasetDir . '/' . $f);
    }

    // Nettoyer Upload/
    foreach ($EXPECTED as $filename) {
        @unlink($UPLOAD_DIR . $filename);
    }

    // Transférer dans la base de données et l'activer en BDD si disponible
    $pdo = DB::get();
    $dbImported = false;
    if ($pdo) {
        $dbImported = importDatasetToDb($pdo, $datasetDir, $datasetName, true);
    } else {
        // Mode dégradé : activer via active.txt
        setActiveDataset($datasetName);
    }

    jsonOk([
        'dataset'      => $datasetName,
        'label'        => $meta['label'],
        'generated_at' => $meta['generated_at'],
        'log'          => $output,
        'stats'        => $meta['stats'],
        'db_imported'  => $dbImported,
    ]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : list — Liste tous les snapshots disponibles
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list') {
    $active = null;
    $pdo = DB::get();
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $active = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            error_log('[tablojs] list action active dataset query failed: ' . $e->getMessage());
        }
    }
    if (!$active) {
        $active = getActiveDataset();
    }
    $datasets = [];
    foreach (scandir($DATA_DIR, 1) as $dir) { // 1 = SCANDIR_FLAG_DESCENDING
        if (!str_ends_with($dir, '-dataset')) continue;
        $fullPath = $DATA_DIR . $dir;
        if (!is_dir($fullPath)) continue;
        $meta = readMeta($fullPath);
        if (!$meta) {
            // Créer un meta minimal si absent
            $meta = ['id' => $dir, 'label' => autoLabel(substr($dir, 0, 13)), 'generated_at' => '?'];
        }
        $datasets[] = array_merge($meta, [
            'id'       => $dir,
            'active'   => ($dir === $active),
            'size_ko'  => dirSizeKo($fullPath),
            // Ne pas retourner le log complet dans la liste (trop lourd)
            'log'      => substr($meta['log'] ?? '', 0, 200),
        ]);
    }
    jsonOk(['datasets' => $datasets, 'active' => $active]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : active — Retourne le dataset actif (pour le front)
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['action']) ? $_GET['action'] : '') === 'active') {
    $active = null;
    $label = 'Aucun dataset';
    $date = '?';
    $stats = [];
    $path = 'data/';

    $pdo = DB::get();
    $dbActive = null;
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug, label, generated_at, stats FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $dbActive = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[tablojs] failed to query active dataset: ' . $e->getMessage());
        }
    }

    if ($dbActive) {
        $active = $dbActive['slug'];
        $label = $dbActive['label'];
        $date = $dbActive['generated_at'];
        $stats = json_decode($dbActive['stats'] ?? '[]', true);
        $path = 'data/' . $active . '/';
    } else {
        $active = getActiveDataset();
        if (!$active) {
            foreach (scandir($DATA_DIR, 1) as $dir) {
                if (str_ends_with($dir, '-dataset') && is_dir($DATA_DIR . $dir)) {
                    $active = $dir;
                    setActiveDataset($active);
                    break;
                }
            }
        }
        if ($active) {
            $meta = readMeta($DATA_DIR . $active);
            $label = $meta['label']        ?? autoLabel(substr($active, 0, 13));
            $date  = $meta['generated_at'] ?? '?';
            $stats = $meta['stats']        ?? [];
            $path  = 'data/' . $active . '/';
        }
    }

    jsonOk([
        'dataset' => $active,
        'label'   => $label,
        'date'    => $date,
        'stats'   => $stats,
        'path'    => $path,
    ]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : activate — Change le dataset actif
════════════════════════════════════════════════════════════════════ */
/* ════════════════════════════════════════════════════════════════════
   ACTION : activate — Rejoue (ré-importe) le dataset du disque vers la BDD et l'active
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'activate') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['dataset'] ?? '');
    if (!$name || !is_dir($DATA_DIR . $name)) jsonErr("Dataset introuvable : $name");
    
    $pdo = DB::get();
    if ($pdo) {
        // Force l'import/rejoue complet du dataset en base de données et l'active
        $datasetDir = $DATA_DIR . $name;
        $imported = importDatasetToDb($pdo, $datasetDir, $name, true);
        if (!$imported) {
            jsonErr("Échec de l'import/rejoue du dataset en base de données");
        }
    } else {
        // Mode dégradé / fallback : écriture dans le fichier active.txt uniquement
        setActiveDataset($name);
    }

    $meta = readMeta($DATA_DIR . $name);
    jsonOk(['dataset' => $name, 'label' => $meta['label'] ?? $name]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : list_db — Liste tous les datasets enregistrés en base de données
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list_db') {
    $pdo = DB::get();
    $datasets = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug, label, generated_at, is_active, stats FROM tablojs_dataset ORDER BY generated_at DESC');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats = json_decode($row['stats'] ?? '[]', true);
                $datasets[] = [
                    'id'           => $row['slug'],
                    'label'        => $row['label'],
                    'generated_at' => $row['generated_at'],
                    'active'       => (bool)$row['is_active'],
                    'stats'        => $stats,
                ];
            }
        } catch (Exception $e) {
            error_log('[tablojs] list_db query failed: ' . $e->getMessage());
        }
    }
    jsonOk(['datasets' => $datasets]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : activate_db — Change le dataset actif uniquement en base de données
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'activate_db') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['dataset'] ?? '');
    
    $pdo = DB::get();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE tablojs_dataset SET is_active = 0');
            $stmt = $pdo->prepare('UPDATE tablojs_dataset SET is_active = 1 WHERE slug = ?');
            $stmt->execute([$name]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[tablojs] activate_db failed: ' . $e->getMessage());
            jsonErr("Erreur BDD lors de l'activation : " . $e->getMessage());
        }
    } else {
        jsonErr("Base de données indisponible");
    }

    // Récupérer le label pour le retour
    $label = $name;
    try {
        $stmtLabel = $pdo->prepare('SELECT label FROM tablojs_dataset WHERE slug = ? LIMIT 1');
        $stmtLabel->execute([$name]);
        $label = $stmtLabel->fetchColumn() ?: $name;
    } catch (Exception $e) {}

    jsonOk(['dataset' => $name, 'label' => $label]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : delete_db — Supprime le dataset de la base de données uniquement
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete_db') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['dataset'] ?? '');
    
    $pdo = DB::get();
    if ($pdo) {
        try {
            // Ne pas supprimer le dataset actif
            $stmtCheck = $pdo->prepare('SELECT is_active, rowid FROM tablojs_dataset WHERE slug = ? LIMIT 1');
            $stmtCheck->execute([$name]);
            $row = $stmtCheck->fetch();
            if ($row) {
                if ($row['is_active']) {
                    jsonErr('Impossible de supprimer le dataset actif de la base de données');
                }
                $datasetId = (int)$row['rowid'];
                
                $pdo->beginTransaction();
                
                // Supprimer les données dépendantes
                $stmtDelStats = $pdo->prepare('DELETE FROM tablojs_product_stat WHERE fk_dataset = ?');
                $stmtDelStats->execute([$datasetId]);
                
                $stmtDelMonthly = $pdo->prepare('DELETE FROM tablojs_monthly_vente WHERE fk_dataset = ?');
                $stmtDelMonthly->execute([$datasetId]);
        
                $stmtDelMonthlyGlobal = $pdo->prepare('DELETE FROM tablojs_monthly_vente_global WHERE fk_dataset = ?');
                $stmtDelMonthlyGlobal->execute([$datasetId]);

                $stmtDelDataset = $pdo->prepare('DELETE FROM tablojs_dataset WHERE rowid = ?');
                $stmtDelDataset->execute([$datasetId]);
                
                $pdo->commit();
            } else {
                jsonErr("Dataset introuvable en base de données");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[tablojs] delete_db failed: ' . $e->getMessage());
            jsonErr("Erreur lors de la suppression en base de données : " . $e->getMessage());
        }
    } else {
        jsonErr("Base de données indisponible");
    }
    jsonOk(['deleted' => $name]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : rename — Modifie le label d'un snapshot
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'rename') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name  = trim($data['dataset'] ?? '');
    $label = trim($data['label']   ?? '');
    if (!$name || !is_dir($DATA_DIR . $name)) jsonErr("Dataset introuvable : $name");
    if ($label === '') jsonErr('Label vide non autorisé');
    
    $metaFile = $DATA_DIR . $name . '/_meta.json';
    $meta = file_exists($metaFile) ? json_decode(decryptFileContent($metaFile), true) : [];
    $meta['label'] = $label;
    file_put_contents($metaFile, encryptData(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
    
    $pdo = DB::get();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('UPDATE tablojs_dataset SET label = ? WHERE slug = ?');
            $stmt->execute([$label, $name]);
        } catch (Exception $e) {
            error_log('[tablojs] rename db update failed: ' . $e->getMessage());
        }
    }

    jsonOk(['dataset' => $name, 'label' => $label]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION : delete — Supprime un snapshot (pas le dataset actif)
════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['dataset'] ?? '');
    if (!$name || !str_ends_with($name, '-dataset')) jsonErr('Nom de dataset invalide');
    if (!is_dir($DATA_DIR . $name)) jsonErr("Dataset introuvable : $name");
    $active = null;
    $pdo = DB::get();
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $active = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            error_log('[tablojs] delete action active dataset query failed: ' . $e->getMessage());
        }
    }
    if (!$active) {
        $active = getActiveDataset();
    }
    if ($name === $active) jsonErr('Impossible de supprimer le dataset actif');
    
    rmdirAll($DATA_DIR . $name);
    
    $pdo = DB::get();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('DELETE FROM tablojs_dataset WHERE slug = ?');
            $stmt->execute([$name]);
        } catch (Exception $e) {
            error_log('[tablojs] delete db update failed: ' . $e->getMessage());
        }
    }

    jsonOk(['deleted' => $name]);
}

/* ════════════════════════════════════════════════════════════════════
   ACTION inconnue
════════════════════════════════════════════════════════════════════ */
jsonErr('Action inconnue', 404);
