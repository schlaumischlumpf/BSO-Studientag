<?php
// Raum-Zuteilungssystem für Admins

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('raeume_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Alle Räume abrufen  
$stmt = $db->prepare("SELECT * FROM rooms WHERE edition_id = ? ORDER BY room_number");
$stmt->execute([$activeEditionId]);
$rooms = $stmt->fetchAll();

// Alle Aussteller abrufen
$stmt = $db->prepare("
    SELECT 
        e.*,
        r.room_number,
        r.equipment as room_equipment
    FROM exhibitors e
    LEFT JOIN rooms r ON e.room_id = r.id
    WHERE e.active = 1 AND e.edition_id = ?
    ORDER BY e.name
");
$stmt->execute([$activeEditionId]);
$exhibitors = $stmt->fetchAll();

// Aussteller nach Zuordnung gruppieren
$assignedExhibitors = [];
$unassignedExhibitors = [];

foreach ($exhibitors as $ex) {
    if ($ex['room_id']) {
        if (!isset($assignedExhibitors[$ex['room_id']])) {
            $assignedExhibitors[$ex['room_id']] = [];
        }
        $assignedExhibitors[$ex['room_id']][] = $ex;
    } else {
        $unassignedExhibitors[] = $ex;
    }
}
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Hörsaal-Zuteilung</h2>
            <p class="text-sm text-gray-500 mt-1">Ziehe Fachbereiche auf Hörsäle, um sie zuzuordnen</p>
        </div>

    <!-- Mobile Touch Hint (nur auf Touch-Geräten sichtbar) -->
    <div class="dnd-mobile-hint hidden mb-2 items-center gap-2 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-800">
        <i class="fas fa-hand-point-up text-blue-500 flex-shrink-0"></i>
        <span>Halte einen Fachbereich gedrückt und ziehe ihn auf einen Hörsaal. Tippen funktioniert auch!</span>
    </div>
        <?php if (isAdmin() || hasPermission('raeume_erstellen')): ?>
        <button onclick="openAddRoomModal()" class="px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Neuer Hörsaal
        </button>
        <?php endif; ?>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Fachbereiche gesamt</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($exhibitors); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-building text-blue-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Zugeordnet</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($exhibitors) - count($unassignedExhibitors); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Nicht zugeordnet</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($unassignedExhibitors); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-amber-500"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Left: Unassigned Exhibitors -->
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <h3 class="text-base font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-list text-amber-500 mr-2"></i>
                Nicht zugeordnete Fachbereiche
                <span class="ml-auto text-sm font-normal text-gray-500">
                    <?php echo count($unassignedExhibitors); ?> Fachbereiche
                </span>
            </h3>

            <div id="unassignedList" class="space-y-3 min-h-[200px]">
                <?php if (empty($unassignedExhibitors)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-check-circle text-4xl mb-3 text-green-500"></i>
                        <p>Alle Fachbereiche sind Hörsälen zugeordnet!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($unassignedExhibitors as $ex): ?>
                        <div class="exhibitor-card p-4 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300 hover:border-blue-500 cursor-move transition"
                             draggable="true"
                             data-id="<?php echo $ex['id']; ?>"
                             data-name="<?php echo htmlspecialchars($ex['name']); ?>"
                             data-equipment="<?php echo htmlspecialchars($ex['equipment'] ?? ''); ?>">
                            <div class="flex items-center">
                                <i class="fas fa-grip-vertical text-gray-400 mr-3"></i>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-bold text-gray-800"><?php echo htmlspecialchars($ex['name']); ?></h4>
                                    <p class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars($ex['short_description']); ?></p>
                                    <?php if (!empty($ex['equipment'])): ?>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach (explode(',', $ex['equipment']) as $equip): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-orange-50 text-orange-600 border border-orange-100">
                                                <i class="fas fa-tools text-[10px] mr-1"></i><?php echo htmlspecialchars(trim($equip)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Rooms with Assigned Exhibitors -->
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <h3 class="text-base font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-door-open text-blue-500 mr-2"></i>
                Hörsäle
                <span class="ml-auto text-sm font-normal text-gray-500">
                    <span id="roomCount"><?php echo count($rooms); ?></span> Hörsäle
                </span>
            </h3>

            <!-- Filter Section -->
            <div class="bg-gray-50 rounded-lg p-4 mb-4 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-700">
                        <i class="fas fa-filter text-gray-400 mr-2"></i>Filter
                    </span>
                    <button onclick="clearRoomFilters()" class="text-xs text-blue-600 hover:text-blue-800">
                        Zurücksetzen
                    </button>
                </div>
                
                <!-- Equipment Filter -->
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Ausstattung</label>
                    <select id="equipmentFilter" onchange="filterRooms()" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Alle Ausstattungen</option>
                        <option value="Beamer">Beamer</option>
                        <option value="Smartboard">Smartboard</option>
                        <option value="Whiteboard">Whiteboard</option>
                        <option value="Lautsprecher">Lautsprecher</option>
                        <option value="WLAN">WLAN</option>
                        <option value="Steckdosen">Steckdosen</option>
                    </select>
                </div>
                
                <!-- Capacity Filter -->
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Min. Kapazität</label>
                        <input type="number" id="minCapacity" onchange="filterRooms()" min="0" placeholder="0"
                               class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Max. Kapazität</label>
                        <input type="number" id="maxCapacity" onchange="filterRooms()" min="0" placeholder="∞"
                               class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div id="roomsList" class="space-y-3 max-h-[600px] overflow-y-auto">
                <?php foreach ($rooms as $room): ?>
                    <div class="room-container border-2 border-gray-200 rounded-lg p-4 hover:border-blue-500 transition"
                         data-room-id="<?php echo $room['id']; ?>">
                        <!-- Room Header -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center">
                                <i class="fas fa-door-open text-blue-600 mr-3"></i>
                                <div>
                                    <h4 class="font-bold text-gray-800">
                                        <?php echo htmlspecialchars($room['room_number']); ?>
                                    </h4>
                                    <p class="text-sm text-gray-600">
                                        <?php if ($room['floor']): ?>
                                            <?php echo $room['floor']; ?>. Stock •
                                        <?php endif; ?>
                                        Max. <?php echo $room['capacity']; ?> Pers.
                                    </p>
                                    <?php if (!empty($room['address'])): ?>
                                    <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                                        <i class="fas fa-map-marker-alt text-blue-400"></i>
                                        <?php echo htmlspecialchars($room['address']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if (!empty($room['equipment'])): ?>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach (explode(',', $room['equipment']) as $equip): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 text-blue-600 border border-blue-100">
                                                <?php echo htmlspecialchars(trim($equip)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-sm font-semibold text-gray-600 mr-2">
                                    <?php echo isset($assignedExhibitors[$room['id']]) ? count($assignedExhibitors[$room['id']]) : 0; ?> zugeordnet
                                </span>
                                <?php if (isAdmin() || hasPermission('raeume_bearbeiten')): ?>
                                    <button onclick="openEditRoomModal(<?php echo htmlspecialchars(json_encode(['id' => $room['id'], 'room_number' => $room['room_number'], 'floor' => $room['floor'] ?? '', 'address' => $room['address'] ?? '', 'capacity' => $room['capacity'], 'equipment' => $room['equipment'] ?? '']), ENT_QUOTES); ?>)"
                                            class="text-blue-500 hover:text-blue-700 transition px-2 py-1 rounded hover:bg-blue-50"
                                            title="Raum bearbeiten">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ((!isset($assignedExhibitors[$room['id']]) || count($assignedExhibitors[$room['id']]) === 0) && (isAdmin() || hasPermission('raeume_loeschen'))): ?>
                                    <button onclick="deleteRoom(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_number'], ENT_QUOTES); ?>')"
                                            class="text-red-500 hover:text-red-700 transition px-2 py-1 rounded hover:bg-red-50"
                                            title="Hörsaal löschen">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Drop Zone -->
                        <div class="room-dropzone min-h-[80px] bg-blue-50 border-2 border-dashed border-blue-200 rounded-lg p-3"
                             data-room-id="<?php echo $room['id']; ?>">
                            <?php if (isset($assignedExhibitors[$room['id']])): ?>
                                <?php foreach ($assignedExhibitors[$room['id']] as $ex): 
                                    // Equipment-Kompatibilität prüfen
                                    $exhibitorEquipment = !empty($ex['equipment']) ? explode(',', $ex['equipment']) : [];
                                    $roomEquipment = !empty($room['equipment']) ? explode(',', $room['equipment']) : [];
                                    $exhibitorEquipment = array_map('trim', $exhibitorEquipment);
                                    $roomEquipment = array_map('trim', $roomEquipment);
                                    
                                    $missingEquipment = array_diff($exhibitorEquipment, $roomEquipment);
                                    $hasCompatibilityIssue = !empty($exhibitorEquipment) && !empty($missingEquipment);
                                ?>
                                    <div class="exhibitor-card p-3 bg-white rounded-lg border <?php echo $hasCompatibilityIssue ? 'border-amber-400' : 'border-blue-300'; ?> mb-2 cursor-move hover:shadow-md transition"
                                         draggable="true"
                                         data-id="<?php echo $ex['id']; ?>"
                                         data-name="<?php echo htmlspecialchars($ex['name']); ?>"
                                         data-equipment="<?php echo htmlspecialchars($ex['equipment'] ?? ''); ?>"
                                         data-room-id="<?php echo $room['id']; ?>">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center flex-1 min-w-0">
                                                <i class="fas fa-grip-vertical text-gray-400 mr-3"></i>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <h5 class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($ex['name']); ?></h5>
                                                        <?php if (!empty($ex['equipment'])): ?>
                                                            <button type="button" 
                                                                    onclick="showEquipmentInfo(event, <?php echo htmlspecialchars(json_encode($exhibitorEquipment)); ?>, <?php echo htmlspecialchars(json_encode($roomEquipment)); ?>)"
                                                                    class="<?php echo $hasCompatibilityIssue ? 'text-amber-500 hover:text-amber-600' : 'text-blue-500 hover:text-blue-600'; ?> transition"
                                                                    title="Equipment-Info anzeigen">
                                                                <i class="fas fa-info-circle text-sm"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="text-xs text-gray-600 truncate"><?php echo htmlspecialchars($ex['short_description']); ?></p>
                                                    <?php if ($hasCompatibilityIssue): ?>
                                                    <p class="text-xs text-amber-600 mt-1">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>Fehlendes Equipment: <?php echo implode(', ', $missingEquipment); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <button onclick="removeAssignment(<?php echo $ex['id']; ?>)" 
                                                    class="ml-2 text-red-500 hover:text-red-700 transition flex-shrink-0" title="Zuordnung entfernen">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-center text-gray-400 text-sm py-4">
                                    <i class="fas fa-arrow-down mb-2"></i><br>
                                    Ziehe die Fachbereiche hierher
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="flex flex-wrap gap-3">
        <button onclick="clearAllAssignments()" class="px-4 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-medium">
            <i class="fas fa-trash mr-2"></i>Alle Zuordnungen löschen
        </button>
        <button onclick="location.reload()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">
            <i class="fas fa-sync mr-2"></i>Aktualisieren
        </button>
    </div>
</div>

<!-- Modal: Neuen Hörsaal hinzufügen -->
<div id="addRoomModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-plus-circle text-blue-600 mr-3"></i>
                    Neuen Hörsaal hinzufügen
                </h3>
                <button onclick="closeAddRoomModal()" class="text-gray-500 hover:text-gray-700 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <form id="addRoomForm" class="p-6">
            <div class="space-y-6">
                <!-- Room Number -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bezeichnung <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="room_number" name="room_number" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="z.B. Henry-Ford-Bau, HS 1">
                </div>

                <!-- Address -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-map-marker-alt text-gray-400 mr-1"></i> Adresse
                    </label>
                    <input type="text" id="address" name="address"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="z.B. Garystraße 35, 14195 Berlin">
                </div>

                <!-- Floor and Capacity -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Stockwerk
                        </label>
                        <input type="number" id="floor" name="floor" min="0" max="20"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="z.B. 1">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Kapazität <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="capacity" name="capacity" required min="1" value="25"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Max. Personen">
                    </div>
                </div>

                <!-- Equipment (Issue #17) -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-tools text-gray-400 mr-1"></i> Ausstattung
                    </label>
                    <div class="flex flex-wrap gap-2 mb-2" id="equipmentCheckboxes">
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Beamer" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-video text-gray-400 mr-1"></i> Beamer
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Smartboard" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-chalkboard text-gray-400 mr-1"></i> Smartboard
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Whiteboard" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-chalkboard-teacher text-gray-400 mr-1"></i> Whiteboard
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Lautsprecher" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-volume-up text-gray-400 mr-1"></i> Lautsprecher
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="WLAN" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-wifi text-gray-400 mr-1"></i> WLAN
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Steckdosen" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-plug text-gray-400 mr-1"></i> Steckdosen
                        </label>
                    </div>
                    <input type="text" id="equipment_custom" name="equipment_custom"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="Weitere Ausstattung (kommagetrennt)">
                </div>
            </div>

            <div class="mt-8 flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition font-semibold">
                    <i class="fas fa-save mr-2"></i>Hörsaal speichern
                </button>
                <button type="button" onclick="closeAddRoomModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                    Abbrechen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Hörsaal bearbeiten -->
<div id="editRoomModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-edit text-blue-600 mr-3"></i>
                    Hörsaal bearbeiten
                </h3>
                <button onclick="closeEditRoomModal()" class="text-gray-500 hover:text-gray-700 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <form id="editRoomForm" class="p-6">
            <input type="hidden" id="edit_room_id">
            <div class="space-y-6">
                <!-- Room Number -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Bezeichnung <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="edit_room_number" name="room_number" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="z.B. Henry-Ford-Bau, HS 1">
                </div>

                <!-- Address -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-map-marker-alt text-gray-400 mr-1"></i> Adresse
                    </label>
                    <input type="text" id="edit_address" name="address"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="z.B. Garystraße 35, 14195 Berlin">
                </div>

                <!-- Floor and Capacity -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Stockwerk
                        </label>
                        <input type="number" id="edit_floor" name="floor" min="0" max="20"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="z.B. 1">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Kapazität <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="edit_capacity" name="capacity" required min="1" value="25"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Max. Personen">
                    </div>
                </div>

                <!-- Equipment -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-tools text-gray-400 mr-1"></i> Ausstattung
                    </label>
                    <div class="flex flex-wrap gap-2 mb-2" id="editEquipmentCheckboxes">
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Beamer" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-video text-gray-400 mr-1"></i> Beamer
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Smartboard" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-chalkboard text-gray-400 mr-1"></i> Smartboard
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Whiteboard" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-chalkboard-teacher text-gray-400 mr-1"></i> Whiteboard
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Lautsprecher" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-volume-up text-gray-400 mr-1"></i> Lautsprecher
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="WLAN" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-wifi text-gray-400 mr-1"></i> WLAN
                        </label>
                        <label class="inline-flex items-center px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition">
                            <input type="checkbox" name="equipment[]" value="Steckdosen" class="mr-2 rounded text-blue-600">
                            <i class="fas fa-plug text-gray-400 mr-1"></i> Steckdosen
                        </label>
                    </div>
                    <input type="text" id="edit_equipment_custom" name="equipment_custom"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="Weitere Ausstattung (kommagetrennt)">
                </div>
            </div>

            <div class="mt-8 flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition font-semibold">
                    <i class="fas fa-save mr-2"></i>Änderungen speichern
                </button>
                <button type="button" onclick="closeEditRoomModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                    Abbrechen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let draggedElement = null;

// Modal Functions
function openAddRoomModal() {
    document.getElementById('addRoomModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeAddRoomModal() {
    document.getElementById('addRoomModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('addRoomForm').reset();
}

// Add Room Form Submit
document.getElementById('addRoomForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = {
        room_number: document.getElementById('room_number').value,
        floor: document.getElementById('floor').value,
        address: document.getElementById('address').value,
        capacity: document.getElementById('capacity').value,
        equipment: getSelectedEquipment()
    };
    
    fetch('api/add-room.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'Hörsaal wurde erfolgreich hinzugefügt!');
            closeAddRoomModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Hinzufügen des Raums');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Fehler beim Hinzufügen des Raums');
    });
});

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddRoomModal();
        closeEditRoomModal();
    }
});

// Close modal on background click
document.getElementById('addRoomModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddRoomModal();
    }
});

