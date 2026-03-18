<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

// Check if user has permission
if (!isAdmin() && !hasPermission('raeume_erstellen')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

$db = getDB();
$activeEditionId = getActiveEditionId();

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (empty($data['room_number'])) {
    echo json_encode(['success' => false, 'message' => 'Hörsaalbezeichnung ist erforderlich']);
    exit;
}

if (empty($data['capacity']) || $data['capacity'] < 1) {
    echo json_encode(['success' => false, 'message' => 'Kapazität muss mindestens 1 sein']);
    exit;
}

try {
    // Check if room number already exists
    $stmt = $db->prepare("SELECT id FROM rooms WHERE room_number = ? AND rooms.edition_id = ?");
    $stmt->execute([$data['room_number'], $activeEditionId]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ein Raum mit dieser Nummer existiert bereits']);
        exit;
    }
    
    // Insert new room (with equipment - Issue #17)
    $stmt = $db->prepare("
        INSERT INTO rooms (room_number, floor, address, capacity, equipment, edition_id) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['room_number'],
        $data['floor'] ?: null,
        !empty($data['address']) ? $data['address'] : null,
        $data['capacity'],
        $data['equipment'] ?? null,
        $activeEditionId
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Hörsaal wurde erfolgreich hinzugefügt',
        'room_id' => $db->lastInsertId()
    ]);
    logAuditAction('raum_erstellt', "Hörsaal '{$data['room_number']}' erstellt (Kap.: {$data['capacity']})");
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-RaumHinzufügen');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
