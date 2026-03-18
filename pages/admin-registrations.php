<?php
/**
 * Admin Einschreibungsverwaltung (Issue #12)
 * Admins können Schüler einschreiben und Einschreibungen zurücknehmen
 */

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('anmeldungen_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Alle Schüler laden
$stmt = $db->query("SELECT id, username, firstname, lastname, class FROM users WHERE role = 'student' ORDER BY lastname, firstname");
$students = $stmt->fetchAll();

// Alle aktiven Aussteller laden
$stmt = $db->prepare("SELECT id, name FROM exhibitors WHERE active = 1 AND exhibitors.edition_id = ? ORDER BY name");
$stmt->execute([$activeEditionId]);
$exhibitors = $stmt->fetchAll();

$maxRegistrations = intval(getSetting('max_registrations_per_student', 3));

// Handle: Admin meldet Schüler an
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_register'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('anmeldungen_erstellen')) die('Keine Berechtigung');
    $studentId = intval($_POST['student_id']);
    $exhibitorId = intval($_POST['exhibitor_id']);
    $priority = max(1, min(3, intval($_POST['priority'] ?? 2)));
    
    // Prüfen ob bereits registriert
    $stmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND registrations.edition_id = ?");
    $stmt->execute([$studentId, $exhibitorId, $activeEditionId]);
    if ($stmt->fetchColumn() > 0) {
        $message = ['type' => 'error', 'text' => 'Schüler ist bereits für diesen Fachbereich registriert.'];
    } else {
        // Prüfen ob max. Registrierungen erreicht
        $stmt = $db->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
        $stmt->execute([$studentId, $activeEditionId]);
        $regCount = $stmt->fetchColumn();
        
        if ($regCount >= $maxRegistrations) {
            $message = ['type' => 'error', 'text' => 'Schüler hat bereits die maximale Anzahl an Anmeldungen erreicht (' . $maxRegistrations . ').'];
        } else {
            // Prüfen ob diese Priorität bereits verwendet wird
            $stmt = $db->prepare("SELECT priority FROM registrations WHERE user_id = ? AND registrations.edition_id = ?");
            $stmt->execute([$studentId, $activeEditionId]);
            $usedPriorities = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (in_array($priority, $usedPriorities)) {
                $availablePriorities = array_diff([1, 2, 3], $usedPriorities);
                $priorityLabels = [1 => 'Hoch', 2 => 'Mittel', 3 => 'Niedrig'];
                $availableLabels = array_map(function($p) use ($priorityLabels) { return $priorityLabels[$p]; }, $availablePriorities);
                $message = ['type' => 'error', 'text' => 'Diese Priorität wurde bereits verwendet. Verfügbare Prioritäten: ' . implode(', ', $availableLabels)];
            } else {
                $stmt = $db->prepare("INSERT INTO registrations (user_id, exhibitor_id, timeslot_id, registration_type, priority, edition_id) VALUES (?, ?, NULL, 'admin', ?, ?)");
                $stmt->execute([$studentId, $exhibitorId, $priority, $activeEditionId]);
                // Für Audit-Log Namen lesen
                $stmtN = $db->prepare("SELECT u.firstname, u.lastname, u.class, e.name as ename FROM users u, exhibitors e WHERE u.id = ? AND e.id = ?");
                $stmtN->execute([$studentId, $exhibitorId]);
                $logR = $stmtN->fetch();
                $logDesc = $logR ? "Schüler '{$logR['firstname']} {$logR['lastname']}' (Klasse {$logR['class']}) bei '{$logR['ename']}' eingeschrieben" : "Schüler #$studentId bei Fachbereich #$exhibitorId";
                logAuditAction('einschreibung_admin', $logDesc);
                $message = ['type' => 'success', 'text' => 'Schüler erfolgreich eingeschrieben.'];
            }
        }
    }
}

