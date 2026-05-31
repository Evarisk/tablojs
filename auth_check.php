<?php
/**
 * tablojs — Vérification de session (appelé par le JS du dashboard)
 * Retourne JSON : {"authenticated": true/false, "user": "admin"}
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (isAuthenticated()) {
    echo json_encode([
        'authenticated' => true,
        'user'          => $_SESSION['auth_user'],
        'expires_in'    => SESSION_DURATION - (time() - $_SESSION['auth_time']),
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'authenticated' => false,
        'redirect'      => 'login.php',
    ]);
}
