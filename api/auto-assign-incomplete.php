<?php
/**
 * Auto-Zuweisung für unvollständige Registrierungen
 * 
 * PHASE 1: Schüler mit Anmeldungen (timeslot_id = NULL) 
 *          → Slots bei ihren gewählten Ausstellern zuweisen
 * PHASE 2: Schüler ohne vollständige Slots 
 *          → auf beliebige Aussteller verteilen
 */

require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

if (!isAdmin() && !hasPermission('zuteilung_ausfuehren')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

requireCsrf();

$db = getDB();
$activeEditionId = getActiveEditionId();

try {
    // Verwaltete Slots (nur 1, 3, 5)
    $managedSlots = getManagedSlotNumbers();
    
    $assignedCount = 0;
    $errors = [];
    
    // ============================================================
    // PHASE 1: Schüler mit Anmeldungen (timeslot_id = NULL) 
    //          → Slots bei ihren gewählten Ausstellern zuweisen
    // ============================================================
    
    $stmt = $db->prepare("
        SELECT r.id as registration_id, r.user_id, r.exhibitor_id, e.name as exhibitor_name, e.room_id, COALESCE(r.priority, 2) as priority
        FROM registrations r
        JOIN exhibitors e ON r.exhibitor_id = e.id
        WHERE r.timeslot_id IS NULL
        AND e.active = 1
        AND e.room_id IS NOT NULL
        AND r.edition_id = ? AND e.edition_id = ?
        ORDER BY COALESCE(r.priority, 2) ASC, r.registered_at ASC
    ");
    $stmt->execute([$activeEditionId, $activeEditionId]);
    $pendingRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pendingRegistrations as $reg) {
        $studentId = $reg['user_id'];
        $exhibitorId = $reg['exhibitor_id'];
        $roomId = $reg['room_id'];
        $priority = intval($reg['priority']);
        
        // Welche Slots hat der Schüler bereits?
        $stmt = $db->prepare("
            SELECT t.slot_number 
            FROM registrations r
            JOIN timeslots t ON r.timeslot_id = t.id
            WHERE r.user_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
            AND r.edition_id = ? AND t.edition_id = ?
        ");
        $stmt->execute([$studentId, $activeEditionId, $activeEditionId]);
        $usedSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $availableSlots = array_diff($managedSlots, $usedSlots);
        
        if (empty($availableSlots)) {
            $stmt = $db->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$reg['registration_id']]);
            $errors[] = "Schüler $studentId hat bereits 3 Slots - überschüssige Anmeldung entfernt";
            continue;
        }
        
        // Besten verfügbaren Slot finden
        $bestSlot = null;
        $lowestCount = PHP_INT_MAX;
        
        foreach ($availableSlots as $slotNumber) {
            $stmt = $db->prepare("SELECT id FROM timeslots WHERE slot_number = ? AND timeslots.edition_id = ?");
            $stmt->execute([$slotNumber, $activeEditionId]);
            $timeslotId = $stmt->fetchColumn();
            
            if (!$timeslotId) continue;
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND timeslot_id = ? AND registrations.edition_id = ?");
            $stmt->execute([$exhibitorId, $timeslotId, $activeEditionId]);
            $currentCount = $stmt->fetchColumn();
            
            $slotCapacity = getRoomSlotCapacity($roomId, $timeslotId, $priority);
            
            if ($slotCapacity > 0 && $currentCount < $slotCapacity && $currentCount < $lowestCount) {
                $bestSlot = ['slot_number' => $slotNumber, 'timeslot_id' => $timeslotId];
                $lowestCount = $currentCount;
            }
        }
        
        if ($bestSlot) {
            $stmt = $db->prepare("UPDATE registrations SET timeslot_id = ?, registration_type = 'automatic' WHERE id = ?");
            if ($stmt->execute([$bestSlot['timeslot_id'], $reg['registration_id']])) {
                $assignedCount++;
            }
        } else {
            $errors[] = "Kein freier Slot für Schüler $studentId bei {$reg['exhibitor_name']}";
        }
    }
    
    // ============================================================
    // PHASE 2: Schüler ohne vollständige Slots verteilen
    // ============================================================
    
    $stmt = $db->prepare("
        SELECT u.id,
               COALESCE(SUM(CASE WHEN r.timeslot_id IS NOT NULL AND t.slot_number " . getManagedSlotsSqlIn() . " THEN 1 ELSE 0 END), 0) as assigned_count
        FROM users u
        LEFT JOIN registrations r ON u.id = r.user_id AND r.edition_id = ?
        LEFT JOIN timeslots t ON r.timeslot_id = t.id AND t.edition_id = ?
        WHERE u.role = 'student'
        GROUP BY u.id
        HAVING assigned_count < " . getManagedSlotCount() . "
        ORDER BY assigned_count DESC
    ");
    $stmt->execute([$activeEditionId, $activeEditionId]);
    $studentsNeedingSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($studentsNeedingSlots as $student) {
        $studentId = $student['id'];
        
        $stmt = $db->prepare("
            SELECT t.slot_number 
            FROM registrations r
            JOIN timeslots t ON r.timeslot_id = t.id
            WHERE r.user_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
            AND r.edition_id = ? AND t.edition_id = ?
        ");
        $stmt->execute([$studentId, $activeEditionId, $activeEditionId]);
        $assignedSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt = $db->prepare("SELECT exhibitor_id FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
        $stmt->execute([$studentId, $activeEditionId]);
        $existingExhibitors = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $missingSlots = array_diff($managedSlots, $assignedSlots);
        
        foreach ($missingSlots as $slotNumber) {
            $stmt = $db->prepare("SELECT id FROM timeslots WHERE slot_number = ? AND timeslots.edition_id = ?");
            $stmt->execute([$slotNumber, $activeEditionId]);
            $timeslotId = $stmt->fetchColumn();
            
            if (!$timeslotId) continue;
            
            $stmt = $db->prepare("
                SELECT e.id, e.name, e.room_id, COUNT(DISTINCT reg.user_id) as current_count
                FROM exhibitors e
                LEFT JOIN registrations reg ON e.id = reg.exhibitor_id AND reg.timeslot_id = ? AND reg.edition_id = ?
                WHERE e.active = 1 AND e.room_id IS NOT NULL AND e.edition_id = ?
                GROUP BY e.id, e.name, e.room_id
                ORDER BY current_count ASC, RAND()
            ");
            $stmt->execute([$timeslotId, $activeEditionId, $activeEditionId]);
            $exhibitors = $stmt->fetchAll();
            
            $selectedExhibitor = null;
            
            foreach ($exhibitors as $ex) {
                if (in_array($ex['id'], $existingExhibitors)) continue;
                
                $slotCapacity = getRoomSlotCapacity($ex['room_id'], $timeslotId);
                if ($slotCapacity > 0 && $ex['current_count'] < $slotCapacity) {
                    $selectedExhibitor = $ex;
                    break;
                }
            }
            
            if ($selectedExhibitor) {
                $stmt = $db->prepare("INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, edition_id) VALUES (?, ?, ?, 'automatic', ?)");
                if ($stmt->execute([$studentId, $selectedExhibitor['id'], $timeslotId, $activeEditionId])) {
                    $assignedCount++;
                    $existingExhibitors[] = $selectedExhibitor['id'];
                }
            } else {
                $errors[] = "Kein verfügbarer Aussteller für Slot $slotNumber (Schüler $studentId)";
            }
        }
    }
    
    // Statistik erstellen
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $totalStudents = $stmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT user_id) as complete
        FROM (
            SELECT r.user_id, COUNT(DISTINCT t.slot_number) as slot_count
            FROM registrations r
            JOIN timeslots t ON r.timeslot_id = t.id
            WHERE t.slot_number " . getManagedSlotsSqlIn() . "
            AND r.edition_id = ? AND t.edition_id = ?
            GROUP BY r.user_id
            HAVING slot_count = " . getManagedSlotCount() . "
        ) as complete_registrations
    ");
    $stmt->execute([$activeEditionId, $activeEditionId]);
    $completeStudents = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'message' => "$assignedCount Zuweisungen erfolgreich durchgeführt",
        'assigned' => $assignedCount,
        'errors' => $errors,
        'statistics' => [
            'total_students' => $totalStudents,
            'complete_registrations' => $completeStudents,
            'incomplete_registrations' => $totalStudents - $completeStudents
        ]
    ]);
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-AutoZuweisungUnvollstaendig');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
