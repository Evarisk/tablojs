# AGENTS.md — Instructions IA pour tablojs

Ce fichier est la référence absolue pour toute IA intervenant sur le dépôt `tablojs`.

---

## 1. Présentation du projet

**tablojs** est un dashboard web de gestion prévisionnelle des stocks.  
Stack : **PHP 8+ / MariaDB / Vanilla JS / Vanilla CSS** — pas de framework JS, pas de bundler.

### Architecture des fichiers
```
tablojs/
├── index.html            ← SPA principale (toute la logique JS inline)
├── login.php             ← Page de connexion (CAPTCHA, lockout, logging)
├── logout.php            ← Destruction de session
├── auth.php              ← Guard d'authentification (inclure en tête de tout PHP protégé)
├── auth_check.php        ← Endpoint JSON vérifié par le JS au démarrage
├── upload.php            ← Backend import XLSX + gestion des snapshots
├── generate_data.php     ← Génération des JSON à partir des XLSX
├── conf/conf.php         ← Config DB (host, port, user, pass, charset) — NE PAS committer
├── config/auth.php       ← Constantes AUTH_USER, AUTH_PASS_HASH, SESSION_DURATION, SESSION_NAME
├── config/settings.json  ← Paramètres dynamiques (branding, sécurité, logs)
├── api/
│   ├── data.php          ← Proxy sécurisé vers les fichiers JSON des datasets
│   ├── settings.php      ← CRUD paramètres + upload logo
│   └── logs.php          ← Lecture/suppression du journal auth (fichier + DB)
├── css/
│   ├── style.css         ← Design system complet (tokens, composants, pages)
│   └── import.css        ← Styles spécifiques page Import
├── data/
│   ├── Upload/           ← Zone de dépôt temporaire des XLSX
│   ├── active.txt        ← Nom du snapshot actif
│   └── YYYYMMDD-HHMMSS-dataset/  ← Snapshots horodatés (JSON + XLSX + _meta.json)
├── install/              ← Wizard d'installation (protégé par install.lock)
│   └── mariadb/tables/   ← Schémas SQL des tables
├── logs/auth.log         ← Journal texte (JSON-lines) — fallback si DB indisponible
└── uploads/              ← Logos uploadés
```

---

## 2. Philosophie & Priorités

- **Vanilla partout** : PHP natif côté serveur, Vanilla JS côté client. Pas de Composer, pas de npm, pas de framework JS.
- **Sécurité d'abord** : Toute route PHP protégée commence par `require_once __DIR__ . '/auth.php'; requireAuth(true);`.
- **Simplicité** : Fonctions courtes et ciblées. Pas de sur-ingénierie.
- **Données structurées** : Les datasets sont des snapshots horodatés immuables. On n'écrase jamais, on crée un nouveau snapshot.

---

## 3. Règles IA

- Privilégier les patterns existants avant d'en créer de nouveaux.
- Proposer des diffs minimaux et fonctionnels.
- Les commentaires JSDoc/PHPDoc sont obligatoires pour toute nouvelle fonction métier.
- Code PHP asynchrone impossible → toujours gérer les exceptions avec `try/catch`.
- Code JS asynchrone : préférer `async/await` avec `try/catch` sur tous les `fetch`.
- **Ne jamais utiliser `require_once` pour charger `conf/conf.php` à l'intérieur d'une fonction** — le fichier est souvent déjà chargé par un IIFE appelant. Utiliser ce pattern d'isolation à la place :
  ```php
  $dbConf = (static function($f) {
      require $f;
      return compact('tablojs_db_host','tablojs_db_port','tablojs_db_name','tablojs_db_user','tablojs_db_pass');
  })($confFile);
  ```

---

## 4. Conventions Critiques & Patterns Universels

### Authentification PHP
Toute route protégée commence obligatoirement par :
```php
require_once __DIR__ . '/../auth.php'; // (adapter le chemin relatif)
requireAuth(true); // true = réponse JSON 401 au lieu de redirect HTML
```
L'exception est `upload.php?action=active` qui est publique (utilisée par le JS au démarrage sans session).

