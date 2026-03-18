<?php
require_once 'config.php';
require_once 'functions.php';

// Editions-Session-Cache initialisieren
if (isLoggedIn() && !isset($_SESSION['active_edition_id'])) {
    getActiveEditionId();
}

// Seitenpasswort prüfen
checkSitePassword();

requireLogin();

$db = getDB();
$activeEditionId = getActiveEditionId();

// Handle page redirects BEFORE any HTML output
$currentPage = $_GET['page'] ?? 'dashboard';

// Automatische Weiterleitung: Wenn ?token= vorhanden aber kein page=qr-checkin,
// direkt zur QR-Checkin-Seite weiterleiten (z.B. QR-Code Scan)
if (isset($_GET['token']) && !isset($_GET['page'])) {
    $currentPage = 'qr-checkin';
}

// Auto-Assign durchführen wenn aufgerufen (VOR jeglichem HTML-Output!)
if (isset($_GET['auto_assign']) && $_GET['auto_assign'] === 'run' && (isAdmin() || hasPermission('zuteilung_ausfuehren'))) {
    // Direkt die API-Logik ausführen
    try {
        // Verwaltete Slots (nur 1, 3, 5)
        $managedSlots = getManagedSlotNumbers();
        
        $assignedCount = 0;
        $errors = [];
        
        // ============================================================
        // PHASE 1: Schüler mit Anmeldungen (timeslot_id = NULL) 
        //          → Slots bei ihren gewählten Ausstellern zuweisen
        // ============================================================
        
        // Alle Anmeldungen ohne Slot-Zuteilung laden (Priorität berücksichtigen - Issue #16)
        $stmt = $db->prepare("
            SELECT r.id as registration_id, r.user_id, r.exhibitor_id, e.name as exhibitor_name, e.room_id, COALESCE(r.priority, 2) as priority
            FROM registrations r
            JOIN exhibitors e ON r.exhibitor_id = e.id
            WHERE r.timeslot_id IS NULL
            AND e.active = 1
            AND e.room_id IS NOT NULL
            AND r.edition_id = ?
            AND e.edition_id = ?
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
                WHERE r.user_id = ? AND r.edition_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
            ");
            $stmt->execute([$studentId, $activeEditionId]);
            $usedSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Verfügbare Slots für diesen Schüler
            $availableSlots = array_diff($managedSlots, $usedSlots);
            
            if (empty($availableSlots)) {
                // Schüler hat bereits alle 3 Slots - Anmeldung ohne Slot löschen
                $stmt = $db->prepare("DELETE FROM registrations WHERE id = ?");
                $stmt->execute([$reg['registration_id']]);
                $errors[] = "Schüler $studentId hat bereits 3 Slots - überschüssige Anmeldung für {$reg['exhibitor_name']} entfernt";
                continue;
            }
            
            // Besten verfügbaren Slot für diesen Aussteller finden (wenigste Belegung)
            $bestSlot = null;
            $lowestCount = PHP_INT_MAX;
            
            foreach ($availableSlots as $slotNumber) {
                $stmt = $db->prepare("SELECT id FROM timeslots WHERE slot_number = ? AND edition_id = ?");
                $stmt->execute([$slotNumber, $activeEditionId]);
                $timeslotId = $stmt->fetchColumn();
                
                if (!$timeslotId) continue;
                
                // Aktuelle Belegung bei diesem Aussteller in diesem Slot
                $stmt = $db->prepare("
                    SELECT COUNT(*) as cnt FROM registrations 
                    WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?
                ");
                $stmt->execute([$exhibitorId, $timeslotId, $activeEditionId]);
                $currentCount = $stmt->fetchColumn();
                
                // Kapazität prüfen (mit Priorität)
                $slotCapacity = getRoomSlotCapacity($roomId, $timeslotId, $priority);
                
                if ($slotCapacity > 0 && $currentCount < $slotCapacity && $currentCount < $lowestCount) {
                    $bestSlot = ['slot_number' => $slotNumber, 'timeslot_id' => $timeslotId, 'count' => $currentCount];
                    $lowestCount = $currentCount;
                }
            }
            
            if ($bestSlot) {
                // Slot zuweisen durch UPDATE der bestehenden Registrierung
                $stmt = $db->prepare("UPDATE registrations SET timeslot_id = ?, registration_type = 'automatic' WHERE id = ?");
                if ($stmt->execute([$bestSlot['timeslot_id'], $reg['registration_id']])) {
                    $assignedCount++;
                } else {
                    $errors[] = "Fehler beim Zuweisen von Slot {$bestSlot['slot_number']} für Schüler $studentId bei {$reg['exhibitor_name']}";
                }
            } else {
                $errors[] = "Kein freier Slot für Schüler $studentId bei {$reg['exhibitor_name']} (alle Slots voll oder bereits belegt)";
            }
        }
        
        // ============================================================
        // PHASE 2: Schüler ohne vollständige Slots 
        //          → auf beliebige Aussteller verteilen
        // ============================================================
        
        // Alle Schüler mit weniger als 3 zugewiesenen Slots (timeslot_id NOT NULL)
        // Sortierung: Schüler die sich eingeschrieben hatten zuerst, dann nach zugewiesenen Slots absteigend
        // Schüler ohne jegliche Einschreibung kommen zuletzt
        $stmt = $db->prepare("
            SELECT u.id,
                   COALESCE(SUM(CASE WHEN r.timeslot_id IS NOT NULL AND t.slot_number " . getManagedSlotsSqlIn() . " THEN 1 ELSE 0 END), 0) as assigned_count,
                   COUNT(r.id) as total_registrations
            FROM users u
            LEFT JOIN registrations r ON u.id = r.user_id AND r.edition_id = ?
            LEFT JOIN timeslots t ON r.timeslot_id = t.id
            WHERE u.role = 'student'
            GROUP BY u.id
            HAVING assigned_count < " . getManagedSlotCount() . "
            ORDER BY (COUNT(r.id) = 0) ASC, assigned_count DESC
        ");
        $stmt->execute([$activeEditionId]);
        $studentsNeedingSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($studentsNeedingSlots as $student) {
            $studentId = $student['id'];
            
            // Welche Slots hat der Schüler bereits zugewiesen?
            $stmt = $db->prepare("
                SELECT t.slot_number 
                FROM registrations r
                JOIN timeslots t ON r.timeslot_id = t.id
                WHERE r.user_id = ? AND r.edition_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
            ");
            $stmt->execute([$studentId, $activeEditionId]);
            $assignedSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Bei welchen Ausstellern ist der Schüler bereits (mit oder ohne Slot)?
            $stmt = $db->prepare("SELECT exhibitor_id FROM registrations WHERE user_id = ? AND edition_id = ?");
            $stmt->execute([$studentId, $activeEditionId]);
            $existingExhibitors = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Fehlende Slots ermitteln
            $missingSlots = array_diff($managedSlots, $assignedSlots);
            
            foreach ($missingSlots as $slotNumber) {
                // Timeslot ID ermitteln
                $stmt = $db->prepare("SELECT id FROM timeslots WHERE slot_number = ? AND edition_id = ?");
                $stmt->execute([$slotNumber, $activeEditionId]);
                $timeslotId = $stmt->fetchColumn();
                
                if (!$timeslotId) {
                    $errors[] = "Slot $slotNumber nicht gefunden";
                    continue;
                }
                
                // Aussteller mit wenigsten Teilnehmern in diesem Slot finden
                $stmt = $db->prepare("
                    SELECT e.id, e.name, e.room_id,
                           COUNT(DISTINCT reg.user_id) as current_count
                    FROM exhibitors e
                    LEFT JOIN rooms r ON e.room_id = r.id
                    LEFT JOIN registrations reg ON e.id = reg.exhibitor_id AND reg.timeslot_id = ? AND reg.edition_id = ?
                    WHERE e.active = 1 AND e.room_id IS NOT NULL AND e.edition_id = ?
                    GROUP BY e.id, e.name, e.room_id
                    ORDER BY current_count ASC, RAND()
                ");
                $stmt->execute([$timeslotId, $activeEditionId, $activeEditionId]);
                $exhibitors = $stmt->fetchAll();
                
                $selectedExhibitor = null;
                
                foreach ($exhibitors as $ex) {
                    // Schüler darf nicht bereits bei diesem Aussteller sein
                    if (in_array($ex['id'], $existingExhibitors)) {
                        continue;
                    }
                    
                    // Kapazität prüfen
                    $slotCapacity = getRoomSlotCapacity($ex['room_id'], $timeslotId);
                    if ($slotCapacity > 0 && $ex['current_count'] < $slotCapacity) {
                        $selectedExhibitor = $ex;
                        break;
                    }
                }
                
                if ($selectedExhibitor) {
                    // Neue Registrierung erstellen
                    $stmt = $db->prepare("
                        INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, edition_id)
                        VALUES (?, ?, ?, 'automatic', ?)
                    ");
                    
                    if ($stmt->execute([$studentId, $selectedExhibitor['id'], $timeslotId, $activeEditionId])) {
                        $assignedCount++;
                        $existingExhibitors[] = $selectedExhibitor['id']; // Merken für nächste Iteration
                    } else {
                        $errors[] = "Fehler bei Zuweisung: Schüler $studentId zu {$selectedExhibitor['name']} (Slot $slotNumber)";
                    }
                } else {
                    $errors[] = "Kein verfügbarer Aussteller für Slot $slotNumber (Schüler ID: $studentId)";
                }
            }
        }
        
        // Statistik erstellen - Anzahl Schüler mit unvollständigen Anmeldungen
        $stmt = $db->prepare("
            SELECT COUNT(*) as incomplete
            FROM users u
            WHERE u.role = 'student'
            AND (
                SELECT COUNT(DISTINCT t.slot_number)
                FROM registrations r
                JOIN timeslots t ON r.timeslot_id = t.id
                WHERE r.user_id = u.id AND r.edition_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
            ) < " . getManagedSlotCount() . "
        ");
        $stmt->execute([$activeEditionId]);
        $incompleteStudents = $stmt->fetchColumn();
        
        $_SESSION['auto_assign_success'] = true;
        $_SESSION['auto_assign_count'] = $assignedCount;
        $_SESSION['auto_assign_students'] = $incompleteStudents;
        $_SESSION['auto_assign_errors'] = $errors;
        
        logAuditAction('Auto-Zuteilung', "$assignedCount Zuweisungen durchgeführt, $incompleteStudents Schüler noch unvollständig");
        
        // Auto-Close: Einschreibung automatisch schliessen nach Zuteilung (Issue #12)
        $autoClose = getSetting('auto_close_registration', '1');
        if ($autoClose === '1') {
            $regStatus = getRegistrationStatus();
            if ($regStatus === 'open') {
                // Einschreibungsende auf jetzt setzen
                $stmt = $db->prepare("UPDATE settings SET value = ? WHERE `key` = 'registration_end'");
                $stmt->execute([date('Y-m-d H:i:s')]);
                $_SESSION['auto_assign_closed'] = true;
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['auto_assign_error'] = 'Fehler bei der automatischen Zuteilung: ' . $e->getMessage();
    }
    
    header('Location: ?page=admin-dashboard&auto_assign=done');
    exit;
}

// QR-Code Generierung (Bulk) - BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_all']) && (isAdmin() || hasPermission('qr_codes_erstellen'))) {
    requireCsrf();
    $generated = 0;
    $errorMsg = '';
    try {
        $stmt = $db->prepare("SELECT id FROM exhibitors WHERE active = 1 AND edition_id = ?");
        $stmt->execute([$activeEditionId]);
        $exhibitors = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $db->prepare("SELECT id, end_time FROM timeslots WHERE edition_id = ? ORDER BY slot_number ASC");
        $stmt->execute([$activeEditionId]);
        $timeslots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($exhibitors)) {
            throw new Exception("Keine aktiven Aussteller gefunden");
        }
        if (empty($timeslots)) {
            throw new Exception("Keine Zeitslots gefunden");
        }

        $eventDate     = getSetting('event_date');
        $validityAfter = intval(getSetting('qr_validity_after', 15));

        foreach ($exhibitors as $exId) {
            foreach ($timeslots as $ts) {
                $tsId  = $ts['id'];
                $token = bin2hex(random_bytes(3)); // 6 Zeichen
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
                    ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
                ");
                $stmt->execute([$exId, $tsId, $token, $expiresAt, $activeEditionId]);
                $generated++;
            }
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log('QR generation error: ' . $errorMsg);
    }
    
    if ($errorMsg) {
        logAuditAction('QR-Codes Generierung fehlgeschlagen', $errorMsg);
        header('Location: ?page=admin-qr-codes&error=' . urlencode($errorMsg));
    } else {
        logAuditAction('QR-Codes generiert', "$generated QR-Codes für alle Aussteller erstellt");
        header('Location: ?page=admin-qr-codes&generated=' . $generated);
    }
    exit;
}

// QR-Code Generierung (Einzeln) - BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_single']) && (isAdmin() || hasPermission('qr_codes_erstellen'))) {
    requireCsrf();
    try {
        $exhibitorId = intval($_POST['exhibitor_id']);
        $timeslotId  = intval($_POST['timeslot_id']);
        $token       = bin2hex(random_bytes(3)); // 6 Zeichen

        $eventDate     = getSetting('event_date');
        $validityAfter = intval(getSetting('qr_validity_after', 15));

        $tsStmt = $db->prepare("SELECT end_time FROM timeslots WHERE id = ? AND edition_id = ?");
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
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$exhibitorId, $timeslotId, $token, $expiresAt, $activeEditionId]);
    } catch (Exception $e) {
        error_log('QR generation error: ' . $e->getMessage());
    }
    header('Location: ?page=admin-qr-codes&generated=1');
    exit;
}

// Aussteller laden
$stmt = $db->prepare("SELECT * FROM exhibitors WHERE active = 1 AND edition_id = ? ORDER BY name ASC");
$stmt->execute([$activeEditionId]);
$exhibitors = $stmt->fetchAll();

// Einschreibungen des Benutzers laden (LEFT JOIN für NULL timeslot_id - Issue #6)
$stmt = $db->prepare("SELECT r.*, e.name as exhibitor_name, t.slot_name 
                      FROM registrations r 
                      JOIN exhibitors e ON r.exhibitor_id = e.id 
                      LEFT JOIN timeslots t ON r.timeslot_id = t.id 
                      WHERE r.user_id = ? AND r.edition_id = ?");
$stmt->execute([$_SESSION['user_id'], $activeEditionId]);
$userRegistrations = $stmt->fetchAll();

// Registrierungsstatus prüfen
$regStatus = getRegistrationStatus();
$regStart = getSetting('registration_start');
$regEnd = getSetting('registration_end');
$eventDate = getSetting('event_date');
$eventYear = $eventDate ? date('Y', strtotime($eventDate)) : date('Y');

// ============================================================
// Audit Log TXT-Export (VOR HTML-Output, damit header() wirkt)
// ============================================================
if ($currentPage === 'admin-audit-logs' && isset($_GET['export']) && $_GET['export'] === 'txt') {
    if (isAdmin() || hasPermission('audit_logs_sehen')) {
        // Filter-Parameter
        $expFilterUser = $_GET['filter_user'] ?? '';
        $expFilterAction = $_GET['filter_action'] ?? '';
        $expFilterDate = $_GET['filter_date'] ?? '';

        // Query aufbauen
        $expWhere = [];
        $expParams = [];
        if ($expFilterUser) {
            $expWhere[] = "(al.username LIKE ? OR al.user_id = ?)";
            $expParams[] = "%$expFilterUser%";
            $expParams[] = intval($expFilterUser);
        }
        if ($expFilterAction) {
            $expWhere[] = "al.action LIKE ?";
            $expParams[] = "%$expFilterAction%";
        }
        if ($expFilterDate) {
            $expWhere[] = "DATE(al.created_at) = ?";
            $expParams[] = $expFilterDate;
        }
        $expWhereClause = !empty($expWhere) ? 'WHERE ' . implode(' AND ', $expWhere) : '';

        $stmt = $db->prepare("SELECT al.* FROM audit_logs al $expWhereClause ORDER BY al.created_at DESC");
        $stmt->execute($expParams);
        $exportLogs = $stmt->fetchAll();

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-logs-' . date('Y-m-d_His') . '.txt"');
        header('Cache-Control: no-cache, must-revalidate');

        // UTF-8 BOM
        echo "\xEF\xBB\xBF";
        echo "================================================================================\n";
        echo "                    BSO-TAG AN DER FU - AUDIT LOG EXPORT\n";
        echo "================================================================================\n\n";

        echo "  Exportiert am:   " . date('d.m.Y H:i:s') . "\n";
        if ($expFilterUser)   echo "  Filter Benutzer: " . $expFilterUser . "\n";
        if ($expFilterAction) echo "  Filter Aktion:   " . $expFilterAction . "\n";
        if ($expFilterDate)   echo "  Filter Datum:    " . date('d.m.Y', strtotime($expFilterDate)) . "\n";
        echo "  Anzahl Eintraege:" . count($exportLogs) . "\n";
        echo "\n================================================================================\n\n";

        if (empty($exportLogs)) {
            echo "  Keine Eintraege gefunden.\n";
        } else {
            $currentDate = '';
            foreach ($exportLogs as $log) {
                $logDate = date('d.m.Y', strtotime($log['created_at']));

                if ($logDate !== $currentDate) {
                    if ($currentDate !== '') echo "\n";
                    echo "--- " . $logDate . " " . str_repeat('-', 65) . "\n\n";
                    $currentDate = $logDate;
                }

                $time = date('H:i:s', strtotime($log['created_at']));
                $user = $log['username'] . ' (ID: ' . ($log['user_id'] ?? '-') . ')';
                $action = $log['action'];
                $ip = $log['ip_address'] ?? '-';
                $details = $log['details'] ?? '';

                echo "  " . $time . "  " . str_pad($user, 28) . "  " . str_pad($action, 32) . "\n";
                echo "             IP: " . $ip;
                if ($details) {
                    echo "    Details: " . $details;
                }
                echo "\n\n";
            }
        }

        echo "================================================================================\n";
        echo "  Ende des Exports - Generiert mit BSO-Tag an der FU Admin-System\n";
        echo "================================================================================\n";
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSO-Tag an der FU - Dashboard</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/tailwind-config.js"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Material Icons (genutzt in der Guided Tour) -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    
    <!-- Custom Design System -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/design-system.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/guided-tour.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/easter-eggs.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css">
    
    <style>
        /* ==========================================================================
           Pastel Color Palette - CSS Variables
           ========================================================================== */
        :root {
            /* Pastellfarben */
            --color-pastel-mint: #a8e6cf;
            --color-pastel-mint-light: #d4f5e4;
            --color-pastel-lavender: #c3b1e1;
            --color-pastel-lavender-light: #e8dff5;
            --color-pastel-peach: #ffb7b2;
            --color-pastel-peach-light: #ffdad8;
            --color-pastel-sky: #b5deff;
            --color-pastel-sky-light: #dceeff;
            --color-pastel-butter: #fff3b0;
            --color-pastel-rose: #ffc8dd;
            
            /* Primary Colors */
            --color-primary: var(--color-pastel-mint);
            --color-primary-dark: #6bc4a6;
            --color-secondary: var(--color-pastel-lavender);
            --color-accent: var(--color-pastel-peach);
            
            /* UI Colors */
            --color-text-main: #1f2937;
            --color-text-muted: #6b7280;
            --color-border: #e5e7eb;
            --color-bg: #f9fafb;
            
            /* Sidebar */
            --sidebar-bg: #ffffff;
            --sidebar-text: #6b7280;
            --sidebar-text-active: #6bc4a6;
            --sidebar-hover: #f3f4f6;
            --sidebar-active-bg: #d4f5e4;
            --sidebar-width: 16rem;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--color-text-main);
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
        }

        /* ==========================================================================
           Sidebar Styles with Animations
           ========================================================================== */
        .sidebar-transition {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* ==========================================================================
           Exhibitor Modal - Komplett neu geschrieben
           ========================================================================== */
        .exhibitor-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .exhibitor-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .exhibitor-modal-box {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 42rem;
            max-height: 90vh;
            margin: 1rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: scale(0.95) translateY(10px);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .exhibitor-modal-overlay.active .exhibitor-modal-box {
            transform: scale(1) translateY(0);
        }

        /* Custom Scrollbar */
        .sidebar-scroll::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);
            border-radius: 20px;
        }

        /* Navigation Links with Hover Effects */
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            color: var(--sidebar-text);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 0.25rem;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(180deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);
            border-radius: 0 4px 4px 0;
            transform: scaleY(0);
            transition: transform 0.25s ease;
        }
        
        .nav-link:hover {
            background: linear-gradient(135deg, var(--color-pastel-mint-light) 0%, #ffffff 100%);
            color: var(--color-text-main);
            transform: translateX(4px);
        }
        
        .nav-link:hover::before {
            transform: scaleY(1);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--color-pastel-mint-light) 0%, var(--color-pastel-lavender-light) 100%);
            color: var(--color-primary-dark);
            box-shadow: 0 4px 12px rgba(168, 230, 207, 0.3);
        }
        
        .nav-link.active::before {
            transform: scaleY(1);
        }
        
        .nav-link i {
            width: 1.5rem;
            text-align: center;
            margin-right: 0.75rem;
            font-size: 1rem;
            transition: transform 0.25s ease;
        }
        
        .nav-link:hover i {
            transform: scale(1.1);
        }

        /* Nav Group Title */
        .nav-group-title {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #9ca3af;
            margin: 1.5rem 0 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-group-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--color-border) 0%, transparent 100%);
            margin-right: 1rem;
        }

        /* ==========================================================================
           Card Animations
           ========================================================================== */
        .card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--color-border);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.1);
            border-color: var(--color-pastel-mint);
        }

        /* ==========================================================================
           Button Animations
           ========================================================================== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        
        .btn:hover::after {
            opacity: 1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--color-pastel-mint) 0%, var(--color-primary-dark) 100%);
            color: var(--color-text-main);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(168, 230, 207, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: var(--color-text-muted);
            border: 1px solid var(--color-border);
        }
        
        .btn-secondary:hover {
            background: var(--color-bg);
            border-color: var(--color-pastel-mint);
            color: var(--color-text-main);
        }

        /* ==========================================================================
           Badge Styles
           ========================================================================== */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 9999px;
            transition: all 0.2s ease;
        }
        
        .badge-mint {
            background: var(--color-pastel-mint-light);
            color: var(--color-primary-dark);
        }
        
        .badge-lavender {
            background: var(--color-pastel-lavender-light);
            color: #7c3aed;
        }
        
        .badge-peach {
            background: var(--color-pastel-peach-light);
            color: #dc2626;
        }
        
        .badge-sky {
            background: var(--color-pastel-sky-light);
            color: #2563eb;
        }

        /* ==========================================================================
           Responsive Styles
           ========================================================================== */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            
            /* Bessere Touch-Targets auf Mobile */
            .nav-link {
                padding: 0.875rem 1rem;
                font-size: 0.9375rem;
                margin-bottom: 0.125rem;
            }

            .nav-link i {
                width: 1.75rem;
                font-size: 1.0625rem;
                margin-right: 0.5rem; /* Reduzierter Abstand auf Mobile */
            }

            /* Sidebar Navigation: Text bleibt auf Mobile sichtbar */
            .nav-link span {
                display: inline !important; /* Überschreibt die allgemeine Button-Regel */
            }

            /* User Info Bereich: Help Button kompakter */
            .sidebar .border-t button {
                font-size: 0.8125rem;
                padding: 0.5rem 0.75rem;
            }
            
            /* Kompakteres Padding auf Mobile */
            .page-content {
                padding: 0.75rem !important;
            }
            
            /* Karten auf Mobile: volle Breite, kein seitliches Abschneiden */
            .card, .bg-white.rounded-xl {
                border-radius: 0.75rem;
            }
            
            /* Filter-Buttons auf Mobile scrollbar */
            .flex-wrap.items-center.gap-2.mb-6 {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 0.5rem;
                scrollbar-width: none;
            }
            .flex-wrap.items-center.gap-2.mb-6::-webkit-scrollbar {
                display: none;
            }
            .flex-wrap.items-center.gap-2.mb-6 button {
                white-space: nowrap;
                flex-shrink: 0;
            }
            
            /* Ausstellerkarten: 1 Spalte auf Mobile */
            .grid.grid-cols-1.md\\:grid-cols-2.xl\\:grid-cols-3 {
                grid-template-columns: 1fr;
            }
            
            /* Mobile Header: Safe Area beachten */
            #mobileHeader {
                padding-top: max(0.75rem, env(safe-area-inset-top, 0.75rem));
            }

            /* Buttons in Tabellen-Aktionsspalten: nur Icon auf Mobile */
            /* Verwende explizite .btn-icon-only Klasse statt pauschaler :has()-Regel */
            .btn-icon-only span {
                display: none !important;
            }
            .btn-icon-only {
                padding: 0.5rem !important;
                min-width: 44px;
                min-height: 44px;
                justify-content: center;
            }

            /* Mobile - Tagesplan Buttons kompakter */
            .schedule-actions .btn,
            .schedule-actions a {
                padding: 0.375rem 0.5rem;
                font-size: 0.75rem;
            }
            
            /* Tabellen auf Mobile: horizontal scrollbar */
            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
            }
            
            /* Formulare auf Mobile: größere Inputs */
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="number"],
            input[type="date"],
            input[type="datetime-local"],
            input[type="url"],
            select,
            textarea {
                font-size: 16px !important; /* verhindert Auto-Zoom auf iOS */
                min-height: 44px;
            }
            
            /* Buttons auf Mobile größer */
            button[type="submit"],
            a.btn, button.btn {
                min-height: 44px;
            }

            /* Admin-Bereich: Tabellen auf Mobile */
            .overflow-x-auto table {
                font-size: 0.875rem;
            }

            .overflow-x-auto table th,
            .overflow-x-auto table td {
                padding: 0.75rem 0.5rem;
                white-space: nowrap;
            }

            /* Admin-Bereich: Stat Cards auf Mobile stapeln */
            .grid.grid-cols-1.md\\:grid-cols-3 {
                gap: 0.75rem;
            }

            /* Admin-Bereich: Modals auf Mobile */
            .fixed.inset-0 > div {
                margin: 0.5rem;
                max-height: 95vh;
            }

            /* Admin-Bereich: Filter/Action Buttons scrollbar */
            .flex.gap-2 {
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .flex.gap-2 > button,
            .flex.gap-2 > a {
                white-space: nowrap;
                flex-shrink: 0;
            }

            /* Admin-Bereich: Kompaktere Headers */
            h2.text-xl {
                font-size: 1.125rem;
            }

            h3.text-lg {
                font-size: 1rem;
            }

            /* Admin-Bereich: Reduziertes Padding */
            .bg-white.rounded-xl.p-6 {
                padding: 1rem;
            }

            .bg-white.rounded-xl.p-5 {
                padding: 0.875rem;
            }

            /* Admin-Bereich: Bessere Touch-Targets für Checkboxen */
            input[type="checkbox"] {
                width: 1.25rem;
                height: 1.25rem;
            }

            /* Admin-Bereich: Select-Felder größer */
            select {
                min-height: 44px;
                font-size: 16px !important;
            }

            /* Admin-Bereich: Tab Navigation scrollbar */
            .flex.border-b {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .flex.border-b::-webkit-scrollbar {
                display: none;
            }

            .flex.border-b button,
            .flex.border-b a {
                flex-shrink: 0;
            }

            /* Admin-Bereich: Statistics Grid kompakter */
            .grid.gap-4 {
                gap: 0.75rem;
            }

            /* Admin-Bereich: Action Buttons kompakter */
            .flex.justify-end.gap-3 button,
            .flex.justify-end.gap-3 a {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }

            /* Admin-Bereich: Bessere Badge-Größen */
            .px-3.py-1 {
                padding: 0.375rem 0.625rem;
                font-size: 0.75rem;
            }

            /* Admin-Bereich: Responsive Flex Direction */
            .flex.items-center.justify-between {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .flex.items-center.justify-between > .flex.gap-2 {
                width: 100%;
                justify-content: flex-start;
            }

            /* Anti-Umbruch: Namen und Text in Tabellen */
            table td p {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 200px;
            }

            /* Anti-Umbruch: Badges und Spans */
            .badge,
            span.px-3.py-1,
            span.px-2.py-1 {
                white-space: nowrap;
            }

            /* Anti-Umbruch: Button Text */
            button span,
            a span {
                white-space: nowrap;
            }
        }

        /* Anti-Umbruch: Allgemein (Desktop & Mobile) */
        .font-semibold.text-gray-800,
        .text-sm.text-gray-500 {
            overflow-wrap: break-word;
            word-break: keep-all;
        }

        /* Anti-Umbruch: Tabellenzellen mit Text */
        td > div > div > p.font-semibold,
        td > div > div > p.text-sm {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Issue #24: Exhibitor Modal - Schließen-Button mehr Abstand */
        .exhibitor-modal-box > div:last-child {
            padding-bottom: 1.5rem !important;
        }
        
        @media (max-width: 768px) {
            .exhibitor-modal-box > div:last-child {
                padding-bottom: max(2rem, env(safe-area-inset-bottom, 2rem)) !important;
            }
            
            .exhibitor-modal-box {
                max-height: 85vh;
                margin-bottom: 2rem;
            }
        }
        
        /* ==========================================================================
           Page Load Animation
           ========================================================================== */
        @keyframes pageSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-content {
            animation: pageSlideIn 0.4s ease-out;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Mobile Overlay -->
    <div id="mobileOverlay" class="lg:hidden fixed inset-0 bg-black/50 z-30 hidden transition-opacity duration-300 opacity-0"></div>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar sidebar-transition fixed left-0 top-0 h-full bg-white/95 backdrop-blur-lg border-r border-gray-100 w-64 z-40 flex flex-col shadow-xl">
        <div class="p-6 flex items-center gap-3 border-b border-gray-100">
            <!-- Logo with Gradient -->
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0" style="background: linear-gradient(135deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);">
                <i class="fas fa-graduation-cap text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-gray-800 leading-tight">BSO-Tag an der FU</h1>
                <p class="text-xs text-gray-400"><?php echo $eventYear; ?></p>
            </div>
        </div>
        <!-- Mobile Close Button (outside sidebar, right edge) - hidden by default -->
        <button id="sidebarCloseBtn" class="lg:hidden hidden absolute top-4 p-2 bg-white/90 text-gray-500 hover:text-gray-700 hover:bg-white rounded-full shadow-lg transition-all z-50" style="left: calc(100% + 12px); min-width:40px; min-height:40px;" aria-label="Seitenleiste schließen">
            <i class="fas fa-times text-base"></i>
        </button>

        <!-- Navigation -->
        <div class="flex-1 overflow-y-auto sidebar-scroll px-4 py-4">
            <nav class="space-y-1">
                <?php if (isAdmin() || isTeacher()): ?>
                    <?php $activeEd = getActiveEdition(); ?>
                    <div class="px-3 py-2 mb-1 rounded-lg bg-emerald-50 border border-emerald-100 text-xs">
                        <div class="text-emerald-600 font-semibold flex items-center gap-1">
                            <i class="fas fa-calendar-check text-emerald-500"></i>
                            Aktiver BSO-Tag
                        </div>
                        <div class="text-gray-700 mt-0.5 truncate font-medium">
                            <?php echo htmlspecialchars($activeEd['name']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!isTeacher()): ?>
                <div class="nav-group-title">Übersicht</div>
                
                <!-- Dashboard als Startseite -->
                <a href="<?php echo $currentPage === 'dashboard' ? 'javascript:void(0)' : '?page=dashboard'; ?>" data-page="dashboard" class="nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Fachbereiche -->
                <a href="<?php echo $currentPage === 'exhibitors' ? 'javascript:void(0)' : '?page=exhibitors'; ?>" data-page="exhibitors" class="nav-link <?php echo $currentPage === 'exhibitors' ? 'active' : ''; ?>">
                    <i class="fas fa-university"></i>
                    <span>Fachbereiche</span>
                </a>
                <?php endif; ?>

                <?php if (isTeacher() && !isAdmin()): ?>
                <div class="nav-group-title">Lehrer</div>
                
                <a href="<?php echo $currentPage === 'teacher-dashboard' ? 'javascript:void(0)' : '?page=teacher-dashboard'; ?>" data-page="teacher-dashboard" class="nav-link <?php echo $currentPage === 'teacher-dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Übersicht</span>
                </a>
                
                <a href="<?php echo $currentPage === 'exhibitors' ? 'javascript:void(0)' : '?page=exhibitors'; ?>" data-page="exhibitors" class="nav-link <?php echo $currentPage === 'exhibitors' ? 'active' : ''; ?>">
                    <i class="fas fa-university"></i>
                    <span>Fachbereiche</span>
                </a>
                
                <?php if (hasPermission('raeume_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-rooms' ? 'javascript:void(0)' : '?page=admin-rooms'; ?>" data-page="admin-rooms" class="nav-link <?php echo $currentPage === 'admin-rooms' ? 'active' : ''; ?>">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Hörsaalplan</span>
                </a>
                <?php endif; ?>
                
                <?php if (hasPermission('berichte_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-print' ? 'javascript:void(0)' : '?page=admin-print'; ?>" data-page="admin-print" class="nav-link <?php echo $currentPage === 'admin-print' ? 'active' : ''; ?>">
                    <i class="fas fa-print"></i>
                    <span>Druckzentrale</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (isAdmin() || hasAnyPermission('dashboard_sehen', 'aussteller_sehen', 'branchen_sehen', 'orga_team_sehen', 'raeume_sehen', 'kapazitaeten_sehen', 'benutzer_sehen', 'berechtigungen_sehen', 'einstellungen_sehen', 'berichte_sehen', 'anmeldungen_sehen', 'audit_logs_sehen')): ?>
                
                <!-- GRUPPE: Tagesbetrieb (am häufigsten genutzt) -->
                <?php if (isAdmin() || hasAnyPermission('anmeldungen_sehen', 'berichte_sehen', 'dashboard_sehen')): ?>
                <div class="nav-group-title">Tagesbetrieb</div>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('anmeldungen_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-registrations' ? 'javascript:void(0)' : '?page=admin-registrations'; ?>" data-page="admin-registrations" class="nav-link <?php echo $currentPage === 'admin-registrations' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Einschreibungen</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('berichte_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-print' ? 'javascript:void(0)' : '?page=admin-print'; ?>" data-page="admin-print" class="nav-link <?php echo $currentPage === 'admin-print' ? 'active' : ''; ?>">
                    <i class="fas fa-print"></i>
                    <span>Druckzentrale</span>
                </a>
                <?php endif; ?>

                <!-- GRUPPE: Inhalte (Fachbereiche & Hörsäle) -->
                <?php if (isAdmin() || hasAnyPermission('aussteller_sehen', 'branchen_sehen', 'orga_team_sehen', 'raeume_sehen', 'kapazitaeten_sehen')): ?>
                <div class="nav-group-title">Inhalte</div>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('aussteller_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-exhibitors' ? 'javascript:void(0)' : '?page=admin-exhibitors'; ?>" data-page="admin-exhibitors" class="nav-link <?php echo $currentPage === 'admin-exhibitors' ? 'active' : ''; ?>">
                    <i class="fas fa-university"></i>
                    <span>Fachbereiche</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('raeume_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-rooms' ? 'javascript:void(0)' : '?page=admin-rooms'; ?>" data-page="admin-rooms" class="nav-link <?php echo $currentPage === 'admin-rooms' ? 'active' : ''; ?>">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Hörsäle</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('kapazitaeten_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-room-capacities' ? 'javascript:void(0)' : '?page=admin-room-capacities'; ?>" data-page="admin-room-capacities" class="nav-link <?php echo $currentPage === 'admin-room-capacities' ? 'active' : ''; ?>">
                    <i class="fas fa-table"></i>
                    <span>Kapazitäten</span>
                </a>
                <?php endif; ?>

                <!-- GRUPPE: Personen & System -->
                <?php if (isAdmin() || hasAnyPermission('benutzer_sehen', 'berechtigungen_sehen', 'einstellungen_sehen', 'audit_logs_sehen')): ?>
                <div class="nav-group-title">System</div>

                <?php if (isAdmin() || hasPermission('dashboard_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-dashboard' ? 'javascript:void(0)' : '?page=admin-dashboard'; ?>" data-page="admin-dashboard" class="nav-link <?php echo $currentPage === 'admin-dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Übersicht</span>
                </a>
                <?php endif; ?>

                <?php if (isAdmin() || hasPermission('benutzer_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-users' ? 'javascript:void(0)' : '?page=admin-users'; ?>" data-page="admin-users" class="nav-link <?php echo $currentPage === 'admin-users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Benutzer</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || hasPermission('berechtigungen_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-permissions' ? 'javascript:void(0)' : '?page=admin-permissions'; ?>" data-page="admin-permissions" class="nav-link <?php echo $currentPage === 'admin-permissions' ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt"></i>
                    <span>Berechtigungen</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin()): ?>
                <a href="<?php echo $currentPage === 'admin-editions' ? 'javascript:void(0)' : '?page=admin-editions'; ?>"
                   data-page="admin-editions"
                   class="nav-link <?php echo $currentPage === 'admin-editions' ? 'active' : ''; ?>">
                    <i class="fas fa-layer-group"></i>
                    <span>Messe-Editionen</span>
                </a>
                <?php endif; ?>

                <?php if (isAdmin() || hasPermission('einstellungen_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-settings' ? 'javascript:void(0)' : '?page=admin-settings'; ?>" data-page="admin-settings" class="nav-link <?php echo $currentPage === 'admin-settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Einstellungen</span>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin()): ?>
                <a href="<?php echo $currentPage === 'admin-announcements' ? 'javascript:void(0)' : '?page=admin-announcements'; ?>"
                   data-page="admin-announcements"
                   class="nav-link <?php echo $currentPage === 'admin-announcements' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i>
                    <span>Ankündigungen</span>
                    <?php
                    try {
                        $annNow = date('Y-m-d H:i:s');
                        $annStmt = $db->prepare("SELECT COUNT(*) FROM announcements
                            WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > ?)");
                        $annStmt->execute([$annNow]);
                        $annCount = $annStmt->fetchColumn();
                        if ($annCount > 0): ?>
                        <span class="ml-auto w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>
                    <?php endif;
                    } catch (Exception $e) { } ?>
                </a>
                <?php endif; ?>

                <?php if (isAdmin() || hasPermission('audit_logs_sehen')): ?>
                <a href="<?php echo $currentPage === 'admin-audit-logs' ? 'javascript:void(0)' : '?page=admin-audit-logs'; ?>" data-page="admin-audit-logs" class="nav-link <?php echo $currentPage === 'admin-audit-logs' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Audit Logs</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            </nav>
        </div>

        <!-- User Info with Help Button -->
        <div class="p-4 border-t border-gray-100 mt-auto bg-gradient-to-r from-gray-50 to-white">
            <!-- Help/Tour Button -->
            <button onclick="startGuidedTour()" class="w-full mb-3 flex items-center justify-center gap-2 px-3 py-2 bg-gradient-to-r from-amber-50 to-orange-50 text-amber-700 rounded-lg text-sm font-medium hover:from-amber-100 hover:to-orange-100 transition-all duration-300 border border-amber-200">
                <i class="fas fa-question-circle"></i>
                <span>Hilfe & Tour</span>
            </button>
            
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl overflow-hidden shadow-md" style="background: linear-gradient(135deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);">
                    <div class="w-full h-full flex items-center justify-center text-white font-bold text-sm">
                        <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1) . substr($_SESSION['lastname'], 0, 1)); ?>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-sm truncate text-gray-800"><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></p>
                    <p class="text-xs text-gray-500 truncate capitalize">
                        <?php echo htmlspecialchars($_SESSION['role']); ?>
                    </p>
                </div>
                <a href="logout.php" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all duration-200" title="Abmelden">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="lg:ml-64 min-h-screen">
        <!-- Content Area with Animation -->
        <div class="page-content p-4 sm:p-6 lg:p-8">
            <!-- Mobile Burger (floats left of page title) -->
            <div id="mobileHeader" class="lg:hidden inline-flex items-center mr-3 relative z-20" style="float:left;">
                <button id="mobileMenuBtn" class="bg-gray-50 text-gray-600 p-2.5 rounded-xl border border-gray-200 transition-all duration-200 hover:bg-gray-100" style="min-width:44px; min-height:44px;" aria-label="Menü öffnen" aria-expanded="false">
                    <i class="fas fa-bars text-lg"></i>
                </button>
            </div>
            <?php
            $activeEditionId = getActiveEditionId();
            ?>
<?php
$_announcements = isLoggedIn() ? getActiveAnnouncements($_SESSION['role'] ?? 'student') : [];
if (!empty($_announcements)):
?>
<div id="announcementContainer" class="mb-4 space-y-2">
    <?php foreach ($_announcements as $_ann):
        $annColors = [
            'info'    => 'bg-blue-50 border-blue-200 text-blue-800',
            'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
            'success' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'error'   => 'bg-red-50 border-red-200 text-red-800',
        ];
        $annIcons = [
            'info'    => 'fa-info-circle text-blue-500',
            'warning' => 'fa-exclamation-triangle text-amber-500',
            'success' => 'fa-check-circle text-emerald-500',
            'error'   => 'fa-times-circle text-red-500',
        ];
        $colorClass = $annColors[$_ann['type']] ?? $annColors['info'];
        $iconClass  = $annIcons[$_ann['type']]  ?? $annIcons['info'];
    ?>
    <div class="announcement-banner flex items-start gap-3 px-4 py-3 rounded-xl border <?php echo $colorClass; ?>"
         data-announcement-id="<?php echo $_ann['id']; ?>">
        <i class="fas <?php echo $iconClass; ?> mt-0.5 flex-shrink-0"></i>
        <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm"><?php echo htmlspecialchars($_ann['title']); ?></p>
            <?php if (!empty($_ann['body'])): ?>
            <p class="text-xs mt-0.5 opacity-80"><?php echo nl2br(htmlspecialchars($_ann['body'])); ?></p>
            <?php endif; ?>
        </div>
        <button onclick="this.closest('.announcement-banner').remove()"
                class="flex-shrink-0 opacity-50 hover:opacity-100 transition text-lg leading-none"
                aria-label="Schließen">×</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
            <?php
            // Seiten-Content laden
            $pageLoaded = false;
            
            switch ($currentPage) {
                case 'dashboard':
                    include 'pages/dashboard.php';
                    $pageLoaded = true;
                    break;
                case 'exhibitors':
                    include 'pages/exhibitors.php';
                    $pageLoaded = true;
                    break;
                case 'registration':
                    include 'pages/registration.php';
                    $pageLoaded = true;
                    break;
                case 'my-registrations':
                    include 'pages/my-registrations.php';
                    $pageLoaded = true;
                    break;
                case 'schedule':
                    include 'pages/schedule.php';
                    $pageLoaded = true;
                    break;
                case 'print-view':
                    // Sollte oben bereits abgefangen sein, aber sicherheitshalber
                    include 'pages/print-view.php';
                    $pageLoaded = true;
                    break;
                case 'teacher-dashboard':
                    if (isTeacher() || isAdmin()) {
                        include 'pages/teacher-dashboard.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'teacher-class-list':
                    if (isTeacher() || isAdmin()) {
                        include 'pages/teacher-class-list.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-dashboard':
                    if (isAdmin() || hasPermission('dashboard_sehen')) {
                        include 'pages/admin-dashboard.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-exhibitors':
                    if (isAdmin() || hasPermission('aussteller_sehen')) {
                        include 'pages/admin-exhibitors.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-rooms':
                    if (isAdmin() || hasPermission('raeume_sehen')) {
                        include 'pages/admin-rooms.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-room-capacities':
                    if (isAdmin() || hasPermission('kapazitaeten_sehen')) {
                        include 'pages/admin-room-capacities.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-users':
                    if (isAdmin() || hasPermission('benutzer_sehen')) {
                        include 'pages/admin-users.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-permissions':
                    if (isAdmin() || hasPermission('berechtigungen_sehen')) {
                        include 'pages/admin-permissions.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-print':
                    if (isAdmin() || hasPermission('berichte_sehen')) {
                        include 'pages/admin-print.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-settings':
                    if (isAdmin() || hasPermission('einstellungen_sehen')) {
                        include 'pages/admin-settings.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-qr-codes':
                    if (isAdmin() || hasPermission('qr_codes_sehen')) {
                        include 'pages/admin-qr-codes.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-attendance':
                    if (isAdmin() || hasPermission('qr_codes_sehen') || hasPermission('qr_codes_erstellen')) {
                        include 'pages/admin-attendance.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-registrations':
                    if (isAdmin() || hasPermission('anmeldungen_sehen')) {
                        include 'pages/admin-registrations.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'admin-announcements':
                    if (isAdmin()) {
                        include 'pages/admin-announcements.php';
                        $pageLoaded = true;
                    }
                    break;

                case 'admin-editions':
                    if (isAdmin()) {
                        include 'pages/admin-editions.php';
                        $pageLoaded = true;
                    }
                    break;

                case 'admin-audit-logs':
                    if (isAdmin() || hasPermission('audit_logs_sehen')) {
                        include 'pages/admin-audit-logs.php';
                        $pageLoaded = true;
                    }
                    break;
                case 'qr-checkin':
                    include 'pages/qr-checkin.php';
                    $pageLoaded = true;
                    break;
            }
            
            // Fallback zum Dashboard wenn keine Seite geladen wurde
            if (!$pageLoaded) {
                if (isTeacher()) {
                    include 'pages/teacher-dashboard.php';
                } else {
                    include 'pages/dashboard.php';
                }
            }
            ?>
        </div>
    </main>

    <!-- JavaScript Libraries -->
    <script src="<?php echo BASE_URL; ?>assets/js/animations.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile-tables.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/guided-tour.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/easter-eggs.js"></script>
    
    <script>
        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileHeader = document.getElementById('mobileHeader');
        const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobileOverlay');

        function openMobileSidebar() {
            sidebar.classList.add('open');
            if (mobileHeader) mobileHeader.style.display = 'none';
            if (sidebarCloseBtn) sidebarCloseBtn.classList.remove('hidden');
            if (mobileOverlay) {
                mobileOverlay.classList.remove('hidden');
                setTimeout(() => mobileOverlay.classList.add('opacity-100'), 10);
                mobileOverlay.classList.remove('opacity-0');
            }
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('open');
            if (mobileHeader) mobileHeader.style.display = '';
            if (sidebarCloseBtn) sidebarCloseBtn.classList.add('hidden');
            if (mobileOverlay) {
                mobileOverlay.classList.remove('opacity-100');
                mobileOverlay.classList.add('opacity-0');
                setTimeout(() => mobileOverlay.classList.add('hidden'), 300);
            }
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                openMobileSidebar();
            });
        }

        if (sidebarCloseBtn) {
            sidebarCloseBtn.addEventListener('click', closeMobileSidebar);
        }

        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', closeMobileSidebar);
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth < 1024 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !(mobileOverlay && mobileOverlay.contains(e.target))) {
                    closeMobileSidebar();
                }
            }
        });

        // Close sidebar when a nav-link is clicked on mobile
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 1024) closeMobileSidebar();
            });
        });

        // Update aria-expanded on hamburger button
        function updateAriaExpanded(open) {
            if (mobileMenuBtn) mobileMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        const _origOpen = openMobileSidebar;
        const _origClose = closeMobileSidebar;
        // Patch open/close to update aria
        window.openMobileSidebar = function() { _origOpen(); updateAriaExpanded(true); };
        window.closeMobileSidebar = function() { _origClose(); updateAriaExpanded(false); };
        
        // Start Guided Tour Function
        function startGuidedTour() {
            // Verhindere mehrfaches Starten
            if (window.currentGuidedTour && window.currentGuidedTour.isActive) {
                console.warn('Eine Tour ist bereits aktiv');
                return;
            }
            
            // Navigate to dashboard first
            const currentPage = new URLSearchParams(window.location.search).get('page');
            const userRole = '<?php echo $_SESSION["role"] ?? "student"; ?>';
            
            // Determine the correct dashboard page based on actual role
            let dashboardPage = 'dashboard'; // default for students
            if (userRole === 'admin') {
                dashboardPage = 'admin-dashboard';
            } else if (userRole === 'teacher') {
                dashboardPage = 'teacher-dashboard';
            }
            
            // If not on the correct dashboard, navigate there first
            if (currentPage !== dashboardPage) {
                window.location.href = '?page=' + dashboardPage + '&start_tour=1';
                return;
            }
            
            if (typeof GuidedTour !== 'undefined') {
                // Use server-side role for role-specific steps
                const steps = typeof generateTourSteps !== 'undefined' ? generateTourSteps(userRole) : (window.berufsmesseTourSteps || []);

                window.currentGuidedTour = new GuidedTour({
                    steps: steps,
                    role: userRole,
                    onComplete: () => {
                        if (typeof showToast !== 'undefined') {
                            showToast('Tour abgeschlossen! 🎉', 'success');
                        }
                        window.currentGuidedTour = null;
                    },
                    onSkip: () => {
                        if (typeof showToast !== 'undefined') {
                            showToast('Tour übersprungen', 'info');
                        }
                        window.currentGuidedTour = null;
                    }
                });
                window.currentGuidedTour.reset(); // Allow restarting
                window.currentGuidedTour.start();
            } else {
                console.warn('GuidedTour nicht geladen');
            }
        }
        
        // Initialize page animations
        document.addEventListener('DOMContentLoaded', () => {
            // Add animation class to cards
            document.querySelectorAll('.card, .quick-action-card').forEach((card, index) => {
                card.style.animationDelay = `${index * 50}ms`;
            });
        });

        // ==========================================================================
        // EXHIBITOR MODAL SYSTEM (GLOBAL) - Komplett neu geschrieben
        // ==========================================================================
        const ExhibitorModal = {
            overlay: null,
            
            init() {
                this.overlay = document.getElementById('exhibitorModalOverlay');
                if (!this.overlay) return;
                
                // Click auf Overlay schließt Modal
                this.overlay.addEventListener('click', (e) => {
                    if (e.target === this.overlay) {
                        this.close();
                    }
                });
                
                // ESC schließt Modal
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.overlay.classList.contains('active')) {
                        this.close();
                    }
                });
            },
            
            open(exhibitorId) {
                if (!this.overlay) this.init();
                
                // Modal anzeigen
                this.overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
                
                // Register Button aktualisieren
                const regBtn = document.getElementById('modalRegisterBtn');
                if (regBtn) {
                    regBtn.href = '?page=registration&exhibitor=' + exhibitorId;
                    const isManagement = <?php echo (isTeacher() || isAdmin() || isOrga()) ? 'true' : 'false'; ?>;
                    const isRegistrationPage = '<?php echo $currentPage; ?>' === 'registration';
                    regBtn.style.display = (isManagement || isRegistrationPage) ? 'none' : 'inline-flex';
                }
                
                // Daten laden
                this.loadDetails(exhibitorId);
            },
            
            close() {
                if (!this.overlay) return;
                this.overlay.classList.remove('active');
                document.body.style.overflow = '';
            },
            
            loadDetails(exhibitorId) {
                const modalBody = document.getElementById('modalBody');
                modalBody.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:3rem;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#10b981;"></i></div>';
                
                fetch('api/get-exhibitor.php?id=' + exhibitorId + '&tab=details')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('modalTitle').textContent = data.exhibitor.name;
                            modalBody.innerHTML = data.content;
                        } else {
                            modalBody.innerHTML = '<div style="text-align:center;padding:3rem;color:#ef4444;">Fehler beim Laden</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        modalBody.innerHTML = '<div style="text-align:center;padding:3rem;color:#ef4444;">Fehler beim Laden</div>';
                    });
            }
        };
        
        // Globale Funktionen für onclick Handler
        function openExhibitorModal(exhibitorId) {
            ExhibitorModal.open(exhibitorId);
        }
        
        function closeExhibitorModal() {
            ExhibitorModal.close();
        }
        
        // Initialisierung
        document.addEventListener('DOMContentLoaded', () => {
            ExhibitorModal.init();
        });
    </script>

    <!-- Global Exhibitor Modal - Komplett neu geschrieben -->
    <div id="exhibitorModalOverlay" class="exhibitor-modal-overlay">
        <div class="exhibitor-modal-box">
            <!-- Modal Header -->
            <div style="padding: 1rem 1.5rem; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between;">
                <h2 id="modalTitle" style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin: 0;">Unternehmensdetails</h2>
                <button onclick="closeExhibitorModal()" style="background: none; border: none; padding: 0.5rem; cursor: pointer; color: #9ca3af; border-radius: 0.5rem; transition: all 0.2s;" onmouseover="this.style.background='#f3f4f6';this.style.color='#4b5563'" onmouseout="this.style.background='none';this.style.color='#9ca3af'">
                    <i class="fas fa-times" style="font-size: 1.125rem;"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div id="modalBody" style="padding: 1.5rem; overflow-y: auto; flex: 1;">
                <div style="display: flex; align-items: center; justify-content: center; padding: 3rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #10b981;"></i>
                </div>
            </div>

            <!-- Modal Footer -->
            <div style="padding: 1rem 1.5rem; border-top: 1px solid #f3f4f6; background: #f9fafb; display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button onclick="closeExhibitorModal()" style="padding: 0.5rem 1rem; background: #e5e7eb; color: #374151; border: none; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#d1d5db'" onmouseout="this.style.background='#e5e7eb'">
                    Schließen
                </button>
                <a id="modalRegisterBtn" href="#" style="padding: 0.5rem 1rem; background: #10b981; color: white; border: none; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; transition: background 0.2s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                    <i class="fas fa-user-plus" style="margin-right: 0.5rem;"></i> Einschreiben
                </a>
            </div>
        </div>
    </div>
</body>
</html>
