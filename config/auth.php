<?php
/**
 * tablojs — Configuration d'authentification
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * Pour changer le mot de passe, exécuter dans un terminal PHP :
 *   php -r "echo password_hash('NOUVEAU_MOT_DE_PASSE', PASSWORD_BCRYPT);"
 * puis remplacer la valeur de AUTH_PASS_HASH ci-dessous.
 */

// Identifiant de connexion
define('AUTH_USER', 'admin');

// Hash bcrypt du mot de passe défini lors de l'installation
// ⚠️  CHANGER CE MOT DE PASSE EN PRODUCTION
define('AUTH_PASS_HASH', '$2y$10$e1o0pBOfbrUrE1vEdV3skO/vSF/cTNSJEPz29PadwcGBi7xjX.EiK');

// Durée de session en secondes (28800 = 8 heures)
define('SESSION_DURATION', 28800);

// Nom de la session
define('SESSION_NAME', 'tablojs_SESS');

// Clé CSRF (générée une fois, fixe)
define('CSRF_SECRET', 'dshcpc_' . md5(__FILE__ . AUTH_USER));
