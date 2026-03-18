<?php
if (!isAdmin()) die('Keine Berechtigung');

$db      = getDB();
$message = null;

// Erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
    requireCsrf();
    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body']  ?? '');
    $type       = in_array($_POST['type'] ?? '', ['info','warning','success','error']) ? $_POST['type'] : 'info';
    $targetRole = in_array($_POST['target_role'] ?? '', ['all','student','teacher','admin']) ? $_POST['target_role'] : 'all';
    $expiresRaw = trim($_POST['expires_at'] ?? '');
    $expiresAt  = !empty($expiresRaw) ? date('Y-m-d H:i:s', strtotime($expiresRaw)) : null;
    if (empty($title)) {
        $message = ['type' => 'error', 'text' => 'Titel darf nicht leer sein.'];
    } else {
        $db->prepare("INSERT INTO announcements (title,body,type,target_role,expires_at,is_active,created_by) VALUES (?,?,?,?,?,1,?)")
           ->execute([$title, $body, $type, $targetRole, $expiresAt, $_SESSION['user_id']]);
        logAuditAction('ankuendigung_erstellt', "\"$title\" für: $targetRole");
        $message = ['type' => 'success', 'text' => 'Ankündigung erstellt.'];
    }
}

// Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_announcement'])) {
    requireCsrf();
    $annId = intval($_POST['announcement_id']);
    $db->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?")->execute([$annId]);
    logAuditAction('ankuendigung_toggle', "Ankündigung #$annId umgeschaltet");
    header('Location: ?page=admin-announcements'); exit();
}

// Löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    requireCsrf();
    $annId = intval($_POST['announcement_id']);
    $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$annId]);
    logAuditAction('ankuendigung_geloescht', "Ankündigung #$annId gelöscht", 'warning');
    header('Location: ?page=admin-announcements'); exit();
}

// Daten laden
try {
    $allAnnouncements = $db->query("
        SELECT a.*, u.firstname, u.lastname
        FROM   announcements a
        LEFT JOIN users u ON a.created_by = u.id
        ORDER BY a.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $allAnnouncements = [];
    $message = ['type' => 'error', 'text' => 'Ankündigungs-Tabelle noch nicht vorhanden. Bitte Setup ausführen.'];
}

$typeBadge = [
    'info'    => 'bg-blue-100 text-blue-700',
    'warning' => 'bg-amber-100 text-amber-700',
    'success' => 'bg-emerald-100 text-emerald-700',
    'error'   => 'bg-red-100 text-red-700',
];
$typeLabel = ['info' => 'Info', 'warning' => 'Warnung', 'success' => 'Erfolg', 'error' => 'Fehler'];
$roleLabel = ['all' => 'Alle', 'student' => 'Schüler', 'teacher' => 'Lehrer', 'admin' => 'Admin'];
?>

<div class="p-4 sm:p-6">
    <h1 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2">
        <i class="fas fa-bullhorn text-indigo-500"></i> Ankündigungen
    </h1>

    <?php if ($message): ?>
    <div class="mb-4 p-3 rounded-lg text-sm <?php echo $message['type'] === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?>">
        <?php echo htmlspecialchars($message['text']); ?>
    </div>
    <?php endif; ?>

    <!-- Tabelle bestehender Ankündigungen -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Titel</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Typ</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Zielgruppe</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Ablauf</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Status</th>
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Erstellt von</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($allAnnouncements as $ann): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($ann['title']); ?></div>
                        <?php if ($ann['body']): ?><div class="text-xs text-gray-400 truncate max-w-xs"><?php echo htmlspecialchars(substr($ann['body'], 0, 80)); ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $typeBadge[$ann['type']] ?? ''; ?>"><?php echo $typeLabel[$ann['type']] ?? $ann['type']; ?></span></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo $roleLabel[$ann['target_role']] ?? $ann['target_role']; ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?php echo $ann['expires_at'] ? formatDateTime($ann['expires_at']) : '–'; ?></td>
                    <td class="px-4 py-3">
                        <?php
                        $isExpired = $ann['expires_at'] && strtotime($ann['expires_at']) < time();
                        if ($isExpired): ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Abgelaufen</span>
                        <?php elseif ($ann['is_active']): ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Aktiv</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars(($ann['firstname'] ?? '') . ' ' . ($ann['lastname'] ?? '')); ?></td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 justify-end">
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" name="toggle_announcement"
                                        class="px-2 py-1 text-xs rounded border <?php echo $ann['is_active'] ? 'border-gray-200 text-gray-600 hover:bg-gray-50' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50'; ?> transition">
                                    <?php echo $ann['is_active'] ? 'Deaktivieren' : 'Aktivieren'; ?>
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Ankündigung wirklich löschen?')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" name="delete_announcement"
                                        class="px-2 py-1 text-xs rounded border border-red-200 text-red-600 hover:bg-red-50 transition">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($allAnnouncements)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">Keine Ankündigungen vorhanden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Neue Ankündigung -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-4">Neue Ankündigung veröffentlichen</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Titel *</label>
                <input type="text" name="title" required
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Text (optional)</label>
                <textarea name="body" rows="3"
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Typ</label>
                    <select name="type" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="info">Info</option>
                        <option value="warning">Warnung</option>
                        <option value="success">Erfolg</option>
                        <option value="error">Fehler</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Zielgruppe</label>
                    <select name="target_role" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                        <option value="all">Alle</option>
                        <option value="student">Schüler</option>
                        <option value="teacher">Lehrer</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Ablauf (leer = nie)</label>
                    <input type="datetime-local" name="expires_at"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg">
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" name="create_announcement"
                        class="px-6 py-2 bg-indigo-500 text-white text-sm font-medium rounded-lg hover:bg-indigo-600 transition">
                    <i class="fas fa-bullhorn mr-2"></i>Veröffentlichen
                </button>
            </div>
        </form>
    </div>
</div>