// Handle: Admin nimmt Einschreibung zurück
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_unregister'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('anmeldungen_loeschen')) die('Keine Berechtigung');
    $registrationId = intval($_POST['registration_id']);
    // Für Audit-Log vor dem Löschen lesen
    $stmtN = $db->prepare("SELECT u.firstname, u.lastname, u.class, e.name as ename FROM registrations r JOIN users u ON r.user_id = u.id JOIN exhibitors e ON r.exhibitor_id = e.id WHERE r.id = ? AND r.edition_id = ?");
    $stmtN->execute([$registrationId, $activeEditionId]);
    $logR = $stmtN->fetch();
    $stmt = $db->prepare("DELETE FROM registrations WHERE id = ?");
    if ($stmt->execute([$registrationId])) {
        $logDesc = $logR ? "Einschreibung von '{$logR['firstname']} {$logR['lastname']}' (Klasse {$logR['class']}) bei '{$logR['ename']}' zurückgenommen" : "Einschreibung #$registrationId gelöscht";
        logAuditAction('einschreibung_zurueckgenommen', $logDesc);
        $message = ['type' => 'success', 'text' => 'Einschreibung erfolgreich zurückgenommen.'];
    } else {
        $message = ['type' => 'error', 'text' => 'Fehler beim Zurücknehmen der Einschreibung.'];
    }
}

// Suchfilter
$filterStudent = $_GET['student'] ?? '';
$filterExhibitor = $_GET['exhibitor'] ?? '';

// Alle Einschreibungen laden
$query = "
    SELECT r.*, u.firstname, u.lastname, u.class, u.username, 
           e.name as exhibitor_name, t.slot_name, t.slot_number
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN exhibitors e ON r.exhibitor_id = e.id
    LEFT JOIN timeslots t ON r.timeslot_id = t.id
    WHERE u.role = 'student' AND r.edition_id = ? AND e.edition_id = ?
";
$params = [$activeEditionId, $activeEditionId];

if (!empty($filterStudent)) {
    $query .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR u.class LIKE ?)";
    $params[] = "%$filterStudent%";
    $params[] = "%$filterStudent%";
    $params[] = "%$filterStudent%";
    $params[] = "%$filterStudent%";
}
if (!empty($filterExhibitor)) {
    $query .= " AND e.id = ?";
    $params[] = $filterExhibitor;
}

