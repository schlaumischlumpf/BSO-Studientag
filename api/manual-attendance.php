<?php
/**
 * Manuelle Anwesenheitsverwaltung (API)
 * Admin kann Anwesenheiten bestätigen oder wieder entfernen
 */
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Nicht eingeloggt']);
    exit;
}

if (!isAdmin() && !hasPermission('qr_codes_erstellen')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

$db  = getDB();
$activeEditionId = getActiveEditionId();
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$action     = $data['action']      ?? '';
$userId     = intval($data['user_id']     ?? 0);
$exhibitorId = intval($data['exhibitor_id'] ?? 0);
$timeslotId  = intval($data['timeslot_id']  ?? 0);

if (!$userId || !$exhibitorId || !$timeslotId) {
    echo json_encode(['success' => false, 'message' => 'Pflichtfelder fehlen']);
    exit;
}

try {
    if ($action === 'mark_present') {
        // Prüfen ob bereits eingetragen
        $stmt = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND attendance.edition_id = ?");
        $stmt->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => true, 'message' => 'Bereits als anwesend eingetragen', 'already' => true]);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO attendance (user_id, exhibitor_id, timeslot_id, qr_token, edition_id) VALUES (?, ?, ?, 'manual_admin', ?)");
        $stmt->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);

        // Audit-Log
        $stmt = $db->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        logAuditAction('manuell_anwesend', "Manuelle Anwesenheit: {$u['firstname']} {$u['lastname']} (ID $userId) bei Aussteller #$exhibitorId Slot #$timeslotId");

        echo json_encode(['success' => true, 'message' => 'Anwesenheit eingetragen']);

    } elseif ($action === 'mark_absent') {
        $stmt = $db->prepare("DELETE FROM attendance WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?");
        $stmt->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);

        // Audit-Log
        $stmt = $db->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        logAuditAction('manuell_abwesend', "Anwesenheit entfernt: {$u['firstname']} {$u['lastname']} (ID $userId) bei Aussteller #$exhibitorId Slot #$timeslotId");

        echo json_encode(['success' => true, 'message' => 'Anwesenheit entfernt']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    logErrorToAudit($e, 'API-Anwesenheit');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
