<?php
/**
 * tablojs — API Logs d'authentification v3
 * GET ?limit=50 → retourne les N dernières entrées (BDD ou fichier)
 * DELETE        → vide le journal (BDD et fichier)
 */
require_once __DIR__ . '/../auth.php';
requireAuth(true);

require_once __DIR__ . '/../conf/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$logFile = __DIR__ . '/../logs/auth.log';

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Vider d'abord la base de données si disponible
    $pdo = DB::get();
    if ($pdo) {
        try {
            $pdo->exec("DELETE FROM tablojs_auth_log");
        } catch (Exception $e) {
            // Silencieux : le fichier log et la DB peuvent être désynchronisés temporairement
        }
    }

    // Vider le fichier texte de secours
    file_put_contents($logFile, '');

    // Tracer l'action de suppression dans les logs
    logAuth('log_cleared', ['user' => $_SESSION['auth_user'] ?? '?']);

    echo json_encode(['ok' => true, 'message' => 'Logs vidés']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit  = min(500, max(10, (int)($_GET['limit'] ?? 100)));
    $filter = $_GET['filter'] ?? '';

    $entries = [];
    $total = 0;

    // Tenter de lire depuis MariaDB
    $pdo = DB::get();
    if ($pdo) {
        try {
            // Compter le nombre total d'entrées globales pour le badge de la sidebar
            $totalStmt = $pdo->query("SELECT COUNT(*) FROM tablojs_auth_log");
            $total = (int)$totalStmt->fetchColumn();

            // Filtrer par mot-clé si fourni
            if ($filter) {
                // Utiliser des paramètres nommés uniques (:f1 à :f5) car PDO avec emulation=false
                // lève une exception HY093 si le même paramètre nommé est réutilisé plusieurs fois.
                $sql = "SELECT * FROM tablojs_auth_log 
                        WHERE event LIKE :f1 
                           OR ip LIKE :f2 
                           OR user_login LIKE :f3 
                           OR user_agent LIKE :f4 
                           OR details LIKE :f5 
                        ORDER BY ts DESC LIMIT :limit";
                $stmt = $pdo->prepare($sql);
                $likeFilter = '%' . $filter . '%';
                $stmt->bindValue(':f1', $likeFilter, PDO::PARAM_STR);
                $stmt->bindValue(':f2', $likeFilter, PDO::PARAM_STR);
                $stmt->bindValue(':f3', $likeFilter, PDO::PARAM_STR);
                $stmt->bindValue(':f4', $likeFilter, PDO::PARAM_STR);
                $stmt->bindValue(':f5', $likeFilter, PDO::PARAM_STR);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM tablojs_auth_log ORDER BY ts DESC LIMIT :limit");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            }

            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Reconstruire le format JSON attendu par le frontend JS
            foreach ($rows as $row) {
                $entry = [
                    'ts'    => $row['ts'],
                    'event' => $row['event'],
                    'ip'    => $row['ip'],
                    'ua'    => $row['user_agent']
                ];
                if (!empty($row['details'])) {
                    $details = json_decode($row['details'], true);
                    if (is_array($details)) {
                        $entry = array_merge($entry, $details);
                    }
                }
                $entries[] = $entry;
            }

            echo json_encode(['ok' => true, 'entries' => $entries, 'total' => $total]);
            exit;
        } catch (Exception $e) {
            // Silencieux : le fallback sur fichier texte prendra le relais en cas de panne BDD
        }
    }

    // Mode dégradé : lecture depuis le fichier plat logs/auth.log
    if (!file_exists($logFile)) {
        echo json_encode(['ok' => true, 'entries' => [], 'total' => 0]);
        exit;
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) continue;
        if ($filter && strpos(strtolower($line), strtolower($filter)) === false) continue;
        $entries[] = $entry;
        if (count($entries) >= $limit) break;
    }

    echo json_encode(['ok' => true, 'entries' => $entries, 'total' => count($lines)]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