// Drag Start
document.addEventListener('dragstart', (e) => {
    if (e.target.classList.contains('exhibitor-card')) {
        draggedElement = e.target;
        e.target.style.opacity = '0.5';
    }
});

// Drag End
document.addEventListener('dragend', (e) => {
    if (e.target.classList.contains('exhibitor-card')) {
        e.target.style.opacity = '1';
    }
});

// Drag Over
document.addEventListener('dragover', (e) => {
    e.preventDefault();
    const dropzone = e.target.closest('.room-dropzone');
    if (dropzone) {
        dropzone.style.backgroundColor = '#bfdbfe';
    }
});

// Drag Leave
document.addEventListener('dragleave', (e) => {
    const dropzone = e.target.closest('.room-dropzone');
    if (dropzone) {
        dropzone.style.backgroundColor = '';
    }
});

// Drop
document.addEventListener('drop', (e) => {
    e.preventDefault();
    const dropzone = e.target.closest('.room-dropzone');
    
    if (dropzone && draggedElement) {
        dropzone.style.backgroundColor = '';
        
        const exhibitorId = draggedElement.dataset.id;
        const roomId = dropzone.dataset.roomId;
        const exhibitorName = draggedElement.dataset.name;
        
        // Zuordnung speichern
        assignExhibitorToRoom(exhibitorId, roomId, exhibitorName);
    }
});

