<?php
// Meine Anmeldungen Seite

// Registrierungen des Benutzers mit Details laden (LEFT JOIN für NULL timeslot_id)
$stmt = $db->prepare("
    SELECT r.*, e.name as exhibitor_name, e.short_description, t.slot_name, t.slot_number, t.start_time, t.end_time,
           r.registration_type
    FROM registrations r 
    JOIN exhibitors e ON r.exhibitor_id = e.id 
    LEFT JOIN timeslots t ON r.timeslot_id = t.id 
    WHERE r.user_id = ? AND r.edition_id = ? AND e.edition_id = ?
    ORDER BY t.slot_number ASC
");
$stmt->execute([$_SESSION['user_id'], $activeEditionId, $activeEditionId]);
$myRegistrations = $stmt->fetchAll();

// Handle Abmeldung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unregister'])) {
    requireCsrf();
    $registrationId = intval($_POST['registration_id']);
    
    // Prüfen ob die Registrierung dem User gehört
    $stmt = $db->prepare("SELECT * FROM registrations WHERE id = ? AND user_id = ?");
    $stmt->execute([$registrationId, $_SESSION['user_id']]);
    $registration = $stmt->fetch();
    
    if ($registration) {
        // Nur manuelle Registrierungen können abgemeldet werden
        if ($registration['registration_type'] === 'manual' && getRegistrationStatus() === 'open') {
            $stmt = $db->prepare("DELETE FROM registrations WHERE id = ?");
            if ($stmt->execute([$registrationId])) {
                $message = ['type' => 'success', 'text' => 'Abmeldung erfolgreich'];
                
                // Registrierungen neu laden (LEFT JOIN für NULL timeslot_id)
                $stmt = $db->prepare("
                    SELECT r.*, e.name as exhibitor_name, e.short_description, t.slot_name, t.slot_number, t.start_time, t.end_time,
                           r.registration_type
                    FROM registrations r 
                    JOIN exhibitors e ON r.exhibitor_id = e.id 
                    LEFT JOIN timeslots t ON r.timeslot_id = t.id 
                    WHERE r.user_id = ? AND r.edition_id = ? AND e.edition_id = ?
                    ORDER BY t.slot_number ASC
                ");
                $stmt->execute([$_SESSION['user_id'], $activeEditionId, $activeEditionId]);
                $myRegistrations = $stmt->fetchAll();
            } else {
                $message = ['type' => 'error', 'text' => 'Fehler beim Abmelden'];
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Diese Anmeldung kann nicht abgemeldet werden'];
        }
    }
}
?>

<div class="max-w-5xl mx-auto">
    <?php if (isset($message)): ?>
    <div class="mb-6">
        <?php if ($message['type'] === 'success'): ?>
            <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <p class="text-green-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <p class="text-red-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Übersicht Card -->
    <div class="bg-white rounded-xl border border-gray-100 p-6 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800 mb-1">Ihre Anmeldungen</h2>
                <p class="text-gray-500 text-sm">Übersicht aller gebuchten Messetermine</p>
            </div>
            <div class="bg-emerald-50 rounded-xl px-5 py-3 text-center">
                <div class="text-2xl font-bold text-emerald-600"><?php echo count($myRegistrations); ?></div>
                <div class="text-xs text-emerald-600">Termine</div>
            </div>
        </div>
    </div>

    <?php if (empty($myRegistrations)): ?>
    <!-- Keine Anmeldungen -->
    <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
        <i class="fas fa-calendar-times text-5xl text-gray-200 mb-4"></i>
        <h3 class="text-lg font-semibold text-gray-700 mb-2">Noch keine Anmeldungen</h3>
        <p class="text-gray-500 mb-6 text-sm">Du hast dich noch für keinen Fachbereich eingeschrieben.</p>
        <a href="?page=registration" class="inline-flex items-center px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
            <i class="fas fa-plus-circle mr-2"></i>
            Jetzt einschreiben
        </a>
    </div>
    <?php else: ?>
    <!-- Registrierungen nach Zeitslot -->
    <div class="space-y-4">
        <?php 
        // Nach Slot gruppieren - separate Behandlung für NULL timeslots
        $slotGroups = [];
        $noTimeslotRegs = [];
        foreach ($myRegistrations as $reg) {
            if ($reg['slot_number'] === null) {
                $noTimeslotRegs[] = $reg;
            } else {
                $slotGroups[$reg['slot_number']][] = $reg;
            }
        }
        
        foreach ($slotGroups as $slotNumber => $registrations):
            $slot = $registrations[0];
        ?>
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
            <!-- Slot Header -->
            <div class="bg-gray-50 border-b border-gray-100 px-5 py-3">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-emerald-600"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($slot['slot_name'] ?? 'Kein Zeitslot'); ?></h3>
                            <p class="text-xs text-gray-500">
                                <?php 
                                if ($slot['start_time'] && $slot['end_time']) {
                                    echo date('H:i', strtotime($slot['start_time'])) . ' - ' . date('H:i', strtotime($slot['end_time'])) . ' Uhr';
                                } else {
                                    echo 'Zeit wird noch festgelegt';
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <span class="text-xs font-medium text-gray-500 bg-gray-200 px-2 py-1 rounded">
                        <?php echo count($registrations); ?> Anmeldung(en)
                    </span>
                </div>
            </div>

            <!-- Slot Content -->
            <div class="p-5 space-y-3">
                <?php foreach ($registrations as $reg): ?>
                <div class="border border-gray-100 rounded-lg p-4 hover:border-emerald-200 hover:bg-gray-50 transition">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="font-semibold text-gray-800">
                                    <?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
                                </h4>
                                <?php if ($reg['registration_type'] === 'automatic'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                        <i class="fas fa-robot mr-1"></i>Auto
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500">
                                <?php echo htmlspecialchars($reg['short_description'] ?? ''); ?>
                            </p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2">
                            <button onclick="openExhibitorModal(<?php echo $reg['exhibitor_id']; ?>)" 
                                    class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition text-sm">
                                <i class="fas fa-info-circle mr-1"></i>Details
                            </button>
                            
                            <?php if ($reg['registration_type'] === 'manual' && getRegistrationStatus() === 'open'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Möchtest Du dich wirklich abmelden?')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                <button type="submit" 
                                        name="unregister"
                                        class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition text-sm">
                                    <i class="fas fa-times-circle mr-1"></i>Abmelden
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($noTimeslotRegs)): ?>
        <!-- Registrierungen ohne Zeitslot -->
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
            <!-- Header -->
            <div class="bg-amber-50 border-b border-amber-100 px-5 py-3">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-hourglass-half text-amber-600"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800">Ohne Zeitslot</h3>
                            <p class="text-xs text-amber-600">
                                Zeit wird noch festgelegt
                            </p>
                        </div>
                    </div>
                    <span class="text-xs font-medium text-amber-700 bg-amber-200 px-2 py-1 rounded">
                        <?php echo count($noTimeslotRegs); ?> Anmeldung(en)
                    </span>
                </div>
            </div>

            <!-- Content -->
            <div class="p-5 space-y-3">
                <?php foreach ($noTimeslotRegs as $reg): ?>
                <div class="border border-gray-100 rounded-lg p-4 hover:border-emerald-200 hover:bg-gray-50 transition">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="font-semibold text-gray-800">
                                    <?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?>
                                </h4>
                                <?php if ($reg['registration_type'] === 'automatic'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                        <i class="fas fa-robot mr-1"></i>Auto
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500">
                                <?php echo htmlspecialchars($reg['short_description'] ?? ''); ?>
                            </p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-2">
                            <button onclick="openExhibitorModal(<?php echo $reg['exhibitor_id']; ?>)" 
                                    class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition text-sm">
                                <i class="fas fa-info-circle mr-1"></i>Details
                            </button>
                            
                            <?php if ($reg['registration_type'] === 'manual' && getRegistrationStatus() === 'open'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Möchtest Du dich wirklich abmelden?')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                <button type="submit" 
                                        name="unregister"
                                        class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition text-sm">
                                    <i class="fas fa-times-circle mr-1"></i>Abmelden
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Info Box -->
    <div class="mt-6 bg-blue-50 border border-blue-100 rounded-xl p-5">
        <h4 class="font-semibold text-blue-900 mb-3 flex items-center">
            <i class="fas fa-info-circle mr-2"></i>
            Wichtige Hinweise
        </h4>
        <ul class="space-y-2 text-sm text-blue-800">
            <li class="flex items-start">
                <i class="fas fa-check text-blue-600 mr-2 mt-1"></i>
                <span>Automatisch zugeteilte Anmeldungen können nicht abgemeldet werden.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-600 mr-2 mt-1"></i>
                <span>Abmeldungen sind nur während des Einschreibungszeitraums möglich.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-600 mr-2 mt-1"></i>
                <span>Bitte erscheine pünktlich zu Deinen gebuchten Terminen.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-600 mr-2 mt-1"></i>
                <span>Notiere Dir die Uhrzeiten der einzelnen Zeitslots.</span>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
