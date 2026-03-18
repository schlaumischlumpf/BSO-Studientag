<?php
/**
 * Admin QR-Code Verwaltung (Issue #15)
 * Generiert und zeigt QR-Codes für Anwesenheitsprüfung
 */

// Debugging: Sicherstellen dass die Seite lädt
if (!isset($db)) {
    die('Datenbank nicht verfügbar');
}

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('qr_codes_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Aussteller mit Räumen laden - gefiltert nach Orga-Zuordnung
try {
    // Admins und Benutzer mit qr_codes_verwalten sehen alle Aussteller
    if (isAdmin() || hasPermission('qr_codes_verwalten')) {
        $stmt = $db->prepare("
            SELECT e.*, r.room_number
            FROM exhibitors e
            LEFT JOIN rooms r ON e.room_id = r.id
            WHERE e.active = 1 AND e.edition_id = ?
            ORDER BY e.name
        ");
        $stmt->execute([$activeEditionId]);
        $exhibitors = $stmt->fetchAll();
    } else {
        // Exhibitor-spezifische Orga-Mitglieder sehen nur ihre zugewiesenen Aussteller
        $stmt = $db->prepare("
            SELECT e.*, r.room_number
            FROM exhibitors e
            LEFT JOIN rooms r ON e.room_id = r.id
            INNER JOIN exhibitor_orga_team eot ON e.id = eot.exhibitor_id
            WHERE e.active = 1 AND eot.user_id = ? AND e.edition_id = ? AND eot.edition_id = ?
            ORDER BY e.name
        ");
        $stmt->execute([$_SESSION['user_id'], $activeEditionId, $activeEditionId]);
        $exhibitors = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $exhibitors = [];
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Exhibitors Query Error: ' . $e->getMessage());
}

// Alle Timeslots laden (inkl. Slots 2 und 4 für freie Wahl)
try {
    $stmt = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? AND is_break = 0 ORDER BY start_time ASC, slot_number ASC");
    $stmt->execute([$activeEditionId]);
    $timeslots = $stmt->fetchAll();
} catch (Exception $e) {
    $timeslots = [];
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Timeslots Query Error: ' . $e->getMessage());
}

// Bestehende Tokens laden
try {
    // Zuerst prüfen: Existiert die Tabelle überhaupt?
    $tableCheck = $db->query("SHOW TABLES LIKE 'qr_tokens'")->fetch();
    if (!$tableCheck) {
        throw new Exception("Tabelle 'qr_tokens' existiert nicht. Bitte führen Sie das Setup erneut aus.");
    }
    
    // Zuerst prüfen: Sind überhaupt Tokens in der DB?
    $stmtCheck = $db->prepare("SELECT COUNT(*) as total, MIN(expires_at) as earliest, MAX(expires_at) as latest FROM qr_tokens WHERE edition_id = ?");
    $stmtCheck->execute([$activeEditionId]);
    $tokenCheck = $stmtCheck->fetch();
    $tokenCheck['current_time'] = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("
        SELECT qt.*, e.name as exhibitor_name, t.slot_name, t.slot_number
        FROM qr_tokens qt
        JOIN exhibitors e ON qt.exhibitor_id = e.id
        JOIN timeslots t ON qt.timeslot_id = t.id
        WHERE qt.expires_at > NOW() AND qt.edition_id = ?
        ORDER BY e.name, t.slot_number
    ");
    $stmt->execute([$activeEditionId]);
    $existingTokens = $stmt->fetchAll();
} catch (Exception $e) {
    $existingTokens = [];
    $tokenCheck = ['total' => 0, 'earliest' => null, 'latest' => null, 'current_time' => date('Y-m-d H:i:s')];
    $dbError = $e->getMessage();
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Tokens Query Error: ' . $e->getMessage());
}

// Tokens nach Aussteller+Slot gruppieren
$tokenMap = [];
foreach ($existingTokens as $token) {
    $key = $token['exhibitor_id'] . '_' . $token['timeslot_id'];
    $tokenMap[$key] = $token;
}

// Such-Filter für Aussteller
$searchQuery = $_GET['search'] ?? '';
$filteredExhibitors = $exhibitors;
if (!empty($searchQuery)) {
    $filteredExhibitors = array_filter($exhibitors, function($exhibitor) use ($searchQuery) {
        return stripos($exhibitor['name'], $searchQuery) !== false ||
               stripos($exhibitor['room_number'], $searchQuery) !== false ||
               stripos($exhibitor['building'], $searchQuery) !== false;
    });
}

// Anwesenheitsstatistik laden
try {
    $stmt = $db->prepare("
        SELECT a.exhibitor_id, a.timeslot_id, COUNT(*) as present_count
        FROM attendance a
        WHERE a.edition_id = ?
        GROUP BY a.exhibitor_id, a.timeslot_id
    ");
    $stmt->execute([$activeEditionId]);
    $attendanceStats = [];
    foreach ($stmt->fetchAll() as $row) {
        $attendanceStats[$row['exhibitor_id'] . '_' . $row['timeslot_id']] = $row['present_count'];
    }
} catch (Exception $e) {
    $attendanceStats = [];
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Attendance Stats Error: ' . $e->getMessage());
}

// Registrierungen + Anwesenheit laden (zeigt alle Angemeldeten mit Scan-Status)
try {
    $stmt = $db->prepare("
        SELECT r.exhibitor_id, r.timeslot_id, u.firstname, u.lastname, u.class, u.id as user_id,
               a.checked_in_at
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN attendance a ON a.user_id = r.user_id AND a.exhibitor_id = r.exhibitor_id AND a.timeslot_id = r.timeslot_id
        WHERE r.timeslot_id IS NOT NULL AND r.edition_id = ?
        ORDER BY r.exhibitor_id, r.timeslot_id, u.lastname, u.firstname
    ");
    $stmt->execute([$activeEditionId]);
    $attendanceDetails = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['exhibitor_id'] . '_' . $row['timeslot_id'];
        if (!isset($attendanceDetails[$key])) {
            $attendanceDetails[$key] = [];
        }
        $attendanceDetails[$key][] = $row;
    }
} catch (Exception $e) {
    $attendanceDetails = [];
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Attendance Details Error: ' . $e->getMessage());
}

// Gesamt-Statistik
try {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as total FROM attendance WHERE edition_id = ?");
    $stmt->execute([$activeEditionId]);
    $totalAttendees = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM attendance WHERE edition_id = ?");
    $stmt->execute([$activeEditionId]);
    $totalCheckins = $stmt->fetchColumn();
} catch (Exception $e) {
    $totalAttendees = 0;
    $totalCheckins = 0;
    logErrorToAudit($e, 'QR-Codes');
    error_log('QR-Codes Page - Total Stats Error: ' . $e->getMessage());
}

// QR-Code Base URL aus Einstellungen laden
$qrCodeBaseUrl = getSetting('qr_code_url', 'https://localhost' . BASE_URL);

// POST-Handling wurde nach index.php verschoben (vor HTML-Output)
?>

<div class="space-y-6">
    <!-- Datenbank-Fehler anzeigen -->
    <?php if (isset($dbError)): ?>
    <div class="bg-red-50 border border-red-200 p-4 rounded-xl">
        <div class="flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-red-500"></i>
            <div>
                <p class="text-red-800 font-medium text-sm">Datenbankfehler</p>
                <p class="text-red-700 text-xs mt-1 font-mono"><?php echo htmlspecialchars($dbError); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($tokenMap)): ?>
    <div class="bg-blue-50 border border-blue-200 p-4 rounded-xl">
        <div class="flex items-center gap-2">
            <i class="fas fa-info-circle text-blue-500"></i>
            <div>
                <p class="text-blue-800 font-medium text-sm">Keine gültigen QR-Codes gefunden</p>
                <p class="text-blue-700 text-xs mt-1">
                    Es sind aktuell keine QR-Codes in der Datenbank vorhanden.
                    <br>Klicken Sie auf <strong>"Alle QR-Codes generieren"</strong> um neue QR-Codes zu erstellen.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">
                <i class="fas fa-qrcode text-emerald-500 mr-2"></i>
                QR-Code Anwesenheit
            </h2>
            <p class="text-sm text-gray-500 mt-1">QR-Codes für die Anwesenheitsprüfung generieren und verwalten</p>
        </div>
        <?php if (isAdmin() || hasPermission('qr_codes_erstellen')): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <button type="submit" name="generate_all" value="1"
                    class="px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium flex items-center gap-2"
                    onclick="return confirm('Alle QR-Codes neu generieren? Bestehende werden ersetzt.')">
                <i class="fas fa-sync-alt"></i>
                Alle QR-Codes generieren
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['generated'])): ?>
    <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-xl">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
            <p class="text-emerald-700 text-sm"><?php echo intval($_GET['generated']); ?> QR-Code(s) erfolgreich generiert.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="bg-red-50 border border-red-200 p-4 rounded-xl">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
            <div>
                <p class="text-red-700 font-medium text-sm">Fehler beim Generieren der QR-Codes</p>
                <p class="text-red-600 text-xs mt-1"><?php echo htmlspecialchars($_GET['error']); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Anwesenheits-Übersicht (Issue #27) -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">
                <i class="fas fa-user-check text-emerald-500 mr-2"></i>
                Anwesenheitsübersicht
            </h3>
            <div class="flex gap-4 text-sm">
                <span class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full font-medium">
                    <?php echo $totalAttendees; ?> Schüler eingecheckt
                </span>
                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full font-medium">
                    <?php echo $totalCheckins; ?> Check-ins gesamt
                </span>
            </div>
        </div>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4">
        <h4 class="font-semibold text-blue-900 text-sm flex items-center mb-2">
            <i class="fas fa-info-circle mr-2"></i>Ablauf
        </h4>
        <ol class="list-decimal list-inside space-y-1 text-xs text-blue-800">
            <li>QR-Codes für alle Fachbereiche/Slots generieren</li>
            <li>QR-Codes ausdrucken und den Fachbereichen aushändigen</li>
            <li>Schüler scannen den QR-Code am Stand mit ihrem Handy</li>
            <li>Die Webapp gleicht den eingeloggten Benutzer mit der Zuteilung ab</li>
            <li>Anwesenheit wird automatisch erfasst</li>
        </ol>
    </div>

    <!-- Suchfeld für Aussteller -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <form method="GET" class="flex items-center gap-3">
            <input type="hidden" name="page" value="admin-qr-codes">
            <div class="flex-1 relative">
                <input type="text" 
                       name="search" 
                       value="<?php echo htmlspecialchars($searchQuery); ?>" 
                       placeholder="Fachbereich suchen (Name, Hörsaal, Gebäude)..." 
                       class="w-full px-4 py-2.5 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            </div>
            <button type="submit" class="px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm">
                <i class="fas fa-search mr-2"></i>Suchen
            </button>
            <?php if (!empty($searchQuery)): ?>
            <a href="?page=admin-qr-codes" class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium text-sm">
                <i class="fas fa-times mr-2"></i>Zurücksetzen
            </a>
            <?php endif; ?>
        </form>
        <?php if (!empty($searchQuery)): ?>
        <p class="text-xs text-gray-500 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            <?php echo count($filteredExhibitors); ?> von <?php echo count($exhibitors); ?> Fachbereichen gefunden
        </p>
        <?php endif; ?>
    </div>

    <!-- QR-Code Übersicht nach Fachbereich -->
    <?php foreach ($filteredExhibitors as $exhibitor): ?>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($exhibitor['name']); ?></h3>
                <?php if ($exhibitor['room_number']): ?>
                <p class="text-xs text-gray-500">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <?php echo htmlspecialchars($exhibitor['room_number']); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach ($timeslots as $timeslot): 
                $key = $exhibitor['id'] . '_' . $timeslot['id'];
                $token = $tokenMap[$key] ?? null;
                $presentCount = $attendanceStats[$key] ?? 0;
                
                // Registrierungen für diesen Slot zählen
                $stmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND timeslot_id = ? AND registrations.edition_id = ?");
                $stmt->execute([$exhibitor['id'], $timeslot['id'], $activeEditionId]);
                $regCount = $stmt->fetchColumn();
            ?>
            <div class="border border-gray-200 rounded-lg p-4 <?php echo $token ? 'bg-gray-50' : 'bg-yellow-50 border-yellow-200'; ?>">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($timeslot['slot_name']); ?></span>
                    <?php $isFreeSlot = in_array($timeslot['slot_number'], [2, 4]); ?>
                    <button type="button" onclick='showAttendanceList(<?php echo json_encode($exhibitor["name"]); ?>, <?php echo json_encode($timeslot["slot_name"]); ?>, <?php echo json_encode($attendanceDetails[$key] ?? []); ?>)'
                            class="text-xs px-2 py-1 rounded-full cursor-pointer hover:opacity-80 transition <?php echo $presentCount > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'; ?>"
                            title="Klicken für Anwesenheitsliste">
                        <i class="fas fa-users mr-1"></i><?php if ($isFreeSlot): ?><?php echo $presentCount; ?> anwesend<?php else: ?><?php echo $presentCount; ?>/<?php echo $regCount; ?> anwesend<?php endif; ?>
                    </button>
                </div>
                
                <?php if ($token): ?>
                    <!-- QR-Code anzeigen -->
                    <div class="text-center mb-3">
                        <div id="qr-<?php echo $key; ?>" class="inline-block bg-white p-3 rounded-lg border border-gray-200">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($qrCodeBaseUrl . '?page=qr-checkin&token=' . $token['token']); ?>" 
                                 alt="QR-Code" class="w-36 h-36" loading="lazy">
                        </div>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-gray-400 mb-2">
                            Gültig bis: <?php echo date('d.m.Y H:i', strtotime($token['expires_at'])); ?>
                        </p>
                        <div class="flex gap-2 justify-center">
                            <button onclick="window.open('https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=<?php echo urlencode($qrCodeBaseUrl . '?page=qr-checkin&token=' . $token['token']); ?>', '_blank')" 
                                    class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-xs">
                                <i class="fas fa-print mr-1"></i>Gross
                            </button>
                            <button onclick="showToken('<?php echo htmlspecialchars($token['token'], ENT_QUOTES); ?>')" 
                                    class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition text-xs">
                                <i class="fas fa-key mr-1"></i>Token
                            </button>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="exhibitor_id" value="<?php echo $exhibitor['id']; ?>">
                                <input type="hidden" name="timeslot_id" value="<?php echo $timeslot['id']; ?>">
                                <button type="submit" name="generate_single" value="1"
                                        class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition text-xs">
                                    <i class="fas fa-redo mr-1"></i>Neu
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Kein QR-Code vorhanden -->
                    <div class="text-center py-4">
                        <i class="fas fa-qrcode text-3xl text-gray-300 mb-2"></i>
                        <p class="text-xs text-gray-500 mb-3">Kein QR-Code vorhanden</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <input type="hidden" name="exhibitor_id" value="<?php echo $exhibitor['id']; ?>">
                            <input type="hidden" name="timeslot_id" value="<?php echo $timeslot['id']; ?>">
                            <button type="submit" name="generate_single" value="1"
                                    class="px-3 py-1.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition text-xs">
                                <i class="fas fa-plus mr-1"></i>Generieren
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($exhibitors)): ?>
    <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
        <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">Keine aktiven Fachbereiche vorhanden.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Token Modal -->
<div id="tokenModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-800">
                <i class="fas fa-key text-blue-600 mr-2"></i>QR-Code Token
            </h3>
            <button onclick="closeTokenModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4">
            <p class="text-sm text-gray-600 mb-2">Token:</p>
            <div class="flex items-center gap-2">
                <code id="tokenValue" class="flex-1 text-2xl font-mono font-bold text-gray-800 tracking-wider"></code>
                <button onclick="copyTokenToClipboard()" class="px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>
        <p class="text-xs text-gray-500 text-center" id="copyStatus"></p>
    </div>
</div>

<script>
let currentToken = '';

function showToken(token) {
    currentToken = token;
    document.getElementById('tokenValue').textContent = token;
    document.getElementById('copyStatus').textContent = '';
    document.getElementById('tokenModal').classList.remove('hidden');
}

function closeTokenModal() {
    document.getElementById('tokenModal').classList.add('hidden');
}

function copyTokenToClipboard() {
    navigator.clipboard.writeText(currentToken).then(function() {
        document.getElementById('copyStatus').textContent = '✓ In Zwischenablage kopiert!';
        document.getElementById('copyStatus').className = 'text-xs text-green-600 text-center';
    }, function(err) {
        // Fallback für ältere Browser
        const textarea = document.createElement('textarea');
        textarea.value = currentToken;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        document.getElementById('copyStatus').textContent = '✓ In Zwischenablage kopiert!';
        document.getElementById('copyStatus').className = 'text-xs text-green-600 text-center';
    });
}

// Modal schließen bei Klick außerhalb
document.getElementById('tokenModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeTokenModal();
    }
});

// Modal schließen mit Escape-Taste
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTokenModal();
        closeAttendanceModal();
    }
});

