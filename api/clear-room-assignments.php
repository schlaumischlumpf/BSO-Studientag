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
    $db = getDB();
    $activeEditionId = getActiveEditionId();
    
    // Alle Raum-Zuordnungen entfernen
    $stmt = $db->prepare("UPDATE exhibitors SET room_id = NULL WHERE edition_id = ?");
    $stmt->execute([$activeEditionId]);
    
    $affectedRows = $stmt->rowCount();
    logAuditAction('raumzuordnungen_geleert', "Alle $affectedRows Raum-Zuordnungen der Aussteller entfernt");
    
    echo json_encode([
        'success' => true,
        'message' => 'Alle Raum-Zuordnungen wurden gelöscht',
        'affected_rows' => $affectedRows
    ]);
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-RaumClear');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