// =========================================================================
// TOUCH / POINTER EVENTS DnD (für Smartphones & Tablets)
// Implementiert dasselbe Drag-and-Drop-Verhalten über Pointer Events,
// da HTML5 DnD auf Touch-Geräten nicht zuverlässig funktioniert.
// =========================================================================
(function () {
    let touchDragging = null;
    let ghost = null;
    let lastDropzone = null;

    function createGhost(el) {
        const rect = el.getBoundingClientRect();
        ghost = el.cloneNode(true);
        ghost.style.cssText = `
            position: fixed;
            left: ${rect.left}px;
            top: ${rect.top}px;
            width: ${rect.width}px;
            z-index: 9999;
            opacity: 0.85;
            pointer-events: none;
            transform: scale(1.03);
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            border-radius: 0.5rem;
            transition: none;
        `;
        document.body.appendChild(ghost);
    }

    function removeGhost() {
        if (ghost) { ghost.remove(); ghost = null; }
    }

    function getDropzoneAt(x, y) {
        const els = document.elementsFromPoint(x, y);
        for (const el of els) {
            const dz = el.closest('.room-dropzone');
            if (dz) return dz;
        }
        return null;
    }

    document.addEventListener('pointerdown', (e) => {
        // Only handle touch/pen, not mouse (mouse already handled by HTML5 DnD)
        if (e.pointerType === 'mouse') return;

        const card = e.target.closest('.exhibitor-card[draggable="true"]');
        if (!card) return;

        e.preventDefault();
        touchDragging = card;
        card.setPointerCapture(e.pointerId);
        card.style.opacity = '0.4';
        createGhost(card);
    }, { passive: false });

    document.addEventListener('pointermove', (e) => {
        if (!touchDragging || !ghost) return;
        e.preventDefault();

        ghost.style.left = (e.clientX - ghost.offsetWidth / 2) + 'px';
        ghost.style.top  = (e.clientY - ghost.offsetHeight / 2) + 'px';

        // Highlight dropzone under touch point
        const dz = getDropzoneAt(e.clientX, e.clientY);
        if (dz !== lastDropzone) {
            if (lastDropzone) lastDropzone.style.backgroundColor = '';
            if (dz) dz.style.backgroundColor = '#bfdbfe';
            lastDropzone = dz;
        }
    }, { passive: false });

    document.addEventListener('pointerup', (e) => {
        if (!touchDragging) return;

        touchDragging.style.opacity = '1';
        removeGhost();

        const dz = getDropzoneAt(e.clientX, e.clientY);
        if (dz) {
            dz.style.backgroundColor = '';
            const exhibitorId   = touchDragging.dataset.id;
            const roomId        = dz.dataset.roomId;
            const exhibitorName = touchDragging.dataset.name;
            if (exhibitorId && roomId) {
                assignExhibitorToRoom(exhibitorId, roomId, exhibitorName);
            }
        }

        if (lastDropzone) { lastDropzone.style.backgroundColor = ''; lastDropzone = null; }
        touchDragging = null;
    });

    document.addEventListener('pointercancel', () => {
        if (touchDragging) touchDragging.style.opacity = '1';
        removeGhost();
        if (lastDropzone) { lastDropzone.style.backgroundColor = ''; lastDropzone = null; }
        touchDragging = null;
    });
})();

