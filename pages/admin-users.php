<?php
// Admin Nutzerverwaltung

// Berechtigungsprüfung
if (!isAdmin() && !hasPermission('benutzer_sehen')) {
    die('Keine Berechtigung zum Anzeigen dieser Seite');
}

// Datenbankverbindung holen
$db = getDB();

// Handle CSV Import
$csvImportResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    requireCsrf();
    if (!isAdmin() && !hasPermission('benutzer_importieren')) die('Keine Berechtigung');
    if (!empty($_FILES['csv_file']['name'])) {
        $file = $_FILES['csv_file'];
        
        // Prüfe ob Datei valide ist
        if ($file['error'] === UPLOAD_ERR_OK && in_array(pathinfo($file['name'], PATHINFO_EXTENSION), ['csv', 'txt'])) {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $importResult = [
                    'imported' => 0,
                    'skipped' => 0,
                    'errors' => []
                ];
                
                // Erste Zeile überspringen (Header)
                fgetcsv($handle, 0, ';');

                $rowNumber = 2;
                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    // Spalten: firstname, lastname, username, email, role, class, password
                    if (count($row) < 6) {
                        $importResult['errors'][] = "Zeile $rowNumber: Zu wenige Spalten";
                        $rowNumber++;
                        continue;
                    }
                    
                    $firstname = sanitize(trim($row[0]));
                    $lastname = sanitize(trim($row[1]));
                    $username = sanitize(trim($row[2]));
                    $email = sanitize(trim($row[3]));
                    $role = sanitize(trim($row[4]));
                    $class = sanitize(trim($row[5] ?? ''));
                    
                    $passwordProvided = isset($row[6]) && !empty(trim($row[6]));

                    if ($passwordProvided) {
                        $password = trim($row[6]);
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    } else {
                        $password = null;
                        $hashedPassword = null; // Kein Passwort → kein Login möglich
                    }
                    
                    // Validierungen
                    if (empty($firstname) || empty($lastname) || empty($username) || empty($role)) {
                        $importResult['errors'][] = "Zeile $rowNumber: Erforderliche Felder fehlen (Vorname, Nachname, Benutzername, Rolle)";
                        $rowNumber++;
                        continue;
                    }
                    
                    // Prüfe ob Username in dieser Edition bereits existiert
                    $csvUserEditionId = ($role === 'admin') ? null : $activeEditionId;
                    if ($csvUserEditionId !== null) {
                        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id = ?");
                        $stmt->execute([$username, $csvUserEditionId]);
                    } else {
                        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id IS NULL");
                        $stmt->execute([$username]);
                    }
                    if ($stmt->fetch()) {
                        $importResult['skipped']++;
                        $rowNumber++;
                        continue;
                    }
                    
                    // Validiere Rolle
                    $allowedRoles = ['student', 'teacher', 'admin', 'orga'];
                    if (!in_array($role, $allowedRoles, true)) {
                        $importResult['errors'][] = "Zeile $rowNumber: Ungültige Rolle '$role'";
                        $rowNumber++;
                        continue;
                    }
                    
                    // Sicherheit: Nur Admins können Admin-Accounts über CSV erstellen
                    if ($role === 'admin' && !isAdmin()) {
                        $importResult['errors'][] = "Zeile $rowNumber: Nur Administratoren können Admin-Accounts erstellen";
                        $rowNumber++;
                        continue;
                    }
                    
                    // Benutzer erstellen
                    $force_password_change = $passwordProvided ? 1 : 0;
                    $csvUserEditionId = ($role === 'admin') ? null : $activeEditionId;
                    
                    try {
                        $stmt = $db->prepare("INSERT INTO users (firstname, lastname, username, email, password, role, class, must_change_password, edition_id) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$firstname, $lastname, $username, $email, $hashedPassword, $role, $class, $force_password_change, $csvUserEditionId])) {
                            $importResult['imported']++;
                            // Keine generated_passwords mehr hier speichern – das macht der neue Bulk-Button
                        }
                    } catch (PDOException $e) {
                        $importResult['errors'][] = "Zeile $rowNumber: Datenbankfehler - " . $e->getMessage();
                        logErrorToAudit($e, 'Benutzerverwaltung');
                    }
                    
                    $rowNumber++;
                }
                
                fclose($handle);
                $csvImportResult = $importResult;
                
                // Nachricht vorbereiten
                if ($importResult['imported'] > 0) {
                    logAuditAction('csv_import', $importResult['imported'] . " Benutzer importiert, " . $importResult['skipped'] . " übersprungen");
                    $message = ['type' => 'success', 'text' => $importResult['imported'] . ' Benutzer importiert, ' . $importResult['skipped'] . ' übersprungen'];
                } elseif (!empty($importResult['errors'])) {
                    $message = ['type' => 'error', 'text' => 'Import fehlgeschlagen: ' . htmlspecialchars($importResult['errors'][0], ENT_QUOTES, 'UTF-8')];
                } elseif ($importResult['skipped'] > 0) {
                    $message = ['type' => 'warning', 'text' => 'Alle ' . $importResult['skipped'] . ' Benutzer wurden übersprungen (bereits vorhanden).'];
                }
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Ungültige Datei. Bitte eine CSV-Datei hochladen.'];
        }
    } else {
        $message = ['type' => 'error', 'text' => 'Keine Datei ausgewählt'];
    }
}

