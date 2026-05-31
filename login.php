<?php
/**
 * tablojs — Page de connexion v2
 * CAPTCHA mathématique | Blocage 3 tentatives | Logging | Whitelist IP
 */

// ── Vérification installation complète ──────────────────────────────────────
(function() {
    $confFile  = __DIR__ . '/conf/conf.php';
    $lockFile  = __DIR__ . '/install/install.lock';
    $installOk = false;

    if (file_exists($confFile) && file_exists($lockFile)) {
        try {
            require_once $confFile;
            $dsn = "mysql:host={$tablojs_db_host};port={$tablojs_db_port};dbname={$tablojs_db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $tablojs_db_user, $tablojs_db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $count = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            )->fetchColumn();
            $installOk = ($count >= 6);
        } catch (Exception $e) {
            $installOk = false;
        }
    }

    if (!$installOk) {
        // Redirection relative vers le dossier d'installation
        header('Location: install/');
        exit;
    }
})();
// ────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/auth.php';
// Note : auth.php gère déjà ini_set, session_name et session_start

// Charger les settings dynamiques
$settings      = loadSettings();
$maxAttempts   = (int)($settings['max_attempts']     ?? 3);
$lockDuration  = (int)($settings['lockout_duration'] ?? 300);
$captchaOn     = (bool)($settings['captcha_enabled'] ?? true);
$companyName   = htmlspecialchars($settings['company_name']    ?? 'tablojs');
$companyTag    = htmlspecialchars($settings['company_tagline'] ?? 'Gestion des stocks');
$companyLogo   = $settings['company_logo'] ?? '';
$hasLogo       = !empty($companyLogo) && file_exists(__DIR__ . '/' . $companyLogo);

// IP déjà connue
$clientIp = getClientIp();

// Vérifier whitelist IP
if (!checkIpAllowed()) {
    logAuth('ip_blocked');
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Accès refusé</title>'
       . '<style>body{font-family:sans-serif;background:#0b0e1a;color:#e2e8f0;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh;margin:0}</style></head>'
       . '<body><div style="text-align:center">'
       . '<div style="font-size:48px;margin-bottom:16px">⛔</div>'
       . '<h1 style="color:#ef4444;margin-bottom:8px">Accès refusé</h1>'
       . '<p style="color:#94a3b8">Votre adresse IP n\'est pas autorisée à accéder à cette application.<br>'
       . '<code style="color:#4f80ff;font-size:13px">' . htmlspecialchars($clientIp) . '</code></p>'
       . '</div></body></html>';
    exit;
}

// Déjà connecté → rediriger
if (!empty($_SESSION['auth_user']) && !empty($_SESSION['auth_time'])
    && time() - $_SESSION['auth_time'] < ($settings['session_duration'] ?? SESSION_DURATION)) {
    // Redirection relative vers la racine
    header('Location: ./');
    exit;
}

/* ── Génération CAPTCHA ───────────────────────────────────────────── */
function generateCaptcha(): string {
    $a = rand(2, 9);
    $b = rand(1, 9);
    $ops = ['+', '-'];
    $op  = $ops[rand(0, 1)];
    if ($op === '-' && $b > $a) [$a, $b] = [$b, $a]; // résultat toujours positif
    $answer = $op === '+' ? $a + $b : $a - $b;
    $_SESSION['captcha_answer']   = $answer;
    $_SESSION['captcha_question'] = "$a $op $b";
    return "$a $op $b";
}

function validateCaptcha(string $input): bool {
    if (!isset($_SESSION['captcha_answer'])) return false;
    return trim($input) === (string)$_SESSION['captcha_answer'];
}

// Générer CAPTCHA si pas déjà en session ou si nouveau chargement GET
if ($captchaOn && (empty($_SESSION['captcha_question']) || $_SERVER['REQUEST_METHOD'] === 'GET')) {
    generateCaptcha();
}

