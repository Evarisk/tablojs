<?php
/**
 * tablojs — API Paramètres
 * GET  → retourne les settings
 * POST → met à jour les settings (JSON)
 * POST ?action=upload_logo → upload du logo
 */
require_once __DIR__ . '/../auth.php';
requireAuth(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ── Upload logo ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload_logo') {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fichier manquant ou erreur upload']);
        exit;
    }

    // Validation MIME réelle via magic bytes (finfo, pas mime_content_type qui se fie à l'extension)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['logo']['tmp_name']);

    // Map MIME → extension de destination (dérivée du MIME réel, jamais du nom fourni par le client)
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    if (!array_key_exists($mime, $mimeToExt)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Format non autorisé. Utilisez JPG, PNG, GIF, WEBP ou SVG.']);
        exit;
    }

    $maxSize = 2 * 1024 * 1024; // 2 Mo
    if ($_FILES['logo']['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 2 Mo)']);
        exit;
    }

    // Extension forcée depuis le MIME réel — jamais depuis $_FILES['logo']['name']
    $ext      = $mimeToExt[$mime];
    $filename = 'logo.' . $ext;
    $dest     = __DIR__ . '/../uploads/' . $filename;

    // Supprimer l'ancien logo (tous formats confondus)
    foreach (glob(__DIR__ . '/../uploads/logo.*') as $old) {
        @unlink($old);
    }

    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Impossible de sauvegarder le logo']);
        exit;
    }

    // Mettre à jour settings.json
    $s = loadSettings();
    $s['company_logo'] = 'uploads/' . $filename;
    saveSettings($s);

    logAuth('logo_uploaded', ['user' => $_SESSION['auth_user'] ?? '?', 'file' => $filename, 'mime' => $mime]);
    echo json_encode(['ok' => true, 'logo' => 'uploads/' . $filename . '?t=' . time()]);
    exit;
}


/* ── DELETE logo ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && ($_GET['action'] ?? '') === 'logo') {
    foreach (glob(__DIR__ . '/../uploads/logo.*') as $old) {
        @unlink($old);
    }
    $s = loadSettings();
    $s['company_logo'] = '';
    saveSettings($s);
    echo json_encode(['ok' => true]);
    exit;
}

/* ── GET settings ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = loadSettings();
    $s['_client_ip'] = getClientIp();
    // Ajouter timestamp au logo pour éviter le cache navigateur
    if (!empty($s['company_logo'])) {
        $logoPath = __DIR__ . '/../' . $s['company_logo'];
        if (file_exists($logoPath)) {
            $s['company_logo_url'] = $s['company_logo'] . '?t=' . filemtime($logoPath);
        }
    }
    echo json_encode(['ok' => true, 'settings' => $s]);
    exit;
}

/* ── POST settings ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'JSON invalide']); exit; }

    $current = loadSettings();

    // Branding
    if (isset($body['company_name']))    $current['company_name']    = substr(strip_tags($body['company_name']), 0, 60);
    if (isset($body['company_tagline'])) $current['company_tagline'] = substr(strip_tags($body['company_tagline']), 0, 120);

    // Sécurité
    if (isset($body['allowed_ips']) && is_array($body['allowed_ips'])) {
        $ips = [];
        foreach ($body['allowed_ips'] as $ip) {
            $ip = trim($ip);
            if ($ip === '') continue;
            if (filter_var($ip, FILTER_VALIDATE_IP)) { $ips[] = $ip; continue; }
            if (preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $ip)) { $ips[] = $ip; continue; }
            if (preg_match('/^[\d\.\*]+$/', $ip)) { $ips[] = $ip; continue; }
        }
        $current['allowed_ips'] = $ips;
    }
    if (isset($body['max_attempts']))     $current['max_attempts']     = max(1, min(10, (int)$body['max_attempts']));
    if (isset($body['lockout_duration'])) $current['lockout_duration'] = max(60, min(3600, (int)$body['lockout_duration']));
    if (isset($body['session_duration'])) $current['session_duration'] = max(900, min(86400, (int)$body['session_duration']));
    if (isset($body['captcha_enabled']))  $current['captcha_enabled']  = (bool)$body['captcha_enabled'];
    if (isset($body['log_enabled']))      $current['log_enabled']      = (bool)$body['log_enabled'];

    if (saveSettings($current)) {
        logAuth('settings_updated', ['user' => $_SESSION['auth_user'] ?? '?']);
        $current['_client_ip'] = getClientIp();
        echo json_encode(['ok' => true, 'settings' => $current]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Impossible de sauvegarder']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
