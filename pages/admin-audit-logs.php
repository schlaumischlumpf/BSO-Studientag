<?php
/**
 * Admin Audit Logs (Issue #21)
 * Zeigt Protokolle aller Nutzeraktionen (filterbar)
 * Nur für Admins einsehbar, nicht editierbar
 */

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('audit_logs_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

$db = getDB();

// Filter-Parameter
$filterUser = $_GET['filter_user'] ?? '';
$filterAction = $_GET['filter_action'] ?? '';
$filterDate = $_GET['filter_date'] ?? '';
$filterSeverity = $_GET['filter_severity'] ?? '';
$page = max(1, intval($_GET['log_page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Query aufbauen
$where = [];
$params = [];

if ($filterUser) {
    $where[] = "(al.username LIKE ? OR al.user_id = ?)";
    $params[] = "%$filterUser%";
    $params[] = intval($filterUser);
}

if ($filterAction) {
    $where[] = "al.action LIKE ?";
    $params[] = "%$filterAction%";
}

if ($filterDate) {
    $where[] = "DATE(al.created_at) = ?";
    $params[] = $filterDate;
}

if ($filterSeverity) {
    $allowedSeverities = ['info', 'warning', 'error'];
    if (in_array($filterSeverity, $allowedSeverities, true)) {
        $where[] = "al.severity = ?";
        $params[] = $filterSeverity;
    }
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Gesamtanzahl
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al $whereClause");
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $perPage));

// Fehleranzahl (gefiltert nach anderen Parametern, aber immer severity=error)
$errorCountWhere = $where;
$errorCountParams = $params;
if (!$filterSeverity) {
    $errorCountWhere[] = "al.severity = ?";
    $errorCountParams[] = 'error';
}
$errorCountStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al " .
    (!empty($errorCountWhere) ? 'WHERE ' . implode(' AND ', $errorCountWhere) : ''));
$errorCountStmt->execute($errorCountParams);
$errorCount = $errorCountStmt->fetchColumn();

// Logs laden
$stmt = $db->prepare("
    SELECT al.*
    FROM audit_logs al
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Verfügbare Aktionen für Filter
$actionStmt = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$availableActions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-xl font-semibold text-gray-800">
            <i class="fas fa-history text-indigo-500 mr-2"></i>
            Audit Logs
        </h2>
        <p class="text-sm text-gray-500 mt-1">Protokoll aller Nutzeraktionen – nur lesbar, nicht editierbar</p>
    </div>

    <!-- Filter -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="page" value="admin-audit-logs">
            
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Benutzer</label>
                <input type="text" name="filter_user" value="<?php echo htmlspecialchars($filterUser); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="Benutzername oder ID">
            </div>
            
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Aktion</label>
                <select name="filter_action" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">Alle Aktionen</option>
                    <?php foreach ($availableActions as $action): ?>
                    <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filterAction === $action ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($action); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="min-w-[180px]">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Datum</label>
                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="min-w-[180px]">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Schweregrad</label>
                <select name="filter_severity" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">Alle Schweregrade</option>
                    <option value="info"    <?php echo $filterSeverity === 'info'    ? 'selected' : ''; ?>>Info</option>
                    <option value="warning" <?php echo $filterSeverity === 'warning' ? 'selected' : ''; ?>>Warnung</option>
                    <option value="error"   <?php echo $filterSeverity === 'error'   ? 'selected' : ''; ?>>Fehler</option>
                </select>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition text-sm font-medium">
                    <i class="fas fa-search mr-1"></i>Filtern
                </button>
                <a href="?page=admin-audit-logs" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm font-medium">
                    <i class="fas fa-times mr-1"></i>Zurücksetzen
                </a>
                <a href="?page=admin-audit-logs&filter_user=<?php echo urlencode($filterUser); ?>&filter_action=<?php echo urlencode($filterAction); ?>&filter_date=<?php echo urlencode($filterDate); ?>&filter_severity=<?php echo urlencode($filterSeverity); ?>&export=txt"
                   class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-800 transition text-sm font-medium">
                    <i class="fas fa-download mr-1"></i>TXT Export
                </a>
            </div>
        </form>
    </div>

    <!-- Statistik -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Gesamte Einträge</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $totalLogs; ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center">
                    <i class="fas fa-list text-indigo-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Seite</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $page; ?> / <?php echo $totalPages; ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-file text-blue-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Aktionstypen</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo count($availableActions); ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                    <i class="fas fa-tags text-purple-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Fehler (gefiltert)</p>
                    <p class="text-2xl font-semibold <?php echo $errorCount > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
                        <?php echo $errorCount; ?>
                    </p>
                </div>
                <div class="w-10 h-10 rounded-lg <?php echo $errorCount > 0 ? 'bg-red-50' : 'bg-gray-50'; ?> flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle <?php echo $errorCount > 0 ? 'text-red-500' : 'text-gray-400'; ?>"></i>
                </div>
            </div>
            <?php if ($errorCount > 0 && $filterSeverity !== 'error'): ?>
            <a href="?page=admin-audit-logs&filter_severity=error" class="text-xs text-red-500 hover:underline mt-1 inline-block">
                Nur Fehler anzeigen &rarr;
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Log-Tabelle -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Zeitpunkt (UTC)</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Nutzer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Aktion</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Schweregrad</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Details</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-3xl mb-2"></i>
                            <p>Keine Audit-Log-Einträge gefunden.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr class="<?php echo ($log['severity'] ?? 'info') === 'error' ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50'; ?> transition">
                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                            <?php echo date('d.m.Y H:i:s', strtotime($log['created_at'])); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="font-medium text-gray-800"><?php echo htmlspecialchars($log['username']); ?></span>
                            <?php if ($log['user_id']): ?>
                            <span class="text-xs text-gray-400 ml-1">(#<?php echo $log['user_id']; ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded-full text-xs font-semibold">
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php
                            $sev = $log['severity'] ?? 'info';
                            $sevConfig = [
                                'info'    => ['bg-blue-100 text-blue-800',    'Info'],
                                'warning' => ['bg-yellow-100 text-yellow-800', 'Warnung'],
                                'error'   => ['bg-red-100 text-red-800',       'Fehler'],
                            ];
                            [$sevClass, $sevLabel] = $sevConfig[$sev] ?? $sevConfig['info'];
                            ?>
                            <span class="px-2 py-1 <?php echo $sevClass; ?> rounded-full text-xs font-semibold">
                                <?php echo $sevLabel; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                            <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">
                            <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between">
        <div class="text-sm text-gray-500">
            Zeige <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalLogs); ?> von <?php echo $totalLogs; ?> Einträgen
        </div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?page=admin-audit-logs&log_page=<?php echo $page - 1; ?>&filter_user=<?php echo urlencode($filterUser); ?>&filter_action=<?php echo urlencode($filterAction); ?>&filter_date=<?php echo urlencode($filterDate); ?>&filter_severity=<?php echo urlencode($filterSeverity); ?>"
               class="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm">
                <i class="fas fa-chevron-left mr-1"></i>Zurück
            </a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=admin-audit-logs&log_page=<?php echo $page + 1; ?>&filter_user=<?php echo urlencode($filterUser); ?>&filter_action=<?php echo urlencode($filterAction); ?>&filter_date=<?php echo urlencode($filterDate); ?>&filter_severity=<?php echo urlencode($filterSeverity); ?>"
               class="px-3 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition text-sm">
                Weiter<i class="fas fa-chevron-right ml-1"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hinweis -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-yellow-500 mr-3 mt-0.5"></i>
            <div class="text-sm text-yellow-800">
                <strong>Hinweis:</strong> Audit Logs sind schreibgeschützt und können nicht von Administratoren bearbeitet oder gelöscht werden. 
                Format: Zeitpunkt (UTC), Nutzer, Aktion.
            </div>
        </div>
    </div>
</div>