/* ── Gestion du formulaire POST ───────────────────────────────────── */
$error     = '';
$errorType = ''; // 'lock' | 'ip' | 'captcha' | 'creds'
$attempts  = $_SESSION['login_attempts']  ?? 0;
$lockUntil = $_SESSION['login_lock_until'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user        = trim($_POST['username'] ?? '');
    $pass        = $_POST['password']      ?? '';
    $captchaIn   = trim($_POST['captcha']  ?? '');

    // 1. Vérifier blocage actif
    if ($lockUntil > time()) {
        $wait      = ceil(($lockUntil - time()) / 60);
        $error     = "Compte bloqué. Réessayez dans {$wait} min.";
        $errorType = 'lock';
        logAuth('login_blocked', ['reason' => 'lockout_active', 'attempts' => $attempts]);

    // 2. Vérifier CAPTCHA
    } elseif ($captchaOn && !validateCaptcha($captchaIn)) {
        $error     = 'Réponse au CAPTCHA incorrecte.';
        $errorType = 'captcha';
        generateCaptcha(); // Régénérer
        logAuth('login_fail', ['reason' => 'bad_captcha', 'user' => htmlspecialchars($user)]);

    // 3. Vérifier credentials
    } elseif (!hash_equals(AUTH_USER, $user) || !password_verify($pass, AUTH_PASS_HASH)) {
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        generateCaptcha(); // Nouveau CAPTCHA à chaque échec

        if ($attempts >= $maxAttempts) {
            $_SESSION['login_lock_until'] = time() + $lockDuration;
            $mins      = ceil($lockDuration / 60);
            $error     = "Trop de tentatives ({$maxAttempts}/{$maxAttempts}). Compte bloqué {$mins} min.";
            $errorType = 'lock';
            logAuth('login_locked', [
                'reason'   => 'max_attempts_reached',
                'user_try' => htmlspecialchars($user),
                'attempts' => $attempts,
            ]);
        } else {
            $remaining = $maxAttempts - $attempts;
            $error     = "Identifiant ou mot de passe incorrect. ({$remaining} tentative(s) restante(s))";
            $errorType = 'creds';
            logAuth('login_fail', [
                'reason'   => 'bad_credentials',
                'user_try' => htmlspecialchars($user),
                'attempts' => $attempts,
                'remaining'=> $remaining,
            ]);
        }

    // 4. Connexion réussie
    } else {
        session_regenerate_id(true);
        $_SESSION['auth_user']        = $user;
        $_SESSION['auth_time']        = time();
        $_SESSION['csrf_token']       = bin2hex(random_bytes(32));
        $_SESSION['login_attempts']   = 0;
        unset($_SESSION['login_lock_until'], $_SESSION['captcha_answer'], $_SESSION['captcha_question']);

        logAuth('login_success', ['user' => $user]);
        // Redirection relative vers la racine après succès
        header('Location: ./');
        exit;
    }
}