// Handle User Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (isset($_POST['create_user'])) {
        if (!isAdmin() && !hasPermission('benutzer_erstellen')) die('Keine Berechtigung');
        // Neuen Benutzer erstellen
        $username = sanitize($_POST['username']);
        $email = sanitize($_POST['email']);
        $firstname = sanitize($_POST['firstname']);
        $lastname = sanitize($_POST['lastname']);
        $role = sanitize($_POST['role']);
        $class = sanitize($_POST['class'] ?? '');
        $password = $_POST['password'];
        
        // SICHERHEIT: Nur echte Admins können Admin-Accounts erstellen
        if ($role === 'admin' && !isAdmin()) {
            $message = ['type' => 'error', 'text' => 'Nur Administratoren können Admin-Accounts erstellen'];
        } else {
            // Prüfen ob Username in dieser Edition bereits existiert
            $userEditionId = ($role === 'admin') ? null : $activeEditionId;
            if ($userEditionId !== null) {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id = ?");
                $stmt->execute([$username, $userEditionId]);
            } else {
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id IS NULL");
                $stmt->execute([$username]);
            }
            if ($stmt->fetch()) {
                $message = ['type' => 'error', 'text' => 'Benutzername existiert bereits'];
            } else {
                // Validate role to avoid DB truncation/enum issues
                $allowedRoles = ['student', 'teacher', 'admin', 'orga']; // must match app expectations
                if (!in_array($role, $allowedRoles, true)) {
                    // fallback to a safe default
                    $role = 'student';
                }

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $userEditionId = ($role === 'admin') ? null : $activeEditionId;
                // Force password change on first login for admin-created accounts
                $stmt = $db->prepare("INSERT INTO users (username, email, password, firstname, lastname, role, class, must_change_password, edition_id) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                try {
                    if ($stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname, $role, $class, 1, $userEditionId])) {
                        logAuditAction('benutzer_erstellt', "Benutzer '$username' ($firstname $lastname, Rolle: $role) erstellt");
                        $message = ['type' => 'success', 'text' => 'Benutzer erfolgreich erstellt'];
                    } else {
                        // fetch error info for logging
                        $err = $stmt->errorInfo();
                        error_log('DB insert user failed: ' . json_encode($err));
                        $message = ['type' => 'error', 'text' => 'Fehler beim Erstellen des Benutzers'];
                    }
                } catch (PDOException $e) {
                    logErrorToAudit($e, 'Benutzerverwaltung');
                    // Log and show a friendly error instead of letting the script crash
                    error_log('PDOException when inserting user: ' . $e->getMessage());
                    $message = ['type' => 'error', 'text' => 'Datenbankfehler beim Erstellen des Benutzers. Bitte überprüfe Eingaben und Schema.'];
                }
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        if (!isAdmin() && !hasPermission('benutzer_passwort_zuruecksetzen')) die('Keine Berechtigung');
        // Passwort zurücksetzen
        $userId = intval($_POST['user_id']);
        $newPassword = $_POST['new_password'];
        
        // SICHERHEIT: Prüfen ob der Zielbenutzer ein Admin ist
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetUser = $stmt->fetch();
        
        if ($targetUser && $targetUser['role'] === 'admin' && !isAdmin()) {
            $message = ['type' => 'error', 'text' => 'Nur Administratoren können Admin-Passwörter ändern'];
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?");
            if ($stmt->execute([$hashedPassword, $userId])) {
                logAuditAction('passwort_zurueckgesetzt', "Passwort für Benutzer #$userId zurückgesetzt");
                $message = ['type' => 'success', 'text' => 'Passwort erfolgreich zurückgesetzt. Der Benutzer muss es beim nächsten Login ändern.'];
            } else {
                $message = ['type' => 'error', 'text' => 'Fehler beim Zurücksetzen des Passworts'];
            }
        }
    } elseif (isset($_POST['edit_user'])) {
        if (!isAdmin() && !hasPermission('benutzer_bearbeiten')) die('Keine Berechtigung');
        $userId = intval($_POST['user_id']);

        // SICHERHEIT: Eigenen Account nicht bearbeiten
        if ($userId === intval($_SESSION['user_id'])) {
            $message = ['type' => 'error', 'text' => 'Du kannst deinen eigenen Account nicht bearbeiten'];
        } else {
            // Prüfen ob der Zielbenutzer ein Admin ist
            $stmt = $db->prepare("SELECT role, username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $message = ['type' => 'error', 'text' => 'Benutzer nicht gefunden'];
            } elseif ($targetUser['role'] === 'admin' && !isAdmin()) {
                $message = ['type' => 'error', 'text' => 'Nur Administratoren können Admin-Accounts bearbeiten'];
            } else {
                $newFirstname = sanitize(trim($_POST['edit_firstname']));
                $newLastname = sanitize(trim($_POST['edit_lastname']));
                $newUsername = sanitize(trim($_POST['edit_username']));
                $newRole = sanitize(trim($_POST['edit_role']));
                $newClass = sanitize(trim($_POST['edit_class'] ?? ''));

                // Validierung
                $allowedRoles = ['student', 'teacher', 'orga'];
                if (isAdmin()) $allowedRoles[] = 'admin';
                if (!in_array($newRole, $allowedRoles, true)) {
                    $newRole = 'student';
                }

                // Nur Admins dürfen zur Admin-Rolle befördern
                if ($newRole === 'admin' && !isAdmin()) {
                    $message = ['type' => 'error', 'text' => 'Nur Administratoren können die Admin-Rolle vergeben'];
                } else {
                    // Prüfen ob neuer Benutzername in der gleichen Edition schon vergeben ist (wenn geändert)
                    if ($newUsername !== $targetUser['username']) {
                        $editEditionId = ($newRole === 'admin') ? null : $activeEditionId;
                        if ($editEditionId !== null) {
                            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id = ? AND id != ?");
                            $stmt->execute([$newUsername, $editEditionId, $userId]);
                        } else {
                            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id IS NULL AND id != ?");
                            $stmt->execute([$newUsername, $userId]);
                        }
                        if ($stmt->fetch()) {
                            $message = ['type' => 'error', 'text' => 'Benutzername existiert bereits'];
                        }
                    }

                    if (!isset($message)) {
                        $stmt = $db->prepare("UPDATE users SET firstname = ?, lastname = ?, username = ?, role = ?, class = ? WHERE id = ?");
                        if ($stmt->execute([$newFirstname, $newLastname, $newUsername, $newRole, $newClass, $userId])) {
                            $changes = [];
                            if ($targetUser['role'] !== $newRole) $changes[] = "Rolle: {$targetUser['role']} → $newRole";
                            if ($targetUser['username'] !== $newUsername) $changes[] = "Username: {$targetUser['username']} → $newUsername";
                            $changeStr = !empty($changes) ? ' (' . implode(', ', $changes) . ')' : '';
                            logAuditAction('benutzer_bearbeitet', "Benutzer '$newUsername' (ID: $userId) bearbeitet" . $changeStr);
                            $message = ['type' => 'success', 'text' => 'Benutzer erfolgreich aktualisiert'];
                        } else {
                            $message = ['type' => 'error', 'text' => 'Fehler beim Aktualisieren'];
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        if (!isAdmin() && !hasPermission('benutzer_loeschen')) die('Keine Berechtigung');
        // Benutzer löschen
        $userId = intval($_POST['user_id']);
        
        // SICHERHEIT: Prüfen ob der Zielbenutzer ein Admin ist
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetUser = $stmt->fetch();
        
        if ($targetUser && $targetUser['role'] === 'admin' && !isAdmin()) {
            $message = ['type' => 'error', 'text' => 'Nur Administratoren können Admin-Accounts löschen'];
        } else {
            // Zunächst alle Registrierungen löschen
            $stmt = $db->prepare("DELETE FROM registrations WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Berechtigungen löschen
            $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Dann den Benutzer löschen
            $stmt = $db->prepare("SELECT username, firstname, lastname, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $delUser = $stmt->fetch();
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$userId])) {
                $uname = $delUser ? "'{$delUser['username']}' ({$delUser['firstname']} {$delUser['lastname']}, {$delUser['role']})" : "#$userId";
                logAuditAction('benutzer_geloescht', "Benutzer $uname gelöscht");
                $message = ['type' => 'success', 'text' => 'Benutzer erfolgreich gelöscht'];
            } else {
                $message = ['type' => 'error', 'text' => 'Fehler beim Löschen des Benutzers'];
            }
        }
    }
}

// Alle Benutzer laden (Admins + Benutzer der aktiven Edition)
$stmt = $db->prepare("
    SELECT u.*, COUNT(DISTINCT r.id) as registration_count
    FROM users u
    LEFT JOIN registrations r ON u.id = r.user_id
    WHERE u.role = 'admin' OR u.edition_id = ?
    GROUP BY u.id
    ORDER BY u.role ASC, u.lastname ASC, u.firstname ASC
");
$stmt->execute([$activeEditionId]);
$users = $stmt->fetchAll();

// Statistiken (für aktive Edition)
$stats = [];
$stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
$stats['admins'] = $stmt->fetch()['count'];
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND edition_id = ?");
$stmt->execute([$activeEditionId]);
$stats['students'] = $stmt->fetch()['count'];
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND edition_id = ?");
$stmt->execute([$activeEditionId]);
$stats['teachers'] = $stmt->fetch()['count'];
?>

<div class="space-y-6">
    <?php if (isset($message)): ?>
    <div class="mb-4">
        <?php if ($message['type'] === 'success'): ?>
            <div class="bg-emerald-50 border border-emerald-200 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-emerald-500 mr-3"></i>
                    <p class="text-emerald-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php elseif ($message['type'] === 'warning'): ?>
            <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 mr-3"></i>
                    <p class="text-yellow-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-red-50 border border-red-200 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <p class="text-red-700"><?php echo $message['text']; ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Nutzerverwaltung</h2>
            <p class="text-sm text-gray-500 mt-1">Benutzer erstellen, bearbeiten und löschen</p>
        </div>
        <div class="flex gap-2">
            <?php if (isAdmin() || hasPermission('benutzer_importieren')): ?>
            <button onclick="openImportCsvModal()" class="px-5 py-2.5 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition font-medium flex items-center gap-2">
                <i class="fas fa-file-upload"></i>
                CSV Import
            </button>
            <?php
            $stmtNoPass = $db->prepare(
                "SELECT COUNT(*) FROM users WHERE (password IS NULL OR password = '') AND edition_id = ?"
            );
            $stmtNoPass->execute([$activeEditionId]);
            $countNoPass = $stmtNoPass->fetchColumn();
            ?>
            <?php if ($countNoPass > 0): ?>
            <a href="/api/generate-passwords-pdf.php" 
               class="px-5 py-2.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition font-medium flex items-center gap-2"
               title="Generiert Passwörter für <?php echo $countNoPass; ?> Nutzer ohne Passwort und erstellt ein PDF">
                <i class="fas fa-key mr-2"></i>
                Passwörter generieren &amp; PDF
                <span class="ml-1 bg-orange-700 text-white text-xs px-2 py-0.5 rounded-full">
                    <?php echo $countNoPass; ?>
                </span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (isAdmin() || hasPermission('benutzer_erstellen')): ?>
            <button onclick="openCreateUserModal()" class="px-5 py-2.5 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition font-medium flex items-center gap-2">
                <i class="fas fa-user-plus"></i>
                Neuer Benutzer
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Administratoren</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $stats['admins']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                    <i class="fas fa-user-shield text-purple-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Schüler</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $stats['students']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-user-graduate text-blue-500"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm mb-1">Lehrer</p>
                    <p class="text-2xl font-semibold text-gray-800"><?php echo $stats['teachers']; ?></p>
                </div>
                <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher text-emerald-500"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="userSearchInput" placeholder="Benutzer suchen (Name, Username, E-Mail, Klasse)..."
                   class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 transition-all"
                   onkeyup="filterUsers()">
        </div>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Benutzer</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Rolle</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Klasse</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Anmeldungen</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Erstellt</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                    <tr class="user-row hover:bg-gray-50 transition"
                        data-name="<?php echo strtolower($user['firstname'] . ' ' . $user['lastname']); ?>"
                        data-username="<?php echo strtolower($user['username']); ?>"
                        data-email="<?php echo strtolower($user['email'] ?? ''); ?>"
                        data-role="<?php echo strtolower($user['role']); ?>"
                        data-class="<?php echo strtolower($user['class'] ?? ''); ?>">
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <span class="font-bold text-sm text-blue-600">
                                        <?php echo strtoupper(substr($user['firstname'], 0, 1) . substr($user['lastname'], 0, 1)); ?>
                                    </span>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($user['username']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="inline-flex items-center whitespace-nowrap px-3 py-1 bg-purple-100 text-purple-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-user-shield mr-1"></i>Admin
                                </span>
                            <?php elseif ($user['role'] === 'teacher'): ?>
                                <span class="inline-flex items-center whitespace-nowrap px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-chalkboard-teacher mr-1"></i>Lehrer
                                </span>
                            <?php elseif ($user['role'] === 'orga'): ?>
                                <span class="inline-flex items-center whitespace-nowrap px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-users-cog mr-1"></i>Orga
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center whitespace-nowrap px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                    <i class="fas fa-user-graduate mr-1"></i>Schüler
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-gray-700">
                            <?php echo htmlspecialchars($user['class'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-gray-700 font-semibold"><?php echo $user['registration_count']; ?></span>
                        </td>
                        <td class="px-6 py-4 text-gray-600 text-sm">
                            <?php echo formatDate($user['created_at']); ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <?php 
                                // Nur Admins können Admin-Accounts bearbeiten
                                $canEdit = ($user['role'] !== 'admin') || isAdmin();
                                ?>
                                <?php if ($canEdit): ?>
                                    <?php if ($user['id'] !== $_SESSION['user_id'] && (isAdmin() || hasPermission('benutzer_bearbeiten'))): ?>
                                    <button onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode([
                                        'id' => $user['id'],
                                        'firstname' => $user['firstname'],
                                        'lastname' => $user['lastname'],
                                        'username' => $user['username'],
                                        'role' => $user['role'],
                                        'class' => $user['class'] ?? '',
                                    ]), ENT_QUOTES); ?>)"
                                            class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition text-sm font-medium">
                                        <i class="fas fa-edit mr-1"></i>Bearbeiten
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="openResetPasswordModal(<?php echo intval($user['id']); ?>, '<?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname'], ENT_QUOTES); ?>', '<?php echo $user['role']; ?>')"
                                            class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200 transition text-sm font-medium">
                                        <i class="fas fa-key mr-1"></i>Passwort
                                    </button>
                                    <?php if ($user['id'] !== $_SESSION['user_id'] && (isAdmin() || hasPermission('benutzer_loeschen'))): ?>
                                    <button onclick="confirmDeleteUser(<?php echo intval($user['id']); ?>, '<?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname'], ENT_QUOTES); ?>', '<?php echo $user['role']; ?>')"
                                            class="px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 transition text-sm font-medium">
                                        <i class="fas fa-trash mr-1"></i>Löschen
                                    </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm italic">
                                        <i class="fas fa-lock mr-1"></i>Nur für Admins
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div id="importCsvModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl">
            <h3 class="text-xl font-bold">Benutzer aus CSV importieren</h3>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-blue-900">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>CSV-Format (Semikolon-getrennt):</strong> <code class="bg-white px-1 rounded">firstname;lastname;username;email;role;class;password</code> (password optional)
                </p>
                <p class="text-sm text-blue-700 mt-2">
                    Rollen: <code class="bg-white px-2 py-1 rounded">student</code>, <code class="bg-white px-2 py-1 rounded">teacher</code>, <code class="bg-white px-2 py-1 rounded">orga</code>, <code class="bg-white px-2 py-1 rounded">admin</code>
                </p>
                <p class="text-sm text-blue-700 mt-2">
                    Wenn kein Passwort angegeben wird, wird der Nutzer <strong>ohne Passwort</strong> angelegt 
                    und kann sich noch nicht anmelden. Verwende den Button 
                    <em>"Passwörter generieren &amp; PDF"</em>, um Passwörter in einem Schritt zu erstellen.
                </p>
                <p class="text-sm text-blue-700 mt-3">
                    <a href="<?php echo BASE_URL; ?>example-users-import.csv" download class="text-blue-600 hover:text-blue-800 font-semibold">
                        <i class="fas fa-download mr-1"></i>Beispiel-CSV herunterladen
                    </a>
                </p>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">CSV-Datei *</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-500 transition">
                    <input type="file" id="csvFile" name="csv_file" accept=".csv,.txt" required class="hidden">
                    <label for="csvFile" class="cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-600">
                            <span class="text-blue-600 font-semibold">Klick zum Durchsuchen</span> oder Datei hierher ziehen
                        </p>
                        <p class="text-xs text-gray-500 mt-1">CSV oder TXT-Datei</p>
                    </label>
                </div>
                <p id="fileName" class="text-sm text-gray-600 mt-2"></p>
            </div>
            
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeImportCsvModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" name="import_csv" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-upload mr-2"></i>Importieren
                </button>
            </div>
        </form>
        
        <?php if ($csvImportResult): ?>
        <div class="bg-gray-50 border-t border-gray-200 p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <p class="text-sm text-gray-600">Importiert</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo $csvImportResult['imported']; ?></p>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <p class="text-sm text-gray-600">Übersprungen</p>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $csvImportResult['skipped']; ?></p>
                </div>
            </div>
            
            <?php if (!empty($csvImportResult['errors'])): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="font-semibold text-red-700 mb-2">Fehler:</p>
                <ul class="text-sm text-red-600 space-y-1">
                    <?php foreach (array_slice($csvImportResult['errors'], 0, 5) as $error): ?>
                    <li><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($csvImportResult['errors']) > 5): ?>
                    <li class="text-gray-500">... und <?php echo count($csvImportResult['errors']) - 5; ?> weitere Fehler</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($csvImportResult['generated_passwords'])): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="font-semibold text-blue-700 mb-2">
                    <i class="fas fa-key mr-2"></i><?php echo count($csvImportResult['generated_passwords']); ?> Passwörter generiert
                </p>
                <p class="text-sm text-blue-600 mb-3">Diese Benutzer müssen ihr Passwort beim ersten Login ändern.</p>
                <button type="button" onclick="downloadPasswordsFile()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition text-sm font-medium">
                    <i class="fas fa-download mr-2"></i>Passwörter herunterladen (CSV)
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl">
            <h3 class="text-xl font-bold">Neuen Benutzer erstellen</h3>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Vorname *</label>
                    <input type="text" name="firstname" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nachname *</label>
                    <input type="text" name="lastname" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Benutzername *</label>
                <input type="text" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">E-Mail</label>
                <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Passwort *</label>
                <input type="password" name="password" required minlength="6" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1">Mindestens 6 Zeichen</p>
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Rolle *</label>
                <select name="role" id="roleSelect" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" onchange="toggleClassField()">
                    <option value="student">Schüler</option>
                    <option value="teacher">Lehrer</option>
                    <option value="orga">Orga</option>
                    <?php if (isAdmin()): ?>
                    <option value="admin">Administrator</option>
                    <?php endif; ?>
                </select>
                <?php if (!isAdmin()): ?>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle mr-1"></i>Nur Administratoren können Admin-Accounts erstellen
                </p>
                <?php endif; ?>
            </div>
            
            <div id="classField">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Klasse</label>
                <input type="text" name="class" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeCreateUserModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" name="create_user" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-user-plus mr-2"></i>Erstellen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4">
        <div class="bg-gradient-to-r from-yellow-600 to-orange-600 text-white px-6 py-4 rounded-t-xl">
            <h3 class="text-xl font-bold">Passwort zurücksetzen</h3>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="user_id" id="resetUserId">
            
            <p class="text-gray-700">
                Passwort zurücksetzen für: <strong id="resetUserName"></strong>
            </p>
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Neues Passwort *</label>
                <input type="password" name="new_password" required minlength="6" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                <p class="text-xs text-gray-500 mt-1">Mindestens 6 Zeichen</p>
            </div>
            
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeResetPasswordModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" name="reset_password" class="px-6 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition">
                    <i class="fas fa-key mr-2"></i>Passwort zurücksetzen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4">
        <div class="bg-gradient-to-r from-red-600 to-pink-600 text-white px-6 py-4 rounded-t-xl">
            <h3 class="text-xl font-bold">Benutzer löschen</h3>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="user_id" id="deleteUserId">
            
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded">
                <p class="text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Möchtest Du den Benutzer <strong id="deleteUserName"></strong> wirklich löschen?
                </p>
                <p class="text-red-700 text-sm mt-2">
                    Diese Aktion kann nicht rückgängig gemacht werden. Alle Anmeldungen des Benutzers werden ebenfalls gelöscht.
                </p>
            </div>
            
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeDeleteUserModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" name="delete_user" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-trash mr-2"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full mx-4">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl">
            <h3 class="text-xl font-bold">Benutzer bearbeiten</h3>
        </div>

        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Vorname *</label>
                    <input type="text" name="edit_firstname" id="editFirstname" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nachname *</label>
                    <input type="text" name="edit_lastname" id="editLastname" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Benutzername *</label>
                <input type="text" name="edit_username" id="editUsername" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Rolle *</label>
                <select name="edit_role" id="editRole" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        onchange="toggleEditClassField()">
                    <option value="student">Schüler</option>
                    <option value="teacher">Lehrer</option>
                    <option value="orga">Orga</option>
                    <?php if (isAdmin()): ?>
                    <option value="admin">Administrator</option>
                    <?php endif; ?>
                </select>
                <?php if (!isAdmin()): ?>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle mr-1"></i>Nur Administratoren können die Admin-Rolle vergeben
                </p>
                <?php endif; ?>
            </div>

            <div id="editClassField">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Klasse</label>
                <input type="text" name="edit_class" id="editClass"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                <p class="text-sm text-amber-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    Änderungen an der Rolle wirken sich sofort auf die Zugriffsrechte des Benutzers aus.
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeEditUserModal()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" name="edit_user" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-save mr-2"></i>Speichern
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Handle file drag and drop for CSV upload
const csvFile = document.getElementById('csvFile');
const fileNameDisplay = document.getElementById('fileName');

if (csvFile) {
    csvFile.addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            fileNameDisplay.textContent = '✓ ' + e.target.files[0].name;
        }
    });
    
    // Drag and drop
    const dropArea = csvFile.parentElement.parentElement;
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        dropArea.addEventListener(eventName, () => {
            dropArea.classList.add('border-blue-500', 'bg-blue-50');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, () => {
            dropArea.classList.remove('border-blue-500', 'bg-blue-50');
        }, false);
    });
    
    dropArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        csvFile.files = files;
        
        if (files.length > 0) {
            fileNameDisplay.textContent = '✓ ' + files[0].name;
        }
    }
}

