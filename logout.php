<?php
/**
 * tablojs — Déconnexion
 */
require_once __DIR__ . '/config/auth.php';

session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

// Détruire complètement la session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: /tablojs/login.php');
exit;
