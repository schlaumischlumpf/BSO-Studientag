<?php
/**
 * QR-Code Check-In API (Issue #15)
 * Schüler scannen QR-Code und werden als anwesend markiert
 */
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Nicht eingeloggt']);
    exit;
}

requireCsrf();

$db = getDB();
$activeEditionId = getActiveEditionId();
$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? $_GET['token'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Kein QR-Token angegeben']);
    exit;
}

try {
    // Token validieren
    $stmt = $db->prepare("
        SELECT qt.*, e.name as exhibitor_name, t.slot_name, t.slot_number,
               t.start_time, t.end_time
        FROM qr_tokens qt
        JOIN exhibitors e ON qt.exhibitor_id = e.id
        JOIN timeslots t ON qt.timeslot_id = t.id
        WHERE qt.token = ? AND (qt.expires_at IS NULL OR qt.expires_at > NOW())
        AND qt.edition_id = ? AND e.edition_id = ? AND t.edition_id = ?
    ");
    $stmt->execute([$token, $activeEditionId, $activeEditionId, $activeEditionId]);
    $qrToken = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$qrToken) {
        echo json_encode(['success' => false, 'message' => 'Ungültiger oder abgelaufener QR-Code']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    $exhibitorId = $qrToken['exhibitor_id'];
    $timeslotId = $qrToken['timeslot_id'];
    $slotNumber = $qrToken['slot_number'];

    // Zeitfenster-Prüfung anhand der Einstellungen
    $eventDate       = getSetting('event_date');
    $validityEnabled = getSetting('qr_validity_enabled', '1');
    $validityBefore  = intval(getSetting('qr_validity_before', 10));
    $validityAfter   = intval(getSetting('qr_validity_after', 15));

    if ($validityEnabled !== '0' && $eventDate && !empty($qrToken['start_time']) && !empty($qrToken['end_time'])) {
        $now           = time();
        $tsWindowStart = strtotime("$eventDate " . $qrToken['start_time']);
        $tsWindowEnd   = strtotime("$eventDate " . $qrToken['end_time']);

        if ($tsWindowStart !== false && $tsWindowEnd !== false) {
            $windowStart = $tsWindowStart - $validityBefore * 60;
            $windowEnd   = $tsWindowEnd   + $validityAfter  * 60;

            if ($now < $windowStart) {
                echo json_encode(['success' => false, 'message' => 'Check-in noch nicht möglich. Bitte komm kurz vor Slotbeginn wieder.']);
                exit;
            } elseif ($now > $windowEnd) {
                echo json_encode(['success' => false, 'message' => 'Das Zeitfenster für diesen Check-in ist abgelaufen.']);
                exit;
            }
        }
    }

    $isFreeSlot = in_array($slotNumber, [2, 4]);

    // Prüfen ob registriert
    $stmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND registrations.edition_id = ?");
    $stmt->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);
    $registration = $stmt->fetch();

    if (!$registration && !$isFreeSlot) {
        // Feste Slots: Muss registriert sein
        echo json_encode([
            'success' => false,
            'message' => 'Du bist nicht für ' . $qrToken['exhibitor_name'] . ' in ' . $qrToken['slot_name'] . ' registriert.'
        ]);
        exit;
    }

    if (!$registration && $isFreeSlot) {
        // Freie Wahl: Automatisch einschreiben falls Kapazität
        $stmt2 = $db->prepare("SELECT room_id FROM exhibitors WHERE id = ? AND exhibitors.edition_id = ?");
        $stmt2->execute([$exhibitorId, $activeEditionId]);
        $exData = $stmt2->fetch();
        $roomId = $exData ? $exData['room_id'] : null;

        $stmt2 = $db->prepare("SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND timeslot_id = ? AND registrations.edition_id = ?");
        $stmt2->execute([$exhibitorId, $timeslotId, $activeEditionId]);
        $currentCount = $stmt2->fetchColumn();

        $slotCapacity = $roomId ? getRoomSlotCapacity($roomId, $timeslotId) : 0;

        if ($slotCapacity > 0 && $currentCount >= $slotCapacity) {
            echo json_encode(['success' => false, 'message' => 'Slot Limit erreicht']);
            exit;
        }

        // Registrierung anlegen
        $stmt2 = $db->prepare("INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, edition_id) VALUES (?, ?, ?, 'qr_checkin', ?)");
        $stmt2->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);
    }

    // Prüfen ob bereits eingecheckt
    $stmt = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND attendance.edition_id = ?");
    $stmt->execute([$userId, $exhibitorId, $timeslotId, $activeEditionId]);

    if ($stmt->fetch()) {
        echo json_encode([
            'success' => true,
            'message' => 'Du bist bereits als anwesend markiert bei ' . $qrToken['exhibitor_name'] . '.',
            'already_checked_in' => true
        ]);
        exit;
    }

    // Anwesenheit eintragen
    $stmt = $db->prepare("INSERT INTO attendance (user_id, exhibitor_id, timeslot_id, qr_token, edition_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $exhibitorId, $timeslotId, $token, $activeEditionId]);

    echo json_encode([
        'success' => true,
        'message' => 'Anwesenheit erfolgreich bestätigt für ' . $qrToken['exhibitor_name'] . ' (' . $qrToken['slot_name'] . ')',
        'exhibitor' => $qrToken['exhibitor_name'],
        'slot' => $qrToken['slot_name']
    ]);
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-QRCheckin');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