$captchaQuestion = $_SESSION['captcha_question'] ?? '? + ?';
$lockRemaining   = max(0, $lockUntil - time());
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — <?= $companyName ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0b0e1a; --bg2: #111827; --border: #1e2a3a;
      --primary: #4f80ff; --primary-h: #3b6ee8;
      --green: #22c55e; --red: #ef4444; --orange: #f59e0b;
      --text: #e2e8f0; --text2: #94a3b8; --text3: #475569;
    }
    body {
      font-family: -apple-system, 'Segoe UI', sans-serif;
      background: var(--bg); color: var(--text);
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(79,128,255,.15), transparent),
        radial-gradient(ellipse 60% 40% at 80% 80%, rgba(34,197,94,.06), transparent);
    }
    .wrap { width: 100%; max-width: 400px; padding: 24px; animation: fadeIn .4s ease; }
    @keyframes fadeIn { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:none} }

    .logo { text-align: center; margin-bottom: 28px; }
    .logo-icon {
      width: 56px; height: 56px;
      background: linear-gradient(135deg, var(--primary), #7c3aed);
      border-radius: 16px; display: inline-flex; align-items: center;
      justify-content: center; font-size: 26px; margin-bottom: 10px;
      box-shadow: 0 8px 32px rgba(79,128,255,.4);
    }
    .logo-title { font-size: 22px; font-weight: 700; letter-spacing: .04em; }
    .logo-sub { font-size: 11px; color: var(--text3); margin-top: 4px; letter-spacing: .08em; text-transform: uppercase; }

    .card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: 16px; padding: 28px;
      box-shadow: 0 24px 64px rgba(0,0,0,.6);
    }

    /* Alertes */
    .alert {
      border-radius: 8px; padding: 10px 14px; font-size: 13px;
      margin-bottom: 18px; display: flex; align-items: flex-start; gap: 8px;
      line-height: 1.4;
    }
    .alert-error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
    .alert-lock  { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); color: #fde68a; }

    /* Compteur tentatives */
    .attempt-bar {
      display: flex; gap: 5px; margin-bottom: 18px; justify-content: center;
    }
    .attempt-dot {
      width: 10px; height: 10px; border-radius: 50%;
      background: var(--border); transition: background .3s;
    }
    .attempt-dot.used { background: var(--red); box-shadow: 0 0 6px var(--red); }
    .attempt-dot.last { background: var(--orange); box-shadow: 0 0 6px var(--orange); }

    /* Champs */
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 12px; font-weight: 500; color: var(--text2); margin-bottom: 5px; }
    .field input {
      width: 100%;
      background: #161d2e;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 14px;
      color: var(--text);
      font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
      outline: none;
      -webkit-appearance: none;
      appearance: none;
      /* Neutralise l'autofill blanc/jaune du navigateur */
      -webkit-box-shadow: 0 0 0 1000px #161d2e inset;
      -webkit-text-fill-color: #e2e8f0;
    }
    .field input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(79,128,255,.15);
      -webkit-box-shadow: 0 0 0 1000px #1a2438 inset, 0 0 0 3px rgba(79,128,255,.15);
    }
    .field input.error-field { border-color: rgba(239,68,68,.5); }
    .field input::placeholder { color: var(--text3); }

    /* CAPTCHA */
    .captcha-wrap { display: flex; gap: 10px; align-items: stretch; margin-bottom: 18px; }
    .captcha-question {
      background: rgba(79,128,255,.12);
      border: 1px solid rgba(79,128,255,.3);
      border-radius: 8px; padding: 10px 16px;
      font-size: 18px; font-weight: 700; color: var(--primary);
      display: flex; align-items: center; white-space: nowrap;
      letter-spacing: .05em; min-width: 100px; justify-content: center;
    }
    .captcha-input {
      flex: 1;
      background: #161d2e;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 18px; font-weight: 700;
      color: var(--text);
      font-family: inherit; text-align: center;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      -webkit-appearance: none;
      appearance: none;
      -webkit-box-shadow: 0 0 0 1000px #161d2e inset;
      -webkit-text-fill-color: #e2e8f0;
    }
    .captcha-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(79,128,255,.15);
      -webkit-box-shadow: 0 0 0 1000px #1a2438 inset, 0 0 0 3px rgba(79,128,255,.15);
    }
    .captcha-input.error-field { border-color: rgba(239,68,68,.5); }
    /* Masquer les flèches du champ number */
    .captcha-input::-webkit-inner-spin-button,
    .captcha-input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .captcha-input[type=number] { -moz-appearance: textfield; }
    .captcha-label { font-size: 11px; color: var(--text3); margin-bottom: 6px; }

    /* Countdown lock */
    .lock-countdown {
      text-align: center; font-size: 28px; font-weight: 700;
      color: var(--orange); letter-spacing: .05em; margin: 8px 0;
    }

    /* Bouton */
    .btn-login {
      width: 100%; background: var(--primary); color: #fff;
      border: none; border-radius: 8px; padding: 12px;
      font-size: 14px; font-weight: 600; font-family: inherit;
      cursor: pointer; transition: background .2s, transform .1s, box-shadow .2s;
      box-shadow: 0 4px 16px rgba(79,128,255,.4); margin-top: 4px;
    }
    .btn-login:hover:not(:disabled) { background: var(--primary-h); box-shadow: 0 6px 20px rgba(79,128,255,.5); }
    .btn-login:active:not(:disabled) { transform: scale(.98); }
    .btn-login:disabled { opacity: .4; cursor: not-allowed; }

    /* IP info */
    .ip-info { text-align: center; margin-top: 16px; font-size: 10px; color: var(--text3); }
    .footer { text-align: center; margin-top: 20px; font-size: 11px; color: var(--text3); }
  </style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <?php if ($hasLogo): ?>
      <img src="<?= htmlspecialchars($companyLogo) ?>" alt="Logo" style="width:56px;height:56px;object-fit:contain;border-radius:12px;margin-bottom:10px">
    <?php else: ?>
      <div class="logo-icon">📦</div>
    <?php endif; ?>
    <div class="logo-title"><?= $companyName ?></div>
    <div class="logo-sub"><?= $companyTag ?> — Accès restreint</div>
  </div>

  <div class="card">

    <?php if ($error): ?>
    <div class="alert <?= $errorType === 'lock' ? 'alert-lock' : 'alert-error' ?>">
      <span><?= $errorType === 'lock' ? '🔒' : '⚠️' ?></span>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($attempts > 0 && $lockUntil <= time()): ?>
    <div class="attempt-bar" title="Tentatives de connexion">
      <?php for ($i = 0; $i < $maxAttempts; $i++):
        $cls = $i < $attempts ? ($attempts >= $maxAttempts - 1 && $i === $maxAttempts - 2 ? 'used last' : 'used') : '';
      ?>
      <div class="attempt-dot <?= $cls ?>"></div>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php if ($lockUntil > time()): ?>
    <!-- Compte à rebours de déverrouillage -->
    <div class="lock-countdown" id="lockCountdown"></div>
    <script>
      (function() {
        const until = <?= $lockUntil ?> * 1000;
        function tick() {
          const left = Math.max(0, Math.ceil((until - Date.now()) / 1000));
          const m = String(Math.floor(left / 60)).padStart(2, '0');
          const s = String(left % 60).padStart(2, '0');
          document.getElementById('lockCountdown').textContent = m + ':' + s;
          if (left > 0) setTimeout(tick, 1000);
          else location.reload();
        }
        tick();
      })();
    </script>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="field">
        <label for="username">Identifiant</label>
        <input type="text" id="username" name="username"
               placeholder="admin"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               <?= $lockUntil > time() ? 'disabled' : '' ?>
               required autofocus>
      </div>
      <div class="field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password"
               placeholder="••••••••"
               class="<?= $errorType === 'creds' ? 'error-field' : '' ?>"
               <?= $lockUntil > time() ? 'disabled' : '' ?>
               required>
      </div>

      <?php if ($captchaOn): ?>
      <div>
        <div class="captcha-label">Vérification anti-bot — Combien font :</div>
        <div class="captcha-wrap">
          <div class="captcha-question"><?= htmlspecialchars($captchaQuestion) ?> = ?</div>
          <input type="number" name="captcha" id="captcha"
                 class="captcha-input <?= $errorType === 'captcha' ? 'error-field' : '' ?>"
                 placeholder="?" min="-9" max="99" autocomplete="off"
                 <?= $lockUntil > time() ? 'disabled' : '' ?>
                 required>
        </div>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn-login"
              <?= $lockUntil > time() ? 'disabled' : '' ?>>
        <?= $lockUntil > time() ? '🔒 Compte temporairement bloqué' : 'Se connecter →' ?>
      </button>
    </form>

    <div class="ip-info">Votre IP : <code><?= htmlspecialchars($clientIp) ?></code></div>
  </div>

  <div class="footer"><?= $companyName ?> &copy; <?= date('Y') ?> — Toute tentative d'accès non autorisé est journalisée</div>
</div>
</body>
</html>
