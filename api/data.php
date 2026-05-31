<?php
/**
 * tablojs — Proxy sécurisé pour les fichiers JSON
 *
 * Usage : api/data.php?dataset=20260523-224300-dataset&file=produits.json
 *         api/data.php?action=active   (retransmet upload.php?action=active)
 *
 * Remplace l'accès direct à data/{dataset}/{file}.json
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../conf/db.php';

// Vérification de la session (répond en JSON si non authentifié)
requireAuth(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$action  = $_GET['action']  ?? '';
$dataset = $_GET['dataset'] ?? '';
$file    = $_GET['file']    ?? '';

// ─── Action spéciale : dataset actif ──────────────────────────────────────
if ($action === 'active') {
    // Tente de récupérer le dataset actif depuis la base de données
    $pdo = DB::get();
    $active = null;
    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT slug FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $active = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            error_log('[tablojs] active dataset query failed: ' . $e->getMessage());
        }
    }

    // Fallback fichier si indisponible ou absent en base
    if (!$active) {
        $activeFile = realpath(__DIR__ . '/../data/active.txt');
        $dataBase   = realpath(__DIR__ . '/../data');
        if ($activeFile && file_exists($activeFile)) {
            $v = trim(file_get_contents($activeFile));
            if ($v !== '' && $dataBase && is_dir($dataBase . DIRECTORY_SEPARATOR . $v)) {
                $active = $v;
            }
        }
    }
    echo json_encode(['ok' => true, 'active' => $active]);
    exit;
}

// ─── Accès fichier JSON ────────────────────────────────────────────────────
if (empty($file)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètre file manquant']);
    exit;
}

// Sécurité : interdire path traversal
$file    = basename($file);
$dataset = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dataset);

// Whitelist d'extensions — seuls les fichiers JSON sont servis par ce proxy
if (!str_ends_with(strtolower($file), '.json')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Type de fichier non autorisé']);
    exit;
}

// ─── Tentative de lecture en Base de Données ────────────────────────────────
$pdo = DB::get();
$datasetId = null;

if ($pdo) {
    try {
        if (!empty($dataset)) {
            // Recherche par le slug spécifié
            $stmt = $pdo->prepare('SELECT rowid FROM tablojs_dataset WHERE slug = ? LIMIT 1');
            $stmt->execute([$dataset]);
            $row = $stmt->fetch();
            if ($row) {
                $datasetId = (int)$row['rowid'];
            }
        } else {
            // Recherche du dataset actif par défaut
            $stmt = $pdo->query('SELECT rowid FROM tablojs_dataset WHERE is_active = 1 LIMIT 1');
            $row = $stmt->fetch();
            if ($row) {
                $datasetId = (int)$row['rowid'];
            }
        }
    } catch (Exception $e) {
        error_log('[tablojs] Failed to query dataset in api/data.php: ' . $e->getMessage());
    }
}

// Si le dataset a été trouvé en base de données, on sert directement depuis SQL
if ($datasetId !== null) {
    try {
        switch ($file) {
            case 'kpis.json':
                serveKpisFromDb($pdo, $datasetId);
                exit;
            case 'monthly_ventes.json':
                serveMonthlyVentesFromDb($pdo, $datasetId);
                exit;
            case 'produits.json':
                serveProduitsFromDb($pdo, $datasetId);
                exit;
            case 'top_ventes_montant.json':
            case 'top_ventes_freq.json':
            case 'top_achats_montant.json':
            case 'top_achats_freq.json':
                serveTopsFromDb($pdo, $datasetId, $file);
                exit;
        }
    } catch (Exception $e) {
        error_log('[tablojs] Error serving ' . $file . ' from database: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur de base de données : ' . $e->getMessage()]);
        exit;
    }
}

// Si la base de données est disponible mais que le dataset n'a pas été trouvé, on n'autorise pas le fallback fichier
if ($pdo !== null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "Le dataset n'est pas disponible en base de données."]);
    exit;
}

// ─── Fallback : Accès fichier physique ──────────────────────────────────────
$basePath = realpath(__DIR__ . '/../data');
if (!$basePath) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Dossier data introuvable']);
    exit;
}

$filePath = $dataset
    ? $basePath . DIRECTORY_SEPARATOR . $dataset . DIRECTORY_SEPARATOR . $file
    : $basePath . DIRECTORY_SEPARATOR . $file;

$realPath = realpath($filePath);

// Vérification anti-traversal
if (!$realPath || strpos($realPath, $basePath) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']);
    exit;
}

if (!is_file($realPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Fichier non trouvé : ' . $file]);
    exit;
}

// Déchiffrement transparent du fichier avant envoi
$decrypted = decryptFileContent($realPath);
if ($decrypted === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur de lecture du fichier']);
    exit;
}
echo $decrypted;

/* ── Fonctions Métier (Service BDD) ──────────────────────────────── */