// Assign Exhibitor to Room
function assignExhibitorToRoom(exhibitorId, roomId, exhibitorName) {
    fetch('api/assign-room.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        },
        body: JSON.stringify({
            exhibitor_id: exhibitorId,
            room_id: roomId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Success notification
            showNotification('success', `${exhibitorName} wurde dem Hörsaal zugeordnet!`);
            // Reload page after short delay
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Zuordnen');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Fehler beim Zuordnen');
    });
}

// Remove Assignment
function removeAssignment(exhibitorId) {
    if (!confirm('Möchtest Du die Raum-Zuordnung wirklich entfernen?')) {
        return;
    }
    
    fetch('api/assign-room.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        },
        body: JSON.stringify({
            exhibitor_id: exhibitorId,
            room_id: null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'Zuordnung wurde entfernt!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Entfernen');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Fehler beim Entfernen');
    });
}

// Clear All Assignments
function clearAllAssignments() {
    if (!confirm('Möchtest Du wirklich ALLE Raum-Zuordnungen löschen? Diese Aktion kann nicht rückgängig gemacht werden!')) {
        return;
    }
    
    fetch('api/clear-room-assignments.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'Alle Zuordnungen wurden gelöscht!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Löschen');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Fehler beim Löschen');
    });
}

// Delete Unused Room
function deleteRoom(roomId, roomNumber) {
    if (!confirm(`Möchtest Du den ungenutzten Hörsaal "${roomNumber}" wirklich löschen?\n\nDieser Hörsaal hat keine zugeordneten Fachbereiche und kann sicher gelöscht werden.`)) {
        return;
    }
    
    fetch('api/delete-room.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        },
        body: JSON.stringify({
            room_id: roomId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', `Raum "${roomNumber}" wurde gelöscht!`);
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Löschen des Raums');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Fehler beim Löschen des Raums');
    });
}

