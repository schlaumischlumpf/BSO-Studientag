<?php
/**
 * QR-Code Token generieren/abrufen (Issue #15)
 * Generiert für jeden Aussteller pro Slot einen temporären QR-Token
 */
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

if (!isAdmin() && !hasPermission('qr_codes_erstellen')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

$db = getDB();
$activeEditionId = getActiveEditionId();
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? $_GET['action'] ?? 'generate';

try {
    if ($action === 'generate') {
        // QR-Tokens für alle Aussteller und Slots generieren/erneuern
        $exhibitorId = intval($data['exhibitor_id'] ?? 0);
        $timeslotId = intval($data['timeslot_id'] ?? 0);

        $eventDate     = getSetting('event_date');
        $validityAfter = intval(getSetting('qr_validity_after', 15));

        if ($exhibitorId && $timeslotId) {
            // Einzelnen Token generieren (32 Zeichen)
            $token = bin2hex(random_bytes(16));

            $tsStmt = $db->prepare("SELECT end_time FROM timeslots WHERE id = ? AND timeslots.edition_id = ?");
            $tsStmt->execute([$timeslotId, $activeEditionId]);
            $tsData = $tsStmt->fetch(PDO::FETCH_ASSOC);

            if ($eventDate && $tsData && !empty($tsData['end_time'])) {
                $tsEnd = strtotime("$eventDate " . $tsData['end_time']);
                $expiresAt = $tsEnd !== false
                    ? date('Y-m-d H:i:s', $tsEnd + $validityAfter * 60)
                    : date('Y-m-d H:i:s', strtotime('+24 hours'));
            } else {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            }
            
            $stmt = $db->prepare("
                INSERT INTO qr_tokens (exhibitor_id, timeslot_id, token, expires_at, edition_id) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$exhibitorId, $timeslotId, $token, $expiresAt, $activeEditionId]);
            
            echo json_encode(['success' => true, 'token' => $token, 'expires_at' => $expiresAt]);
        } else {
            // Alle Tokens generieren
            $stmt = $db->prepare("SELECT id FROM exhibitors WHERE active = 1 AND exhibitors.edition_id = ?");
            $stmt->execute([$activeEditionId]);
            $exhibitors = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $stmt = $db->prepare("SELECT id, end_time FROM timeslots WHERE timeslots.edition_id = ? AND is_break = 0 ORDER BY slot_number ASC");
            $stmt->execute([$activeEditionId]);
            $timeslots = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $generated = 0;
            foreach ($exhibitors as $exId) {
                foreach ($timeslots as $ts) {
                    $token = bin2hex(random_bytes(16)); // 32 Zeichen
                    if ($eventDate && !empty($ts['end_time'])) {
                        $tsEnd = strtotime("$eventDate " . $ts['end_time']);
                        $expiresAt = $tsEnd !== false
                            ? date('Y-m-d H:i:s', $tsEnd + $validityAfter * 60)
                            : date('Y-m-d H:i:s', strtotime('+24 hours'));
                    } else {
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    }
                    
                    $stmt = $db->prepare("
                        INSERT INTO qr_tokens (exhibitor_id, timeslot_id, token, expires_at, edition_id) 
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$exId, $ts['id'], $token, $expiresAt, $activeEditionId]);
                    $generated++;
                }
            }
            
            echo json_encode(['success' => true, 'generated' => $generated]);
        }
    } elseif ($action === 'list') {
        // Alle Tokens abrufen
        $stmt = $db->prepare("
            SELECT qt.*, e.name as exhibitor_name, t.slot_name, t.slot_number
            FROM qr_tokens qt
            JOIN exhibitors e ON qt.exhibitor_id = e.id
            JOIN timeslots t ON qt.timeslot_id = t.id
            WHERE e.active = 1
            AND qt.edition_id = ? AND e.edition_id = ? AND t.edition_id = ?
            ORDER BY e.name, t.slot_number
        ");
        $stmt->execute([$activeEditionId, $activeEditionId, $activeEditionId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'tokens' => $tokens]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    logErrorToAudit($e, 'API-QRTokens');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
