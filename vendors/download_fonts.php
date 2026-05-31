<?php
/**
 * Script one-shot : télécharge Inter (woff2) + génère inter.css local
 * Usage : php vendors/download_fonts.php
 */
$destDir = __DIR__ . '/fonts/inter/';
if (!is_dir($destDir)) mkdir($destDir, 0755, true);

// Les 7 fichiers woff2 uniques pour le subset latin + variable-weight
// Source : subset latin uniquement (les 7 poids : 300-800 + latin only = 7 URLs uniques)
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

// Récupérer le CSS complet Google Fonts
$ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n"]]);
$googleCss = file_get_contents(
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap',
    false, $ctx
);
if (!$googleCss) {
    echo "[ERREUR] Impossible de récupérer le CSS Google Fonts\n";
    exit(1);
}

// Extraire les blocs @font-face
preg_match_all('/@font-face\s*\{([^}]+)\}/s', $googleCss, $blocks);

$localCss = "/* Inter — self-hosted (téléchargé depuis Google Fonts) */\n";
$downloaded = [];

foreach ($blocks[1] as $block) {
    // Ne garder que le subset latin (pas cyrillic, greek, latin-ext)
    // Les @font-face ont un commentaire "/* latin */" juste avant
    // On peut aussi filtrer sur la position dans le CSS
    
    if (!preg_match('/font-weight:\s*(\d+)/', $block, $wm)) continue;
    if (!preg_match('/url\(([^)]+\.woff2)\)/', $block, $um)) continue;
    
    $weight = $wm[1];
    $url    = $um[1];
    $fname  = 'inter-' . $weight . '.woff2';
    $fpath  = $destDir . $fname;
    
    if (in_array($weight, array_keys($downloaded))) continue; // déjà ce poids
    
    echo "Téléchargement $fname ($url)...\n";
    $data = file_get_contents($url, false, $ctx);
    if ($data === false) {
        echo "  [ERREUR] Échec\n";
        continue;
    }
    file_put_contents($fpath, $data);
    $sizeKo = round(filesize($fpath) / 1024, 1);
    echo "  OK — {$sizeKo} Ko\n";
    $downloaded[$weight] = $fname;
    
    $localCss .= "@font-face {\n";
    $localCss .= "  font-family: 'Inter';\n";
    $localCss .= "  font-style: normal;\n";
    $localCss .= "  font-weight: $weight;\n";
    $localCss .= "  font-display: swap;\n";
    $localCss .= "  src: url('$fname') format('woff2');\n";
    $localCss .= "}\n";
}

file_put_contents($destDir . 'inter.css', $localCss);
echo "\ninter.css généré (" . count($downloaded) . " poids)\n";
echo "Poids téléchargés : " . implode(', ', array_keys($downloaded)) . "\n";
