<?php
// Admin Einstellungen

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('einstellungen_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('einstellungen_bearbeiten')) {
        die('Keine Berechtigung');
    }
    $regStart = $_POST['registration_start'];
    $regEnd = $_POST['registration_end'];
    $eventDate = $_POST['event_date'];
    $maxReg = intval($_POST['max_registrations_per_student']);

    // Auto-Close Einstellung (Issue #12)
    $autoClose = isset($_POST['auto_close_registration']) ? '1' : '0';

    // Ein-Slot-Modus (für den BSO-Tagesablauf mit genau einer Zuteilung je Schüler)
    $singleSlotEnabled = isset($_POST['single_slot_assignment_enabled']) ? '1' : '0';
    $singleSlotTimeslotId = max(0, intval($_POST['single_slot_timeslot_id'] ?? 0));
    $singleSlotLawCapacity = max(1, intval($_POST['single_slot_law_capacity'] ?? 20));

    $singleSlotFacultyIds = [
        'single_slot_faculty_bio_chem_pharma_id' => max(0, intval($_POST['single_slot_faculty_bio_chem_pharma_id'] ?? 0)),
        'single_slot_faculty_education_psychology_id' => max(0, intval($_POST['single_slot_faculty_education_psychology_id'] ?? 0)),
        'single_slot_faculty_politics_social_id' => max(0, intval($_POST['single_slot_faculty_politics_social_id'] ?? 0)),
        'single_slot_faculty_law_id' => max(0, intval($_POST['single_slot_faculty_law_id'] ?? 0)),
        'single_slot_faculty_economics_id' => max(0, intval($_POST['single_slot_faculty_economics_id'] ?? 0)),
    ];

    $filledFacultyIds = array_values(array_filter($singleSlotFacultyIds, static function ($id) {
        return $id > 0;
    }));

    if ($singleSlotEnabled === '1' && (count($filledFacultyIds) !== 5 || count(array_unique($filledFacultyIds)) !== 5)) {
        $message = ['type' => 'error', 'text' => 'Ein-Slot-Modus aktiv: Bitte 5 unterschiedliche Fachbereich-IDs auswählen.'];
    } else {
        updateSetting('registration_start', $regStart);
        updateSetting('registration_end', $regEnd);
        updateSetting('event_date', $eventDate);
        updateSetting('max_registrations_per_student', $maxReg);
        updateSetting('auto_close_registration', $autoClose);

        updateSetting('single_slot_assignment_enabled', $singleSlotEnabled);
        updateSetting('single_slot_timeslot_id', $singleSlotTimeslotId > 0 ? (string)$singleSlotTimeslotId : '');
        updateSetting('single_slot_law_capacity', (string)$singleSlotLawCapacity);

        foreach ($singleSlotFacultyIds as $key => $value) {
            updateSetting($key, $value > 0 ? (string)$value : '');
        }

        $slotInfo = $singleSlotTimeslotId > 0 ? "Timeslot-ID: $singleSlotTimeslotId" : 'Timeslot-ID: automatisch';
        logAuditAction(
            'einstellungen_geaendert',
            "Einschreibezeitraum: $regStart - $regEnd, Veranstaltung: $eventDate, Max. Registrierungen: $maxReg, Auto-Close: $autoClose, Ein-Slot-Modus: $singleSlotEnabled, Rechts-Limit: $singleSlotLawCapacity, $slotInfo"
        );
        $message = ['type' => 'success', 'text' => 'Einstellungen erfolgreich gespeichert'];
    }
}

// Handle Security Settings Update (nur Admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_security'])) {
    requireCsrf();
    if (!isAdmin()) {
        die('Keine Berechtigung - nur Administratoren');
    }
    $regEnabled = isset($_POST['registration_page_enabled']) ? '1' : '0';
    $sitePassEnabled = isset($_POST['site_password_enabled']) ? '1' : '0';
    $sitePass = $_POST['site_password'] ?? '';

    updateSetting('registration_page_enabled', $regEnabled);
    updateSetting('site_password_enabled', $sitePassEnabled);

    // Passwort nur aktualisieren wenn ein neues eingegeben wurde
    if (!empty($sitePass)) {
        updateSetting('site_password', password_hash($sitePass, PASSWORD_DEFAULT));
    }

    // Wenn Seitenpasswort deaktiviert wird, Hash loeschen
    if ($sitePassEnabled === '0') {
        updateSetting('site_password', '');
    }

    logAuditAction('sicherheit_geaendert', "Registrierung: $regEnabled, Seitenpasswort: $sitePassEnabled");
    $message = ['type' => 'success', 'text' => 'Sicherheitseinstellungen gespeichert'];
}

// Handle QR Code URL Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_qr_url'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('einstellungen_bearbeiten')) {
        die('Keine Berechtigung');
    }
    $qrUrl = sanitize($_POST['qr_url']);
    updateSetting('qr_code_url', $qrUrl);
    logAuditAction('qr_url_geaendert', "QR-Code Base-URL auf '$qrUrl' gesetzt");
    $message = ['type' => 'success', 'text' => 'QR-Code URL erfolgreich gespeichert'];
}

