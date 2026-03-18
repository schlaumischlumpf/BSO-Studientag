<?php
// Klassenliste für Lehrer (Issue #8)

$class = $_GET['class'] ?? '';

if (empty($class)) {
    header('Location: ?page=teacher-dashboard');
    exit;
}

// Schüler dieser Klasse laden
$stmt = $db->prepare("SELECT * FROM users WHERE role = 'student' AND class = ? ORDER BY lastname, firstname");
$stmt->execute([$class]);
$students = $stmt->fetchAll();

// Für jeden Schüler die Anmeldungen laden
$studentData = [];
foreach ($students as $student) {
    $stmt = $db->prepare("
        SELECT r.*, e.name as exhibitor_name, t.slot_name, t.slot_number, t.start_time, t.end_time
        FROM registrations r
        JOIN exhibitors e ON r.exhibitor_id = e.id
        JOIN timeslots t ON r.timeslot_id = t.id
        WHERE r.user_id = ? AND r.edition_id = ? AND e.edition_id = ?
        ORDER BY t.slot_number
    ");
    $stmt->execute([$student['id'], $activeEditionId, $activeEditionId]);
    $registrations = $stmt->fetchAll();
    
    // Slots gruppieren
    $slots = [];
    foreach ($registrations as $reg) {
        $slots[$reg['slot_number']] = $reg;
    }
    
    $studentData[] = [
        'student' => $student,
        'registrations' => $registrations,
        'slots' => $slots
    ];
}

// Verwaltete Slots
$managedSlots = getManagedSlotNumbers();
$stmt = $db->prepare("SELECT * FROM timeslots WHERE slot_number " . getManagedSlotsSqlIn() . " AND timeslots.edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmt->execute([$activeEditionId]);;
$timeslots = $stmt->fetchAll();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-xl p-6 border-l-4 border-green-600">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">
                    <i class="fas fa-users text-green-600 mr-3"></i>
                    Klassenliste: <?php echo htmlspecialchars($class); ?>
                </h2>
                <p class="text-gray-600"><?php echo count($students); ?> Schüler</p>
            </div>
            <div class="flex gap-3">
                <a href="?page=admin-print&type=class&class=<?php echo urlencode($class); ?>" 
                   target="_blank"
                   class="px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition font-semibold">
                    <i class="fas fa-print mr-2"></i>Drucken
                </a>
                <a href="?page=teacher-dashboard" 
                   class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold">
                    <i class="fas fa-arrow-left mr-2"></i>Zurück
                </a>
            </div>
        </div>
    </div>

    <!-- Student List -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-100 border-b-2 border-gray-300">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase sticky left-0 bg-gray-100 z-10">
                            Nr.
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase sticky left-12 bg-gray-100 z-10">
                            Name
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">
                            Status
                        </th>
                        <?php foreach ($timeslots as $slot): ?>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">
                            <?php echo htmlspecialchars($slot['slot_name']); ?><br>
                            <span class="text-xs font-normal"><?php echo date('H:i', strtotime($slot['start_time'])); ?>-<?php echo date('H:i', strtotime($slot['end_time'])); ?></span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($studentData as $index => $data): 
                        $student = $data['student'];
                        $slots = $data['slots'];
                        $registrations = $data['registrations'];
                        
                        // Status berechnen
                        $registeredSlotsCount = count(array_intersect(array_keys($slots), $managedSlots));
                        $isComplete = $registeredSlotsCount === 3;
                        $hasNone = $registeredSlotsCount === 0;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 sticky left-0 bg-white hover:bg-gray-50">
                            <?php echo $index + 1; ?>
                        </td>
                        <td class="px-4 py-3 sticky left-12 bg-white hover:bg-gray-50">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <span class="font-bold text-xs text-blue-600">
                                        <?php echo strtoupper(substr($student['firstname'], 0, 1) . substr($student['lastname'], 0, 1)); ?>
                                    </span>
                                </div>
                                <div>
                                    <div class="font-semibold text-gray-800 text-sm">
                                        <?php echo htmlspecialchars($student['lastname'] . ', ' . $student['firstname']); ?>
                                    </div>
                                    <?php if ($student['email']): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($student['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($isComplete): ?>
                                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-check-circle mr-1"></i>Vollständig
                                </span>
                            <?php elseif ($hasNone): ?>
                                <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-times-circle mr-1"></i>Keine
                                </span>
                            <?php else: ?>
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-exclamation-triangle mr-1"></i><?php echo $registeredSlotsCount; ?>/3
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($timeslots as $slot): ?>
                        <td class="px-4 py-3">
                            <?php if (isset($slots[$slot['slot_number']])): 
                                $reg = $slots[$slot['slot_number']];
                            ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-2">
                                    <div class="font-semibold text-xs text-blue-900 truncate">
                                        <?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
                                    </div>
                                    <div class="text-xs text-blue-600 mt-1">
                                        <?php echo $reg['registration_type'] === 'automatic' ? 'Auto' : 'Manuell'; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-2 text-center">
                                    <span class="text-xs text-gray-400">-</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php
        $complete = count(array_filter($studentData, function($d) use ($managedSlots) {
            return count(array_intersect(array_keys($d['slots']), $managedSlots)) === 3;
        }));
        $incomplete = count(array_filter($studentData, function($d) use ($managedSlots) {
            $count = count(array_intersect(array_keys($d['slots']), $managedSlots));
            return $count > 0 && $count < 3;
        }));
        $none = count(array_filter($studentData, function($d) use ($managedSlots) {
            return count(array_intersect(array_keys($d['slots']), $managedSlots)) === 0;
        }));
        ?>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm mb-1">Vollständig</p>
                    <p class="text-3xl font-bold"><?php echo $complete; ?></p>
                    <p class="text-xs text-green-100 mt-1">
                        <?php echo count($students) > 0 ? round(($complete / count($students)) * 100) : 0; ?>% der Klasse
                    </p>
                </div>
                <i class="fas fa-check-circle text-3xl opacity-80"></i>
            </div>
        </div>

        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-100 text-sm mb-1">Unvollständig</p>
                    <p class="text-3xl font-bold"><?php echo $incomplete; ?></p>
                    <p class="text-xs text-yellow-100 mt-1">Benötigen Unterstützung</p>
                </div>
                <i class="fas fa-exclamation-triangle text-3xl opacity-80"></i>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm mb-1">Ohne Anmeldung</p>
                    <p class="text-3xl font-bold"><?php echo $none; ?></p>
                    <p class="text-xs text-red-100 mt-1">Dringend ansprechen</p>
                </div>
                <i class="fas fa-user-times text-3xl opacity-80"></i>
            </div>
        </div>
    </div>
</div>
