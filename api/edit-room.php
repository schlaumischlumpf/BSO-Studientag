<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('raeume_bearbeiten')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

$db = getDB();
$activeEditionId = getActiveEditionId();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['room_id'])) {
    echo json_encode(['success' => false, 'message' => 'Raum-ID fehlt']);
    exit;
}

if (empty($data['room_number'])) {
    echo json_encode(['success' => false, 'message' => 'Hörsaalbezeichnung ist erforderlich']);
    exit;
}

if (empty($data['capacity']) || $data['capacity'] < 1) {
    echo json_encode(['success' => false, 'message' => 'Kapazität muss mindestens 1 sein']);
    exit;
}

try {
    // Prüfen ob Raum existiert
    $stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE id = ? AND rooms.edition_id = ?");
    $stmt->execute([$data['room_id'], $activeEditionId]);
    $existingRoom = $stmt->fetch();

    if (!$existingRoom) {
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    // Prüfen ob Raumnummer bereits durch anderen Raum verwendet wird
    $stmt = $db->prepare("SELECT id FROM rooms WHERE room_number = ? AND id != ? AND rooms.edition_id = ?");
    $stmt->execute([$data['room_number'], $data['room_id'], $activeEditionId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ein anderer Raum mit dieser Nummer existiert bereits']);
        exit;
    }

    // Raum aktualisieren
    $stmt = $db->prepare("
        UPDATE rooms SET room_number = ?, floor = ?, address = ?, capacity = ?, equipment = ? WHERE id = ? AND edition_id = ?
    ");
    $stmt->execute([
        $data['room_number'],
        !empty($data['floor']) ? $data['floor'] : null,
        !empty($data['address']) ? $data['address'] : null,
        intval($data['capacity']),
        !empty($data['equipment']) ? $data['equipment'] : null,
        $data['room_id'],
        $activeEditionId
    ]);

    logAuditAction('raum_bearbeitet', "Hörsaal '{$data['room_number']}' (ID: {$data['room_id']}) aktualisiert (Kap.: {$data['capacity']}, Equipment: {$data['equipment']})");

    echo json_encode([
        'success' => true,
        'message' => 'Raum erfolgreich aktualisiert'
    ]);

} catch (Exception $e) {
    logErrorToAudit($e, 'API-RaumBearbeiten');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
