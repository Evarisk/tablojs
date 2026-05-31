<?php
/**
 * generate_data.php — tablojs Stock Dashboard
 * Lit les 3 fichiers Excel (.xlsx) et génère les JSON du dashboard.
 *
 * Pré-requis PHP : ZipArchive, SimpleXML (activés par défaut sur WAMP)
 * Usage CLI : php generate_data.php
 * Usage web : include depuis upload.php → appeler generateData()
 */

/* Compatibilité PHP 7.4 */
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool { return $n==='' || substr($h,-strlen($n))===$n; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h,$n,strlen($n))===0; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n==='' || strpos($h,$n)!==false; }
}


/* ═══════════════════════════════════════════════════════════════════
   XLSX READER — Pure PHP, sans dépendance externe
   Lit un .xlsx via ZipArchive + SimpleXML (format Office Open XML)
═══════════════════════════════════════════════════════════════════ */

/**
 * Lit la première feuille d'un fichier .xlsx
 * Retourne un tableau de tableaux (lignes × colonnes), en-tête incluse.
 */
function xlsxRead(string $path): array {
    if (!file_exists($path)) {
        throw new RuntimeException("Fichier introuvable : $path");
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("Extension ZipArchive manquante (activer dans php.ini)");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Impossible d'ouvrir : $path");
    }

    /* 1 — Shared Strings (les cellules texte sont stockées par index) */
    $ss = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ssXml = _loadXml($ssRaw);
        if ($ssXml) {
            foreach ($ssXml->si as $si) {
                if (isset($si->r)) {
                    // Rich text : concaténer tous les <r><t>
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string)($r->t ?? '');
                    }
                    $ss[] = $t;
                } else {
                    $ss[] = (string)($si->t ?? '');
                }
            }
        }
    }

    /* 2 — Trouver le fichier de la feuille contenant les données
       (les fichiers ont 2 feuilles : sheet1 = requête SQL, sheet2 = données) */
    $sheetFiles = [];
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsRaw !== false) {
        $relsXml = _loadXml($relsRaw);
        if ($relsXml) {
            foreach ($relsXml->Relationship as $rel) {
                $type = (string)$rel['Type'];
                if (str_ends_with($type, '/worksheet')) {
                    $target = (string)$rel['Target'];
                    $sheetFiles[] = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    if (empty($sheetFiles)) {
        // Fallback : essayer sheet1 puis sheet2
        $sheetFiles = ['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'];
    }
    // Sor par nom (sheet1, sheet2…) pour avoir un ordre déterministe
    sort($sheetFiles);

    /* 3 — Lire les feuilles et garder celle avec le plus de lignes (les données) */
    $bestSheet = null;
    $bestRowCount = 0;
    $bestSheetRaw = null;

    foreach ($sheetFiles as $sf) {
        $raw = $zip->getFromName($sf);
        if ($raw === false) continue;
        // Compter les <row> sans parser tout le XML (rapide)
        $rowCount = substr_count($raw, '<row ');
        if ($rowCount > $bestRowCount) {
            $bestRowCount  = $rowCount;
            $bestSheet     = $sf;
            $bestSheetRaw  = $raw;
        }
    }
    $zip->close();

    if ($bestSheetRaw === null) {
        throw new RuntimeException("Aucune feuille lisible dans : $path");
    }
    $sheetRaw = $bestSheetRaw;


    $xml = _loadXml($sheetRaw);
    if (!$xml) {
        throw new RuntimeException("Erreur XML dans : $sheetFile");
    }

    /* 4 — Construire le tableau de données */
    $rows    = [];
    $maxCol  = 0;

    foreach ($xml->sheetData->row as $rowNode) {
        $rowIdx = (int)($rowNode['r'] ?? 1) - 1;
        $cells  = [];

        foreach ($rowNode->c as $c) {
            $ref   = (string)($c['r'] ?? '');
            $col   = _colIndex($ref);
            $type  = (string)($c['t'] ?? '');
            $vRaw  = (string)($c->v ?? '');

            if ($type === 's') {
                // Shared string
                $value = isset($ss[(int)$vRaw]) ? $ss[(int)$vRaw] : null;
            } elseif ($type === 'inlineStr') {
                $value = (string)($c->is->t ?? '');
            } elseif ($vRaw === '') {
                $value = null;
            } else {
                // Nombre (int ou float)
                $value = $vRaw + 0;
            }

            $maxCol = max($maxCol, $col);
            $cells[$col] = $value;
        }

        if (!empty($cells)) {
            $full = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $full[] = $cells[$i] ?? null;
            }
            $rows[$rowIdx] = $full;
        }
    }

    ksort($rows);
    return array_values($rows);
}

/** Supprime les namespaces XML pour simplifier le parsing avec SimpleXML */
function _stripNs(string $xml): string {
    // 1. Supprimer les déclarations xmlns:xxx="..."
    $xml = preg_replace('/\s+xmlns(?::\w+)?="[^"]*"/', '', $xml);
    // 2. Supprimer les attributs préfixés (ex: x14ac:dyDescent="..." mc:Ignorable="...")
    $xml = preg_replace('/\s+[\w]+:[\w]+=(?:"[^"]*"|\'[^\']*\')/', '', $xml);
    // 3. Supprimer les balises ouvrantes préfixées <ns:tag → <tag
    $xml = preg_replace('/<([\w]+):/', '<', $xml);
    // 4. Supprimer les balises fermantes préfixées </ns:tag → </tag
    $xml = preg_replace('/<\/([\w]+):/', '</', $xml);
    return $xml;
}

/** Charge un XML en supprimant les erreurs de namespace */
function _loadXml(string $raw): ?SimpleXMLElement {
    $raw = _stripNs($raw);
    return @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
}

/** Convertit une référence de cellule Excel (ex: "AB3") en index de colonne 0-based */
function _colIndex(string $ref): int {
    preg_match('/^([A-Za-z]+)/', $ref, $m);
    if (empty($m[1])) return 0;
    $letters = strtoupper($m[1]);
    $col = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $col = $col * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $col - 1;
}

/* ═══════════════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════════════ */

function _log(string $msg): void {
    echo $msg . "\n";
    flush();
}

function _findFile(string $dataDir, string $preferred, string $keyword, array $exclude = []): ?string {
    $p = $dataDir . DIRECTORY_SEPARATOR . $preferred;
    if (file_exists($p)) return $p;
    foreach (scandir($dataDir) as $f) {
        if (!str_ends_with(strtolower($f), '.xlsx')) continue;
        if (stripos($f, $keyword) === false) continue;
        $skip = false;
        foreach ($exclude as $ex) {
            if (stripos($f, $ex) !== false) { $skip = true; break; }
        }
        if (!$skip) return $dataDir . DIRECTORY_SEPARATOR . $f;
    }
    return null;
}

function _writeJson(string $dataDir, string $name, mixed $data): void {
    $path = $dataDir . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    _log('  OK ' . $name . ' (' . round(filesize($path) / 1024) . ' Ko)');
}

const TRANSPORT_PREFIXES = ['TRANSPORT','TRANSP','TRSEMI','TRANSFRET','TRANSACH',
                            'TRANSINT','TR10T','TRVL','TR5T','TRANSNC','TRANSS'];

function _isTransport(string $ref, string $des): bool {
    $ref = strtoupper($ref);
    $des = strtoupper($des);
    if (str_contains($des, 'TRANSPORT')) return true;
    foreach (TRANSPORT_PREFIXES as $pfx) {
        if (str_starts_with($ref, $pfx)) return true;
    }
    return false;
}

/* ═══════════════════════════════════════════════════════════════════
   FONCTION PRINCIPALE
═══════════════════════════════════════════════════════════════════ */

function generateData(string $sourceDir = '', string $outputDir = ''): array {
    if ($sourceDir === '') {
        $sourceDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    }
    if ($outputDir === '') {
        $outputDir = $sourceDir; // écrire au même endroit que les sources
    }

    $startTime = microtime(true);
    $log = [];
    $out = function(string $m) use (&$log) { _log($m); $log[] = $m; };



    /* ── 1. STOCK ─────────────────────────────────────────── */
    $out('[1/7] Chargement stock...');
    $stockFile = _findFile($sourceDir, 'stock.xlsx', 'stock', ['achat','vente']);
    if (!$stockFile) throw new RuntimeException("Fichier stock introuvable dans data/Upload/");

    $stockRows = xlsxRead($stockFile);
    array_shift($stockRows); // supprimer en-tête

    $stock = [];
    foreach ($stockRows as $r) {
        $ref = isset($r[0]) ? trim((string)$r[0]) : '';
        $qty = $r[1] ?? 0;
        if ($ref !== '') $stock[$ref] = (float)$qty;
    }
    $rowsStock = count($stockRows);
    $out('  ' . count($stock) . ' références en stock (' . basename($stockFile) . ')');

    /* ── 2. ACHATS ────────────────────────────────────────── */
    $out('[2/7] Chargement achats...');
    $achatsFile = _findFile($sourceDir, 'achats.xlsx', 'achat');
    if (!$achatsFile) throw new RuntimeException("Fichier achats introuvable dans data/Upload/");

    $achatsRows = xlsxRead($achatsFile);
    array_shift($achatsRows);

    $achatsByProd = [];
    foreach ($achatsRows as $r) {
        $nb      = (int)($r[0] ?? 0);
        $ref     = trim((string)($r[1] ?? ''));
        $des     = trim((string)($r[2] ?? ''));
        $montant = (float)($r[3] ?? 0);
        if ($ref === '') continue;
        if (!isset($achatsByProd[$ref])) {
            $achatsByProd[$ref] = [
                'ref' => $ref, 'des' => $des, 'nb' => 0, 'montant' => 0.0,
                'is_transport' => _isTransport($ref, $des),
            ];
        }
        $achatsByProd[$ref]['nb']      += $nb;
        $achatsByProd[$ref]['montant'] += $montant;
    }
    $rowsAchats = count($achatsRows);
    $out('  ' . count($achatsByProd) . ' références achetées (' . basename($achatsFile) . ')');

    /* ── 3. VENTES ────────────────────────────────────────── */
    $out('[3/7] Chargement ventes...');
    $ventesFile = _findFile($sourceDir, 'ventes.xlsx', 'vente');
    if (!$ventesFile) throw new RuntimeException("Fichier ventes introuvable dans data/Upload/");

    $ventesRows = xlsxRead($ventesFile);
    array_shift($ventesRows);

    $ventesByProd  = [];
    $ventesMonthly = []; // 'YYYY-MM' => montant

    foreach ($ventesRows as $r) {
        $nb      = (int)($r[0]  ?? 0);
        $ref     = trim((string)($r[1] ?? ''));
        $des     = trim((string)($r[2] ?? ''));
        $montant = (float)($r[3] ?? 0);
        $mois    = (int)($r[4]  ?? 0);
        $annee   = (int)($r[5]  ?? 0);
        if ($ref === '') continue;

        if (!isset($ventesByProd[$ref])) {
            $ventesByProd[$ref] = [
                'ref' => $ref, 'des' => $des, 'nb' => 0, 'montant' => 0.0,
                'monthly' => [], 'is_transport' => _isTransport($ref, $des),
            ];
        }
        $ventesByProd[$ref]['nb']      += $nb;
        $ventesByProd[$ref]['montant'] += $montant;
        $ventesByProd[$ref]['des']      = $des;

        if ($mois > 0 && $annee > 0) {
            $key = sprintf('%04d-%02d', $annee, $mois);
            $ventesByProd[$ref]['monthly'][$key] = ($ventesByProd[$ref]['monthly'][$key] ?? 0) + $montant;
            $ventesMonthly[$key] = ($ventesMonthly[$key] ?? 0) + $montant;
        }
    }
    $rowsVentes = count($ventesRows);
    $out('  ' . count($ventesByProd) . ' références vendues (' . basename($ventesFile) . ')');

    /* ── 4. ANALYSE ABC ───────────────────────────────────── */
    $out('[4/7] Calcul ABC...');
    $produits = array_filter($ventesByProd,
        fn($d) => !$d['is_transport'] && $d['montant'] > 0
    );
    usort($produits, fn($a, $b) => $b['montant'] <=> $a['montant']);
    $totalCA = array_sum(array_column($produits, 'montant'));

    $cumul = 0;
    foreach ($produits as &$p) {
        $cumul += $p['montant'];
        $pct    = $totalCA > 0 ? $cumul / $totalCA * 100 : 0;
        $p['abc'] = $pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C');
        // Reporter dans le tableau principal
        $ventesByProd[$p['ref']]['abc'] = $p['abc'];
    }
    unset($p);
    // ABC pour les transports et refs sans vente
    foreach ($ventesByProd as &$d) {
        if (!isset($d['abc'])) $d['abc'] = $d['is_transport'] ? 'T' : 'C';
    }
    unset($d);

    /* ── 5. MODÈLE DE WILSON ──────────────────────────────── */
    $out('[5/7] Modèle de Wilson...');
    ksort($ventesMonthly);
    $nbMois   = count($ventesMonthly);
    $nbAnnees = max(round($nbMois / 12, 1), 1.0);
    $S        = 50.0;   // coût de passation
    $Hrate    = 0.20;   // taux de possession

    foreach ($ventesByProd as &$d) {
        if ($d['is_transport'] || $d['nb'] <= 0) { $d['wilson'] = null; continue; }
        $Dann = $d['nb'] / $nbAnnees;
        $ach  = $achatsByProd[$d['ref']] ?? null;
        $pu   = ($ach && $ach['nb'] > 0) ? $ach['montant'] / $ach['nb']
                                          : ($d['nb'] > 0 ? $d['montant'] / $d['nb'] : 0);
        if ($pu <= 0) { $d['wilson'] = null; continue; }
        $H     = $Hrate * $pu;
        $Qstar = sqrt(2 * $Dann * $S / $H);
        $d['wilson'] = [
            'D_annuel'       => round($Dann, 1),
            'pu_estime'      => round($pu, 2),
            'Q_star'         => round($Qstar, 1),
            'nb_commandes_an'=> $Qstar > 0 ? round($Dann / $Qstar, 1) : 0,
            'stock_securite' => round($Dann / 12 * 1.5, 1),
        ];
    }
    unset($d);

    /* ── 6. CONSOLIDATION ─────────────────────────────────── */
    $out('[6/7] Consolidation...');
    $allRefs = array_unique(array_merge(
        array_keys($stock), array_keys($ventesByProd), array_keys($achatsByProd)
    ));

    $consolidated = [];
    foreach ($allRefs as $ref) {
        $vd  = $ventesByProd[$ref]  ?? [];
        $ad  = $achatsByProd[$ref]  ?? [];
        $qty = $stock[$ref] ?? 0;
        $des = $vd['des'] ?? $ad['des'] ?? '';
        $isT = ($vd['is_transport'] ?? false) || ($ad['is_transport'] ?? false);
        $Dann  = ($vd['nb'] ?? 0) / $nbAnnees;
        $moisStock = ($Dann > 0 && $qty > 0) ? round($qty / ($Dann / 12), 1) : null;

        $consolidated[] = [
            'ref'           => $ref,
            'des'           => $des,
            'is_transport'  => $isT,
            'abc'           => $vd['abc'] ?? 'C',
            'stock_qty'     => $qty,
            'mois_stock'    => $moisStock,
            'vente_montant' => round($vd['montant'] ?? 0, 2),
            'vente_nb'      => $vd['nb'] ?? 0,
            'achat_montant' => round($ad['montant'] ?? 0, 2),
            'achat_nb'      => $ad['nb'] ?? 0,
            'monthly_ventes'=> $vd['monthly'] ?? (object)[],
            'wilson'        => $vd['wilson'] ?? null,
        ];
    }

    /* Tops Pareto */
    $physiques = array_filter($consolidated, fn($c) => !$c['is_transport']);

    $tvM = $tvF = $taM = $taF = $physiques;

    usort($tvM, fn($a,$b) => $b['vente_montant'] <=> $a['vente_montant']);
    $tvM = array_values(array_filter($tvM, fn($c) => $c['vente_montant'] > 0));

    usort($tvF, fn($a,$b) => $b['vente_nb'] <=> $a['vente_nb']);
    $tvF = array_values(array_filter($tvF, fn($c) => $c['vente_nb'] > 0));

    usort($taM, fn($a,$b) => $b['achat_montant'] <=> $a['achat_montant']);
    $taM = array_values(array_filter($taM, fn($c) => $c['achat_montant'] > 0));

    usort($taF, fn($a,$b) => $b['achat_nb'] <=> $a['achat_nb']);
    $taF = array_values(array_filter($taF, fn($c) => $c['achat_nb'] > 0));

    /* ── 7. ÉCRITURE JSON ─────────────────────────────────── */
    $out('[7/7] Écriture JSON...');

    /* ABC stats */
    $abcStats = [
        'A' => ['count' => 0, 'montant' => 0.0],
        'B' => ['count' => 0, 'montant' => 0.0],
        'C' => ['count' => 0, 'montant' => 0.0],
    ];
    foreach ($consolidated as $c) {
        if (isset($abcStats[$c['abc']])) {
            $abcStats[$c['abc']]['count']++;
            $abcStats[$c['abc']]['montant'] += $c['vente_montant'];
        }
    }

    /* Monthly : trier les clés */
    ksort($ventesMonthly);
    $monthlyKeys = array_keys($ventesMonthly);

    $kpis = [
        'nb_refs_stock'      => count($stock),
        'nb_refs_vendues'    => count(array_filter($consolidated, fn($c) => $c['vente_montant'] > 0)),
        'nb_refs_achetees'   => count(array_filter($consolidated, fn($c) => $c['achat_montant'] > 0)),
        'ca_ventes_total'    => round(array_sum(array_column($consolidated, 'vente_montant')), 2),
        'ca_achats_total'    => round(array_sum(array_column($consolidated, 'achat_montant')), 2),
        'ca_ventes_produits' => round(array_sum(array_column(
            array_filter($consolidated, fn($c) => !$c['is_transport']), 'vente_montant')), 2),
        'ca_achats_produits' => round(array_sum(array_column(
            array_filter($consolidated, fn($c) => !$c['is_transport']), 'achat_montant')), 2),
        'periode'            => [
            'debut' => !empty($monthlyKeys) ? $monthlyKeys[0]  : '',
            'fin'   => !empty($monthlyKeys) ? end($monthlyKeys) : '',
        ],
        'abc'          => $abcStats,
        'nb_annees'    => $nbAnnees,
        'generated_at' => date('Y-m-d'),
        'sources'      => [
            'stock'  => basename($stockFile),
            'achats' => basename($achatsFile),
            'ventes' => basename($ventesFile),
        ],
    ];

    _writeJson($outputDir, 'kpis.json',               $kpis);
    _writeJson($outputDir, 'monthly_ventes.json',     $ventesMonthly);
    _writeJson($outputDir, 'top_ventes_montant.json', array_slice($tvM, 0, 20));
    _writeJson($outputDir, 'top_ventes_freq.json',    array_slice($tvF, 0, 20));
    _writeJson($outputDir, 'top_achats_montant.json', array_slice($taM, 0, 20));
    _writeJson($outputDir, 'top_achats_freq.json',    array_slice($taF, 0, 20));

    /* Produits actifs triés par vente décroissante */
    usort($consolidated, fn($a,$b) => $b['vente_montant'] <=> $a['vente_montant']);
    $actifs = array_values(array_filter($consolidated,
        fn($c) => $c['vente_montant'] > 0 || $c['achat_montant'] > 0 || $c['stock_qty'] > 0
    ));
    _writeJson($outputDir, 'produits.json', $actifs);

    $elapsed = round(microtime(true) - $startTime, 1);
    $out('');
    $out('Termine -- ' . count($actifs) . ' produits actifs, periode ' . $nbAnnees . ' ans (' . $elapsed . 's)');
    $out('  CA Ventes produits : ' . number_format($kpis['ca_ventes_produits'], 0, ',', ' ') . ' EUR');
    $out('  CA Achats produits : ' . number_format($kpis['ca_achats_produits'], 0, ',', ' ') . ' EUR');
    $out('  Periode : ' . $kpis['periode']['debut'] . ' -> ' . $kpis['periode']['fin']);

    return [
        'ok'           => true,
        'log'          => implode("\n", $log),
        'generated_at' => date('Y-m-d H:i:s'),
        'stats'        => [
            'nb_refs_stock'      => count($stock),
            'nb_refs_vendues'    => $kpis['nb_refs_vendues'],
            'nb_refs_achetees'   => $kpis['nb_refs_achetees'],
            'ca_ventes_produits' => $kpis['ca_ventes_produits'],
            'ca_achats_produits' => $kpis['ca_achats_produits'],
            'periode'            => $kpis['periode'],
            'nb_annees'          => $nbAnnees,
        ],
        'sources' => [
            'stock'  => ['file' => basename($stockFile),  'size_ko' => (int)round(filesize($stockFile)/1024),  'rows' => $rowsStock  ?? 0],
            'achats' => ['file' => basename($achatsFile), 'size_ko' => (int)round(filesize($achatsFile)/1024), 'rows' => $rowsAchats ?? 0],
            'ventes' => ['file' => basename($ventesFile), 'size_ko' => (int)round(filesize($ventesFile)/1024), 'rows' => $rowsVentes ?? 0],
        ],
    ];
}

/* ═══════════════════════════════════════════════════════════════════
   USAGE CLI : php generate_data.php
═══════════════════════════════════════════════════════════════════ */
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        generateData(__DIR__ . '/data');
    } catch (Exception $e) {
        echo '[ERREUR] ' . $e->getMessage() . "\n";
        exit(1);
    }
}
