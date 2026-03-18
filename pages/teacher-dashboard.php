<?php
// Lehrer Dashboard (Issue #8)

$regStatus = getRegistrationStatus();
$regStart  = getSetting('registration_start');
$regEnd    = getSetting('registration_end');

// Alle Klassen abrufen
$stmt = $db->query("SELECT DISTINCT class FROM users WHERE role = 'student' AND class IS NOT NULL AND class != '' ORDER BY class");
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Statistiken
$stats = [];

// Gesamtzahl Schüler
$stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
$stats['total_students'] = $stmt->fetch()['count'];

// Schüler mit vollständigen Anmeldungen (alle 3 Slots)
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT user_id) as count
    FROM (
        SELECT r.user_id, COUNT(DISTINCT t.slot_number) as slot_count
        FROM registrations r
        JOIN timeslots t ON r.timeslot_id = t.id
        JOIN users u ON r.user_id = u.id
        WHERE t.slot_number " . getManagedSlotsSqlIn() . " AND u.role = 'student' AND r.edition_id = ?
        GROUP BY r.user_id
        HAVING slot_count = " . getManagedSlotCount() . "
    ) as complete_registrations
");
$stmt->execute([$activeEditionId]);
$stats['complete_students'] = $stmt->fetch()['count'];

// Schüler mit unvollständigen Anmeldungen
$stats['incomplete_students'] = $stats['total_students'] - $stats['complete_students'];

// Schüler ohne Anmeldungen
$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM users u
    WHERE u.role = 'student' 
    AND u.id NOT IN (SELECT DISTINCT user_id FROM registrations WHERE edition_id = ?)
");
$stmt->execute([$activeEditionId]);
$stats['no_registrations'] = $stmt->fetch()['count'];
?>

<script>
const REG_START  = "<?php echo htmlspecialchars(getSetting('registration_start', '')); ?>";
const REG_END    = "<?php echo htmlspecialchars(getSetting('registration_end', '')); ?>";
const REG_STATUS = "<?php echo getRegistrationStatus(); ?>";
</script>

<div class="space-y-6">
<?php if ($regStatus === 'open' || $regStatus === 'upcoming'): ?>
<div class="flex items-center gap-3 px-4 py-3 mb-4 rounded-lg text-sm
            <?php echo $regStatus === 'open'
                ? 'bg-emerald-50 border border-emerald-200'
                : 'bg-amber-50 border border-amber-200'; ?>">
    <i class="fas fa-clock <?php echo $regStatus === 'open' ? 'text-emerald-500' : 'text-amber-500'; ?>"></i>
    <span class="<?php echo $regStatus === 'open' ? 'text-emerald-700' : 'text-amber-700'; ?>">
        Einschreibung <?php echo $regStatus === 'open' ? 'endet' : 'startet'; ?>
        in <strong id="teacherCountdownValue" class="tabular-nums">…</strong>
    </span>
