<?php
/**
 * Admin – Manuelle Anwesenheitsverwaltung
 */

if (!isAdmin() && !hasPermission('qr_codes_sehen') && !hasPermission('qr_codes_erstellen') && !hasPermission('attendance_bearbeiten')) {
    echo '<div class="text-center py-12 text-red-500"><i class="fas fa-ban text-3xl mb-3"></i><p>Keine Berechtigung</p></div>';
    return;
}

$db = getDB();

// Alle Schüler mit ihren Einschreibungen (Slots 1, 3, 5) und Anwesenheitsstatus laden
$stmt = $db->prepare("
    SELECT
        u.id        AS user_id,
        u.firstname,
        u.lastname,
        u.class,
        r.id        AS reg_id,
        r.exhibitor_id,
        r.timeslot_id,
        e.name      AS exhibitor_name,
        t.slot_number,
        t.slot_name,
        t.start_time,
        t.end_time,
        IF(a.id IS NOT NULL, 1, 0) AS is_present
    FROM users u
    LEFT JOIN registrations r  ON r.user_id      = u.id
                               AND r.edition_id   = ?
    LEFT JOIN exhibitors e     ON e.id            = r.exhibitor_id
                               AND e.edition_id   = ?
    LEFT JOIN timeslots t      ON t.id            = r.timeslot_id
                               AND t.slot_number " . getManagedSlotsSqlIn() . "
    LEFT JOIN attendance a     ON a.user_id       = u.id
                               AND a.exhibitor_id = r.exhibitor_id
                               AND a.timeslot_id  = r.timeslot_id
                               AND a.edition_id   = ?
    WHERE u.role = 'student'
    ORDER BY u.class ASC, u.lastname ASC, u.firstname ASC, t.slot_number ASC
");
$stmt->execute([$activeEditionId, $activeEditionId, $activeEditionId]);

// Daten nach Schüler gruppieren
$studentsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$students = [];
foreach ($studentsRaw as $row) {
    $uid = $row['user_id'];
    if (!isset($students[$uid])) {
        $students[$uid] = [
            'id'        => $uid,
            'firstname' => $row['firstname'],
            'lastname'  => $row['lastname'],
            'class'     => $row['class'],
            'slots'     => [],
        ];
    }
    if ($row['reg_id'] && $row['timeslot_id']) {
        $students[$uid]['slots'][] = [
            'reg_id'        => $row['reg_id'],
            'exhibitor_id'  => $row['exhibitor_id'],
            'timeslot_id'   => $row['timeslot_id'],
            'exhibitor_name'=> $row['exhibitor_name'],
            'slot_number'   => $row['slot_number'],
            'slot_name'     => $row['slot_name'],
            'start_time'    => $row['start_time'],
            'end_time'      => $row['end_time'],
            'is_present'    => (bool) $row['is_present'],
        ];
    }
}

// Statistiken
$totalStudents   = count($students);
$stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE edition_id = ?");
$stmt->execute([$activeEditionId]);
$presentStudents = $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE edition_id = ?");
$stmt->execute([$activeEditionId]);
$totalCheckins   = $stmt->fetchColumn();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 flex-wrap">
        <div>
            <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-user-check text-emerald-500"></i>
                Manuelle Anwesenheitsverwaltung
            </h2>
            <p class="text-sm text-gray-500 mt-1">Anwesenheiten manuell bestätigen oder entfernen</p>
        </div>
    </div>

    <!-- Statistik-Karten -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-100 p-4 text-center">
            <p class="text-3xl font-bold text-gray-800"><?php echo $totalStudents; ?></p>
            <p class="text-xs text-gray-500 mt-1">Schüler gesamt</p>
        </div>
        <div class="bg-emerald-50 rounded-xl border border-emerald-100 p-4 text-center">
            <p class="text-3xl font-bold text-emerald-700"><?php echo $presentStudents; ?></p>
            <p class="text-xs text-emerald-600 mt-1">mind. 1× anwesend</p>
        </div>
        <div class="bg-blue-50 rounded-xl border border-blue-100 p-4 text-center col-span-2 md:col-span-1">
            <p class="text-3xl font-bold text-blue-700"><?php echo $totalCheckins; ?></p>
            <p class="text-xs text-blue-600 mt-1">Check-ins gesamt</p>
        </div>
    </div>

    <!-- Suche & Filter -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="relative flex-1">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="attendanceSearch" placeholder="Suche nach Name oder Klasse…"
                       class="w-full pl-9 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 text-sm"
                       oninput="filterStudents()">
            </div>
            <select id="classFilter" onchange="filterStudents()"
                    class="px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 text-sm">
                <option value="">Alle Klassen</option>
                <?php
                $classes = array_unique(array_column($students, 'class'));
                sort($classes);
                foreach ($classes as $cls):
                    if ($cls): ?>
                <option value="<?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($cls); ?></option>
                <?php endif; endforeach; ?>
            </select>
            <select id="statusFilter" onchange="filterStudents()"
                    class="px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 text-sm">
                <option value="">Alle Status</option>
                <option value="present">Anwesend</option>
                <option value="absent">Abwesend</option>
                <option value="partial">Teilweise</option>
            </select>
        </div>
        <p id="filterInfo" class="text-xs text-gray-400 mt-2"></p>
    </div>

    <!-- Schülerliste -->
    <div id="studentList" class="space-y-3">
        <?php if (empty($students)): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
            <i class="fas fa-users text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500">Keine Schüler gefunden.</p>
        </div>
        <?php else: ?>
        <?php foreach ($students as $student):
            $totalSlots   = count($student['slots']);
            $presentCount = count(array_filter($student['slots'], fn($s) => $s['is_present']));
            $statusClass  = 'absent';
            if ($presentCount > 0 && $presentCount < $totalSlots) $statusClass = 'partial';
            if ($totalSlots > 0 && $presentCount >= $totalSlots)  $statusClass = 'present';
        ?>
        <div class="student-card bg-white rounded-xl border border-gray-100 overflow-hidden"
             data-name="<?php echo htmlspecialchars(strtolower($student['firstname'] . ' ' . $student['lastname'])); ?>"
             data-class="<?php echo htmlspecialchars(strtolower($student['class'] ?? '')); ?>"
             data-status="<?php echo $statusClass; ?>">

            <!-- Student Header -->
            <div class="flex items-center justify-between px-5 py-3 bg-gray-50 border-b border-gray-100 cursor-pointer"
                 onclick="toggleStudentSlots(<?php echo $student['id']; ?>)">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <span class="text-xs font-bold text-blue-700">
                            <?php echo strtoupper(substr($student['firstname'], 0, 1) . substr($student['lastname'], 0, 1)); ?>
                        </span>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-800 text-sm">
                            <?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>
                        </span>
                        <?php if ($student['class']): ?>
                        <span class="ml-2 text-xs text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full">
                            <?php echo htmlspecialchars($student['class']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($totalSlots > 0): ?>
                    <span class="student-badge text-xs font-semibold px-2.5 py-1 rounded-full 
                        <?php
                            if ($statusClass === 'present') echo 'bg-emerald-100 text-emerald-700';
                            elseif ($statusClass === 'partial') echo 'bg-amber-100 text-amber-700';
                            else echo 'bg-red-50 text-red-600';
                        ?>">
                        <?php
                            echo $presentCount . '/' . $totalSlots . ($statusClass === 'present' ? ' ✓' : ''); 
                        ?>
                    </span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400 italic">Keine Einschreibungen</span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down text-gray-400 text-xs student-chevron-<?php echo $student['id']; ?> transition-transform duration-200"></i>
                </div>
            </div>

            <!-- Slots -->
            <div id="slots-<?php echo $student['id']; ?>" class="student-slots hidden">
                <?php if (empty($student['slots'])): ?>
                <div class="px-5 py-4 text-sm text-gray-400 italic">
                    Keine Anmeldungen für verwaltete Slots vorhanden.
                </div>
                <?php else: ?>
                <div class="divide-y divide-gray-50">
                    <?php foreach ($student['slots'] as $slot): ?>
                    <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition"
                         id="slot-row-<?php echo $student['id']; ?>-<?php echo $slot['timeslot_id']; ?>">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-8 h-8 rounded-lg <?php echo $slot['is_present'] ? 'bg-emerald-100' : 'bg-gray-100'; ?> flex items-center justify-center flex-shrink-0">
                                <span class="text-xs font-bold <?php echo $slot['is_present'] ? 'text-emerald-700' : 'text-gray-500'; ?>">
                                    S<?php echo $slot['slot_number']; ?>
                                </span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">
                                    <?php echo htmlspecialchars($slot['exhibitor_name']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($slot['slot_name'] ?? 'Slot ' . $slot['slot_number']); ?>
                                    <?php if ($slot['start_time'] && $slot['end_time']): ?>
                                    · <?php echo substr($slot['start_time'], 0, 5); ?>–<?php echo substr($slot['end_time'], 0, 5); ?> Uhr
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <?php if (isAdmin() || hasPermission('attendance_bearbeiten')): ?>
                            <button
                                onclick="markAttendance(<?php echo $student['id']; ?>, <?php echo $slot['exhibitor_id']; ?>, <?php echo $slot['timeslot_id']; ?>, 'present', this)"
                                class="attendance-btn-present px-3 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1 <?php echo $slot['is_present'] ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-emerald-100 hover:text-emerald-700'; ?>"
                                title="Als anwesend markieren">
                                <i class="fas fa-check"></i>
                                <span class="hidden sm:inline">Anwesend</span>
                            </button>
                            <button
                                onclick="markAttendance(<?php echo $student['id']; ?>, <?php echo $slot['exhibitor_id']; ?>, <?php echo $slot['timeslot_id']; ?>, 'absent', this)"
                                class="attendance-btn-absent px-3 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1 <?php echo !$slot['is_present'] ? 'bg-red-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-red-100 hover:text-red-700'; ?>"
                                title="Als abwesend markieren">
                                <i class="fas fa-times"></i>
                                <span class="hidden sm:inline">Abwesend</span>
                            </button>
                            <?php else: ?>
                            <span class="text-xs px-2 py-1 rounded-lg <?php echo $slot['is_present'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $slot['is_present'] ? '<i class="fas fa-check mr-1"></i>Anwesend' : '<i class="fas fa-times mr-1"></i>Abwesend'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="noResultsMsg" class="hidden bg-white rounded-xl border border-gray-100 p-10 text-center">
        <i class="fas fa-search text-3xl text-gray-300 mb-3"></i>
        <p class="text-gray-500 text-sm">Keine Schüler entsprechen dem Filter.</p>
    </div>
</div>

<script>
const BASE_API = '<?php echo BASE_URL; ?>api/manual-attendance.php';

// Schüler-Karten filtern
function filterStudents() {
    const searchTerm  = document.getElementById('attendanceSearch').value.toLowerCase().trim();
    const classFilter = document.getElementById('classFilter').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;

    const cards   = document.querySelectorAll('.student-card');
    let   visible = 0;

    cards.forEach(card => {
        const name    = card.dataset.name    || '';
        const cls     = card.dataset.class   || '';
        const status  = card.dataset.status  || '';

        const matchName   = !searchTerm  || name.includes(searchTerm)  || cls.includes(searchTerm);
        const matchClass  = !classFilter || cls === classFilter;
        const matchStatus = !statusFilter || status === statusFilter;

        if (matchName && matchClass && matchStatus) {
            card.style.display = '';
            visible++;
        } else {
            card.style.display = 'none';
        }
    });

    const info = document.getElementById('filterInfo');
    info.textContent = (searchTerm || classFilter || statusFilter)
        ? visible + ' von ' + cards.length + ' Schülern angezeigt'
        : '';

    document.getElementById('noResultsMsg').classList.toggle('hidden', visible > 0 || cards.length === 0);
}

// Slot-Bereich ein-/ausklappen
function toggleStudentSlots(userId) {
    const panel   = document.getElementById('slots-' + userId);
    const chevron = document.querySelector('.student-chevron-' + userId);
    const hidden  = panel.classList.contains('hidden');
    panel.classList.toggle('hidden', !hidden);
    if (chevron) chevron.style.transform = hidden ? 'rotate(180deg)' : '';
}

// Anwesenheit per API setzen
async function markAttendance(userId, exhibitorId, timeslotId, action, btn) {
    btn.disabled = true;
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin text-xs"></i>';

    try {
        const resp = await fetch(BASE_API, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo generateCsrfToken(); ?>'
            },
            body: JSON.stringify({
                action:       action === 'present' ? 'mark_present' : 'mark_absent',
                user_id:      userId,
                exhibitor_id: exhibitorId,
                timeslot_id:  timeslotId
            })
        });
        const data = await resp.json();

        if (data.success) {
            btn.innerHTML = origHtml;
            updateSlotRow(userId, timeslotId, action === 'present');
            updateStudentBadge(userId);
            showToast(data.message || 'Erfolgreich', 'green');
        } else {
            showToast(data.message || 'Fehler', 'red');
            btn.innerHTML = origHtml;
        }
    } catch(e) {
        showToast('Netzwerkfehler', 'red');
        btn.innerHTML = origHtml;
    } finally {
        btn.disabled = false;
    }
}

function updateSlotRow(userId, timeslotId, isPresent) {
    const row = document.getElementById('slot-row-' + userId + '-' + timeslotId);
    if (!row) return;

    const btnPresent = row.querySelector('.attendance-btn-present');
    const btnAbsent  = row.querySelector('.attendance-btn-absent');
    const icon       = row.querySelector('.w-8');

    if (btnPresent) {
        btnPresent.className = btnPresent.className.replace(
            /bg-\w+-\d+ text-\w+-\d+( shadow-sm)?/g, ''
        );
        btnPresent.className += isPresent
            ? ' bg-emerald-500 text-white shadow-sm'
            : ' bg-gray-100 text-gray-600 hover:bg-emerald-100 hover:text-emerald-700';
    }
    if (btnAbsent) {
        btnAbsent.className = btnAbsent.className.replace(
            /bg-\w+-\d+ text-\w+-\d+( shadow-sm)?/g, ''
        );
        btnAbsent.className += !isPresent
            ? ' bg-red-500 text-white shadow-sm'
            : ' bg-gray-100 text-gray-600 hover:bg-red-100 hover:text-red-700';
    }
    if (icon) {
        icon.className = icon.className.replace(/bg-\w+-\d+/, isPresent ? 'bg-emerald-100' : 'bg-gray-100');
        const span = icon.querySelector('span');
        if (span) span.className = span.className.replace(/text-\w+-\d+/, isPresent ? 'text-emerald-700' : 'text-gray-500');
    }
}

function updateStudentBadge(userId) {
    const slotsDiv = document.getElementById('slots-' + userId);
    if (!slotsDiv) return;

    const allRows     = slotsDiv.querySelectorAll('[id^="slot-row-' + userId + '-"]');
    const presentRows = slotsDiv.querySelectorAll('.attendance-btn-present.bg-emerald-500');
    const total       = allRows.length;
    const present     = presentRows.length;

    const card = slotsDiv.closest('.student-card');
    if (!card) return;

    const badge = card.querySelector('.student-badge');
    if (badge) {
        badge.textContent = present + '/' + total + (present >= total && total > 0 ? ' ✓' : '');
        
        // CSS-Klassen für Farbe aktualisieren
        badge.className = badge.className.replace(/bg-\w+-\d+/g, '');
        badge.className = badge.className.replace(/text-\w+-\d+/g, '');
        
        if (present >= total && total > 0) {
            badge.className += ' bg-emerald-100 text-emerald-700';
        } else if (present > 0) {
            badge.className += ' bg-amber-100 text-amber-700';
        } else {
            badge.className += ' bg-red-50 text-red-600';
        }
    }

    // Status aktualisieren für Filter
    let status = 'absent';
    if (present > 0 && present < total) status = 'partial';
    if (total > 0 && present >= total)  status = 'present';
    card.dataset.status = status;
}

function showToast(msg, color) {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-4 right-4 z-50 px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium bg-' + color + '-500';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>
