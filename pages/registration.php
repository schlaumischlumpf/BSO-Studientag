<?php
// Einschreibungsseite mit automatischer gleichmäßiger Verteilung

$regStatus = getRegistrationStatus();
$regStart = getSetting('registration_start');
$regEnd = getSetting('registration_end');
$singleSlotEnabled = getSetting('single_slot_assignment_enabled', '0') === '1';

// Admins dürfen auch nach Einschreibeschluss Änderungen vornehmen (Issue #12)
$canModify = ($regStatus === 'open') || isAdmin();

// Nur verwaltete Timeslots laden (Slots 1, 3, 5) - Slots 2 und 4 sind freie Wahl vor Ort
$stmt = $db->prepare("SELECT * FROM timeslots WHERE slot_number " . getManagedSlotsSqlIn() . " AND timeslots.edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmt->execute([$activeEditionId]);
$timeslots = $stmt->fetchAll();

// Prüfen ob Benutzer bereits für alle Slots registriert ist
$stmt = $db->prepare("SELECT COUNT(*) as count FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
$stmt->execute([$_SESSION['user_id'], $activeEditionId]);
$userRegCount = $stmt->fetch()['count'];
$maxRegistrations = intval(getSetting('max_registrations_per_student', 3));

// Handle Registration Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    requireCsrf();
    if (!$canModify) {
        $message = ['type' => 'error', 'text' => 'Die Einschreibung ist derzeit nicht möglich.'];
    } elseif ($userRegCount >= $maxRegistrations) {
        $message = ['type' => 'error', 'text' => 'Du hast bereits die maximale Anzahl an Einschreibungen erreicht.'];
    } else {
        $exhibitorId = intval($_POST['exhibitor_id']);
        $priority = isset($_POST['priority']) ? max(1, min(3, intval($_POST['priority']))) : 2;

        // Prüfen ob User bereits für diesen Aussteller registriert ist
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND registrations.edition_id = ?");
        $stmt->execute([$_SESSION['user_id'], $exhibitorId, $activeEditionId]);
        $alreadyRegistered = $stmt->fetch()['count'] > 0;

        if ($alreadyRegistered) {
            $message = ['type' => 'error', 'text' => 'Du bist bereits für diesen Fachbereich angemeldet.'];
        } else {
            // Prüfen ob diese Priorität bereits verwendet wird
            $stmt = $db->prepare("SELECT priority FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
            $stmt->execute([$_SESSION['user_id'], $activeEditionId]);
            $usedPriorities = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (in_array($priority, $usedPriorities)) {
                $availablePriorities = array_diff([1, 2, 3], $usedPriorities);
                $priorityLabels = [1 => 'Hoch', 2 => 'Mittel', 3 => 'Niedrig'];
                $availableLabels = array_map(function($p) use ($priorityLabels) { return $priorityLabels[$p]; }, $availablePriorities);
                $message = ['type' => 'error', 'text' => 'Diese Priorität wurde bereits verwendet. Verfügbare Prioritäten: ' . implode(', ', $availableLabels)];
            } else {
                try {
                    // Registrierung OHNE Slot-Zuteilung - Slot wird später automatisch zugewiesen
                    $stmt = $db->prepare("INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, priority, edition_id) VALUES (?, ?, NULL, 'manual', ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $exhibitorId, $priority, $activeEditionId]);

                    $message = ['type' => 'success', 'text' => 'Erfolgreich angemeldet! Der Zeitslot wird später automatisch zugeteilt.'];

                    // Counter aktualisieren
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
                    $stmt->execute([$_SESSION['user_id'], $activeEditionId]);
                    $userRegCount = $stmt->fetch()['count'];
                } catch (PDOException $e) {
                    logErrorToAudit($e, 'Anmeldung');
                    $message = ['type' => 'error', 'text' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.'];
                }
            }
        }
    }
}

// Handle Abmeldung - Admins/Lehrer können immer abmelden, Schüler nur bei offener Einschreibung (Issue #12)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unregister'])) {
    requireCsrf();
    $exhibitorId = intval($_POST['exhibitor_id']);
    
    // Prüfen ob die Registrierung dem User gehört
    $stmt = $db->prepare("SELECT * FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND registrations.edition_id = ?");
    $stmt->execute([$_SESSION['user_id'], $exhibitorId, $activeEditionId]);
    $registration = $stmt->fetch();
    
    if ($registration && $canModify) {
        $stmt = $db->prepare("DELETE FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND registrations.edition_id = ?");
        if ($stmt->execute([$_SESSION['user_id'], $exhibitorId, $activeEditionId])) {
            $message = ['type' => 'success', 'text' => 'Erfolgreich abgemeldet'];
            
            // Counter aktualisieren
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
            $stmt->execute([$_SESSION['user_id'], $activeEditionId]);
            $userRegCount = $stmt->fetch()['count'];
        } else {
            $message = ['type' => 'error', 'text' => 'Fehler beim Abmelden'];
        }
    } elseif (!$canModify) {
        $message = ['type' => 'error', 'text' => 'Die Einschreibung ist geschlossen. Nur Admins können Änderungen vornehmen.'];
    }
}

// Prüfe für jeden Aussteller ob der User bereits registriert ist
$userRegistrations = [];
$stmt = $db->prepare("SELECT exhibitor_id, priority FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
$stmt->execute([$_SESSION['user_id'], $activeEditionId]);
foreach ($stmt->fetchAll() as $row) {
    $userRegistrations[$row['exhibitor_id']] = $row['priority'] ?? 2;
}
?>

<div class="max-w-4xl mx-auto space-y-6">
<style>
/* Registration Page – Mobile Enhancements */
@media (max-width: 768px) {
    /* Sticky progress bar at top of screen on mobile */
    #regProgressBar {
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border-radius: 0 !important;
        margin: 0 -1rem;
        padding: 0.625rem 1rem !important;
        border-left: none !important;
        border-right: none !important;
        border-top: none !important;
    }

    /* Priority select: larger on mobile */
    select[name="priority"] {
        min-height: 44px;
        font-size: 16px !important;
        padding: 0.5rem 0.75rem;
    }

    /* Exhibitor card: make sure buttons stack cleanly */
    .exhibitor-reg-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }

    .exhibitor-reg-actions form {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }

    .exhibitor-reg-actions button[type="submit"],
    .exhibitor-reg-actions .btn-unregister {
        width: 100%;
        min-height: 44px;
        justify-content: center;
    }
}
</style>
<script>
const REG_START  = "<?php echo htmlspecialchars(getSetting('registration_start', '')); ?>";
const REG_END    = "<?php echo htmlspecialchars(getSetting('registration_end', '')); ?>";
const REG_STATUS = "<?php echo getRegistrationStatus(); ?>";
</script>

<?php if ($regStatus === 'upcoming' || $regStatus === 'open'): ?>
<div id="regCountdownBanner"
     class="mb-6 flex items-center gap-4 px-5 py-4 rounded-xl border
            <?php echo $regStatus === 'open' ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200'; ?>">
    <div class="flex-shrink-0">
        <?php if ($regStatus === 'open'): ?>
            <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
            </span>
        <?php else: ?>
            <i class="fas fa-hourglass-start text-amber-500 text-lg"></i>
        <?php endif; ?>
    </div>
    <div class="flex-1">
        <p class="text-sm font-semibold <?php echo $regStatus === 'open' ? 'text-emerald-700' : 'text-amber-700'; ?>">
            <?php echo $regStatus === 'open' ? 'Einschreibung endet in' : 'Einschreibung startet in'; ?>
            <span id="regCountdownValue" class="font-bold tabular-nums">…</span>
        </p>
        <p class="text-xs mt-0.5 <?php echo $regStatus === 'open' ? 'text-emerald-600' : 'text-amber-600'; ?>">
            <?php echo $regStatus === 'open' ? 'bis ' . formatDateTime($regEnd) : 'ab ' . formatDateTime($regStart); ?>
        </p>
    </div>
</div>
<?php endif; ?>

    <!-- Status Banner -->
    <?php if ($regStatus === 'open'): ?>
        <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-xl">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-check-circle text-emerald-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-emerald-800 text-sm">Einschreibung geöffnet</h3>
                    <p class="text-xs text-emerald-600">Bis <?php echo formatDateTime($regEnd); ?></p>
                </div>
            </div>
        </div>
    <?php elseif ($regStatus === 'upcoming'): ?>
        <div class="bg-amber-50 border border-amber-200 p-4 rounded-xl">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-clock text-amber-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-amber-800 text-sm">Einschreibung startet bald</h3>
                    <p class="text-xs text-amber-600">Ab <?php echo formatDateTime($regStart); ?></p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-red-50 border border-red-200 p-4 rounded-xl">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-lock text-red-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-red-800 text-sm">Einschreibung geschlossen</h3>
                    <p class="text-xs text-red-600">Endete am <?php echo formatDateTime($regEnd); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Fortschrittsanzeige -->
    <div id="regProgressBar" class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="text-sm font-semibold text-gray-800 mb-3">Ihr Fortschritt</h3>
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs text-gray-500">Einschreibungen</span>
            <span class="text-xs font-semibold text-gray-700">
                <?php echo $userRegCount; ?> / <?php echo $maxRegistrations; ?>
            </span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-2">
            <?php 
            $progress = ($maxRegistrations > 0) ? ($userRegCount / $maxRegistrations * 100) : 0;
            ?>
            <div class="bg-emerald-500 h-2 rounded-full transition-all" 
                 style="width: <?php echo min($progress, 100); ?>%"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2">
            <?php if ($userRegCount >= $maxRegistrations): ?>
                Alle Einschreibungen genutzt.
            <?php else: ?>
                Noch <?php echo $maxRegistrations - $userRegCount; ?> verfügbar
            <?php endif; ?>
        </p>
    </div>

    <?php if (isset($message)): ?>
    <div class="animate-pulse">
        <?php if ($message['type'] === 'success'): ?>
            <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-xl">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                    <p class="text-emerald-700 text-sm"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-red-50 border border-red-200 p-4 rounded-xl">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <p class="text-red-700 text-sm"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Fachbereich Liste -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="text-base font-semibold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-clipboard-list text-emerald-500 mr-2"></i>
            Verfügbare Fachbereiche
        </h3>

        <?php if (empty($exhibitors)): ?>
            <div class="text-center py-12">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500 text-sm">Derzeit sind keine Fachbereiche verfügbar.</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($exhibitors as $exhibitor): ?>
                <div class="border border-gray-100 rounded-xl p-4 hover:border-emerald-200 hover:bg-gray-50 transition">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-800 mb-1">
                                <?php echo htmlspecialchars($exhibitor['name']); ?>
                            </h4>
                            <?php if (!empty($exhibitor['short_description'])): ?>
                            <p class="text-sm text-gray-500 mb-2">
                                <?php echo htmlspecialchars(substr($exhibitor['short_description'] ?? '', 0, 100)); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="exhibitor-reg-actions flex flex-col sm:flex-row gap-2">
                            <?php if (isset($userRegistrations[$exhibitor['id']])): ?>
                            <!-- Bereits angemeldet - Priorität & Abmelde-Button -->
                            <?php 
                                $currentPriority = $userRegistrations[$exhibitor['id']];
                                $priorityLabels = [1 => 'Hoch', 2 => 'Mittel', 3 => 'Niedrig'];
                                $priorityColors = [1 => 'text-red-600 bg-red-50', 2 => 'text-amber-600 bg-amber-50', 3 => 'text-gray-600 bg-gray-100'];
                            ?>
                            <span class="px-2 py-1 rounded-md text-xs font-medium <?php echo $priorityColors[$currentPriority] ?? $priorityColors[2]; ?>">
                                <i class="fas fa-star mr-1"></i>Prio: <?php echo $priorityLabels[$currentPriority] ?? 'Mittel'; ?>
                            </span>
                            <?php if ($canModify): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="exhibitor_id" value="<?php echo $exhibitor['id']; ?>">
                                <button type="submit" 
                                        name="unregister" 
                                        class="px-3 py-1.5 bg-red-500 text-white rounded-lg hover:bg-red-600 transition font-medium text-sm"
                                        onclick="return confirm('Möchtest du dich wirklich abmelden?')">
                                    <i class="fas fa-times mr-1"></i>Abmelden
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium">
                                <i class="fas fa-check mr-1"></i>Angemeldet
                            </span>
                            <?php endif; ?>
                            <?php else: ?>
                            <!-- Noch nicht angemeldet - Anmelde-Button -->
                            <?php if ($canModify && $userRegCount < $maxRegistrations): ?>
                            <form method="POST" class="inline flex items-center gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="exhibitor_id" value="<?php echo $exhibitor['id']; ?>">
                                <select name="priority" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 bg-white focus:ring-2 focus:ring-emerald-300">
                                    <option value="1">Prio: Hoch</option>
                                    <option value="2" selected>Prio: Mittel</option>
                                    <option value="3">Prio: Niedrig</option>
                                </select>
                                <button type="submit" 
                                        name="register" 
                                        class="px-3 py-1.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm"
                                        onclick="return confirm('Möchtest du dich für diesen Fachbereich anmelden?')">
                                    <i class="fas fa-user-plus mr-1"></i>Anmelden
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-100 rounded-xl p-5">
        <h4 class="font-semibold text-blue-900 mb-3 flex items-center text-sm">
            <i class="fas fa-info-circle mr-2"></i>
            Wie funktioniert die Einschreibung?
        </h4>
        <ul class="space-y-2 text-xs text-blue-800">
            <li class="flex items-start">
                <i class="fas fa-check text-blue-500 mr-2 mt-0.5"></i>
                <span>Du kannst dich für bis zu <?php echo $maxRegistrations; ?> Fachbereiche anmelden.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-500 mr-2 mt-0.5"></i>
                <span>Du kannst dich nur einmal pro Fachbereich anmelden.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-500 mr-2 mt-0.5"></i>
                <span>
                    <?php if ($singleSlotEnabled): ?>
                        Im Ein-Slot-Modus wird genau ein Fachbereich automatisch zugeteilt (Prioritäten + faire Verteilung).
                    <?php else: ?>
                        Die Zuteilung zu den Zeitslots (1, 3, 5) erfolgt automatisch durch das System.
                    <?php endif; ?>
                </span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-500 mr-2 mt-0.5"></i>
                <span>Setze eine Priorität (Hoch/Mittel/Niedrig) - höhere Prioritäten werden bei der Zuteilung bevorzugt.</span>
            </li>
            <li class="flex items-start">
                <i class="fas fa-check text-blue-500 mr-2 mt-0.5"></i>
                <span>Du kannst dich jederzeit wieder abmelden, solange das Einschreibungsfenster geöffnet ist.</span>
            </li>
        </ul>
    </div>
</div>

<script>
function startCountdown(targetIsoStr, elementId) {
    function update() {
        const diff = new Date(targetIsoStr).getTime() - Date.now();
        const el   = document.getElementById(elementId);
        if (!el) return;
        if (diff <= 0) { location.reload(); return; }
        const days  = Math.floor(diff / 86400000);
        const hours = Math.floor((diff % 86400000) / 3600000);
        const mins  = Math.floor((diff % 3600000) / 60000);
        const secs  = Math.floor((diff % 60000) / 1000);
        const showSecs = diff < 2 * 3600 * 1000;
        let text = '';
        if (days > 0)    text += days + 'd ';
        if (hours > 0)   text += hours + 'h ';
        text += mins + 'min';
        if (showSecs)    text += ' ' + secs + 's';
        el.textContent = text.trim();
    }
    update();
    setInterval(update, 1000);
}
if (REG_STATUS === 'open')          startCountdown(REG_END,   'regCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'regCountdownValue');
</script>
