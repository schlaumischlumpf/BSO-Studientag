<?php
if (!isAdmin()) die('Keine Berechtigung');
$db      = getDB();
$message = null;

// Edition aktivieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    requireCsrf();
    $edId = intval($_POST['edition_id']);
    $db->exec("UPDATE messe_editions SET status = 'archived'");
    $db->prepare("UPDATE messe_editions SET status = 'active' WHERE id = ?")->execute([$edId]);
    invalidateEditionCache();
    logAuditAction('edition_aktiviert', "Edition #$edId aktiviert", 'warning');
    header('Location: ?page=admin-editions'); exit();
}

// Neue Edition erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    requireCsrf();
    $name    = trim($_POST['name'] ?? '');
    $year    = intval($_POST['year'] ?? date('Y'));
    $evDate  = trim($_POST['event_date'] ?? '') ?: null;
    $regS    = trim($_POST['registration_start'] ?? '') ?: null;
    $regE    = trim($_POST['registration_end']   ?? '') ?: null;
    $maxReg  = intval($_POST['max_registrations_per_student'] ?? 3);
    $copyExhibitors = !empty($_POST['copy_exhibitors']);
    if (empty($name) || $year < 2000) {
        $message = ['type' => 'error', 'text' => 'Name und Jahr sind Pflichtfelder.'];
    } else {
        $db->prepare("INSERT INTO messe_editions (name,year,status,event_date,registration_start,registration_end,max_registrations_per_student) VALUES (?,?,'archived',?,?,?,?)")
           ->execute([$name, $year, $evDate, $regS, $regE, $maxReg]);
        $newEditionId = (int)$db->lastInsertId();
        $sourceEditionId = getActiveEditionId();
        $copied = 0;

        if ($copyExhibitors) {
            // Zeitslots aus aktiver Edition kopieren
            $db->prepare("
                INSERT INTO timeslots (slot_number, slot_name, start_time, end_time, is_managed, edition_id)
                SELECT slot_number, slot_name, start_time, end_time, is_managed, ?
                FROM timeslots WHERE edition_id = ?
            ")->execute([$newEditionId, $sourceEditionId]);

            // Aussteller kopieren (ohne Raumzuweisung)
            $db->prepare("
                INSERT INTO exhibitors (name, description, short_description, category, logo,
                    contact_person, email, phone, website, total_slots, room_id, active,
                    visible_fields, jobs, features, offer_types, equipment, edition_id)
                SELECT name, description, short_description, category, logo,
                    contact_person, email, phone, website, total_slots, NULL, active,
                    visible_fields, jobs, features, offer_types, equipment, ?
                FROM exhibitors WHERE edition_id = ?
            ")->execute([$newEditionId, $sourceEditionId]);
            $copied = $db->query("SELECT ROW_COUNT()")->fetchColumn();
        }

        logAuditAction('edition_erstellt', "Edition '$name' ($year) erstellt" . ($copied ? ", $copied Fachbereiche übernommen" : ''));
        $message = ['type' => 'success', 'text' => "Edition '$name' erstellt (Status: archiviert)."
            . ($copied ? " $copied Fachbereiche und Zeitslots wurden übernommen." : '')];
    }
}

// Edition löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireCsrf();
    $edId = intval($_POST['edition_id']);
    $stmtChk = $db->prepare("SELECT (SELECT COUNT(*) FROM registrations WHERE edition_id=?) + (SELECT COUNT(*) FROM exhibitors WHERE edition_id=?) + (SELECT COUNT(*) FROM attendance WHERE edition_id=?) AS total");
    $stmtChk->execute([$edId, $edId, $edId]);
    $total = (int)$stmtChk->fetchColumn();
    if ($total > 0) {
        $message = ['type' => 'error', 'text' => "Edition hat noch $total verknüpfte Datensätze und kann nicht gelöscht werden."];
    } else {
        $db->prepare("DELETE FROM messe_editions WHERE id = ? AND status = 'archived'")->execute([$edId]);
        logAuditAction('edition_geloescht', "Edition #$edId gelöscht", 'warning');
        $message = ['type' => 'success', 'text' => 'Edition gelöscht.'];
    }
}