$query .= " ORDER BY u.lastname, u.firstname, t.slot_number";
$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">
                <i class="fas fa-clipboard-list text-emerald-500 mr-2"></i>
                Einschreibungsverwaltung
            </h2>
            <p class="text-sm text-gray-500 mt-1">Einschreibungen verwalten, Schüler ein- und ausschreiben</p>
        </div>
        <span class="px-3 py-1.5 rounded-lg text-sm font-medium <?php echo getRegistrationStatus() === 'open' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'; ?>">
            <i class="fas fa-<?php echo getRegistrationStatus() === 'open' ? 'lock-open' : 'lock'; ?> mr-1"></i>
            Einschreibung: <?php echo getRegistrationStatus() === 'open' ? 'Offen' : 'Geschlossen'; ?>
        </span>
    </div>

    <?php if (isset($message)): ?>
    <div class="<?php echo $message['type'] === 'success' ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'; ?> border p-4 rounded-xl">
        <div class="flex items-center">
            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check-circle text-emerald-500' : 'exclamation-circle text-red-500'; ?> mr-3"></i>
            <p class="text-sm <?php echo $message['type'] === 'success' ? 'text-emerald-700' : 'text-red-700'; ?>"><?php echo $message['text']; ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Schüler einschreiben -->
    <?php if (isAdmin() || hasPermission('anmeldungen_erstellen')): ?>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 text-sm mb-4">
            <i class="fas fa-user-plus text-emerald-500 mr-2"></i>
            Schüler einschreiben
        </h3>
        <form method="POST" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <select name="student_id" required class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400">
                <option value="">Schüler wählen...</option>
                <?php foreach ($students as $student): ?>
                <option value="<?php echo $student['id']; ?>">
                    <?php echo htmlspecialchars($student['lastname'] . ', ' . $student['firstname'] . ' (' . $student['class'] . ')'); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="exhibitor_id" required class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400">
                <option value="">Fachbereich wählen...</option>
                <?php foreach ($exhibitors as $ex): ?>
                <option value="<?php echo $ex['id']; ?>"><?php echo htmlspecialchars($ex['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400">
                <option value="1">Prio: Hoch</option>
                <option value="2" selected>Prio: Mittel</option>
                <option value="3">Prio: Niedrig</option>
            </select>
            <button type="submit" name="admin_register" value="1" class="px-5 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium text-sm whitespace-nowrap">
                <i class="fas fa-plus mr-1"></i>Einschreiben
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Filter -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 text-sm mb-4">
            <i class="fas fa-filter text-gray-400 mr-2"></i>
            Filter
        </h3>
        <form method="GET" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="page" value="admin-registrations">
            <input type="text" name="student" value="<?php echo htmlspecialchars($filterStudent); ?>" 
                   placeholder="Schüler suchen (Name, Klasse)..."
                   class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-emerald-300">
            <select name="exhibitor" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-emerald-300">
                <option value="">Alle Fachbereiche</option>
                <?php foreach ($exhibitors as $ex): ?>
                <option value="<?php echo $ex['id']; ?>" <?php echo $filterExhibitor == $ex['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ex['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-5 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium text-sm">
                <i class="fas fa-search mr-1"></i>Filtern
            </button>
            <a href="?page=admin-registrations" class="px-5 py-2 bg-gray-50 text-gray-500 rounded-lg hover:bg-gray-100 transition font-medium text-sm text-center">
                Zurücksetzen
            </a>
        </form>
    </div>

    <!-- Einschreibungsliste -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800 text-sm">
                <i class="fas fa-list text-gray-400 mr-2"></i>
                Einschreibungen (<?php echo count($registrations); ?>)
            </h3>
        </div>
        
        <?php if (empty($registrations)): ?>
        <div class="p-12 text-center">
            <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 text-sm">Keine Einschreibungen gefunden.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Schüler</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Klasse</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Fachbereich</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Slot</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Priorität</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Typ</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Aktion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($registrations as $reg): 
                        $priorityLabels = [1 => 'Hoch', 2 => 'Mittel', 3 => 'Niedrig'];
                        $priorityColors = [1 => 'text-red-600 bg-red-50', 2 => 'text-amber-600 bg-amber-50', 3 => 'text-gray-600 bg-gray-100'];
                        $prio = $reg['priority'] ?? 2;
                    ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-4 py-3 text-sm font-medium text-gray-800">
                            <?php echo htmlspecialchars($reg['firstname'] . ' ' . $reg['lastname']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($reg['class'] ?? '-'); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($reg['slot_name']): ?>
                            <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-xs font-medium">
                                <?php echo htmlspecialchars($reg['slot_name']); ?>
                            </span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-yellow-50 text-yellow-700 rounded-md text-xs font-medium">
                                Ausstehend
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-md text-xs font-medium <?php echo $priorityColors[$prio] ?? $priorityColors[2]; ?>">
                                <?php echo $priorityLabels[$prio] ?? 'Mittel'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-400 capitalize"><?php echo htmlspecialchars($reg['registration_type'] ?? 'manual'); ?></td>
                        <td class="px-4 py-3 text-right">
                            <?php if (isAdmin() || hasPermission('anmeldungen_loeschen')): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                <button type="submit" name="admin_unregister" value="1"
                                        class="px-3 py-1 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition text-xs font-medium"
                                        onclick="return confirm('Einschreibung zurücknehmen?')">
                                    <i class="fas fa-trash-alt mr-1"></i>Entfernen
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
