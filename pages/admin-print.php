<?php
/**
 * Berufsmesse - Admin Druckansicht
 * Professionelle Druckfunktion für Lehrkräfte und Administratoren
 * Mit Pastel-Design und PDF-optimiertem Layout
 */

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('berichte_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Verschiedene Druckoptionen
$printType = $_GET['type'] ?? 'all';
$filterClass = $_GET['class'] ?? '';
$filterRoom = $_GET['room'] ?? '';

// Basisdaten laden
if ($printType === 'all' || $printType === 'class') {
    // Alle Registrierungen mit Schülerdaten
    $query = "
        SELECT 
            u.firstname, u.lastname, u.class,
            e.name as exhibitor_name,
            t.slot_name, t.slot_number, t.start_time, t.end_time,
            r.room_number
        FROM registrations reg
        JOIN users u ON reg.user_id = u.id
        JOIN exhibitors e ON reg.exhibitor_id = e.id
        JOIN timeslots t ON reg.timeslot_id = t.id
        LEFT JOIN rooms r ON e.room_id = r.id
        WHERE u.role = 'student'
        AND reg.edition_id = ? AND e.edition_id = ?
    ";
    
    $params = [$activeEditionId, $activeEditionId];
    if ($filterClass) {
        $query .= " AND u.class = ?";
        $params[] = $filterClass;
    }
    
    $query .= " ORDER BY u.class, u.lastname, u.firstname, t.slot_number";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
} elseif ($printType === 'rooms') {
    // Raum-basierte Ansicht
    $query = "
        SELECT
            r.room_number,
            e.name as exhibitor_name,
            t.slot_name, t.slot_number, t.start_time, t.end_time,
            u.firstname, u.lastname, u.class
        FROM registrations reg
        JOIN users u ON reg.user_id = u.id
        JOIN exhibitors e ON reg.exhibitor_id = e.id
        JOIN timeslots t ON reg.timeslot_id = t.id
        JOIN rooms r ON e.room_id = r.id
        WHERE reg.edition_id = ? AND e.edition_id = ?
    ";

    $params = [$activeEditionId, $activeEditionId];
    if ($filterRoom) {
        $query .= " AND r.id = ?";
        $params[] = intval($filterRoom);
    }

    $query .= " ORDER BY r.room_number, t.slot_number, u.lastname, u.firstname";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
} elseif ($printType === 'room-assignments') {
    // Raumzuteilungs-Übersicht (Schulleitung)
    $stmt = $db->prepare("
        SELECT r.room_number, e.name as exhibitor_name, e.short_description
        FROM exhibitors e
        JOIN rooms r ON e.room_id = r.id
        WHERE e.active = 1
        AND e.edition_id = ?
        ORDER BY r.room_number, e.name
    ");
    $stmt->execute([$activeEditionId]);
    $roomAssignments = $stmt->fetchAll();
} elseif ($printType === 'absent') {
    // Fehlende Schüler: angemeldet aber nicht gescannt (alle Slots)
    $stmt = $db->prepare("
        SELECT u.firstname, u.lastname, u.class, e.name as exhibitor_name,
               t.slot_name, t.slot_number, r.room_number
        FROM registrations reg
        JOIN users u ON reg.user_id = u.id
        JOIN exhibitors e ON reg.exhibitor_id = e.id
        JOIN timeslots t ON reg.timeslot_id = t.id
        LEFT JOIN rooms r ON e.room_id = r.id
        LEFT JOIN attendance a ON a.user_id = reg.user_id AND a.exhibitor_id = reg.exhibitor_id AND a.timeslot_id = reg.timeslot_id AND a.edition_id = ?
        WHERE reg.timeslot_id IS NOT NULL AND a.id IS NULL AND u.role = 'student'
        AND reg.edition_id = ? AND e.edition_id = ?
        ORDER BY t.slot_number, u.class, u.lastname, u.firstname
    ");
    $stmt->execute([$activeEditionId, $activeEditionId, $activeEditionId]);
    $absentStudents = $stmt->fetchAll();
}

// Alle Klassen für Filter
$stmt = $db->query("SELECT DISTINCT class FROM users WHERE role = 'student' AND class IS NOT NULL AND class != '' ORDER BY class");
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Alle Räume für Filter
$stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE edition_id = ? ORDER BY room_number");
$stmt->execute([$activeEditionId]);
$rooms = $stmt->fetchAll();

// Statistiken
$stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as students FROM registrations WHERE edition_id = ?");
$stmt->execute([$activeEditionId]);
$totalStudents = $stmt->fetch()['students'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM registrations WHERE edition_id = ?");
$stmt->execute([$activeEditionId]);
$totalRegistrations = $stmt->fetch()['total'];
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-emerald-400 to-green-500 rounded-xl flex items-center justify-center text-white">
                        <i class="fas fa-print"></i>
                    </div>
                    Druckzentrale
                </h1>
                <p class="text-gray-500 mt-1">Erstelle professionelle Druckdokumente für den BSO-Tag an der FU</p>
            </div>
            
            <!-- Stats -->
            <div class="flex items-center gap-6">
                <div class="text-center">
                    <div class="text-2xl font-bold text-emerald-600"><?php echo $totalStudents; ?></div>
                    <div class="text-xs text-gray-500">Schüler</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-purple-600"><?php echo $totalRegistrations; ?></div>
                    <div class="text-xs text-gray-500">Anmeldungen</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-sky-600"><?php echo count($rooms); ?></div>
                    <div class="text-xs text-gray-500">Hörsäle</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filter Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fas fa-filter text-gray-400"></i>
            Druckoptionen
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Print Type -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-2 uppercase tracking-wide">Drucktyp</label>
                <div class="relative">
                    <select onchange="window.location.href='?page=admin-print&type='+this.value" 
                            class="w-full appearance-none px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 font-medium focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all cursor-pointer">
                        <option value="all" <?php echo $printType === 'all' ? 'selected' : ''; ?>>Gesamtübersicht</option>
                        <option value="class" <?php echo $printType === 'class' ? 'selected' : ''; ?>>Nach Klasse</option>
                        <option value="rooms" <?php echo $printType === 'rooms' ? 'selected' : ''; ?>>Nach Hörsaal</option>
                        <option value="room-assignments" <?php echo $printType === 'room-assignments' ? 'selected' : ''; ?>>Raumzuteilung (Schulleitung)</option>
                        <option value="absent" <?php echo $printType === 'absent' ? 'selected' : ''; ?>>Fehlende Schüler</option>
                    </select>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                </div>
            </div>
            
            <!-- Class Filter (conditional) -->
            <?php if ($printType === 'class'): ?>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-2 uppercase tracking-wide">Klasse filtern</label>
                <div class="relative">
                    <select onchange="window.location.href='?page=admin-print&type=class&class='+encodeURIComponent(this.value)" 
                            class="w-full appearance-none px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 font-medium focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all cursor-pointer">
                        <option value="">Alle Klassen</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $filterClass === $class ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Room Filter (conditional) -->
            <?php if ($printType === 'rooms'): ?>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-2 uppercase tracking-wide">Hörsaal filtern</label>
                <div class="relative">
                    <select onchange="window.location.href='?page=admin-print&type=rooms&room='+this.value" 
                            class="w-full appearance-none px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-800 font-medium focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all cursor-pointer">
                        <option value="">Alle Hörsäle</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo $room['id']; ?>" <?php echo $filterRoom == $room['id'] ? 'selected' : ''; ?>>
                                Hörsaal <?php echo htmlspecialchars($room['room_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Print Button -->
            <div class="flex items-end">
                <?php
                // PDF-Generator URL bestimmen
                if ($printType === 'rooms') {
                    $pdfUrl = BASE_URL . 'api/generate-room-pdf.php?room=' . $filterRoom;
                } elseif ($printType === 'room-assignments') {
                    $pdfUrl = BASE_URL . 'api/generate-room-assignment-pdf.php';
                } elseif ($printType === 'absent') {
                    $pdfUrl = BASE_URL . 'api/generate-absent-pdf.php';
                } else {
                    // all oder class
                    $pdfUrl = BASE_URL . 'api/generate-class-pdf.php?class=' . urlencode($filterClass);
                }
                ?>
                <a href="<?php echo $pdfUrl; ?>"
                   class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-semibold rounded-xl shadow-lg shadow-emerald-500/25 hover:shadow-xl hover:shadow-emerald-500/30 hover:from-emerald-600 hover:to-green-700 transition-all">
                    <i class="fas fa-file-pdf"></i>
                    PDF herunterladen
                </a>
            </div>
        </div>
    </div>
    
    <!-- Export Section -->
    <?php if (isAdmin() || hasPermission('berichte_sehen')): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fas fa-download text-gray-400"></i> Datenexport
        </h2>
        <p class="text-xs text-gray-500 mb-4">
            Exportiert die aktuellen Daten. Klassenfilter wird automatisch übernommen.
        </p>
        <div class="flex flex-wrap gap-3">
            <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=registrations&class=<?php echo urlencode($filterClass ?? ''); ?>"
               class="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-50 border border-blue-200 text-blue-700 font-medium text-sm rounded-lg hover:bg-blue-100 transition">
                <i class="fas fa-file-csv"></i> Anmeldungen (CSV)
            </a>
            <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=xlsx&type=registrations&class=<?php echo urlencode($filterClass ?? ''); ?>"
               class="inline-flex items-center gap-2 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 font-medium text-sm rounded-lg hover:bg-green-100 transition">
                <i class="fas fa-file-excel"></i> Anmeldungen (Excel)
            </a>
            <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=attendance&class=<?php echo urlencode($filterClass ?? ''); ?>"
               class="inline-flex items-center gap-2 px-4 py-2.5 bg-purple-50 border border-purple-200 text-purple-700 font-medium text-sm rounded-lg hover:bg-purple-100 transition">
                <i class="fas fa-user-check"></i> Check-ins (CSV)
            </a>
            <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=unregistered"
               class="inline-flex items-center gap-2 px-4 py-2.5 bg-red-50 border border-red-200 text-red-700 font-medium text-sm rounded-lg hover:bg-red-100 transition">
                <i class="fas fa-user-times"></i> Ohne Anmeldung (CSV)
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Preview Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800 flex items-center gap-2">
                <i class="fas fa-eye text-gray-400"></i>
                Vorschau
                <span class="text-sm font-normal text-gray-500">
                    (<?php echo count($registrations ?? []); ?> Einträge)
                </span>
            </h2>
        </div>
        
        <div class="p-6">
            <?php if (empty($registrations)): ?>
                <div class="text-center py-12">
                    <div class="w-16 h-16 mx-auto bg-gray-100 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fas fa-inbox text-2xl text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Keine Daten gefunden</h3>
                    <p class="text-gray-500">Für die ausgewählten Filter sind keine Registrierungen vorhanden.</p>
                </div>
            <?php else: ?>
                <?php if ($printType === 'all' || $printType === 'class'): ?>
                    <?php
                    // Gruppieren nach Klasse
                    $groupedByClass = [];
                    foreach ($registrations as $reg) {
                        $class = $reg['class'] ?: 'Keine Klasse';
                        $groupedByClass[$class][] = $reg;
                    }
                    ksort($groupedByClass);
                    ?>
                    
                    <div class="space-y-6">
                        <?php foreach ($groupedByClass as $class => $classRegs): ?>
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gradient-to-r from-emerald-50 to-green-50 px-4 py-3 border-b border-gray-200">
                                    <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                        <i class="fas fa-users text-emerald-600"></i>
                                        <?php echo htmlspecialchars($class); ?>
                                        <span class="text-sm font-normal text-gray-500">(<?php echo count($classRegs); ?> Einträge)</span>
                                    </h3>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Schüler</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Slot</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Fachbereich</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Hörsaal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach (array_slice($classRegs, 0, 10) as $reg): ?>
                                            <tr class="hover:bg-gray-50/50">
                                                <td class="px-4 py-3 font-medium text-gray-800">
                                                    <?php echo htmlspecialchars($reg['lastname'] . ', ' . $reg['firstname']); ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-purple-100 text-purple-700 text-xs font-medium">
                                                        <?php echo htmlspecialchars($reg['slot_name']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td>
                                                <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($reg['room_number'] ?: '—'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (count($classRegs) > 10): ?>
                                            <tr>
                                                <td colspan="4" class="px-4 py-3 text-center text-gray-500 italic">
                                                    ... und <?php echo count($classRegs) - 10; ?> weitere Einträge
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php elseif ($printType === 'room-assignments'): ?>
                    <?php if (empty($roomAssignments)): ?>
                        <div class="text-center py-12">
                            <div class="w-16 h-16 mx-auto bg-gray-100 rounded-2xl flex items-center justify-center mb-4">
                                <i class="fas fa-inbox text-2xl text-gray-400"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Keine Raumzuteilungen</h3>
                            <p class="text-gray-500">Es wurden noch keine Fachbereiche Hörsälen zugewiesen.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $groupedRoomAssign = [];
                        foreach ($roomAssignments as $ra) {
                            $groupedRoomAssign[$ra['room_number']][] = $ra;
                        }
                        ?>
                        <div class="space-y-4">
                            <?php foreach ($groupedRoomAssign as $roomNum => $exhibitorsList): ?>
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gradient-to-r from-sky-50 to-blue-50 px-4 py-3 border-b border-gray-200">
                                    <h3 class="font-bold text-gray-800 flex items-center gap-2" style="font-size: 14px;">
                                        <i class="fas fa-door-open text-sky-600"></i>
                                        Hörsaal <?php echo htmlspecialchars($roomNum); ?>
                                    </h3>
                                </div>
                                <table class="w-full" style="font-size: 13px;">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-semibold text-gray-600">Fachbereich</th>
                                            <th class="px-4 py-2 text-left font-semibold text-gray-600">Beschreibung</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($exhibitorsList as $ex): ?>
                                        <tr class="hover:bg-gray-50/50">
                                            <td class="px-4 py-2 font-medium text-gray-800"><?php echo htmlspecialchars(html_entity_decode($ex['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td>
                                            <td class="px-4 py-2 text-gray-500"><?php echo htmlspecialchars($ex['short_description'] ?: '—'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php elseif ($printType === 'absent'): ?>
                    <?php if (empty($absentStudents)): ?>
                        <div class="text-center py-12">
                            <div class="w-16 h-16 mx-auto bg-emerald-100 rounded-2xl flex items-center justify-center mb-4">
                                <i class="fas fa-check-circle text-2xl text-emerald-500"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Alle anwesend!</h3>
                            <p class="text-gray-500">Keine fehlenden Schüler gefunden.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $groupedAbsent = [];
                        foreach ($absentStudents as $abs) {
                            $groupedAbsent[$abs['slot_name']][] = $abs;
                        }
                        ?>
                        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                            <p class="text-sm text-red-700 font-medium">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <?php echo count($absentStudents); ?> fehlende Einträge (angemeldet aber nicht gescannt)
                            </p>
                        </div>
                        <div class="space-y-6">
                            <?php foreach ($groupedAbsent as $slotName => $slotAbsents): ?>
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gradient-to-r from-red-50 to-rose-50 px-4 py-3 border-b border-gray-200">
                                    <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                        <i class="fas fa-clock text-red-500"></i>
                                        <?php echo htmlspecialchars($slotName); ?>
                                        <span class="text-sm font-normal text-gray-500">(<?php echo count($slotAbsents); ?> fehlend)</span>
                                    </h3>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Schüler</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Klasse</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Fachbereich</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Hörsaal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach (array_slice($slotAbsents, 0, 10) as $abs): ?>
                                            <tr class="hover:bg-gray-50/50">
                                                <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($abs['lastname'] . ', ' . $abs['firstname']); ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($abs['class'] ?: '—'); ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars(html_entity_decode($abs['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td>
                                                <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($abs['room_number'] ?: '—'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (count($slotAbsents) > 10): ?>
                                            <tr>
                                                <td colspan="4" class="px-4 py-3 text-center text-gray-500 italic">
                                                    ... und <?php echo count($slotAbsents) - 10; ?> weitere
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php elseif ($printType === 'rooms'): ?>
                    <?php
                    // Gruppieren nach Hörsaal
                    $groupedByRoom = [];
                    foreach ($registrations as $reg) {
                        $roomKey = $reg['room_number'];
                        $groupedByRoom[$roomKey][] = $reg;
                    }
                    ksort($groupedByRoom);
                    ?>
                    
                    <div class="space-y-6">
                        <?php foreach ($groupedByRoom as $roomNum => $roomRegs): ?>
                            <div class="border border-gray-200 rounded-xl overflow-hidden">
                                <div class="bg-gradient-to-r from-sky-50 to-blue-50 px-4 py-3 border-b border-gray-200">
                                    <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                        <i class="fas fa-door-open text-sky-600"></i>
                                        Hörsaal <?php echo htmlspecialchars($roomNum); ?>
                                        <span class="text-sm font-normal text-gray-500">(<?php echo count($roomRegs); ?> Einträge)</span>
                                    </h3>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Slot</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Fachbereich</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Schüler</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600">Klasse</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach (array_slice($roomRegs, 0, 10) as $reg): ?>
                                            <tr class="hover:bg-gray-50/50">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-sky-100 text-sky-700 text-xs font-medium">
                                                        <?php echo htmlspecialchars($reg['slot_name']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td>
                                                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($reg['lastname'] . ', ' . $reg['firstname']); ?></td>
                                                <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($reg['class'] ?: '—'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (count($roomRegs) > 10): ?>
                                            <tr>
                                                <td colspan="4" class="px-4 py-3 text-center text-gray-500 italic">
                                                    ... und <?php echo count($roomRegs) - 10; ?> weitere Einträge
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
