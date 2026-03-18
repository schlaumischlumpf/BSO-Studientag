<?php
/**
 * Auto-Zuweisung.
 *
 * Modus A (Legacy):
 * - Bestehende Mehrslot-Zuweisung auf Basis managed Slots.
 *
 * Modus B (Ein-Slot, per Settings):
 * - Genau eine Zuteilung pro Schüler
 * - 5 Fachbereiche per ID konfigurierbar
 * - Rechtswissenschaften mit hartem Teilnehmerlimit
 * - Vollständige Neuberechnung pro Lauf
 */

require_once '../config.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Methode nicht erlaubt.');
}

if (!isAdmin() && !hasPermission('zuteilung_ausfuehren')) {
    header('Location: ../index.php');
    exit;
}

requireCsrf();

set_time_limit(300);

$db = getDB();
$activeEditionId = getActiveEditionId();

/**
 * Ermittelt den Ziel-Slot für den Ein-Slot-Modus.
 */
function resolveSingleSlotTimeslotId(PDO $db, int $activeEditionId): int {
    $configuredTimeslotId = intval(getSetting('single_slot_timeslot_id', 0));

    if ($configuredTimeslotId > 0) {
        try {
            $stmt = $db->prepare("SELECT id FROM timeslots WHERE id = ? AND edition_id = ? AND (is_break = 0 OR is_break IS NULL)");
            $stmt->execute([$configuredTimeslotId, $activeEditionId]);
        } catch (Exception $e) {
            $stmt = $db->prepare("SELECT id FROM timeslots WHERE id = ? AND edition_id = ?");
            $stmt->execute([$configuredTimeslotId, $activeEditionId]);
        }

        $explicit = (int)$stmt->fetchColumn();
        if ($explicit > 0) {
            return $explicit;
        }
    }

    try {
        $stmt = $db->prepare("SELECT id FROM timeslots WHERE edition_id = ? AND (is_break = 0 OR is_break IS NULL) ORDER BY start_time ASC, slot_number ASC LIMIT 1");
        $stmt->execute([$activeEditionId]);
    } catch (Exception $e) {
        $stmt = $db->prepare("SELECT id FROM timeslots WHERE edition_id = ? ORDER BY start_time ASC, slot_number ASC LIMIT 1");
        $stmt->execute([$activeEditionId]);
    }

    return (int)$stmt->fetchColumn();
}

/**
 * Ein-Slot-Zuteilung mit Prioritäten und balancierter Verteilung.
 */