// Anwesenheitsliste anzeigen (zeigt alle Angemeldeten mit Scan-Status)
function showAttendanceList(exhibitorName, slotName, attendees) {
    const modal = document.getElementById('attendanceModal');
    document.getElementById('attendanceTitle').textContent = exhibitorName + ' – ' + slotName;

    const tbody = document.getElementById('attendanceTableBody');
    tbody.innerHTML = '';

    if (attendees.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-500"><i class="fas fa-user-slash text-2xl mb-2"></i><br>Keine Anmeldungen</td></tr>';
    } else {
        const scannedCount = attendees.filter(a => a.checked_in_at).length;
        attendees.forEach(function(a, idx) {
            const scanned = !!a.checked_in_at;
            const checkinTime = scanned ? new Date(a.checked_in_at).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '';
            const row = document.createElement('tr');
            row.className = scanned ? 'hover:bg-gray-50 bg-emerald-50/30' : 'hover:bg-gray-50';
            row.innerHTML = '<td class="px-4 py-2 text-sm text-gray-600">' + (idx + 1) + '</td>' +
                '<td class="px-4 py-2 text-sm font-medium text-gray-800">' + escapeHtml(a.firstname) + ' ' + escapeHtml(a.lastname) + '</td>' +
                '<td class="px-4 py-2 text-sm text-gray-600">' + escapeHtml(a.class || '-') + '</td>' +
                '<td class="px-4 py-2 text-sm">' +
                    (scanned
                        ? '<span class="inline-flex items-center gap-1 text-emerald-600"><i class="fas fa-check-circle"></i> ' + checkinTime + '</span>'
                        : '<span class="inline-flex items-center gap-1 text-red-400"><i class="fas fa-times-circle"></i> Fehlt</span>') +
                '</td>';
            tbody.appendChild(row);
        });
        document.getElementById('attendanceCount').textContent = scannedCount + '/' + attendees.length + ' gescannt';
    }

    modal.classList.remove('hidden');
}