// Handle QR Code Validity Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_qr_validity'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('einstellungen_bearbeiten')) {
        die('Keine Berechtigung');
    }
    $before = max(0, intval($_POST['qr_validity_before']));
    $after  = max(0, intval($_POST['qr_validity_after']));
    $enabled = isset($_POST['qr_validity_enabled']) ? '1' : '0';
    updateSetting('qr_validity_before', $before);
    updateSetting('qr_validity_after', $after);
    updateSetting('qr_validity_enabled', $enabled);
    logAuditAction('qr_gueltigkeit_geaendert', "QR-Gültigkeitsfenster: $before Min. vor / $after Min. nach Slot | Aktiviert: $enabled");
    $message = ['type' => 'success', 'text' => 'Gültigkeitsdauer erfolgreich gespeichert'];
}

// Branchen-Verwaltung wurde nach admin-exhibitors.php verschoben (Issue #XX)

// Aktuelle Einstellungen laden
$currentSettings = [
    'registration_start' => getSetting('registration_start'),
    'registration_end' => getSetting('registration_end'),
    'event_date' => getSetting('event_date'),
    'max_registrations_per_student' => getSetting('max_registrations_per_student', 3),
    'auto_close_registration' => getSetting('auto_close_registration', '1'),
    'qr_code_url' => getSetting('qr_code_url', 'https://localhost' . BASE_URL),
    'qr_validity_before' => getSetting('qr_validity_before', 10),
    'qr_validity_after' => getSetting('qr_validity_after', 15),
    'qr_validity_enabled' => getSetting('qr_validity_enabled', '1'),
    'registration_page_enabled' => getSetting('registration_page_enabled', '0'),
    'site_password_enabled' => getSetting('site_password_enabled', '0'),
    'site_password_set' => !empty(getSetting('site_password', '')),
    'single_slot_assignment_enabled' => getSetting('single_slot_assignment_enabled', '0'),
    'single_slot_timeslot_id' => getSetting('single_slot_timeslot_id', ''),
    'single_slot_law_capacity' => getSetting('single_slot_law_capacity', 20),
    'single_slot_faculty_bio_chem_pharma_id' => getSetting('single_slot_faculty_bio_chem_pharma_id', ''),
    'single_slot_faculty_education_psychology_id' => getSetting('single_slot_faculty_education_psychology_id', ''),
    'single_slot_faculty_politics_social_id' => getSetting('single_slot_faculty_politics_social_id', ''),
    'single_slot_faculty_law_id' => getSetting('single_slot_faculty_law_id', ''),
    'single_slot_faculty_economics_id' => getSetting('single_slot_faculty_economics_id', ''),
];

// Branchen-Verwaltung wurde nach admin-exhibitors.php verschoben

// Zeitslot bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_timeslot'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('einstellungen_bearbeiten')) die('Keine Berechtigung');
    $slotId    = intval($_POST['slot_id']);
    $slotName  = trim($_POST['slot_name']);
    $startTime = trim($_POST['start_time']);
    $endTime   = trim($_POST['end_time']);
    $isManaged = isset($_POST['is_managed']) ? 1 : 0;
    $isBreak   = isset($_POST['is_break']) ? 1 : 0;
    if ($isBreak) $isManaged = 0;
    if (empty($slotName)) {
        $message = ['type' => 'error', 'text' => 'Slot-Name darf nicht leer sein.'];
    } else {
        // Überschneidungsprüfung
        $overlapStmt = $db->prepare("SELECT slot_name FROM timeslots WHERE edition_id = ? AND id != ? AND start_time < ? AND end_time > ?");
        $overlapStmt->execute([$activeEditionId, $slotId, $endTime, $startTime]);
        $overlap = $overlapStmt->fetch();
        if ($overlap && $startTime && $endTime) {
            $message = ['type' => 'error', 'text' => 'Zeitraum überschneidet sich mit "' . htmlspecialchars($overlap['slot_name']) . '".'];
        } else {
            $db->prepare("UPDATE timeslots SET slot_name=?, start_time=?, end_time=?, is_managed=?, is_break=? WHERE id=?")
               ->execute([$slotName, $startTime ?: null, $endTime ?: null, $isManaged, $isBreak, $slotId]);
            logAuditAction('timeslot_bearbeitet', "Slot #$slotId: '$slotName', managed=$isManaged, break=$isBreak", 'warning');
            $message = ['type' => 'success', 'text' => 'Zeitslot gespeichert.'];
        }
    }
}

// Zeitslot hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_timeslot'])) {
    requireCsrf();
    if (!isAdmin()) die('Keine Berechtigung');
    $slotName  = trim($_POST['new_slot_name']);
    $startTime = trim($_POST['new_start_time']);
    $endTime   = trim($_POST['new_end_time']);
    $isManaged = isset($_POST['new_is_managed']) ? 1 : 0;
    $isBreak   = isset($_POST['new_is_break']) ? 1 : 0;
    if ($isBreak) $isManaged = 0;
    if (empty($slotName)) {
        $message = ['type' => 'error', 'text' => 'Slot-Name darf nicht leer sein.'];
    } else {
        // Überschneidungsprüfung
        $overlapStmt = $db->prepare("SELECT slot_name FROM timeslots WHERE edition_id = ? AND start_time < ? AND end_time > ?");
        $overlapStmt->execute([$activeEditionId, $endTime, $startTime]);
        $overlap = $overlapStmt->fetch();
        if ($overlap && $startTime && $endTime) {
            $message = ['type' => 'error', 'text' => 'Zeitraum überschneidet sich mit "' . htmlspecialchars($overlap['slot_name']) . '".'];
        } else {
            $stmt = $db->prepare("SELECT COALESCE(MAX(slot_number),0) FROM timeslots WHERE edition_id = ?");
            $stmt->execute([$activeEditionId]);
            $maxNum = (int)$stmt->fetchColumn();
            $db->prepare("INSERT INTO timeslots (slot_number,slot_name,start_time,end_time,is_managed,is_break,edition_id) VALUES (?,?,?,?,?,?,?)")
               ->execute([$maxNum + 1, $slotName, $startTime ?: null, $endTime ?: null, $isManaged, $isBreak, $activeEditionId]);
            logAuditAction('timeslot_erstellt', "Neuer Slot '$slotName'");
            $message = ['type' => 'success', 'text' => 'Zeitslot hinzugefügt.'];
        }
    }
}