### Réponses JSON (PHP)
Utiliser les helpers définis dans `upload.php` ou les reproduire localement :
```php
function jsonOk(array $data): void  { echo json_encode(array_merge(['ok' => true], $data)); exit; }
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
```
Toujours inclure `header('Content-Type: application/json; charset=utf-8');` et `header('Cache-Control: no-store');`.

### Logging des événements d'auth
Utiliser `logAuth(string $event, array $extra)` définie dans `auth.php`.  
Événements valides : `login_success` | `login_fail` | `login_locked` | `login_blocked` | `session_expired` | `ip_blocked` | `log_cleared` | `settings_updated` | `logo_uploaded`.  
La fonction écrit **simultanément** dans `logs/auth.log` (JSON-lines) **et** dans la table MySQL `tablojs_auth_log`.

### Settings dynamiques
- **Lecture** : `$settings = loadSettings();` — retourne le contenu de `config/settings.json`.
- **Écriture** : `saveSettings($settings);` — réécrit le fichier entier.
- Clés disponibles : `company_name`, `company_tagline`, `company_logo`, `allowed_ips[]`, `max_attempts`, `lockout_duration`, `session_duration`, `captcha_enabled`, `log_enabled`.

### Datasets & snapshots
- Un dataset = un dossier `data/YYYYMMDD-HHMMSS-dataset/` contenant `stock.xlsx`, `achats.xlsx`, `ventes.xlsx`, `produits.json`, `monthly_vente.json`, `product_stat.json`, `_meta.json`.
- Le dataset actif est référencé dans `data/active.txt`.
- On ne supprime jamais le dataset actif. On peut en supprimer un inactif via `upload.php?action=delete`.
- `_meta.json` contient : `id`, `timestamp`, `label`, `generated_by`, `generated_at`, `sources`, `stats`, `log`.

### Fetch JS (depuis index.html)
```javascript
async function apiFetch(url, options = {}) {
  try {
    const r = await fetch(url, { credentials: 'same-origin', ...options });
    if (r.status === 401) { window.location.replace('login.php'); return null; }
    return await r.json();
  } catch (e) {
    console.error('[tablojs] fetch error:', e);
    return null;
  }
}
```
Toujours passer `credentials: 'same-origin'` pour transmettre le cookie de session.

### Design System CSS (css/style.css)
Les tokens CSS sont définis dans `:root`. **Ne jamais coder de couleur ou dimension en dur**, utiliser les variables :

| Variable | Usage |
|---|---|
| `--bg` / `--bg2` / `--bg3` | Fonds (du plus sombre au moins sombre) |
| `--surface` / `--surface2` | Surfaces de composants |
| `--border` / `--border2` | Bordures |
| `--text` / `--text2` / `--text3` | Textes (principal → muted) |
| `--primary` / `--primary-d` / `--primary-g` | Bleu principal / dark / glass |
| `--green` / `--orange` / `--red` / `--purple` / `--teal` | Couleurs sémantiques |
| `--radius` / `--radius2` / `--radius3` | Arrondis (8 / 12 / 16px) |
| `--font` | `'Inter', system-ui, sans-serif` |

Classes utilitaires clés : `.btn`, `.btn-primary`, `.btn-ghost`, `.btn-sm`, `.kpi-card`, `.chart-card`, `.data-table`, `.pill`, `.pill-group`, `.abc-badge`, `.stock-dot`, `.mois-chip`, `.tag`.

### Navigation (JS)
La navigation entre pages est gérée par `navigate(pageId)` dans `index.html`. Les IDs de page sont :

| ID | Section | Description |
|---|---|---|
| `overview` | Tableaux de bord | Vue d'ensemble des KPI |
| `pareto` | Tableaux de bord | Analyse Pareto 80/20 |
| `abc` | Tableaux de bord | Classification ABC |
| `wilson` | Tableaux de bord | Modèle de Wilson (EOQ) |
| `tendances` | Tableaux de bord | Tendances mensuelles |
| `stock` | Stock | Stock actuel |
| `rotation` | Stock | Taux de rotation |
| `appro` | Approvisionnement | Propositions d'achats |
| `import` | Outils | Import XLSX → JSON |
| `societe` | Configuration | Branding société (nom, logo) |
| `security` | Configuration | Sécurité (IP, CAPTCHA, sessions, journal) |
| `sysserver` | Administration | Infos serveur web (Apache, OS, PHP SAPI) |
| `sysdb` | Administration | Infos base de données (MariaDB, variables) |
| `systables` | Administration | Tables MySQLi (taille, lignes, index) |
| `sysphp` | Administration | Infos PHP (extensions, ini, version) |