/**
 * Associe les ventes mensuelles aux produits récupérés depuis la BDD.
 *
 * Exécute une seule requête SQL groupée pour associer les historiques de vente
 * mensuels aux articles ciblés, afin d'optimiser le temps de réponse et d'éviter le N+1.
 *
 * @param PDO $pdo Connexion à la base de données
 * @param int $datasetId Identifiant numérique du dataset
 * @param array $products Référence du tableau des produits à enrichir
 * @return void
 */
function attachMonthlySalesToProducts(PDO $pdo, int $datasetId, array &$products): void {
    if (empty($products)) {
        return;
    }

    $productIds = array_column($products, 'fk_product');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $stmt = $pdo->prepare("
        SELECT fk_product, mois, montant 
        FROM tablojs_monthly_vente 
        WHERE fk_dataset = ? AND fk_product IN ($placeholders)
        ORDER BY mois ASC
    ");

    $params = array_merge([$datasetId], $productIds);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Indexation par produit
    $salesByProduct = [];
    foreach ($sales as $s) {
        $pid = $s['fk_product'];
        if (!isset($salesByProduct[$pid])) {
            $salesByProduct[$pid] = [];
        }
        $salesByProduct[$pid][$s['mois']] = (float)$s['montant'];
    }

    // Reconstruction de la structure de l'objet produit
    foreach ($products as &$p) {
        $pid = $p['fk_product'];
        $p['monthly_ventes'] = isset($salesByProduct[$pid]) ? $salesByProduct[$pid] : (object)[];
        unset($p['fk_product']);

        $p['is_transport'] = (int)$p['is_transport'];
        $p['stock_qty']    = (float)$p['stock_qty'];
        if ($p['mois_stock'] !== null) {
            $p['mois_stock'] = (float)$p['mois_stock'];
        }
        $p['vente_montant'] = (float)$p['vente_montant'];
        $p['vente_nb']      = (int)$p['vente_nb'];
        $p['achat_montant'] = (float)$p['achat_montant'];
        $p['achat_nb']      = (int)$p['achat_nb'];

        if ($p['wilson'] !== null) {
            $p['wilson'] = json_decode($p['wilson'], true);
        }
    }
    unset($p);
}

/**
 * Construit et retourne le fichier kpis.json depuis la base de données.
 *
 * @param PDO $pdo Connexion à la base de données
 * @param int $datasetId Identifiant numérique du dataset
 * @return void
 */
function serveKpisFromDb(PDO $pdo, int $datasetId): void {
    $stmt = $pdo->prepare('SELECT label, generated_at, sources FROM tablojs_dataset WHERE rowid = ?');
    $stmt->execute([$datasetId]);
    $ds = $stmt->fetch();
    if (!$ds) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Dataset introuvable']);
        exit;
    }

    $sources = json_decode($ds['sources'] ?? '[]', true);

    // Compte des références en stock
    $stmtStock = $pdo->prepare('SELECT COUNT(*) FROM tablojs_product_stat WHERE fk_dataset = ? AND stock_qty <> 0');
    $stmtStock->execute([$datasetId]);
    $nbRefsStock = (int)$stmtStock->fetchColumn();

    // Compte des références vendues
    $stmtVentes = $pdo->prepare('SELECT COUNT(*) FROM tablojs_product_stat WHERE fk_dataset = ? AND vente_montant > 0');
    $stmtVentes->execute([$datasetId]);
    $nbRefsVendues = (int)$stmtVentes->fetchColumn();

    // Compte des références achetées
    $stmtAchats = $pdo->prepare('SELECT COUNT(*) FROM tablojs_product_stat WHERE fk_dataset = ? AND achat_montant > 0');
    $stmtAchats->execute([$datasetId]);
    $nbRefsAchetees = (int)$stmtAchats->fetchColumn();

    // CA Ventes total
    $stmtCaVentes = $pdo->prepare('SELECT SUM(vente_montant) FROM tablojs_product_stat WHERE fk_dataset = ?');
    $stmtCaVentes->execute([$datasetId]);
    $caVentesTotal = round((float)$stmtCaVentes->fetchColumn(), 2);

    // CA Achats total
    $stmtCaAchats = $pdo->prepare('SELECT SUM(achat_montant) FROM tablojs_product_stat WHERE fk_dataset = ?');
    $stmtCaAchats->execute([$datasetId]);
    $caAchatsTotal = round((float)$stmtCaAchats->fetchColumn(), 2);

    // CA Ventes physiques uniquement
    $stmtCaVentesProd = $pdo->prepare('
        SELECT SUM(s.vente_montant) 
        FROM tablojs_product_stat s 
        JOIN tablojs_product p ON s.fk_product = p.rowid 
        WHERE s.fk_dataset = ? AND p.is_transport = 0
    ');
    $stmtCaVentesProd->execute([$datasetId]);
    $caVentesProduits = round((float)$stmtCaVentesProd->fetchColumn(), 2);

    // CA Achats physiques uniquement
    $stmtCaAchatsProd = $pdo->prepare('
        SELECT SUM(s.achat_montant) 
        FROM tablojs_product_stat s 
        JOIN tablojs_product p ON s.fk_product = p.rowid 
        WHERE s.fk_dataset = ? AND p.is_transport = 0
    ');
    $stmtCaAchatsProd->execute([$datasetId]);
    $caAchatsProduits = round((float)$stmtCaAchatsProd->fetchColumn(), 2);

    // Statistiques par classe ABC
    $stmtAbc = $pdo->prepare('
        SELECT abc, COUNT(*) as cnt, SUM(vente_montant) as sum_vente 
        FROM tablojs_product_stat 
        WHERE fk_dataset = ? AND abc IN (\'A\', \'B\', \'C\') 
        GROUP BY abc
    ');
    $stmtAbc->execute([$datasetId]);
    $abcRows = $stmtAbc->fetchAll();

    $abcStats = [
        'A' => ['count' => 0, 'montant' => 0.0],
        'B' => ['count' => 0, 'montant' => 0.0],
        'C' => ['count' => 0, 'montant' => 0.0],
    ];
    foreach ($abcRows as $r) {
        $abcStats[$r['abc']] = [
            'count'   => (int)$r['cnt'],
            'montant' => round((float)$r['sum_vente'], 2)
        ];
    }

    // Période globale du dataset
    $stmtPeriod = $pdo->prepare('SELECT MIN(mois) as debut, MAX(mois) as fin FROM tablojs_monthly_vente_global WHERE fk_dataset = ?');
    $stmtPeriod->execute([$datasetId]);
    $period = $stmtPeriod->fetch();

    // Nombre d'années du dataset
    $stmtMoisCount = $pdo->prepare('SELECT COUNT(DISTINCT mois) FROM tablojs_monthly_vente_global WHERE fk_dataset = ?');
    $stmtMoisCount->execute([$datasetId]);
    $nbMois = (int)$stmtMoisCount->fetchColumn();
    $nbAnnees = max(round($nbMois / 12, 1), 1.0);

    $kpis = [
        'nb_refs_stock'      => $nbRefsStock,
        'nb_refs_vendues'    => $nbRefsVendues,
        'nb_refs_achetees'   => $nbRefsAchetees,
        'ca_ventes_total'    => $caVentesTotal,
        'ca_achats_total'    => $caAchatsTotal,
        'ca_ventes_produits' => $caVentesProduits,
        'ca_achats_produits' => $caAchatsProduits,
        'periode'            => [
            'debut' => $period['debut'] ?? '',
            'fin'   => $period['fin'] ?? '',
        ],
        'abc'          => $abcStats,
        'nb_annees'    => $nbAnnees,
        'generated_at' => substr($ds['generated_at'], 0, 10),
        'sources'      => $sources,
    ];

    echo json_encode($kpis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Construit et retourne le fichier monthly_ventes.json depuis la base de données.
 *
 * @param PDO $pdo Connexion à la base de données
 * @param int $datasetId Identifiant numérique du dataset
 * @return void
 */
function serveMonthlyVentesFromDb(PDO $pdo, int $datasetId): void {
    $stmt = $pdo->prepare('SELECT mois, montant FROM tablojs_monthly_vente_global WHERE fk_dataset = ? ORDER BY mois ASC');
    $stmt->execute([$datasetId]);
    $rows = $stmt->fetchAll();

    $res = [];
    foreach ($rows as $r) {
        $res[$r['mois']] = (float)$r['montant'];
    }

    if (empty($res)) {
        echo '{}';
    } else {
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

/**
 * Construit et retourne le fichier produits.json depuis la base de données.
 *
 * Filtre les produits actifs (avec stock, vente ou achat) triés par CA ventes décroissant.
 *
 * @param PDO $pdo Connexion à la base de données
 * @param int $datasetId Identifiant numérique du dataset
 * @return void
 */
function serveProduitsFromDb(PDO $pdo, int $datasetId): void {
    $stmt = $pdo->prepare('
        SELECT 
            p.ref,
            p.des,
            p.is_transport,
            s.abc,
            s.stock_qty,
            s.mois_stock,
            s.vente_montant,
            s.vente_nb,
            s.achat_montant,
            s.achat_nb,
            s.wilson,
            s.fk_product
        FROM tablojs_product_stat s
        JOIN tablojs_product p ON s.fk_product = p.rowid
        WHERE s.fk_dataset = ? AND (s.vente_montant > 0 OR s.achat_montant > 0 OR s.stock_qty <> 0)
        ORDER BY s.vente_montant DESC
    ');
    $stmt->execute([$datasetId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    attachMonthlySalesToProducts($pdo, $datasetId, $products);

    echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Construit et retourne les fichiers tops (ex: top_ventes_montant.json) depuis la BDD.
 *
 * Exclut les produits de type transport et limite le résultat aux 20 premiers.
 *
 * @param PDO $pdo Connexion à la base de données
 * @param int $datasetId Identifiant numérique du dataset
 * @param string $filename Nom du fichier demandé
 * @return void
 */
function serveTopsFromDb(PDO $pdo, int $datasetId, string $filename): void {
    $sql = '
        SELECT 
            p.ref,
            p.des,
            p.is_transport,
            s.abc,
            s.stock_qty,
            s.mois_stock,
            s.vente_montant,
            s.vente_nb,
            s.achat_montant,
            s.achat_nb,
            s.wilson,
            s.fk_product
        FROM tablojs_product_stat s
        JOIN tablojs_product p ON s.fk_product = p.rowid
        WHERE s.fk_dataset = ? AND p.is_transport = 0
    ';

    switch ($filename) {
        case 'top_ventes_montant.json':
            $sql .= ' AND s.vente_montant > 0 ORDER BY s.vente_montant DESC LIMIT 20';
            break;
        case 'top_ventes_freq.json':
            $sql .= ' AND s.vente_nb > 0 ORDER BY s.vente_nb DESC LIMIT 20';
            break;
        case 'top_achats_montant.json':
            $sql .= ' AND s.achat_montant > 0 ORDER BY s.achat_montant DESC LIMIT 20';
            break;
        case 'top_achats_freq.json':
            $sql .= ' AND s.achat_nb > 0 ORDER BY s.achat_nb DESC LIMIT 20';
            break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Fichier top inconnu']);
            exit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$datasetId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    attachMonthlySalesToProducts($pdo, $datasetId, $products);

    echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
