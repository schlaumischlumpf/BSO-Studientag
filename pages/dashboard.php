<?php
/**
 * Berufsmesse - Dashboard/Homepage
 * Moderne Homepage mit integrierten Einschreibungen und Kalender
 * Ersetzt die bisherige Sidebar-Navigation für diese Funktionen
 */

// Registrierungen des Benutzers laden
$stmt = $db->prepare("
    SELECT r.*, e.name as exhibitor_name, e.short_description, e.logo, e.room_id,
           rm.room_number,
           t.slot_number, t.slot_name, t.start_time, t.end_time,
           r.registration_type
    FROM registrations r 
    JOIN exhibitors e ON r.exhibitor_id = e.id 
    JOIN timeslots t ON r.timeslot_id = t.id 
    LEFT JOIN rooms rm ON e.room_id = rm.id
    WHERE r.user_id = ? AND r.edition_id = ? AND e.edition_id = ?
    ORDER BY t.slot_number ASC
");
$stmt->execute([$_SESSION['user_id'], $activeEditionId, $activeEditionId]);
$userRegistrations = $stmt->fetchAll();

// Registrierungsstatus
$regStatus = getRegistrationStatus();
$regStart = getSetting('registration_start');
$regEnd = getSetting('registration_end');
$singleSlotEnabled = getSetting('single_slot_assignment_enabled', '0') === '1';

// Statistiken: nur verwaltete Slots 1, 3, 5 zählen (Slots 2/4 sind Freislots – kein Maximum)
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM registrations r
    JOIN timeslots t ON r.timeslot_id = t.id
    WHERE r.user_id = ? AND r.edition_id = ? AND t.slot_number " . getManagedSlotsSqlIn() . "
");
$stmt->execute([$_SESSION['user_id'], $activeEditionId]);
$totalRegistrations = $stmt->fetch()['count'];
$maxRegistrations = intval(getSetting('max_registrations_per_student', 3));

// Aussteller mit freien Plätzen
$stmt = $db->prepare("SELECT COUNT(*) as count FROM exhibitors WHERE active = 1 AND edition_id = ?");
$stmt->execute([$activeEditionId]);
$totalExhibitors = $stmt->fetch()['count'];

// Nächster Termin ermitteln
$nextEvent = null;
$now = new DateTime();
foreach ($userRegistrations as $reg) {
    $startTime = new DateTime($reg['start_time']);
    if ($startTime > $now) {
        $nextEvent = $reg;
        break;
    }
}

// Timeline für heute – dynamisch aus Datenbank laden
$stmtSlots = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmtSlots->execute([$activeEditionId]);
$dbSlots = $stmtSlots->fetchAll();
$timeline = [];
foreach ($dbSlots as $slot) {
    $isBreak = !empty($slot['is_break']);
    $timeline[] = [
        'time' => substr($slot['start_time'] ?? '00:00', 0, 5),
        'end'  => substr($slot['end_time'] ?? '00:00', 0, 5),
        'slot_number' => $slot['slot_number'],
        'slot_name' => $slot['slot_name'],
        'type' => $isBreak ? 'break' : ($slot['is_managed'] ? 'assigned' : 'free'),
    ];
}

$officialDayPlan = [
    [
        'time' => '09:30',
        'title' => 'Vortrag: Studieren an der Freien Universitaet Berlin',
        'details' => [
            'Studienangebot, Studienalltag, Bewerbung, NC',
        ],
        'place' => "Henry-Ford-Bau, Hoersaal A\nGarystr. 35, 14195 Berlin",
    ],
    [
        'time' => 'ca. 10:30',
        'title' => 'Vorstellung ausgewaehlter Fachbereiche (je 5-10 Minuten)',
        'details' => [
            'Biologie, Chemie, Pharmazie',
            'Erziehungswissenschaft, Psychologie und Lehramt',
            'Politik- und Sozialwissenschaften',
            'Rechtswissenschaften (max. 20 Personen)',
            'Wirtschaftswissenschaften',
        ],
        'place' => "Henry-Ford-Bau, Hoersaal A\nGarystr. 35, 14195 Berlin",
    ],
    [
        'time' => 'ca. 11:15 bis ca. 12:00/12:30',
        'title' => 'Aufteilung auf die Fachbereiche',
        'details' => [
            'Campus-/Fachbereichsfuehrung',
            'ggf. Mini-Aufgaben / Schnitzeljagd',
            'ggf. Q&A-Runde in den Raeumlichkeiten des Fachbereichs',
        ],
        'place' => 'Direkt in den jeweiligen Fachbereichen',
    ],
];

// Registrierungen nach Slot
$regBySlot = [];
foreach ($userRegistrations as $reg) {
    $regBySlot[$reg['slot_number']] = $reg;
}
?>

<script>
const REG_START  = "<?php echo htmlspecialchars(getSetting('registration_start', '')); ?>";
const REG_END    = "<?php echo htmlspecialchars(getSetting('registration_end', '')); ?>";
const REG_STATUS = "<?php echo getRegistrationStatus(); ?>";
</script>

<div class="dashboard-container max-w-7xl mx-auto">
    <!-- Welcome Section -->
    <div class="welcome-section mb-8 animate-on-scroll">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">
                    Willkommen, <?php echo htmlspecialchars($_SESSION['firstname']); ?>! 👋
                </h1>
                <p class="text-gray-500">
                    Hier ist deine Übersicht für den BSO-Tag an der FU.
                </p>
            </div>
            
            <!-- Registration Status Badge -->
            <?php if ($regStatus === 'open'): ?>
            <div class="inline-flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl border border-green-200">
                <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                <div>
                    <span class="text-sm font-semibold text-green-700">Einschreibung offen</span>
                    <p class="text-xs text-green-600">
                        noch <span id="dashCountdownValue" class="font-semibold tabular-nums">…</span>
                        · bis <?php echo formatDateTime($regEnd); ?>
                    </p>
                </div>
            </div>
            <?php elseif ($regStatus === 'upcoming'): ?>
            <div class="inline-flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-amber-50 to-yellow-50 rounded-xl border border-amber-200">
                <div class="w-3 h-3 bg-amber-500 rounded-full"></div>
                <div>
                    <span class="text-sm font-semibold text-amber-700">Startet bald</span>
                    <p class="text-xs text-amber-600">
                        noch <span id="dashCountdownValue" class="font-semibold tabular-nums">…</span>
                        · ab <?php echo formatDateTime($regStart); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="quick-actions-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <!-- Meine Termine -->
        <a href="?page=schedule" class="quick-action-card group card card-pastel-sky p-5 ripple animate-on-scroll stagger-1">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-calendar-alt text-blue-600 text-xl"></i>
                </div>
                <span class="badge badge-sky">
                    <i class="fas fa-clock mr-1"></i><?php echo count($userRegistrations); ?> Termine
                </span>
            </div>
            <h3 class="font-semibold text-gray-800 mb-1">Mein Zeitplan</h3>
            <p class="text-sm text-gray-500">Alle Termine auf einen Blick</p>
        </a>

        <!-- Einschreibungen -->
        <a href="?page=registration" class="quick-action-card group card card-pastel-mint p-5 ripple animate-on-scroll stagger-2">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-clipboard-list text-emerald-600 text-xl"></i>
                </div>
                <span class="badge badge-mint">
                    <?php echo $totalRegistrations; ?>/<?php echo $maxRegistrations; ?> Slots
                </span>
            </div>
            <h3 class="font-semibold text-gray-800 mb-1">Einschreibung</h3>
            <p class="text-sm text-gray-500">Für Fachbereiche anmelden</p>
        </a>

        <!-- Fachbereiche -->
        <a href="?page=exhibitors" class="quick-action-card group card card-pastel-lavender p-5 ripple animate-on-scroll stagger-3">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-university text-purple-600 text-xl"></i>
                </div>
                <span class="badge badge-lavender">
                    <?php echo $totalExhibitors; ?> Fachbereiche
                </span>
            </div>
            <h3 class="font-semibold text-gray-800 mb-1">Fachbereiche</h3>
            <p class="text-sm text-gray-500">Alle Fachbereiche entdecken</p>
        </a>

        <!-- Meine Anmeldungen -->
        <a href="?page=my-registrations" class="quick-action-card group card card-pastel-peach p-5 ripple animate-on-scroll stagger-4">
            <div class="flex items-start justify-between mb-4">
                <div class="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-check-circle text-orange-600 text-xl"></i>
                </div>
                <span class="badge badge-peach">
                    Verwalten
                </span>
            </div>
            <h3 class="font-semibold text-gray-800 mb-1">Meine Slots</h3>
            <p class="text-sm text-gray-500">Anmeldungen bearbeiten</p>
        </a>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Schedule & Timeline -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Today's Schedule -->
            <div class="upcoming-schedule card p-6 animate-on-scroll stagger-5">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800">Dein Tagesplan</h2>
                        <p class="text-sm text-gray-500">BSO-Tag an der FU – <?php echo formatDate(date('Y-m-d')); ?></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="api/generate-personal-pdf.php" class="btn btn-primary btn-sm" title="Persönlichen Zeitplan als PDF herunterladen">
                            <i class="fas fa-file-pdf mr-1"></i>PDF herunterladen
                        </a>
                        <a href="?page=schedule" class="btn btn-secondary btn-sm">
                            <i class="fas fa-expand-alt mr-1"></i>Vollansicht
                        </a>
                    </div>
                </div>

                <div class="mb-6 rounded-xl border border-amber-200 bg-gradient-to-br from-amber-50 to-orange-50 overflow-hidden">
                    <div class="px-4 py-3 border-b border-amber-200">
                        <h3 class="font-semibold text-amber-800 flex items-center gap-2">
                            <i class="fas fa-list-check"></i>
                            Offizieller Ablauf (Uhrzeit / Programmpunkt / Ort)
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-white/70">
                                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wide">Uhrzeit</th>
                                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wide">Programmpunkt</th>
                                    <th class="text-left px-4 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wide">Ort(e)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officialDayPlan as $row): ?>
                                <tr class="border-t border-amber-100 align-top">
                                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($row['time']); ?></td>
                                    <td class="px-4 py-3 text-gray-700">
                                        <p class="font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($row['title']); ?></p>
                                        <?php if (!empty($row['details'])): ?>
                                        <ul class="space-y-0.5 text-xs text-gray-600 list-disc list-inside">
                                            <?php foreach ($row['details'] as $detail): ?>
                                            <li><?php echo htmlspecialchars($detail); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($row['place']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mini Timeline -->
                <div class="space-y-3">
                    <?php foreach ($timeline as $slot): 
                        $hasReg = isset($regBySlot[$slot['slot_number']]);
                        $reg = $hasReg ? $regBySlot[$slot['slot_number']] : null;
                        
                        // Break/Pause-Handling
                        if ($slot['type'] === 'break'):
                    ?>
                    <div class="timeline-item flex items-center gap-4 p-3 rounded-xl border bg-amber-50 border-amber-200">
                        <div class="text-center min-w-[60px]">
                            <span class="text-sm font-bold text-amber-700"><?php echo $slot['time']; ?></span>
                            <span class="block text-xs text-amber-400"><?php echo $slot['end']; ?></span>
                        </div>
                        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-coffee text-amber-600"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-medium text-amber-700"><?php echo htmlspecialchars($slot['slot_name'] ?? 'Pause'); ?></h4>
                            <p class="text-xs text-amber-500">Pause</p>
                        </div>
                    </div>
                    <?php else:
                        // Status-Farben
                        if ($slot['type'] === 'free') {
                            $bgClass = 'bg-purple-50 border-purple-200';
                            $iconBg = 'bg-purple-100';
                            $iconColor = 'text-purple-600';
                        } elseif ($hasReg) {
                            $bgClass = 'bg-emerald-50 border-emerald-200';
                            $iconBg = 'bg-emerald-100';
                            $iconColor = 'text-emerald-600';
                        } else {
                            $bgClass = 'bg-gray-50 border-gray-200';
                            $iconBg = 'bg-gray-100';
                            $iconColor = 'text-gray-400';
                        }
                    ?>
                    <div class="timeline-item flex items-center gap-4 p-4 rounded-xl border <?php echo $bgClass; ?> hover:shadow-md transition-all duration-300 group">
                        <!-- Time -->
                        <div class="text-center min-w-[60px]">
                            <span class="text-sm font-bold text-gray-800"><?php echo $slot['time']; ?></span>
                            <span class="block text-xs text-gray-400"><?php echo $slot['end']; ?></span>
                        </div>
                        
                        <!-- Icon -->
                        <div class="w-10 h-10 rounded-lg <?php echo $iconBg; ?> flex items-center justify-center flex-shrink-0 group-hover:scale-110 transition-transform">
                            <?php if ($slot['type'] === 'free'): ?>
                                <i class="fas fa-hand-pointer <?php echo $iconColor; ?>"></i>
                            <?php elseif ($hasReg): ?>
                                <i class="fas fa-check <?php echo $iconColor; ?>"></i>
                            <?php else: ?>
                                <i class="fas fa-hourglass-half <?php echo $iconColor; ?>"></i>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <?php if ($hasReg): ?>
                                <h4 class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></h4>
                                <p class="text-xs text-gray-500 truncate">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <?php echo htmlspecialchars($reg['room_number'] ?? 'Hörsaal folgt'); ?>
                                </p>
                            <?php elseif ($slot['type'] === 'free'): ?>
                                <h4 class="font-medium text-purple-700">Freie Wahl</h4>
                                <p class="text-xs text-purple-500">Besuche einen Fachbereich deiner Wahl</p>
                            <?php else: ?>
                                <h4 class="font-medium text-gray-500">Noch nicht zugewiesen</h4>
                                <p class="text-xs text-gray-400">Zuweisung erfolgt automatisch</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Slot Badge -->
                        <div class="text-right">
                            <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium <?php echo $slot['type'] === 'free' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'; ?>">
                                Slot <?php echo $slot['slot_number']; ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Info Cards -->
        <div class="space-y-6">
            <!-- Next Event Card -->
            <?php if ($nextEvent): ?>
            <div class="card p-6 bg-gradient-to-br from-emerald-50 to-white border-emerald-200 animate-on-scroll stagger-5">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                        <i class="fas fa-bell text-emerald-600"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800">Nächster Termin</h3>
                        <p class="text-xs text-gray-500"><?php echo $nextEvent['slot_name']; ?></p>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-4 border border-emerald-100">
                    <h4 class="font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars(html_entity_decode($nextEvent['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></h4>
                    <div class="space-y-2 text-sm text-gray-600">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-clock w-4 text-emerald-500"></i>
                            <span><?php echo date('H:i', strtotime($nextEvent['start_time'])); ?> - <?php echo date('H:i', strtotime($nextEvent['end_time'])); ?> Uhr</span>
                        </div>
                        <?php if ($nextEvent['room_number']): ?>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-map-marker-alt w-4 text-emerald-500"></i>
                            <span><?php echo htmlspecialchars($nextEvent['room_number']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Einschreibungsfortschritt -->
            <div class="card p-6 animate-on-scroll stagger-6">
                <h3 class="font-bold text-gray-800 mb-4">Einschreibungsfortschritt</h3>
                
                <div class="space-y-4">
                    <!-- Progress Bar -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-gray-600">Fortschritt</span>
                            <span class="text-sm font-bold text-gray-800">
                                <?php echo $totalRegistrations; ?> / <?php echo $maxRegistrations; ?>
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                            <?php 
                            $progressPercent = $maxRegistrations > 0 ? min(100, ($totalRegistrations / $maxRegistrations) * 100) : 0;
                            $progressColor = $progressPercent >= 100 ? 'bg-green-500' : ($progressPercent >= 50 ? 'bg-blue-500' : 'bg-amber-500');
                            ?>
                            <div class="<?php echo $progressColor; ?> h-full transition-all duration-500 rounded-full" 
                                 style="width: <?php echo $progressPercent; ?>%"></div>
                        </div>
                        <?php if ($totalRegistrations >= $maxRegistrations): ?>
                        <p class="text-xs text-green-600 mt-1">
                            <i class="fas fa-check-circle mr-1"></i>Alle Plätze belegt
                        </p>
                        <?php else: ?>
                        <p class="text-xs text-gray-500 mt-1">
                            Noch <?php echo max(0, $maxRegistrations - $totalRegistrations); ?> freie Plätze
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Help Card -->
            <div class="card p-6 bg-gradient-to-br from-amber-50 to-white border-amber-200 animate-on-scroll stagger-7">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-lightbulb text-amber-600"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800 mb-1">Tipp</h3>
                        <p class="text-sm text-gray-600 mb-3">
                            <?php if ($singleSlotEnabled): ?>
                                Du bekommst im Ein-Slot-Modus genau einen Fachbereich fest zugeteilt. Bitte beachte den offiziellen Ablauf oben.
                            <?php else: ?>
                                Bei Slot 2 und 4 kannst du frei wählen, welchen Fachbereich du besuchst!
                            <?php endif; ?>
                        </p>
                        <button onclick="startGuidedTour()" class="text-sm text-amber-600 hover:text-amber-700 font-medium transition">
                            <i class="fas fa-play-circle mr-1"></i>Tour starten
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Start guided tour
function startGuidedTour() {
    // Navigate to dashboard first if not already there
    const currentPage = new URLSearchParams(window.location.search).get('page');
    const userRole = '<?php echo $_SESSION["role"] ?? "student"; ?>';
    
    // Determine the correct dashboard page
    let dashboardPage = 'dashboard';
    // Admin kann Tour sowohl auf dashboard als auch auf admin-dashboard starten
    if (userRole === 'admin' && currentPage !== 'dashboard') {
        dashboardPage = 'admin-dashboard';
    } else if (userRole === 'teacher') {
        dashboardPage = 'teacher-dashboard';
    }
    
    // If not on dashboard, navigate there first
    if (currentPage !== dashboardPage && currentPage !== 'dashboard') {
        window.location.href = '?page=' + dashboardPage + '&start_tour=1';
        return;
    }
    
    if (typeof GuidedTour !== 'undefined') {
        // Generate role-based steps
        const steps = typeof generateTourSteps !== 'undefined' 
            ? generateTourSteps(userRole)
            : (window.berufsmesseTourSteps || []);
        
        const tour = new GuidedTour({
            steps: steps,
            onComplete: () => {
                if (typeof showToast !== 'undefined') {
                    showToast('Tour abgeschlossen! 🎉', 'success');
                }
            },
            onSkip: () => {
                if (typeof showToast !== 'undefined') {
                    showToast('Tour übersprungen', 'info');
                }
            }
        });
        tour.reset(); // Allow restart
        tour.start();
    } else {
        console.warn('GuidedTour nicht geladen');
    }
}

// Check if first visit - show welcome dialog
document.addEventListener('DOMContentLoaded', () => {
    const hasVisited = localStorage.getItem('berufsmesse_visited');
    if (!hasVisited && typeof GuidedTour !== 'undefined') {
        // Show welcome toast after short delay
        setTimeout(() => {
            if (typeof showToast !== 'undefined') {
                showToast('Willkommen! Nutze "Hilfe & Tour" in der Seitenleiste für eine Einführung.', 'info', 6000);
            }
            localStorage.setItem('berufsmesse_visited', 'true');
        }, 1500);
    }
    
    // Auto-start tour if start_tour parameter is present
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('start_tour') === '1') {
        // Remove parameter from URL
        window.history.replaceState({}, '', window.location.pathname + '?page=dashboard');
        // Start tour after a short delay
        setTimeout(() => {
            startGuidedTour();
        }, 500);
    }
});
</script>

<style>
/* Dashboard specific styles */
.quick-action-card {
    cursor: pointer;
    text-decoration: none;
}

.quick-action-card:hover {
    transform: translateY(-4px);
}

.timeline-item {
    cursor: default;
}

/* Counter animation */
.counter-animate {
    display: inline-block;
    min-width: 1.5em;
    text-align: right;
}
</style>

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
if (REG_STATUS === 'open')          startCountdown(REG_END,   'dashCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'dashCountdownValue');
</script>