// Daten laden
$editions = $db->query("
    SELECT e.*,
           (SELECT COUNT(*) FROM exhibitors    WHERE edition_id = e.id) AS cnt_exhibitors,
           (SELECT COUNT(*) FROM registrations WHERE edition_id = e.id) AS cnt_registrations,
           (SELECT COUNT(*) FROM attendance    WHERE edition_id = e.id) AS cnt_checkins
    FROM messe_editions e
    ORDER BY e.year DESC, e.id DESC
")->fetchAll();
?>

<div class="p-4 sm:p-6">
    <h1 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
        <i class="fas fa-layer-group text-emerald-500"></i> Messe-Editionen
    </h1>

    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        <strong>Achtung:</strong> Das Aktivieren einer Edition wechselt die <strong>gesamte Anwendung</strong>
        in diesen Datenbereich. Anmeldungen, Check-ins und andere Daten sind editionsspezifisch.
        Benutzerkonten (Admins, Schüler, Lehrer) bleiben erhalten, haben aber in der neuen Edition
        keine Anmeldungen. Fachbereiche und Zeitslots können beim Erstellen übernommen werden.
    </div>

    <?php if ($message): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?php echo $message['type'] === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?>">
        <?php echo htmlspecialchars($message['text']); ?>
    </div>
    <?php endif; ?>

    <!-- Editions-Tabelle -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Name</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Jahr</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Status</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Messe-Datum</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Fachbereiche</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Anmeldungen</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Check-ins</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($editions as $ed): ?>
                <tr class="<?php echo $ed['status'] === 'active' ? 'bg-emerald-50 border-l-4 border-emerald-400' : 'hover:bg-gray-50'; ?>">
                    <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($ed['name']); ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo $ed['year']; ?></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $ed['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $ed['status'] === 'active' ? 'Aktiv' : 'Archiviert'; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?php echo $ed['event_date'] ? formatDate($ed['event_date']) : '–'; ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo $ed['cnt_exhibitors']; ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo $ed['cnt_registrations']; ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo $ed['cnt_checkins']; ?></td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 justify-end">
                            <?php if ($ed['status'] !== 'active'): ?>
                            <form method="POST" onsubmit="return confirm('Achtung: Alle Ansichten wechseln zur Edition &quot;<?php echo htmlspecialchars($ed['name'], ENT_QUOTES); ?>
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">&quot;.\n\nAnmeldungen und Check-ins sind editionsspezifisch.\nBenutzerkonten bleiben erhalten.\n\nFortfahren?')">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="edition_id" value="<?php echo $ed['id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-emerald-500 text-white text-xs rounded-lg hover:bg-emerald-600 transition font-medium">
                                    Aktivieren
                                </button>
                            </form>
                            <?php if ($ed['cnt_registrations'] == 0 && $ed['cnt_exhibitors'] == 0 && $ed['cnt_checkins'] == 0): ?>
                            <form method="POST" onsubmit="return confirm('Edition wirklich unwiderruflich löschen?')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="edition_id" value="<?php echo $ed['id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-red-50 border border-red-200 text-red-600 text-xs rounded-lg hover:bg-red-100 transition">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-xs text-emerald-600 font-medium"><i class="fas fa-check mr-1"></i>Aktiv</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Neue Edition -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4">Neue Edition anlegen</h2>
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="create">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                <input type="text" name="name" required placeholder="z.B. BSO-Tag an der FU 2027"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Jahr *</label>
                <input type="number" name="year" required value="<?php echo date('Y') + 1; ?>" min="2020" max="2099"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Messe-Datum</label>
                <input type="date" name="event_date"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Einschreibung Start</label>
                <input type="datetime-local" name="registration_start"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Einschreibung Ende</label>
                <input type="datetime-local" name="registration_end"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Max. Anmeldungen/Schüler</label>
                <input type="number" name="max_registrations_per_student" value="3" min="1" max="20"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
            </div>
            <div class="sm:col-span-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="copy_exhibitors" value="1" checked
                           class="w-4 h-4 text-emerald-500 rounded">
                    <span class="text-sm text-gray-700 font-medium">Fachbereiche und Zeitslots aus aktiver Edition übernehmen</span>
                </label>
                <p class="text-xs text-gray-400 mt-1 ml-6">Kopiert alle Fachbereiche (ohne Hörsaalzuweisungen) und Zeitslots in die neue Edition.</p>
            </div>
            <div class="sm:col-span-3 flex justify-end">
                <button type="submit"
                        class="px-6 py-2 bg-emerald-500 text-white text-sm font-medium rounded-lg hover:bg-emerald-600 transition">
                    <i class="fas fa-plus mr-2"></i>Edition anlegen (archiviert)
                </button>
            </div>
        </form>
    </div>
</div>