function openCreateUserModal() {
    document.getElementById('createUserModal').classList.remove('hidden');
    document.getElementById('createUserModal').classList.add('flex');
}

function closeCreateUserModal() {
    document.getElementById('createUserModal').classList.add('hidden');
    document.getElementById('createUserModal').classList.remove('flex');
}

function openImportCsvModal() {
    document.getElementById('importCsvModal').classList.remove('hidden');
    document.getElementById('importCsvModal').classList.add('flex');
}

function closeImportCsvModal() {
    document.getElementById('importCsvModal').classList.add('hidden');
    document.getElementById('importCsvModal').classList.remove('flex');
    document.getElementById('csvFile').value = '';
}

<?php if ($csvImportResult): ?>
// Auto-open modal to show import results
document.addEventListener('DOMContentLoaded', function() { openImportCsvModal(); });
<?php endif; ?>

function downloadPasswordsFile(passwordsData) {
    const passwords = <?php echo json_encode($csvImportResult['generated_passwords'] ?? []); ?>;
    if (passwords.length === 0) return;
    
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Benutzername,Vorname,Nachname,Passwort\n";
    
    passwords.forEach(user => {
        csvContent += `${user.username},${user.firstname},${user.lastname},${user.password}\n`;
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `berufsmesse_passwörter_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function openResetPasswordModal(userId, userName, userRole) {
    // Warnung für Admin-Accounts anzeigen
    if (userRole === 'admin') {
        if (!confirm('WARNUNG: Du änderst das Passwort eines Administrator-Accounts. Möchtest Du fortfahren?')) {
            return;
        }
    }
    
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetPasswordModal').classList.remove('hidden');
    document.getElementById('resetPasswordModal').classList.add('flex');
}

function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.add('hidden');
    document.getElementById('resetPasswordModal').classList.remove('flex');
}

function confirmDeleteUser(userId, userName, userRole) {
    // Extra Warnung für Admin-Accounts
    if (userRole === 'admin') {
        if (!confirm('WARNUNG: Du löschst einen Administrator-Account! Dies ist eine sensible Aktion. Bist Du absolut sicher?')) {
            return;
        }
    }
    
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteUserModal').classList.remove('hidden');
    document.getElementById('deleteUserModal').classList.add('flex');
}

function closeDeleteUserModal() {
    document.getElementById('deleteUserModal').classList.add('hidden');
    document.getElementById('deleteUserModal').classList.remove('flex');
}

function toggleClassField() {
    const roleSelect = document.getElementById('roleSelect');
    const classField = document.getElementById('classField');
    
    if (roleSelect.value === 'student') {
        classField.style.display = 'block';
    } else {
        classField.style.display = 'none';
    }
}

function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editFirstname').value = user.firstname;
    document.getElementById('editLastname').value = user.lastname;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editClass').value = user.class || '';
    toggleEditClassField();
    document.getElementById('editUserModal').classList.remove('hidden');
    document.getElementById('editUserModal').classList.add('flex');
}

function closeEditUserModal() {
    document.getElementById('editUserModal').classList.add('hidden');
    document.getElementById('editUserModal').classList.remove('flex');
}

function toggleEditClassField() {
    const role = document.getElementById('editRole').value;
    document.getElementById('editClassField').style.display = role === 'student' ? 'block' : 'none';
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCreateUserModal();
        closeResetPasswordModal();
        closeDeleteUserModal();
        closeImportCsvModal();
        closeEditUserModal();
    }
});

// Live Search für Benutzer
function filterUsers() {
    const searchTerm = document.getElementById('userSearchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.user-row');

    rows.forEach(row => {
        const name = row.getAttribute('data-name');
        const username = row.getAttribute('data-username');
        const email = row.getAttribute('data-email');
        const role = row.getAttribute('data-role');
        const userClass = row.getAttribute('data-class');

        const matchesSearch = name.includes(searchTerm) ||
                             username.includes(searchTerm) ||
                             email.includes(searchTerm) ||
                             role.includes(searchTerm) ||
                             userClass.includes(searchTerm);

        if (matchesSearch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>