// Edit Room Modal Functions
function openEditRoomModal(roomData) {
    document.getElementById('edit_room_id').value = roomData.id;
    document.getElementById('edit_room_number').value = roomData.room_number;
    document.getElementById('edit_floor').value = roomData.floor || '';
    document.getElementById('edit_address').value = roomData.address || '';
    document.getElementById('edit_capacity').value = roomData.capacity || 25;

    // Reset all checkboxes first
    document.querySelectorAll('#editRoomModal input[name="equipment[]"]').forEach(cb => cb.checked = false);
    document.getElementById('edit_equipment_custom').value = '';

    // Set equipment checkboxes based on current room data
    if (roomData.equipment) {
        const equipList = roomData.equipment.split(',').map(e => e.trim());
        const knownEquip = ['Beamer', 'Smartboard', 'Whiteboard', 'Lautsprecher', 'WLAN', 'Steckdosen'];
        const customEquip = [];
        equipList.forEach(eq => {
            if (knownEquip.includes(eq)) {
                const cb = document.querySelector('#editRoomModal input[value="' + eq + '"]');
                if (cb) cb.checked = true;
            } else if (eq) {
                customEquip.push(eq);
            }
        });
        if (customEquip.length > 0) {
            document.getElementById('edit_equipment_custom').value = customEquip.join(', ');
        }
    }

    document.getElementById('editRoomModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeEditRoomModal() {
    document.getElementById('editRoomModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Edit Room Form Submit
document.getElementById('editRoomForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const checked = [...document.querySelectorAll('#editRoomModal input[name="equipment[]"]:checked')].map(cb => cb.value);
    const custom = document.getElementById('edit_equipment_custom').value.trim();
    if (custom) {
        custom.split(',').forEach(item => {
            const trimmed = item.trim();
            if (trimmed && !checked.includes(trimmed)) checked.push(trimmed);
        });
    }

    const formData = {
        room_id: document.getElementById('edit_room_id').value,
        room_number: document.getElementById('edit_room_number').value,
        floor: document.getElementById('edit_floor').value,
        address: document.getElementById('edit_address').value,
        capacity: document.getElementById('edit_capacity').value,
        equipment: checked.join(', ')
    };

    fetch('api/edit-room.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
        },
        body: JSON.stringify(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'Hörsaal erfolgreich aktualisiert!');
            closeEditRoomModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('error', data.message || 'Fehler beim Aktualisieren');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showNotification('error', 'Fehler beim Aktualisieren des Raums');
    });
});