Les pages d'administration système chargent leurs données via `api/sysinfo.php?section={server|db|tables|php}` au premier accès (lazy load).

---

## 5. Style de code

### PHP — Commentaires
- Les commentaires se placent **sur la ligne au-dessus** du code qu'ils décrivent, jamais en fin de ligne.
- Un bon commentaire explique le **pourquoi**, pas le **quoi** — éviter les commentaires qui répètent simplement ce que fait le code.
- Conserver les **lignes vides** entre les blocs logiques : elles sont intentionnelles et améliorent la lisibilité.
- PHPDoc obligatoire pour toute nouvelle fonction métier (params, return, description d'intention).

```php
// ✅ Correct — explique pourquoi
// require_once saute conf.php s'il est déjà chargé dans un IIFE appelant
$dbConf = (static function($f) { require $f; return compact(...); })($confFile);

// ❌ Incorrect — répète le code
require_once $confFile; // require once conf file
```

### PHP — Logique avant sortie
Toute la logique de préparation des données doit être exécutée **avant** tout `echo`, `print` ou inclusion de HTML. Ne jamais mélanger requêtes DB/calculs et sortie HTML dans le même bloc.

```php
// ✅ Correct
$settings = loadSettings();
$active   = getActiveDataset();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'settings' => $settings]);

// ❌ Incorrect — logique métier après début de sortie
echo '<div>';
$settings = loadSettings(); // trop tard si un header est déjà envoyé
```

### PHP — Validation des entrées
Toujours filtrer et nettoyer les paramètres avant tout usage :

| Usage | Fonction |
|---|---|
| Nom de fichier | `basename($name)` |
| Nom de dataset | `preg_replace('/[^a-zA-Z0-9_\-]/', '', $name)` |
| Champ texte libre | `substr(strip_tags($value), 0, $maxLen)` |
| Entier | `(int) $_GET['param']` avec `max()`/`min()` pour les bornes |
| IP | `filter_var($ip, FILTER_VALIDATE_IP)` |
| Chemin fichier | `realpath()` + vérification de préfixe `str_starts_with($real, $base)` |

### JS — Structure des fonctions de rendu
Chaque section de rendu dans `index.html` suit le patron : **collecter les données → calculer → construire les éléments DOM → insérer**.  
Ne jamais modifier le DOM à l'intérieur d'une boucle `fetch` — préparer un fragment, puis insérer en une seule opération.

```javascript
// ✅ Correct — un seul appel DOM final
async function renderSection(data) {
  const frag = document.createDocumentFragment();
  for (const item of data) {
    const row = document.createElement('tr');
    // ... remplir row avec textContent
    frag.appendChild(row);
  }
  document.getElementById('tableBody').replaceChildren(frag);
}
```

---

## 6. Base de données

### Tables disponibles
| Table | Rôle |
|---|---|
| `tablojs_auth_log` | Journal des événements d'authentification |
| `tablojs_dataset` | Référence des datasets enregistrés |
| `tablojs_monthly_vente` | Ventes mensuelles par référence |
| `tablojs_monthly_vente_globale` | Ventes mensuelles globales |
| `tablojs_product` | Référentiel produits |
| `tablojs_product_category` | Catégories produits |
| `tablojs_product_stat` | Statistiques produits (ABC, Wilson, rotation…) |
| `tablojs_settings` | (réservé — settings actuellement en JSON) |
| `tablojs_unit` | Unités de mesure |

### Schéma tablojs_auth_log
```sql
rowid       BIGINT AUTO_INCREMENT PRIMARY KEY
ts          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
event       VARCHAR(32)  NOT NULL
ip          VARCHAR(45)  NOT NULL DEFAULT ''
user_login  VARCHAR(64)  DEFAULT NULL
user_agent  VARCHAR(200) DEFAULT NULL
details     JSON         DEFAULT NULL
```

### Connexion DB
Les credentials sont dans `conf/conf.php` (variables `$tablojs_db_host`, `$tablojs_db_port`, `$tablojs_db_name`, `$tablojs_db_user`, `$tablojs_db_pass`). Voir section 3 pour le pattern d'isolation à utiliser dans les fonctions.

---

## 7. Anti-Patterns & Sécurité

- **JAMAIS** de `eval()` ni de scripts inline dans les pages HTML (sauf le guard de session dans `<head>` de `index.html`, qui est intentionnel et minimal).
- **JAMAIS** de `innerHTML` avec des données utilisateur — utiliser `textContent` ou `createElement`.
- **JAMAIS** d'accès direct aux fichiers `data/` depuis le navigateur — toujours passer par `api/data.php` (anti path-traversal intégré : `basename()` + `realpath()` + vérification de préfixe).
- **JAMAIS** de `password_hash` stocké en clair ni de credentials en texte — utiliser `AUTH_PASS_HASH` (bcrypt) dans `config/auth.php`.
- **JAMAIS** de modification directe de `conf/conf.php` par du code applicatif — ce fichier est généré une seule fois par le wizard d'installation.
- **JAMAIS** de suppression du dataset actif (`active.txt` doit toujours pointer vers un dossier existant).
- **Toujours** valider et nettoyer les paramètres GET/POST (voir tableau section 5).
- **Toujours** vérifier `!str_starts_with($realPath, $basePath)` pour tout accès à `data/`.
- Le CAPTCHA mathématique est activable/désactivable via `config/settings.json` → `captcha_enabled`. Ne pas le désactiver en dev sans le réactiver.

---

## 8. Flux de données

```
[XLSX uploadés]
      ↓
upload.php?action=upload   (sauvegarde dans data/Upload/)
      ↓
upload.php?action=generate (génère le snapshot + JSON via generate_data.php)
      ↓
data/YYYYMMDD-HHMMSS-dataset/
      ├── produits.json
      ├── monthly_vente.json
      ├── product_stat.json
      └── _meta.json
      ↓
api/data.php?dataset=...&file=produits.json   (servi au JS)
      ↓
index.html (rendu des tableaux, graphiques Chart.js)
```

---

## 9. Conventions Git

### Branches
Format : `{type}/{numéro-issue}-{description-courte}`

```
fix/42-log-db-vide
feat/55-export-csv-appro
chore/60-maj-agents-md
```

### Commits
Format : `#{issue} [{Scope}] {type}: {description courte}`

| Type | Usage |
|---|---|
| `feat` / `add` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `rework` | Refactorisation |
| `chore` | Build, config, maintenance |
| `docs` / `style` | Documentation, formatage |

**Scope** : élément métier si large (`Auth`, `Import`, `Stock`), catégorie technique si ciblé (`PHP`, `JS`, `CSS`, `DB`).

```
#42 [Auth] fix: insertion DB dans logAuth via pattern isolé
#55 [Import] feat: export CSV page Appro
#60 [JS] rework: renderStock optimisé avec DocumentFragment
```

### Règles
- Ne jamais commiter directement sur `main`. Branche de dev : `develop`.
- Une issue = une branche = une PR.
- Ne jamais mélanger plusieurs issues dans une même branche ou PR.

---

## 10. Checklist avant toute PR

- [ ] Tout fichier PHP protégé commence par `requireAuth(true)`.
- [ ] Aucun `innerHTML` avec données non échappées.
- [ ] Aucune couleur ou dimension codée en dur dans le CSS (utiliser les tokens).
- [ ] Toute nouvelle fonction métier PHP a un commentaire PHPDoc.
- [ ] Toute nouvelle fonction JS asynchrone a un `try/catch`.
- [ ] Le `require_once conf.php` n'est **pas** utilisé à l'intérieur d'une fonction (pattern d'isolation obligatoire).
- [ ] Les endpoints API retournent toujours `{ ok: true|false, ... }`.
- [ ] Les chemins de fichiers dans `data/` sont toujours validés par `realpath()` + vérification de préfixe.
- [ ] Les commentaires expliquent le *pourquoi*, pas le *quoi*.
- [ ] Toute la logique métier précède la sortie HTML/JSON (pas de calculs après `echo`).
- [ ] Les entrées GET/POST sont filtrées avant tout usage (voir tableau section 5).