// Zeitslot löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_timeslot'])) {
    requireCsrf();
    if (!isAdmin()) die('Keine Berechtigung');
    $slotId = intval($_POST['slot_id']);
    $stmtCheck = $db->prepare("
        SELECT (SELECT COUNT(*) FROM registrations WHERE timeslot_id=?) +
               (SELECT COUNT(*) FROM attendance    WHERE timeslot_id=?) +
               (SELECT COUNT(*) FROM qr_tokens     WHERE timeslot_id=?) AS total
    ");
    $stmtCheck->execute([$slotId, $slotId, $slotId]);
    $usageCount = (int)$stmtCheck->fetchColumn();
    if ($usageCount > 0) {
        $message = ['type' => 'error', 'text' => "Slot kann nicht gelöscht werden – $usageCount verknüpfte Einträge."];
    } else {
        $db->prepare("DELETE FROM timeslots WHERE id=?")->execute([$slotId]);
        logAuditAction('timeslot_geloescht', "Slot #$slotId gelöscht", 'warning');
        $message = ['type' => 'success', 'text' => 'Zeitslot gelöscht.'];
    }
}

$stmt = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmt->execute([$activeEditionId]);
$allTimeslots = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM exhibitors WHERE active = 1 AND edition_id = ? ORDER BY name ASC");
$stmt->execute([$activeEditionId]);
$activeExhibitors = $stmt->fetchAll();
?>

