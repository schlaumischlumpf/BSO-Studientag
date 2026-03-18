<?php
// Zeitplan/Tagesablauf für Schüler - Timeline Ansicht

// Registrierungen des Benutzers mit allen Informationen abrufen
$stmt = $db->prepare("
    SELECT 
        r.*,
        e.name as exhibitor_name,
        e.short_description,
        e.room_id,
        rm.room_number,
        rm.address as room_address,
        rm.floor,
        t.slot_number,
        t.slot_name,
        t.start_time,
        t.end_time
    FROM registrations r
    JOIN exhibitors e ON r.exhibitor_id = e.id
    JOIN timeslots t ON r.timeslot_id = t.id
    LEFT JOIN rooms rm ON e.room_id = rm.id
    WHERE r.user_id = ? AND r.edition_id = ? AND e.edition_id = ?
    ORDER BY t.slot_number ASC
");
$stmt->execute([$_SESSION['user_id'], $activeEditionId, $activeEditionId]);
$registrations = $stmt->fetchAll();

// Tagesablauf dynamisch aus Datenbank laden
$stmtSlots = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmtSlots->execute([$activeEditionId]);
$dbSlots = $stmtSlots->fetchAll();
$timeline = [];
foreach ($dbSlots as $slot) {
    $isBreak   = !empty($slot['is_break']);
    $isManaged = (bool)$slot['is_managed'];
    if ($isBreak) {
        $timeline[] = [
            'time' => substr($slot['start_time'] ?? '00:00', 0, 5),
            'end'  => substr($slot['end_time'] ?? '00:00', 0, 5),
            'type' => 'break',
            'slot_number' => $slot['slot_number'],
            'label' => $slot['slot_name'],
            'icon' => 'fa-coffee',
            'color' => $slot['slot_name'] === 'Essenspause' ? 'orange' : 'green',
            'assigned' => false,
            'description' => 'Austausch & Verpflegung',
        ];
    } else {
        $timeline[] = [
            'time' => substr($slot['start_time'] ?? '00:00', 0, 5),
            'end'  => substr($slot['end_time'] ?? '00:00', 0, 5),
            'type' => 'slot',
            'slot_number' => $slot['slot_number'],
            'label' => $slot['slot_name'] . ($isManaged ? ' (Feste Zuteilung)' : ' (Freie Wahl)'),
            'icon' => $isManaged ? 'fa-clipboard-check' : 'fa-hand-pointer',
            'color' => $isManaged ? 'blue' : 'purple',
            'assigned' => $isManaged,
        ];
    }
}

// Helper function for pastel colors
function getPastelColorClass($color) {
    switch ($color) {
        case 'blue': return 'bg-gradient-to-r from-blue-50 to-sky-50 border-blue-200 text-blue-800';
        case 'green': return 'bg-gradient-to-r from-emerald-50 to-green-50 border-emerald-200 text-emerald-800';
        case 'purple': return 'bg-gradient-to-r from-purple-50 to-violet-50 border-purple-200 text-purple-800';
        case 'orange': return 'bg-gradient-to-r from-orange-50 to-amber-50 border-orange-200 text-orange-800';
        default: return 'bg-gray-50 border-gray-200 text-gray-800';
    }
}

// Registrierungen nach Slot-Nummer gruppieren
$registrationsBySlot = [];
foreach ($registrations as $reg) {
    $registrationsBySlot[$reg['slot_number']] = $reg;
}
?>

<div class="max-w-6xl mx-auto">
    <!-- Calendar Header -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Kalender</h2>
                <p class="text-gray-500 mt-1">Dein persönlicher Zeitplan für den BSO-Tag an der FU</p>
            </div>
            <div class="flex items-center space-x-4">
                <div class="text-right">
                    <div class="text-sm font-medium text-gray-500">Datum</div>
                    <div class="text-lg font-bold text-gray-800"><?php echo formatDate(date('Y-m-d')); ?></div>
                </div>
                <div class="h-10 w-px bg-gray-200"></div>
                <div class="flex gap-2">
                    <a href="api/generate-personal-pdf.php" class="bg-gradient-to-r from-emerald-500 to-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:from-emerald-600 hover:to-green-700 transition shadow-sm inline-flex items-center gap-2 btn-mobile-icon">
                        <i class="fas fa-file-pdf"></i> <span class="btn-text">PDF herunterladen</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar Grid -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <!-- Grid Header -->
        <div class="grid grid-cols-[100px_1fr] border-b border-gray-100 bg-gray-50/50">
            <div class="p-4 text-sm font-medium text-gray-500 text-center border-r border-gray-100">Zeit</div>
            <div class="p-4 text-sm font-medium text-gray-500">Ablauf</div>
        </div>

        <!-- Grid Body -->
        <div class="divide-y divide-gray-100">
            <?php foreach ($timeline as $item): ?>
                <div class="grid grid-cols-[100px_1fr] group hover:bg-gray-50/30 transition-colors">
                    <!-- Time Column -->
                    <div class="p-4 text-sm text-gray-500 text-center border-r border-gray-100 flex flex-col justify-center">
                        <span class="font-bold text-gray-800"><?php echo $item['time']; ?></span>
                        <span class="text-xs text-gray-400"><?php echo $item['end']; ?></span>
                    </div>

                    <!-- Content Column -->
                    <div class="p-3">
                        <?php 
                        $cardClass = getPastelColorClass($item['color']);
                        $hasRegistration = isset($item['slot_number']) && isset($registrationsBySlot[$item['slot_number']]);
                        $reg = $hasRegistration ? $registrationsBySlot[$item['slot_number']] : null;
                        ?>

                        <div class="<?php echo $cardClass; ?> rounded-lg p-4 border relative transition-all hover:shadow-md">
                            <!-- Left Border Accent -->
                            <div class="absolute left-0 top-0 bottom-0 w-1 rounded-l-lg <?php echo str_replace('bg-', 'bg-', str_replace('pastel-', '', $cardClass)); ?> opacity-50"></div>

                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <!-- Label / Title -->
                                    <div class="flex items-center mb-2">
                                        <div class="w-8 h-8 rounded-full bg-white/60 flex items-center justify-center mr-3 shadow-sm">
                                            <i class="fas <?php echo $item['icon']; ?> text-sm opacity-80"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-sm uppercase tracking-wide opacity-90">
                                                <?php echo $item['label']; ?>
                                            </h3>
                                            <?php if ($item['type'] === 'break'): ?>
                                                <p class="text-xs opacity-75"><?php echo $item['description']; ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Slot Content -->
                                    <?php if ($item['type'] === 'slot'): ?>
                                        <?php if ($hasRegistration): ?>
                                            <div class="mt-3 bg-white/50 rounded-lg p-3 backdrop-blur-sm">
                                                <h4 class="font-bold text-lg mb-1"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></h4>
                                                <div class="flex flex-col gap-1 text-sm opacity-90">
                                                    <span class="flex items-center">
                                                        <i class="fas fa-chalkboard mr-2"></i>
                                                        Hörsaal: <?php echo htmlspecialchars($reg['room_number']); ?>
                                                    </span>
                                                    <?php if (!empty($reg['room_address'])): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                                        <?php echo htmlspecialchars($reg['room_address']); ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($item['assigned']): ?>
                                            <div class="mt-2 text-sm opacity-75 italic">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Noch keine Zuteilung erhalten.
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-2 text-sm opacity-75">
                                                <i class="fas fa-walking mr-1"></i>
                                                Freie Wahl vor Ort - Besuche einen Fachbereich deiner Wahl.
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons (if needed) -->
                                <?php if ($hasRegistration): ?>
                                    <div class="ml-4">
                                        <a href="?page=exhibitors&id=<?php echo $reg['exhibitor_id']; ?>" class="w-8 h-8 rounded-full bg-white/50 hover:bg-white flex items-center justify-center transition shadow-sm" title="Details anzeigen">
                                            <i class="fas fa-arrow-right text-xs"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
@media print {
    /* Hide non-printable elements */
    .sidebar, 
    .mobile-menu-button, 
    button:not(.print-keep), 
    nav, 
    header,
    .no-print {
        display: none !important;
    }
    
    /* Reset body and main container */
    body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    main {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    
    /* Page setup */
    @page {
        size: A4 portrait;
        margin: 15mm;
    }
    
    /* Card styling for print */
    .bg-white {
        box-shadow: none !important;
        border: 1px solid #e5e7eb !important;
    }
    
    /* Keep colors for timeline items */
    .bg-gradient-to-r {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Ensure timeline items fit on page */
    .grid {
        page-break-inside: avoid;
    }
    
    /* Header styling */
    h2 {
        font-size: 1.5rem !important;
    }
    
    /* Hide buttons */
    a[href*="print-view"],
    button[onclick*="print"] {
        display: none !important;
    }
}

/* Print Preview Button Styling */
.print-preview-btn {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: white;
    transition: all 0.3s ease;
}

.print-preview-btn:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
</style>