function closeAttendanceModal() {
    document.getElementById('attendanceModal').classList.add('hidden');
    // Vollbild zurücksetzen
    if (attendanceIsFullscreen) toggleAttendanceFullscreen();
}

let attendanceIsFullscreen = false;
function toggleAttendanceFullscreen() {
    const panel = document.getElementById('attendanceModalPanel');
    const icon  = document.getElementById('attendanceFullscreenIcon');
    attendanceIsFullscreen = !attendanceIsFullscreen;
    if (attendanceIsFullscreen) {
        panel.classList.remove('max-w-lg', 'max-h-[80vh]');
        panel.classList.add('max-w-5xl', 'max-h-[95vh]');
        icon.classList.replace('fa-expand', 'fa-compress');
    } else {
        panel.classList.remove('max-w-5xl', 'max-h-[95vh]');
        panel.classList.add('max-w-lg', 'max-h-[80vh]');
        icon.classList.replace('fa-compress', 'fa-expand');
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Modal schließen bei Klick außerhalb
document.getElementById('attendanceModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeAttendanceModal();
    }
});
</script>

<!-- Anwesenheits-Modal (Issue #27) -->
<div id="attendanceModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div id="attendanceModalPanel" class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[80vh] flex flex-col transition-all duration-300">
        <div class="flex items-center justify-between p-5 border-b border-gray-200">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-user-check text-emerald-500 mr-2"></i>Anwesenheitsliste
                </h3>
                <p id="attendanceTitle" class="text-sm text-gray-500 mt-1"></p>
            </div>
            <div class="flex items-center gap-2">
                <span id="attendanceCount" class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-semibold"></span>
                <button onclick="toggleAttendanceFullscreen()" title="Vollbild umschalten"
                        class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                    <i id="attendanceFullscreenIcon" class="fas fa-expand text-sm"></i>
                </button>
                <button onclick="closeAttendanceModal()" class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="overflow-y-auto flex-1">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Nr.</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Klasse</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody id="attendanceTableBody" class="divide-y divide-gray-100">
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200 bg-gray-50 rounded-b-xl">
            <button onclick="closeAttendanceModal()" class="w-full px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm font-medium">
                Schließen
            </button>
        </div>
    </div>
</div>