<!-- Einstellungen – Tab-basiertes Mobile-First Layout -->
<div class="max-w-7xl mx-auto space-y-4">
    
    <!-- Header -->
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-bold text-gray-800">
            <i class="fas fa-cog text-emerald-500 mr-2"></i>Einstellungen
        </h2>
    </div>

    <?php if (isset($message)): ?>
    <div class="bg-<?php echo $message['type'] === 'success' ? 'emerald' : 'red'; ?>-50 border border-<?php echo $message['type'] === 'success' ? 'emerald' : 'red'; ?>-200 p-3 rounded-lg flex items-center gap-2 text-sm">
        <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check-circle text-emerald-500' : 'exclamation-circle text-red-500'; ?>"></i>
        <span class="<?php echo $message['type'] === 'success' ? 'text-emerald-700' : 'text-red-700'; ?>"><?php echo $message['text']; ?></span>
    </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div class="flex border-b border-gray-100 overflow-x-auto" id="settingsTabs" style="-webkit-overflow-scrolling: touch; scrollbar-width: none;">
            <button onclick="switchSettingsTab('allgemein')" data-tab="allgemein"
                    class="settings-tab active flex items-center gap-2 px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 border-emerald-500 text-emerald-600 bg-emerald-50/50 transition-all">
                <i class="fas fa-sliders-h"></i> <span>Allgemein</span>
            </button>
            <button onclick="switchSettingsTab('qrcodes')" data-tab="qrcodes"
                    class="settings-tab flex items-center gap-2 px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition-all">
                <i class="fas fa-qrcode"></i> <span>QR-Codes</span>
            </button>
            <?php if (isAdmin()): ?>
            <button onclick="switchSettingsTab('sicherheit')" data-tab="sicherheit"
                    class="settings-tab flex items-center gap-2 px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition-all">
                <i class="fas fa-shield-alt"></i> <span>Sicherheit</span>
            </button>
            <?php endif; ?>
            <button onclick="switchSettingsTab('system')" data-tab="system"
                    class="settings-tab flex items-center gap-2 px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 transition-all">
                <i class="fas fa-info-circle"></i> <span>System</span>
            </button>
            <button onclick="switchSettingsTab('zeitslots')" data-tab="zeitslots"
                    class="settings-tab flex items-center gap-2 px-4 py-3 text-sm font-medium
                           whitespace-nowrap border-b-2 border-transparent text-gray-500
                           hover:text-gray-700 hover:bg-gray-50 transition-all">
                <i class="fas fa-clock"></i> Zeitslots
            </button>
        </div>

        <!-- ============================================================ -->
        <!-- TAB 1: Allgemein -->
        <!-- ============================================================ -->
        <div id="tab-allgemein" class="settings-tab-content p-4 sm:p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                
                <!-- Schnellstatus -->
                <div class="p-3 rounded-lg border <?php 
                    $status = getRegistrationStatus();
                    echo $status === 'open' ? 'bg-emerald-50 border-emerald-200' : ($status === 'upcoming' ? 'bg-amber-50 border-amber-200' : 'bg-red-50 border-red-200');
                ?>">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <span class="w-2.5 h-2.5 rounded-full <?php echo $status === 'open' ? 'bg-emerald-500' : ($status === 'upcoming' ? 'bg-amber-500' : 'bg-red-500'); ?>"></span>
                        <span class="<?php echo $status === 'open' ? 'text-emerald-700' : ($status === 'upcoming' ? 'text-amber-700' : 'text-red-700'); ?>">
                            Einschreibung: <?php echo $status === 'open' ? 'Offen' : ($status === 'upcoming' ? 'Noch nicht gestartet' : 'Geschlossen'); ?>
                        </span>
                    </div>
                </div>

                <!-- Einschreibezeitraum -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-purple-500"></i> Einschreibezeitraum
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Beginn *</label>
                            <input type="datetime-local" name="registration_start" 
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($currentSettings['registration_start'])); ?>"
                                   required
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Ende *</label>
                            <input type="datetime-local" name="registration_end" 
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($currentSettings['registration_end'])); ?>"
                                   required
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm">
                        </div>
                    </div>
                </div>

                <!-- Veranstaltungsdatum -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-calendar-check text-green-500"></i> Veranstaltung
                    </h4>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Datum des BSO-Tags *</label>
                        <input type="date" name="event_date" 
                               value="<?php echo $currentSettings['event_date']; ?>"
                               required
                               class="w-full sm:w-auto px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm">
                    </div>
                </div>

                <!-- Einschreibungsparameter -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-sliders-h text-blue-500"></i> Parameter
                    </h4>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Max. Einschreibungen pro Schüler *</label>
                            <div class="flex items-center gap-3">
                                <input type="number" name="max_registrations_per_student" 
                                       value="<?php echo $currentSettings['max_registrations_per_student']; ?>"
                                       min="1" max="20" required
                                       class="w-20 px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm text-center font-bold text-lg">
                                <span class="text-xs text-gray-500">Fachbereiche (= Zeitslots)</span>
                            </div>
                        </div>
                        
                        <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <input type="checkbox" name="auto_close_registration" value="1"
                                   <?php echo $currentSettings['auto_close_registration'] === '1' ? 'checked' : ''; ?>
                                   class="w-5 h-5 text-emerald-500 rounded border-gray-300 focus:ring-emerald-400 mt-0.5 flex-shrink-0">
                            <div>
                                <span class="text-sm font-medium text-gray-700 block">Automatisch schliessen nach Zuteilung</span>
                                <span class="text-xs text-gray-500">Einschreibung wird nach der automatischen Zuteilung geschlossen.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Ein-Slot-Modus -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-chess-board text-indigo-500"></i> Ein-Slot-Modus
                    </h4>
                    <div class="space-y-4">
                        <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                            <input type="checkbox" name="single_slot_assignment_enabled" value="1"
                                   id="singleSlotEnabledToggle"
                                   onchange="toggleSingleSlotConfig(this.checked)"
                                   <?php echo $currentSettings['single_slot_assignment_enabled'] === '1' ? 'checked' : ''; ?>
                                   class="w-5 h-5 text-indigo-500 rounded border-gray-300 focus:ring-indigo-400 mt-0.5 flex-shrink-0">
                            <div>
                                <span class="text-sm font-medium text-gray-700 block">Ein-Slot-Autozuteilung aktivieren</span>
                                <span class="text-xs text-gray-500">Genau 1 Zuteilung pro Schüler. Verwendet Prioritäten und verteilt ausgeglichen auf 5 Fachbereiche.</span>
                            </div>
                        </label>

                        <div id="singleSlotConfigFields" class="space-y-4 <?php echo $currentSettings['single_slot_assignment_enabled'] === '1' ? '' : 'opacity-50 pointer-events-none'; ?>">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Ziel-Timeslot für Zuteilung</label>
                                    <select name="single_slot_timeslot_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Automatisch erster gültiger Slot</option>
                                        <?php foreach ($allTimeslots as $slot): ?>
                                            <?php if (!empty($slot['is_break'])) continue; ?>
                                            <?php $slotId = (string)$slot['id']; ?>
                                            <option value="<?php echo $slotId; ?>" <?php echo (string)$currentSettings['single_slot_timeslot_id'] === $slotId ? 'selected' : ''; ?>>
                                                #<?php echo (int)$slot['slot_number']; ?> - <?php echo htmlspecialchars($slot['slot_name']); ?>
                                                <?php if (!empty($slot['start_time']) && !empty($slot['end_time'])): ?>
                                                    (<?php echo substr($slot['start_time'], 0, 5); ?> - <?php echo substr($slot['end_time'], 0, 5); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Max. Teilnehmer Rechtswissenschaft</label>
                                    <input type="number" name="single_slot_law_capacity"
                                           value="<?php echo (int)$currentSettings['single_slot_law_capacity']; ?>"
                                           min="1" max="500"
                                           class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">1) Biologie, Chemie, Pharmazie (ID)</label>
                                    <select name="single_slot_faculty_bio_chem_pharma_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Bitte wählen</option>
                                        <?php foreach ($activeExhibitors as $ex): ?>
                                            <?php $id = (string)$ex['id']; ?>
                                            <option value="<?php echo $id; ?>" <?php echo (string)$currentSettings['single_slot_faculty_bio_chem_pharma_id'] === $id ? 'selected' : ''; ?>>
                                                <?php echo $id; ?> - <?php echo htmlspecialchars($ex['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">2) Erziehungswiss., Psychologie, Lehramt (ID)</label>
                                    <select name="single_slot_faculty_education_psychology_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Bitte wählen</option>
                                        <?php foreach ($activeExhibitors as $ex): ?>
                                            <?php $id = (string)$ex['id']; ?>
                                            <option value="<?php echo $id; ?>" <?php echo (string)$currentSettings['single_slot_faculty_education_psychology_id'] === $id ? 'selected' : ''; ?>>
                                                <?php echo $id; ?> - <?php echo htmlspecialchars($ex['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">3) Politik und Sozialwissenschaften (ID)</label>
                                    <select name="single_slot_faculty_politics_social_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Bitte wählen</option>
                                        <?php foreach ($activeExhibitors as $ex): ?>
                                            <?php $id = (string)$ex['id']; ?>
                                            <option value="<?php echo $id; ?>" <?php echo (string)$currentSettings['single_slot_faculty_politics_social_id'] === $id ? 'selected' : ''; ?>>
                                                <?php echo $id; ?> - <?php echo htmlspecialchars($ex['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">4) Rechtswissenschaften (ID)</label>
                                    <select name="single_slot_faculty_law_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Bitte wählen</option>
                                        <?php foreach ($activeExhibitors as $ex): ?>
                                            <?php $id = (string)$ex['id']; ?>
                                            <option value="<?php echo $id; ?>" <?php echo (string)$currentSettings['single_slot_faculty_law_id'] === $id ? 'selected' : ''; ?>>
                                                <?php echo $id; ?> - <?php echo htmlspecialchars($ex['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">5) Wirtschaftswissenschaften (ID)</label>
                                    <select name="single_slot_faculty_economics_id" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 text-sm bg-white">
                                        <option value="">Bitte wählen</option>
                                        <?php foreach ($activeExhibitors as $ex): ?>
                                            <?php $id = (string)$ex['id']; ?>
                                            <option value="<?php echo $id; ?>" <?php echo (string)$currentSettings['single_slot_faculty_economics_id'] === $id ? 'selected' : ''; ?>>
                                                <?php echo $id; ?> - <?php echo htmlspecialchars($ex['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="text-xs text-gray-500 bg-indigo-50 border border-indigo-100 rounded-lg p-3">
                                Hinweis: Der Ein-Slot-Algorithmus setzt vor dem Lauf alle bisherigen Schüler-Zuordnungen zurück und berechnet die Zuteilung komplett neu.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Speichern -->
                <div class="pt-2">
                    <?php if (isAdmin() || hasPermission('einstellungen_bearbeiten')): ?>
                    <button type="submit" name="save_settings"
                            class="w-full sm:w-auto px-6 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
                        <i class="fas fa-save mr-2"></i>Einstellungen speichern
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ============================================================ -->
        <!-- TAB 2: QR-Codes -->
        <!-- ============================================================ -->
        <div id="tab-qrcodes" class="settings-tab-content hidden p-4 sm:p-6">
            <div class="space-y-6">
                
                <!-- Base URL Einstellung -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <h4 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-link text-emerald-500"></i> Base-URL für QR-Codes
                    </h4>
                    <p class="text-xs text-gray-500">Diese URL wird als Basis für die QR-Code-Check-in-Links verwendet. Die Schüler scannen Codes, die auf diese URL mit Token verweisen.</p>
                    
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">URL *</label>
                        <input type="url" name="qr_url" id="qrUrlInput"
                               value="<?php echo htmlspecialchars($currentSettings['qr_code_url']); ?>"
                               required placeholder="https://beispiel.de/bso-tag"
                               oninput="updateQrPreview()"
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm font-mono">
                    </div>
                    
                    <!-- Live QR-Vorschau -->
                    <div class="flex flex-col sm:flex-row gap-4 items-start">
                        <div class="bg-white p-3 rounded-lg border-2 border-gray-200 inline-block flex-shrink-0">
                            <img id="qrPreviewImg" 
                                 src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode($currentSettings['qr_code_url']); ?>" 
                                 alt="QR-Code Vorschau" class="w-36 h-36 sm:w-44 sm:h-44">
                        </div>
                        <div class="flex-1 space-y-2">
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Vorschau-URL für Check-in: <br>
                                <code id="qrPreviewUrl" class="text-xs text-emerald-600 break-all"><?php echo htmlspecialchars($currentSettings['qr_code_url']); ?>?page=qr-checkin&amp;token=BEISPIEL</code>
                            </p>
                            <div class="grid grid-cols-1 gap-2">
                                <a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?php echo urlencode($currentSettings['qr_code_url']); ?>" 
                                   download="bso-qrcode.png" target="_blank"
                                   class="flex items-center justify-center gap-2 px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition text-xs font-medium">
                                    <i class="fas fa-download"></i> Download (600px)
                                </a>
                                <a href="https://api.qrserver.com/v1/create-qr-code/?size=1200x1200&data=<?php echo urlencode($currentSettings['qr_code_url']); ?>" 
                                   download="bso-qrcode-hd.png" target="_blank"
                                   class="flex items-center justify-center gap-2 px-3 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition text-xs font-medium">
                                    <i class="fas fa-download"></i> Download HD (1200px)
                                </a>
                                <button type="button" onclick="window.print()"
                                        class="flex items-center justify-center gap-2 px-3 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition text-xs font-medium">
                                    <i class="fas fa-print"></i> QR-Code drucken
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isAdmin() || hasPermission('einstellungen_bearbeiten')): ?>
                    <button type="submit" name="save_qr_url"
                            class="w-full sm:w-auto px-6 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
                        <i class="fas fa-save mr-2"></i>URL speichern
                    </button>
                    <?php endif; ?>
                </form>

                <hr class="border-gray-200 my-4">

                <!-- Gültigkeitsdauer der QR-Codes -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <h4 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-clock text-amber-500"></i> Gültigkeitsdauer der QR-Codes
                    </h4>
                    <p class="text-xs text-gray-500">
                        Lege fest, wie lange vor Slotbeginn und wie lange nach Slotende ein QR-Code gültig ist.
                        Das Datum wird dem in den Einstellungen festgelegten Messetermin entnommen.
                    </p>

                    <!-- Zeitfenster-Toggle -->
                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                        <input type="checkbox" name="qr_validity_enabled" value="1"
                               id="qrValidityEnabled"
                               <?php echo $currentSettings['qr_validity_enabled'] === '1' ? 'checked' : ''; ?>
                               onchange="toggleQrValidityFields(this.checked)"
                               class="w-5 h-5 text-amber-500 rounded border-gray-300 focus:ring-amber-400 mt-0.5 flex-shrink-0">
                        <div>
                            <span class="text-sm font-medium text-gray-700 block">Zeitfenster-Prüfung aktivieren</span>
                            <span class="text-xs text-gray-500">Wenn deaktiviert, sind QR-Codes jederzeit gültig – unabhängig vom Zeitslot.</span>
                        </div>
                    </label>

                    <div id="qrValidityFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4 <?php echo $currentSettings['qr_validity_enabled'] !== '1' ? 'opacity-40 pointer-events-none' : ''; ?>">
                        <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
                            <label class="block text-xs font-semibold text-blue-800 mb-2">
                                <i class="fas fa-hourglass-start mr-1"></i>
                                Minuten <strong>vor</strong> Slotbeginn (Check-in erlaubt ab)
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="qr_validity_before"
                                       value="<?php echo $currentSettings['qr_validity_before']; ?>"
                                       min="0" max="120" required
                                       class="w-24 px-3 py-2 border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-400 text-center font-bold text-lg bg-white">
                                <span class="text-xs text-blue-700">Minuten</span>
                            </div>
                            <p class="text-xs text-blue-600 mt-1">
                                Bsp.: <strong>10</strong> → QR-Code bei Slot 09:00 gültig ab <strong>08:50</strong>
                            </p>
                        </div>

                        <div class="bg-orange-50 border border-orange-100 rounded-lg p-4">
                            <label class="block text-xs font-semibold text-orange-800 mb-2">
                                <i class="fas fa-hourglass-end mr-1"></i>
                                Minuten <strong>nach</strong> Slotende (Check-in erlaubt bis)
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="qr_validity_after"
                                       value="<?php echo $currentSettings['qr_validity_after']; ?>"
                                       min="0" max="120" required
                                       class="w-24 px-3 py-2 border border-orange-200 rounded-lg focus:ring-2 focus:ring-orange-400 text-center font-bold text-lg bg-white">
                                <span class="text-xs text-orange-700">Minuten</span>
                            </div>
                            <p class="text-xs text-orange-600 mt-1">
                                Bsp.: <strong>15</strong> → QR-Code bei Slot 09:00–09:30 erlaubt bis <strong>09:45</strong>
                            </p>
                        </div>
                    </div>

                    <?php if (isAdmin() || hasPermission('einstellungen_bearbeiten')): ?>
                    <button type="submit" name="save_qr_validity"
                            class="w-full sm:w-auto px-6 py-2.5 bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition font-medium text-sm">
                        <i class="fas fa-save mr-2"></i>Gültigkeitsdauer speichern
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: Sicherheit (nur fuer Admins) -->
        <!-- ============================================================ -->
        <?php if (isAdmin()): ?>
        <div id="tab-sicherheit" class="settings-tab-content hidden p-4 sm:p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                <!-- Registrierungsseite -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-user-plus text-purple-500"></i> Registrierungsseite
                    </h4>
                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
                        <input type="checkbox" name="registration_page_enabled" value="1"
                               <?php echo $currentSettings['registration_page_enabled'] === '1' ? 'checked' : ''; ?>
                               class="w-5 h-5 text-emerald-500 rounded border-gray-300 focus:ring-emerald-400 mt-0.5 flex-shrink-0">
                        <div>
                            <span class="text-sm font-medium text-gray-700 block">Registrierungsseite aktivieren</span>
                            <span class="text-xs text-gray-500">Wenn deaktiviert, wird register.php automatisch auf die Login-Seite umgeleitet. Benutzer koennen sich dann nicht selbst registrieren.</span>
                        </div>
                    </label>
                </div>

                <!-- Seitenpasswort -->
                <div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-key text-amber-500"></i> Seitenpasswort (Zugangscode)
                    </h4>
                    <p class="text-xs text-gray-500 mb-3">Schuetzt die gesamte Webseite mit einem Zugangscode. Besucher muessen diesen Code eingeben, bevor sie die Login-Seite sehen.</p>

                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition mb-3">
                        <input type="checkbox" name="site_password_enabled" value="1" id="sitePasswordToggle"
                               <?php echo $currentSettings['site_password_enabled'] === '1' ? 'checked' : ''; ?>
                               onchange="toggleSitePasswordField()"
                               class="w-5 h-5 text-emerald-500 rounded border-gray-300 focus:ring-emerald-400 mt-0.5 flex-shrink-0">
                        <div>
                            <span class="text-sm font-medium text-gray-700 block">Seitenpasswort aktivieren</span>
                            <span class="text-xs text-gray-500">Alle Seiten werden mit einem Zugangscode geschuetzt.</span>
                        </div>
                    </label>

                    <div id="sitePasswordField" class="<?php echo $currentSettings['site_password_enabled'] !== '1' ? 'hidden' : ''; ?>">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Neuen Zugangscode setzen</label>
                        <input type="password" name="site_password"
                               placeholder="<?php echo $currentSettings['site_password_set'] ? 'Aktueller Code gesetzt - leer lassen um beizubehalten' : 'Neuen Zugangscode eingeben'; ?>"
                               autocomplete="new-password"
                               class="w-full sm:w-80 px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm">
                        <?php if ($currentSettings['site_password_set']): ?>
                        <p class="text-xs text-emerald-600 mt-1"><i class="fas fa-check-circle mr-1"></i>Zugangscode ist gesetzt. Leer lassen, um den aktuellen Code beizubehalten.</p>
                        <?php else: ?>
                        <p class="text-xs text-gray-400 mt-1">Der Zugangscode wird verschluesselt gespeichert.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Speichern -->
                <div class="pt-2">
                    <button type="submit" name="save_security"
                            class="w-full sm:w-auto px-6 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
                        <i class="fas fa-save mr-2"></i>Sicherheitseinstellungen speichern
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TAB 3: System (Branchen wurde nach admin-exhibitors.php verschoben) -->
        <!-- ============================================================ -->
        <div id="tab-system" class="settings-tab-content hidden p-4 sm:p-6">
            <div class="space-y-4">
                <h4 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-server text-blue-500"></i> System-Information
                </h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                        <p class="text-xs text-gray-500 mb-0.5">PHP Version</p>
                        <p class="text-sm font-semibold text-gray-800"><?php echo phpversion(); ?></p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                        <p class="text-xs text-gray-500 mb-0.5">Datenbank</p>
                        <p class="text-sm font-semibold text-gray-800"><?php echo DB_NAME; ?></p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                        <p class="text-xs text-gray-500 mb-0.5">Max. Upload-Größe</p>
                        <p class="text-sm font-semibold text-gray-800"><?php echo round(MAX_FILE_SIZE / 1048576, 1); ?> MB</p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                        <p class="text-xs text-gray-500 mb-0.5">Upload-Verzeichnis</p>
                        <p class="text-sm font-semibold text-gray-800 text-xs break-all"><?php echo UPLOAD_DIR; ?></p>
                    </div>
                </div>

                <!-- Hinweise -->
                <div class="bg-amber-50 border border-amber-200 p-4 rounded-lg mt-4">
                    <h5 class="text-sm font-semibold text-amber-800 flex items-center gap-2 mb-2">
                        <i class="fas fa-exclamation-triangle text-amber-500"></i> Wichtige Hinweise
                    </h5>
                    <ul class="space-y-1.5 text-xs text-amber-700">
                        <li class="flex items-start gap-2">
                            <i class="fas fa-check text-amber-500 mt-0.5 flex-shrink-0"></i>
                            <span>Änderungen am Einschreibezeitraum wirken sich sofort aus.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fas fa-check text-amber-500 mt-0.5 flex-shrink-0"></i>
                            <span>Nach Ablauf der Frist → automatische Zuteilung durchführen.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fas fa-check text-amber-500 mt-0.5 flex-shrink-0"></i>
                            <span>Genügend Plätze für alle Schüler sicherstellen.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fas fa-check text-amber-500 mt-0.5 flex-shrink-0"></i>
                            <span>Max. Einschreibungen passend zum aktiven Zuteilungsmodus setzen (Mehrslot oder Ein-Slot).</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div id="tab-zeitslots" class="settings-tab-content hidden p-4 sm:p-6">

            <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                <strong>Achtung:</strong> Änderungen wirken sich sofort auf Anmeldungen, Raumzuteilung
                und automatische Zuteilung aus. Slots mit Anmeldungen können nicht gelöscht werden.
            </div>

            <h4 class="text-sm font-semibold text-gray-800 mb-3">Bestehende Zeitslots</h4>
            <div class="space-y-2 mb-6">
                <?php foreach ($allTimeslots as $slot): ?>
                <form method="POST" class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
                            <input type="text" name="slot_name"
                                   value="<?php echo htmlspecialchars($slot['slot_name']); ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Von</label>
                            <input type="time" name="start_time"
                                   value="<?php echo substr($slot['start_time'] ?? '', 0, 5); ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Bis</label>
                            <input type="time" name="end_time"
                                   value="<?php echo substr($slot['end_time'] ?? '', 0, 5); ?>"
                                   class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                        </div>
                        <div class="sm:col-span-2">
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_managed" value="1"
                                           <?php echo $slot['is_managed'] ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-emerald-500 rounded">
                                    <span class="text-xs text-gray-700 font-medium">Feste Zuteilung (Managed)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_break" value="1"
                                           <?php echo (!empty($slot['is_break'])) ? 'checked' : ''; ?>
                                           class="w-4 h-4 text-amber-500 rounded">
                                    <span class="text-xs text-gray-700 font-medium"><i class="fas fa-coffee mr-1"></i>Pause</span>
                                </label>
                            </div>
                        </div>
                        <div class="flex gap-2 sm:col-span-2 justify-end">
                            <?php if (isAdmin() || hasPermission('einstellungen_bearbeiten')): ?>
                            <button type="submit" name="save_timeslot"
                                    class="px-3 py-2 bg-emerald-500 text-white text-xs rounded-lg hover:bg-emerald-600 transition font-medium">
                                <i class="fas fa-save mr-1"></i>Speichern
                            </button>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                            <button type="submit" name="delete_timeslot"
                                    onclick="return confirm('Zeitslot wirklich löschen?')"
                                    class="px-3 py-2 bg-red-50 border border-red-200 text-red-600 text-xs rounded-lg hover:bg-red-100 transition font-medium">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>

            <?php if (isAdmin()): ?>
            <h4 class="text-sm font-semibold text-gray-800 mb-3">Neuen Zeitslot hinzufügen</h4>
            <form method="POST" class="bg-blue-50 border border-blue-100 rounded-xl p-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Name *</label>
                        <input type="text" name="new_slot_name" required placeholder="z.B. Slot 6"
                               class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Von</label>
                        <input type="time" name="new_start_time" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Bis</label>
                        <input type="time" name="new_end_time" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white">
                    </div>
                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="new_is_managed" value="1" class="w-4 h-4 text-blue-500 rounded">
                                <span class="text-xs text-gray-700 font-medium">Feste Zuteilung</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="new_is_break" value="1" class="w-4 h-4 text-amber-500 rounded">
                                <span class="text-xs text-gray-700 font-medium"><i class="fas fa-coffee mr-1"></i>Pause</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <button type="submit" name="add_timeslot"
                                class="w-full px-4 py-2 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600 transition font-medium">
                            <i class="fas fa-plus mr-1"></i>Hinzufügen
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

        </div><!-- /tab-zeitslots -->

    </div>
</div>

<style>
    /* Tab-Scrollbar auf Mobile verbergen */
    #settingsTabs::-webkit-scrollbar { display: none; }
</style>

<script>
function switchSettingsTab(tabName) {
    // Alle Tabs deaktivieren
    document.querySelectorAll('.settings-tab').forEach(btn => {
        btn.classList.remove('active', 'border-emerald-500', 'text-emerald-600', 'bg-emerald-50/50');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    document.querySelectorAll('.settings-tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Aktiven Tab aktivieren
    const activeBtn = document.querySelector(`.settings-tab[data-tab="${tabName}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active', 'border-emerald-500', 'text-emerald-600', 'bg-emerald-50/50');
        activeBtn.classList.remove('border-transparent', 'text-gray-500');
    }
    const activeContent = document.getElementById('tab-' + tabName);
    if (activeContent) {
        activeContent.classList.remove('hidden');
    }
    
    // URL Hash setzen für Deep-Linking
    history.replaceState(null, '', location.pathname + location.search + '#' + tabName);
}

// Tab aus URL Hash laden
document.addEventListener('DOMContentLoaded', function() {
    const hash = location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
        switchSettingsTab(hash);
    }
    // Branchen-Auto-Switch entfernt (Branchen jetzt in admin-exhibitors.php)
});

// Live QR-Vorschau
let qrUpdateTimer;
function updateQrPreview() {
    clearTimeout(qrUpdateTimer);
    qrUpdateTimer = setTimeout(function() {
        const url = document.getElementById('qrUrlInput').value;
        if (url) {
            document.getElementById('qrPreviewImg').src = 
                'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(url);
            document.getElementById('qrPreviewUrl').textContent = url + '?page=qr-checkin&token=BEISPIEL';
        }
    }, 500);
}

// Seitenpasswort-Feld ein-/ausblenden
function toggleSitePasswordField() {
    const toggle = document.getElementById('sitePasswordToggle');
    const field = document.getElementById('sitePasswordField');
    if (toggle.checked) {
        field.classList.remove('hidden');
    } else {
        field.classList.add('hidden');
    }
}

// QR-Gültigkeitsfelder ein-/ausblenden
function toggleQrValidityFields(enabled) {
    const fields = document.getElementById('qrValidityFields');
    if (fields) {
        if (enabled) {
            fields.classList.remove('opacity-40', 'pointer-events-none');
        } else {
            fields.classList.add('opacity-40', 'pointer-events-none');
        }
    }
}

function toggleSingleSlotConfig(enabled) {
    const fields = document.getElementById('singleSlotConfigFields');
    if (!fields) return;
    if (enabled) {
        fields.classList.remove('opacity-50', 'pointer-events-none');
    } else {
        fields.classList.add('opacity-50', 'pointer-events-none');
    }
}

// editIndustry und cancelEditIndustry wurden nach admin-exhibitors.php verschoben
</script>