</div>
<?php endif; ?>

    <!-- Header -->
    <div class="bg-white rounded-xl p-6 border-l-4 border-green-600">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">
                    <i class="fas fa-chalkboard-teacher text-green-600 mr-3"></i>
                    Lehrer Dashboard
                </h2>
                <p class="text-gray-600">Übersicht über Schüleranmeldungen und Klassenpläne</p>
            </div>
            <div class="text-right">
                <div class="text-sm text-gray-600 mb-1">Angemeldet als</div>
                <div class="text-lg font-semibold text-gray-800">
                    <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm mb-1">Gesamt Schüler</p>
                    <p class="text-3xl font-bold"><?php echo $stats['total_students']; ?></p>
                </div>
                <i class="fas fa-user-graduate text-3xl opacity-80"></i>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm mb-1">Vollständig angemeldet</p>
                    <p class="text-3xl font-bold"><?php echo $stats['complete_students']; ?></p>
                    <p class="text-xs text-green-100 mt-1">
                        <?php echo $stats['total_students'] > 0 ? round(($stats['complete_students'] / $stats['total_students']) * 100) : 0; ?>% Abdeckung
                    </p>
                </div>
                <i class="fas fa-check-circle text-3xl opacity-80"></i>
            </div>
        </div>

        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-100 text-sm mb-1">Unvollständig</p>
                    <p class="text-3xl font-bold"><?php echo $stats['incomplete_students']; ?></p>
                    <p class="text-xs text-yellow-100 mt-1">Fehlen noch Slots</p>
                </div>
                <i class="fas fa-exclamation-triangle text-3xl opacity-80"></i>
            </div>
        </div>

        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm mb-1">Ohne Anmeldung</p>
                    <p class="text-3xl font-bold"><?php echo $stats['no_registrations']; ?></p>
                    <p class="text-xs text-red-100 mt-1">Benötigen Beratung</p>
                </div>
                <i class="fas fa-user-times text-3xl opacity-80"></i>
            </div>
        </div>
    </div>

    <!-- Class Overview -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="bg-gradient-to-r from-green-600 to-teal-600 text-white px-6 py-4">
            <h3 class="text-xl font-bold flex items-center">
                <i class="fas fa-users mr-3"></i>
                Klassenübersicht
            </h3>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($classes as $class): 
                    // Statistiken pro Klasse
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND class = ?");
                    $stmt->execute([$class]);
                    $classTotal = $stmt->fetch()['count'];
                    
                    // Vollständig angemeldet
                    $stmt = $db->prepare("
                        SELECT COUNT(DISTINCT user_id) as count
                        FROM (
                            SELECT r.user_id, COUNT(DISTINCT t.slot_number) as slot_count
                            FROM registrations r
                            JOIN timeslots t ON r.timeslot_id = t.id
                            JOIN users u ON r.user_id = u.id
                            WHERE t.slot_number " . getManagedSlotsSqlIn() . " AND u.role = 'student' AND u.class = ? AND r.edition_id = ?
                            GROUP BY r.user_id
                            HAVING slot_count = " . getManagedSlotCount() . "
                        ) as complete
                    ");
                    $stmt->execute([$class, $activeEditionId]);
                    $classComplete = $stmt->fetch()['count'];
                    
                    $percentage = $classTotal > 0 ? round(($classComplete / $classTotal) * 100) : 0;
                    $colorClasses = [
                        'green' => 80,
                        'yellow' => 50,
                        'red' => 0
                    ];
                    $colorClass = 'red';
                    foreach ($colorClasses as $color => $threshold) {
                        if ($percentage >= $threshold) {
                            $colorClass = $color;
                            break;
                        }
                    }
                ?>
                <div class="bg-gray-50 rounded-lg p-4 border-2 border-gray-200 hover:border-<?php echo $colorClass; ?>-400 transition">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-gray-800 text-lg"><?php echo htmlspecialchars($class); ?></h4>
                        <span class="px-3 py-1 bg-<?php echo $colorClass; ?>-100 text-<?php echo $colorClass; ?>-800 rounded-full text-xs font-semibold">
                            <?php echo $percentage; ?>%
                        </span>
                    </div>
                    <div class="text-sm text-gray-600 space-y-1">
                        <div class="flex justify-between">
                            <span>Gesamt:</span>
                            <span class="font-semibold"><?php echo $classTotal; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Vollständig:</span>
                            <span class="font-semibold text-green-600"><?php echo $classComplete; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Fehlend:</span>
                            <span class="font-semibold text-red-600"><?php echo $classTotal - $classComplete; ?></span>
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <a href="?page=teacher-class-list&class=<?php echo urlencode($class); ?>" 
                           class="flex-1 text-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-xs font-semibold">
                            <i class="fas fa-list mr-1"></i>Liste
                        </a>
                        <a href="?page=admin-print&type=class&class=<?php echo urlencode($class); ?>" 
                           target="_blank"
                           class="flex-1 text-center px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition text-xs font-semibold">
                            <i class="fas fa-print mr-1"></i>Drucken
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-yellow-50 border-l-4 border-yellow-500 p-6 rounded-lg">
        <div class="flex items-start">
            <i class="fas fa-lightbulb text-yellow-500 text-xl mr-3 mt-1"></i>
            <div>
                <h4 class="font-bold text-yellow-900 mb-2">Tipps für Lehrer</h4>
                <ul class="text-sm text-yellow-800 space-y-1">
                    <li><i class="fas fa-check mr-2"></i>Nutze die Klassenlisten, um den Überblick über Schüleranmeldungen zu behalten</li>
                    <li><i class="fas fa-check mr-2"></i>Über die Druckzentrale kannst du Laufzettel und Klassenpläne drucken</li>
                    <li><i class="fas fa-check mr-2"></i>Im Hörsaalplan siehst du die Zuordnung der Fachbereiche zu Hörsälen</li>
                    <li><i class="fas fa-check mr-2"></i>Sprich Schüler mit fehlenden Anmeldungen an</li>
                </ul>
                <button onclick="startGuidedTour()" class="mt-4 text-sm text-amber-600 hover:text-amber-700 font-medium transition">
                    <i class="fas fa-play-circle mr-1"></i>Tour starten
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Start der Tour mit rollenbasierten Schritten
function startGuidedTour() {
    const userRole = <?php echo json_encode($_SESSION['role'] ?? 'teacher'); ?>;
    
    if (typeof GuidedTour !== 'undefined') {
        // Generiere Schritte basierend auf der Rolle oder verwende vordefinierte Schritte
        const steps = typeof generateTourSteps !== 'undefined' 
            ? generateTourSteps(userRole)
            : (window.berufsmesseTourSteps || []);
        
        const tour = new GuidedTour({
            steps: steps,
            role: userRole,
            onSkip: () => {
                if (typeof showToast !== 'undefined') {
                    showToast('Tour übersprungen', 'info');
                }
            }
        });
        tour.reset(); // Neustart der Tour, falls sie bereits einmal gestartet wurde
        tour.start();
    } else {
        console.warn('GuidedTour nicht geladen');
    }
}

// Auto-start tour if start_tour parameter is present
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('start_tour') === '1') {
        // Entferne den Parameter aus der URL, damit die Tour nicht erneut startet, wenn die Seite neu geladen wird
        window.history.replaceState({}, '', window.location.pathname + '?page=teacher-dashboard');
        // Start nach einer kurzen Verzögerung, um sicherzustellen, dass alles geladen ist
        setTimeout(() => {
            startGuidedTour();
        }, 500);
    }
});
</script>

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
if (REG_STATUS === 'open')          startCountdown(REG_END,   'teacherCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'teacherCountdownValue');
</script>