function runSingleSlotAutoAssign(PDO $db, int $activeEditionId): array {
    $facultyConfig = [
        'bio_chem_pharma' => [
            'label' => 'Biologie, Chemie und Pharmazie',
            'id' => intval(getSetting('single_slot_faculty_bio_chem_pharma_id', 0)),
        ],
        'education_psychology' => [
            'label' => 'Erziehungswissenschaft, Psychologie und Lehramt',
            'id' => intval(getSetting('single_slot_faculty_education_psychology_id', 0)),
        ],
        'politics_social' => [
            'label' => 'Politik und Sozialwissenschaften',
            'id' => intval(getSetting('single_slot_faculty_politics_social_id', 0)),
        ],
        'law' => [
            'label' => 'Rechtswissenschaften',
            'id' => intval(getSetting('single_slot_faculty_law_id', 0)),
        ],
        'economics' => [
            'label' => 'Wirtschaftswissenschaften',
            'id' => intval(getSetting('single_slot_faculty_economics_id', 0)),
        ],
    ];

    $facultyIds = [];
    foreach ($facultyConfig as $cfg) {
        if ($cfg['id'] > 0) {
            $facultyIds[] = $cfg['id'];
        }
    }

    if (count($facultyIds) !== 5 || count(array_unique($facultyIds)) !== 5) {
        throw new RuntimeException('Ein-Slot-Modus ist unvollständig konfiguriert. Bitte in den Einstellungen 5 unterschiedliche Fachbereich-IDs setzen.');
    }

    $timeslotId = resolveSingleSlotTimeslotId($db, $activeEditionId);
    if ($timeslotId <= 0) {
        throw new RuntimeException('Kein gültiger Ziel-Zeitslot für den Ein-Slot-Modus gefunden.');
    }

    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));

    $stmt = $db->prepare("SELECT id, name FROM exhibitors WHERE active = 1 AND edition_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$activeEditionId], $facultyIds));
    $configuredExhibitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($configuredExhibitors) !== 5) {
        throw new RuntimeException('Mindestens eine konfigurierte Fachbereich-ID ist nicht aktiv oder gehört nicht zur aktuellen Messe-Edition.');
    }

    $facultyNameById = [];
    foreach ($configuredExhibitors as $ex) {
        $facultyNameById[(int)$ex['id']] = $ex['name'];
    }

    foreach ($facultyConfig as $key => $cfg) {
        $facultyConfig[$key]['name'] = $facultyNameById[$cfg['id']] ?? ('Fachbereich ' . $cfg['id']);
    }

    $stmt = $db->query("SELECT id FROM users WHERE role = 'student' ORDER BY id ASC");
    $studentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($studentIds)) {
        return [
            'assigned_count' => 0,
            'student_count' => 0,
            'errors' => ['Keine Schüler gefunden.'],
        ];
    }

    $stmt = $db->prepare("
        SELECT r.user_id, r.exhibitor_id, COALESCE(r.priority, 3) AS priority, r.registered_at
        FROM registrations r
        JOIN users u ON u.id = r.user_id
        WHERE u.role = 'student'
          AND r.edition_id = ?
          AND r.exhibitor_id IN ($placeholders)
        ORDER BY r.user_id ASC, COALESCE(r.priority, 3) ASC, r.registered_at ASC
    ");
    $stmt->execute(array_merge([$activeEditionId], $facultyIds));
    $wishRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $preferencesByUser = [];
    foreach ($studentIds as $studentId) {
        $preferencesByUser[$studentId] = [1 => [], 2 => [], 3 => []];
    }

    foreach ($wishRows as $wish) {
        $userId = (int)$wish['user_id'];
        $exhibitorId = (int)$wish['exhibitor_id'];
        $priority = max(1, min(3, (int)$wish['priority']));

        if (!isset($preferencesByUser[$userId])) {
            continue;
        }

        if (!in_array($exhibitorId, $preferencesByUser[$userId][$priority], true)) {
            $preferencesByUser[$userId][$priority][] = $exhibitorId;
        }
    }

    $counts = array_fill_keys($facultyIds, 0);
    $capacities = array_fill_keys($facultyIds, PHP_INT_MAX);

    $lawId = (int)$facultyConfig['law']['id'];
    $lawCapacity = max(1, intval(getSetting('single_slot_law_capacity', 20)));
    $capacities[$lawId] = $lawCapacity;

    $pickBalancedFaculty = static function (array $candidateIds, array &$counts, array $capacities): ?int {
        $filtered = [];
        foreach ($candidateIds as $candidateId) {
            $candidateId = (int)$candidateId;
            if (!isset($counts[$candidateId])) {
                continue;
            }
            if ($counts[$candidateId] >= ($capacities[$candidateId] ?? PHP_INT_MAX)) {
                continue;
            }
            if (!in_array($candidateId, $filtered, true)) {
                $filtered[] = $candidateId;
            }
        }

        if (empty($filtered)) {
            return null;
        }

        usort($filtered, static function ($a, $b) use ($counts) {
            if ($counts[$a] === $counts[$b]) {
                return $a <=> $b;
            }
            return $counts[$a] <=> $counts[$b];
        });

        return (int)$filtered[0];
    };

    $assignments = [];
    $unassignedStudents = array_fill_keys($studentIds, true);
    $assignedByPriority = [1 => 0, 2 => 0, 3 => 0, 'fallback' => 0];

    for ($priority = 1; $priority <= 3; $priority++) {
        foreach (array_keys($unassignedStudents) as $studentId) {
            $candidateIds = $preferencesByUser[$studentId][$priority] ?? [];
            $selectedFacultyId = $pickBalancedFaculty($candidateIds, $counts, $capacities);

            if ($selectedFacultyId === null) {
                continue;
            }

            $assignments[$studentId] = [
                'exhibitor_id' => $selectedFacultyId,
                'priority' => $priority,
            ];
            $counts[$selectedFacultyId]++;
            $assignedByPriority[$priority]++;
            unset($unassignedStudents[$studentId]);
        }
    }

    foreach (array_keys($unassignedStudents) as $studentId) {
        $preferredAny = array_merge(
            $preferencesByUser[$studentId][1] ?? [],
            $preferencesByUser[$studentId][2] ?? [],
            $preferencesByUser[$studentId][3] ?? []
        );

        $selectedFacultyId = $pickBalancedFaculty($preferredAny, $counts, $capacities);
        if ($selectedFacultyId === null) {
            $selectedFacultyId = $pickBalancedFaculty($facultyIds, $counts, $capacities);
        }

        if ($selectedFacultyId === null) {
            continue;
        }

        $assignments[$studentId] = [
            'exhibitor_id' => $selectedFacultyId,
            'priority' => null,
        ];
        $counts[$selectedFacultyId]++;
        $assignedByPriority['fallback']++;
    }

    if (count($assignments) !== count($studentIds)) {
        throw new RuntimeException('Nicht alle Schüler konnten zugewiesen werden. Bitte Konfiguration prüfen.');
    }

    $lawPriorityRequests = 0;
    foreach ($studentIds as $studentId) {
        if (in_array($lawId, $preferencesByUser[$studentId][1] ?? [], true)) {
            $lawPriorityRequests++;
        }
    }

    $errors = [];
    if ($lawPriorityRequests > $counts[$lawId]) {
        $errors[] = 'Rechtswissenschaften: ' . $lawPriorityRequests . ' Prio-1-Wünsche, aber wegen Limit nur ' . $counts[$lawId] . ' Zuteilungen.';
    }

    if ($assignedByPriority['fallback'] > 0) {
        $errors[] = $assignedByPriority['fallback'] . ' Schüler wurden ohne passenden Wunsch auf freie Fachbereiche verteilt.';
    }

    $distribution = [];
    foreach ($facultyConfig as $faculty) {
        $distribution[] = ($faculty['name'] ?? $faculty['label']) . ': ' . ($counts[$faculty['id']] ?? 0);
    }
    $errors[] = 'Verteilung: ' . implode(' | ', $distribution);
    $errors[] = 'Prioritäts-Treffer: Prio 1=' . $assignedByPriority[1] . ', Prio 2=' . $assignedByPriority[2] . ', Prio 3=' . $assignedByPriority[3] . ', Fallback=' . $assignedByPriority['fallback'];

    $db->beginTransaction();

    $stmt = $db->prepare("
        DELETE r
        FROM registrations r
        JOIN users u ON u.id = r.user_id
        WHERE u.role = 'student'
          AND r.edition_id = ?
    ");
    $stmt->execute([$activeEditionId]);

    $insert = $db->prepare("
        INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, priority, edition_id)
        VALUES (?, ?, ?, 'automatic', ?, ?)
    ");

    foreach ($assignments as $studentId => $assignment) {
        $insert->execute([
            (int)$studentId,
            (int)$assignment['exhibitor_id'],
            $timeslotId,
            $assignment['priority'],
            $activeEditionId,
        ]);
    }

    $db->commit();

    return [
        'assigned_count' => count($assignments),
        'student_count' => count($studentIds),
        'errors' => $errors,
    ];
}

/**
 * Bisherige Mehrslot-Logik.
 */
function runLegacyAutoAssign(PDO $db, int $activeEditionId): array {
    $managedSlots = getManagedSlotNumbers();

    $assignedCount = 0;
    $errors = [];

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
            $errors[] = 'Schüler ' . $studentId . ' hat bereits ' . getManagedSlotCount() . ' Slots - überschüssige Anmeldung entfernt';
            continue;
        }

        $bestSlot = null;
        $lowestCount = PHP_INT_MAX;

        foreach ($availableSlots as $slotNumber) {
            $stmt = $db->prepare("SELECT id FROM timeslots WHERE slot_number = ? AND timeslots.edition_id = ?");
            $stmt->execute([$slotNumber, $activeEditionId]);
            $timeslotId = $stmt->fetchColumn();

            if (!$timeslotId) {
                continue;
            }

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
            $errors[] = 'Kein freier Slot für Schüler ' . $studentId . ' bei ' . $reg['exhibitor_name'];
        }
    }

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

            if (!$timeslotId) {
                continue;
            }

            $stmt = $db->prepare("
                SELECT e.id, e.name, e.room_id, COUNT(DISTINCT reg.user_id) as current_count
                FROM exhibitors e
                LEFT JOIN registrations reg ON e.id = reg.exhibitor_id AND reg.timeslot_id = ? AND reg.edition_id = ?
                WHERE e.active = 1 AND e.room_id IS NOT NULL AND e.edition_id = ?
                GROUP BY e.id, e.name, e.room_id
                ORDER BY current_count ASC, RAND()
            ");
            $stmt->execute([$timeslotId, $activeEditionId, $activeEditionId]);
            $exhibitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $selectedExhibitor = null;

            foreach ($exhibitors as $ex) {
                if (in_array($ex['id'], $existingExhibitors, true)) {
                    continue;
                }

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
                $errors[] = 'Kein verfügbarer Aussteller für Slot ' . $slotNumber . ' (Schüler ' . $studentId . ')';
            }
        }
    }

    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $studentCount = (int)$stmt->fetchColumn();

    return [
        'assigned_count' => $assignedCount,
        'student_count' => $studentCount,
        'errors' => $errors,
    ];
}

