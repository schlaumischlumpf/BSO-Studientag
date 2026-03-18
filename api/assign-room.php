<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

// Nur für Admins oder Benutzer mit Raum-Bearbeitung
if (!isAdmin() && !hasPermission('raeume_bearbeiten')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

try {
    // JSON Input abrufen
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['exhibitor_id'])) {
        echo json_encode(['success' => false, 'message' => 'Aussteller-ID fehlt']);
        exit;
    }
    
    $exhibitorId = (int)$input['exhibitor_id'];
    $roomId = isset($input['room_id']) && $input['room_id'] !== null ? (int)$input['room_id'] : null;
    
    $db = getDB();
    $activeEditionId = getActiveEditionId();
    
    // Aussteller existiert?
    $stmt = $db->prepare("SELECT id, name FROM exhibitors WHERE id = ? AND exhibitors.edition_id = ?");
    $stmt->execute([$exhibitorId, $activeEditionId]);
    $exhibitor = $stmt->fetch();
    
    if (!$exhibitor) {
        echo json_encode(['success' => false, 'message' => 'Aussteller nicht gefunden']);
        exit;
    }
    
    // Wenn room_id gesetzt ist, prüfen ob Raum existiert
    if ($roomId !== null) {
        $stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE id = ? AND rooms.edition_id = ?");
        $stmt->execute([$roomId, $activeEditionId]);
        $room = $stmt->fetch();
        
        if (!$room) {
            echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
            exit;
        }
    }
    
    // Zuordnung aktualisieren
    $stmt = $db->prepare("UPDATE exhibitors SET room_id = ? WHERE id = ? AND edition_id = ?");
    $stmt->execute([$roomId, $exhibitorId, $activeEditionId]);
    
    if ($roomId === null) {
        $logMsg = "Raum-Zuordnung für Aussteller '{$exhibitor['name']}' entfernt";
        $message = 'Raum-Zuordnung wurde entfernt';
    } else {
        $logMsg = "Aussteller '{$exhibitor['name']}' Raum '{$room['room_number']}' zugeordnet";
        $message = 'Aussteller wurde Raum zugeordnet';
    }
    logAuditAction('raum_zuordnung', $logMsg);
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'exhibitor' => $exhibitor['name'],
        'room' => $roomId ? $room['room_number'] : null
    ]);
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-RaumZuweisung');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
