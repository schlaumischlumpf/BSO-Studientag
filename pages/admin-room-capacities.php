<?php
// Admin Room Capacity Management (Issue #4)

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('kapazitaeten_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Alle Räume laden
$stmt = $db->prepare("SELECT * FROM rooms WHERE edition_id = ? ORDER BY room_number");
$stmt->execute([$activeEditionId]);
$rooms = $stmt->fetchAll();

// Alle Timeslots laden
$stmt = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? AND is_break = 0 ORDER BY start_time ASC, slot_number ASC");
$stmt->execute([$activeEditionId]);
$timeslots = $stmt->fetchAll();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_capacities'])) {
    requireCsrf();
    $db->beginTransaction();
    try {
        // Alle vorhandenen Einträge löschen
        $__stmtExec = $db->prepare("DELETE FROM room_slot_capacities WHERE edition_id = ?");
        $__stmtExec->execute([$activeEditionId]);
        
        // Neue Kapazitäten speichern
        $stmt = $db->prepare("INSERT INTO room_slot_capacities (room_id, timeslot_id, capacity, edition_id) VALUES (?, ?, ?, ?)");
        
        foreach ($_POST['capacity'] as $roomId => $timeslotData) {
            foreach ($timeslotData as $timeslotId => $capacity) {
                $cap = intval($capacity);
                if ($cap > 0) {
                    $stmt->execute([$roomId, $timeslotId, $cap, $activeEditionId]);
                }
            }
        }
        
        $db->commit();
        logAuditAction('kapazitaeten_geaendert', 'Raumkapazitäten pro Zeitslot aktualisiert');
        $message = ['type' => 'success', 'text' => 'Kapazitäten erfolgreich gespeichert'];
    } catch (Exception $e) {
        logErrorToAudit($e, 'Raumkapazitäten');
        $db->rollBack();
        $message = ['type' => 'error', 'text' => 'Fehler beim Speichern: ' . $e->getMessage()];
    }
}

// Aktuelle Kapazitäten laden
$capacities = [];
$stmt = $db->prepare("SELECT * FROM room_slot_capacities WHERE edition_id = ?");
$stmt->execute([$activeEditionId]);
foreach ($stmt->fetchAll() as $cap) {
    $capacities[$cap['room_id']][$cap['timeslot_id']] = $cap['capacity'];
}
?>

<div class="space-y-6">
    <?php if (isset($message)): ?>
    <div class="mb-4">
        <?php if ($message['type'] === 'success'): ?>
            <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                    <p class="text-emerald-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-red-50 border border-red-200 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <p class="text-red-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Raumkapazitäten</h2>
            <p class="text-sm text-gray-500 mt-1">Definiere individuelle Kapazitäten für jeden Raum und Zeitslot</p>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-100 p-4 rounded-lg">
        <p class="text-sm text-blue-700">
            <i class="fas fa-info-circle mr-2"></i>
            Die Standardkapazität entspricht der vollen Raumkapazität. Du kannst für jeden Slot individuelle Werte festlegen. Leere Felder verwenden den Standardwert.
        </p>
    </div>

    <!-- Capacity Form -->
    <form method="POST" class="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider sticky left-0 bg-gray-50 z-10">
                            Raum
                        </th>
                        <th class="px-4 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Standard<br>Kapazität
                        </th>
                        <?php foreach ($timeslots as $slot): ?>
                            <th class="px-4 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <?php echo htmlspecialchars($slot['slot_name']); ?><br>
                                <span class="text-xs font-normal text-gray-400"><?php echo date('H:i', strtotime($slot['start_time'])); ?>-<?php echo date('H:i', strtotime($slot['end_time'])); ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($rooms as $room):
                        // Standardkapazität: 25 oder Raumkapazität (falls kleiner)
                        $roomCap = intval($room['capacity']);
                        $defaultCapacity = min(25, $roomCap);
                    ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 sticky left-0 bg-white hover:bg-gray-50">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-emerald-50 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-door-open text-emerald-500 text-sm"></i>
                                </div>
                                <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($room['room_number']); ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span class="inline-flex items-center px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-semibold">
                                <?php echo $defaultCapacity; ?>
                            </span>
                        </td>
                        <?php foreach ($timeslots as $slot):
                            $currentValue = $capacities[$room['id']][$slot['id']] ?? '';
                        ?>
                            <td class="px-4 py-4 text-center">
                                <input type="number"
                                       name="capacity[<?php echo $room['id']; ?>][<?php echo $slot['id']; ?>]"
                                       value="<?php echo $currentValue; ?>"
                                       placeholder="<?php echo $defaultCapacity; ?>"
                                       min="0"
                                       class="w-20 px-3 py-2 border border-gray-200 rounded-lg text-center text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Actions -->
        <div class="px-6 py-4 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-3">
            <p class="text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1 text-gray-400"></i>
                Änderungen werden sofort auf die automatische Zuteilung angewendet
            </p>
            <div class="flex gap-3">
                <button type="button"
                        onclick="resetToDefaults()"
                        class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium text-sm">
                    <i class="fas fa-undo mr-2"></i>Zurücksetzen
                </button>
                <button type="submit"
                        name="save_capacities"
                        class="px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
                    <i class="fas fa-save mr-2"></i>Speichern
                </button>
            </div>
        </div>
    </form>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Räume gesamt</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($rooms); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-door-open text-blue-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Zeitslots</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($timeslots); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                    <i class="fas fa-clock text-emerald-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Konfigurationen</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($capacities); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                    <i class="fas fa-cog text-purple-500"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function resetToDefaults() {
    if (confirm('Möchtest Du wirklich alle Werte auf die Standardwerte zurücksetzen?')) {
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.value = '';
        });
    }
}

// Auto-save Warnung bei Verlassen
let formChanged = false;
document.querySelectorAll('input[type="number"]').forEach(input => {
    input.addEventListener('change', () => {
        formChanged = true;
    });
});

window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.querySelector('form').addEventListener('submit', () => {
    formChanged = false;
});
</script>