function autoCloseRegistrationIfEnabled(): void {
    $autoClose = getSetting('auto_close_registration', '1');
    if ($autoClose !== '1') {
        return;
    }

    if (getRegistrationStatus() === 'open') {
        updateSetting('registration_end', date('Y-m-d H:i:s'));
        $_SESSION['auto_assign_closed'] = true;
    }
}

try {
    $singleSlotEnabled = getSetting('single_slot_assignment_enabled', '0') === '1';
    $result = $singleSlotEnabled
        ? runSingleSlotAutoAssign($db, $activeEditionId)
        : runLegacyAutoAssign($db, $activeEditionId);

    $_SESSION['auto_assign_success'] = true;
    $_SESSION['auto_assign_count'] = $result['assigned_count'];
    $_SESSION['auto_assign_students'] = $result['student_count'];
    $_SESSION['auto_assign_errors'] = $result['errors'];

    $modeText = $singleSlotEnabled ? 'ein-slot' : 'legacy';
    logAuditAction(
        'auto_zuteilung',
        'Automatische Zuteilung ausgeführt (Modus: ' . $modeText . ', Zuweisungen: ' . $result['assigned_count'] . ', Schüler: ' . $result['student_count'] . ')'
    );

    autoCloseRegistrationIfEnabled();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    logErrorToAudit($e, 'API-AutoZuweisung');
    $_SESSION['auto_assign_error'] = 'Ein interner Fehler ist aufgetreten.';
}

header('Location: ../index.php?page=admin-dashboard&auto_assign=done');
exit();
?>