document.getElementById('editRoomModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditRoomModal();
});

// Equipment-Auswahl sammeln (Issue #17)
function getSelectedEquipment() {
    const checked = [...document.querySelectorAll('input[name="equipment[]"]:checked')].map(cb => cb.value);
    const custom = document.getElementById('equipment_custom')?.value?.trim();
    if (custom) {
        custom.split(',').forEach(item => {
            const trimmed = item.trim();
            if (trimmed && !checked.includes(trimmed)) checked.push(trimmed);
        });
    }
    return checked.join(', ');
}

// Show Notification
function showNotification(type, message) {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-6 py-4 rounded-lg shadow-lg z-50 ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    } text-white font-semibold animate-pulse`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-3"></i>
            <span>${message}</span>
        </div>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

// Filter Rooms
function filterRooms() {
    const equipmentFilter = document.getElementById('equipmentFilter').value.toLowerCase();
    const minCapacity = parseInt(document.getElementById('minCapacity').value) || 0;
    const maxCapacity = parseInt(document.getElementById('maxCapacity').value) || Infinity;
    
    const roomContainers = document.querySelectorAll('.room-container');
    let visibleCount = 0;
    
    roomContainers.forEach(container => {
        const equipmentTags = container.querySelectorAll('.inline-flex.items-center');
        const capacityText = container.querySelector('p.text-sm.text-gray-600').textContent;
        const capacity = parseInt(capacityText.match(/Max\. (\d+) Pers\./)?.[1] || 0);
        
        let hasEquipment = true;
        if (equipmentFilter) {
            hasEquipment = false;
            equipmentTags.forEach(tag => {
                if (tag.textContent.toLowerCase().includes(equipmentFilter)) {
                    hasEquipment = true;
                }
            });
        }
        
        const meetsCapacity = capacity >= minCapacity && capacity <= maxCapacity;
        
        if (hasEquipment && meetsCapacity) {
            container.style.display = '';
            visibleCount++;
        } else {
            container.style.display = 'none';
        }
    });
    
    document.getElementById('roomCount').textContent = visibleCount;
}

// Clear Room Filters
function clearRoomFilters() {
    document.getElementById('equipmentFilter').value = '';
    document.getElementById('minCapacity').value = '';
    document.getElementById('maxCapacity').value = '';
    filterRooms();
}

// Show Equipment Info Tooltip
function showEquipmentInfo(event, exhibitorEquipment, roomEquipment) {
    event.preventDefault();
    event.stopPropagation();
    
    // Remove existing tooltip
    const existingTooltip = document.getElementById('equipmentTooltip');
    if (existingTooltip) {
        existingTooltip.remove();
        return;
    }
    
    const button = event.currentTarget;
    const rect = button.getBoundingClientRect();
    
    const tooltip = document.createElement('div');
    tooltip.id = 'equipmentTooltip';
    tooltip.className = 'fixed bg-white border border-gray-300 rounded-lg shadow-xl p-4 z-50 min-w-[280px]';
    tooltip.style.left = rect.left + 'px';
    tooltip.style.top = (rect.bottom + 8) + 'px';
    
    const missingEquipment = exhibitorEquipment.filter(eq => !roomEquipment.includes(eq));
    const availableEquipment = exhibitorEquipment.filter(eq => roomEquipment.includes(eq));
    
    let html = '<div class="space-y-2">';
    html += '<h4 class="text-sm font-semibold text-gray-800 mb-2">Equipment-Übersicht</h4>';
    
    if (availableEquipment.length > 0) {
        html += '<div class="space-y-1">';
        html += '<p class="text-xs font-medium text-emerald-600"><i class="fas fa-check-circle mr-1"></i>Verfügbar:</p>';
        availableEquipment.forEach(eq => {
            html += `<div class="text-xs text-gray-700 ml-4">• ${eq}</div>`;
        });
        html += '</div>';
    }
    
    if (missingEquipment.length > 0) {
        html += '<div class="space-y-1">';
        html += '<p class="text-xs font-medium text-amber-600"><i class="fas fa-exclamation-triangle mr-1"></i>Fehlt:</p>';
        missingEquipment.forEach(eq => {
            html += `<div class="text-xs text-gray-700 ml-4">• ${eq}</div>`;
        });
        html += '</div>';
    }
    
    if (exhibitorEquipment.length === 0) {
        html += '<p class="text-xs text-gray-500">Kein Equipment benötigt</p>';
    }
    
    html += '</div>';
    tooltip.innerHTML = html;
    document.body.appendChild(tooltip);
    
    // Close on click outside
    setTimeout(() => {
        document.addEventListener('click', function closeTooltip(e) {
            if (!tooltip.contains(e.target) && e.target !== button) {
                tooltip.remove();
                document.removeEventListener('click', closeTooltip);
            }
        });
    }, 100);
}
</script>

<style>
.exhibitor-card {
    touch-action: none;
}

.room-dropzone {
    transition: background-color 0.3s;
}
</style>
