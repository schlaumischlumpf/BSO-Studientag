# Implementierungsprompt: Berufsmesse — Security-Fixes + Features

**Datum:** 25. Februar 2026  
**Scope:** Security-Audit-Fixes (K1–M6, N1) + Feature 3 (CSV/XLSX-Export) +
Feature 5 (Countdown) + Feature 7 (Admin-Ankündigungen) +
Feature 10 (Konfigurierbare Zeitslots) + Feature A/B (Dashboard-Charts) +
Feature C (Mehrjährigkeit / Messe-Editionen)

---

## Kontext

PHP/MySQL-Anwendung, kein Framework, plain PDO. Routing über `index.php?page=xyz`
mit `include`. Globale Funktionen in `functions.php`. Tailwind CDN. Fehler-Logging
via `logErrorToAudit($e, 'Kontext')`. Zugriffssteuerung via `isAdmin()` /
`hasPermission('key')`.

**Absolute Regeln (nie anfassen):**
- `compose.yaml`, `Dockerfile`, `.dockerignore`, `.github/`

---

## Übersicht: betroffene Dateien

### Neu erstellen
| Datei | Grund |
|---|---|
| `api/export-registrations.php` | Feature 3: CSV/XLSX-Export |
| `api/dashboard-stats.php` | Feature A/B: Chart-Daten-Endpunkt |
| `pages/admin-announcements.php` | Feature 7: Ankündigungs-CRUD |
| `pages/admin-editions.php` | Feature C: Editions-Verwaltung |
| `uploads/.htaccess` | Security M6: PHP-Ausführung sperren |

### Ändern
`functions.php` · `config.php` · `register.php` · `login.php` · `site-auth.php` ·
`index.php` · `setup.php` · `update_schema.sql` · `berufsmesse.sql` ·
`.htaccess` ·
`pages/admin-settings.php` · `pages/admin-dashboard.php` · `pages/admin-print.php` ·
`pages/registration.php` · `pages/dashboard.php` · `pages/teacher-dashboard.php` ·
`pages/admin-exhibitors.php` · `pages/admin-registrations.php` · `pages/admin-rooms.php` ·
`pages/admin-room-capacities.php` · `pages/admin-attendance.php` · `pages/admin-qr-codes.php` ·
`pages/admin-print-export.php` · `pages/exhibitors.php` · `pages/my-registrations.php` ·
`pages/schedule.php` · `pages/teacher-class-list.php` · `pages/qr-checkin.php` ·
`api/add-room.php` · `api/edit-room.php` · `api/delete-room.php` · `api/assign-room.php` ·
`api/clear-room-assignments.php` · `api/auto-assign.php` · `api/auto-assign-incomplete.php` ·
`api/generate-class-pdf.php` · `api/generate-personal-pdf.php` · `api/generate-room-pdf.php` ·
`api/generate-room-assignment-pdf.php` · `api/generate-absent-pdf.php` ·
`api/get-exhibitor.php` · `api/get-documents.php` · `api/download-document.php` ·
`api/manual-attendance.php` · `api/qr-checkin.php` · `api/qr-tokens.php` ·
`api/search-users.php`

---

## 1. `functions.php`

### 1a) DB-Verbindungsfehler generisch machen (Security M5)

```php
// ALT:
} catch(PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

// NEU:
} catch(PDOException $e) {
    error_log("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
    http_response_code(503);
    die("Dienst vorübergehend nicht verfügbar.");
}
```

### 1b) Neue Hilfsfunktionen: CSRF (Security K2)

Direkt nach `sanitize()` einfügen:

```php
// ─── CSRF ────────────────────────────────────────────────────────────────────

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Bricht mit HTTP 403 ab wenn der CSRF-Token fehlt oder ungültig ist.
 * Formulare:  $_POST['csrf_token']
 * JSON-APIs:  HTTP-Header 'X-CSRF-Token'
 */
function requireCsrf(): void {
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? null;
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage (CSRF).']);
        } else {
            echo 'Ungültige Anfrage (CSRF-Token fehlt oder abgelaufen).';
        }
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
```

### 1c) `uploadFile()` — Server-seitiger MIME-Check (Security H3)

Direkt nach der Extension-Prüfung, vor `$filename = uniqid() ...` einfügen:

```php
    // Server-seitiger MIME-Typ-Check
    $allowedMimes = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];
    if (function_exists('finfo_open')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $expected = $allowedMimes[$extension] ?? null;
        if ($expected === null || $mimeType !== $expected) {
            return ['success' => false, 'message' => 'Dateityp (MIME) nicht erlaubt.'];
        }
    }
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
        if (@getimagesize($file['tmp_name']) === false) {
            return ['success' => false, 'message' => 'Datei ist kein gültiges Bild.'];
        }
    }
```

### 1d) Neue Funktionen: Zeitslot-Helfer (Feature 10)

Direkt nach den beiden `define()`-Zeilen (`MANAGED_SLOTS_COUNT`, `DEFAULT_CAPACITY_DIVISOR`):

```php
/**
 * Gibt alle slot_number-Werte zurück, die als managed markiert sind.
 * Statischer Cache pro Request. Fallback auf [1,3,5] vor Migration.
 * @return int[]
 */
function getManagedSlotNumbers(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $db    = getDB();
        $stmt  = $db->query("SELECT slot_number FROM timeslots WHERE is_managed = 1 ORDER BY slot_number ASC");
        $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($cache)) return $cache;
    } catch (Exception $e) { /* Spalte noch nicht vorhanden */ }
    $cache = [1, 3, 5];
    return $cache;
}

function getManagedSlotCount(): int {
    return count(getManagedSlotNumbers());
}

/**
 * Gibt "IN (1,3,5)" zurück — nur Integer, SQL-Injection-sicher für direkte Einbettung.
 */
function getManagedSlotsSqlIn(): string {
    return 'IN (' . implode(',', getManagedSlotNumbers()) . ')';
}
```

### 1e) Funktion `getActiveAnnouncements()` (Feature 7)

Direkt nach `logAuditAction()`:

```php
/**
 * Lädt alle aktiven, nicht abgelaufenen Ankündigungen für die gegebene Rolle.
 * Gibt leeres Array zurück wenn die Tabelle noch nicht existiert.
 */
function getActiveAnnouncements(string $role): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT id, title, body, type, target_role
            FROM   announcements
            WHERE  is_active   = 1
              AND  (expires_at IS NULL OR expires_at > NOW())
              AND  (target_role = 'all' OR target_role = ?)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
```

### 1f) Mehrjährigkeit: Editions-Funktionen (Feature C)

Am Ende der Datei, vor dem schließenden `?>` (falls vorhanden):

```php
// ─── Messe-Editionen (Mehrjährigkeit) ────────────────────────────────────────

function getActiveEditionId(): int {
    if (isset($_SESSION['active_edition_id'])) {
        return (int)$_SESSION['active_edition_id'];
    }
    try {
        $db   = getDB();
        $stmt = $db->query("SELECT id FROM messe_editions WHERE status = 'active' LIMIT 1");
        $row  = $stmt->fetch();
        if ($row) {
            $_SESSION['active_edition_id'] = (int)$row['id'];
            return (int)$row['id'];
        }
    } catch (Exception $e) { /* Tabelle existiert noch nicht */ }
    return 1; // Fallback
}

function getActiveEdition(): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM messe_editions WHERE id = ?");
        $stmt->execute([getActiveEditionId()]);
        $row  = $stmt->fetch();
        if ($row) return $row;
    } catch (Exception $e) { }
    return [
        'id'                            => 1,
        'name'                          => 'Berufsmesse',
        'year'                          => (int)date('Y'),
        'status'                        => 'active',
        'registration_start'            => getSetting('registration_start'),
        'registration_end'              => getSetting('registration_end'),
        'event_date'                    => getSetting('event_date'),
        'max_registrations_per_student' => (int)getSetting('max_registrations_per_student', 3),
    ];
}

function invalidateEditionCache(): void {
    unset($_SESSION['active_edition_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
```

### 1g) `getSetting()` und `updateSetting()` editions-aware machen (Feature C)

Die bestehenden Funktionen **vollständig ersetzen**:

```php
function getSetting($key, $default = null) {
    $editionKeys = ['registration_start', 'registration_end', 'event_date', 'max_registrations_per_student'];
    if (in_array($key, $editionKeys)) {
        $edition = getActiveEdition();
        $val     = $edition[$key] ?? null;
        return $val !== null ? $val : $default;
    }
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function updateSetting($key, $value) {
    $editionKeys = ['registration_start', 'registration_end', 'event_date', 'max_registrations_per_student'];
    if (in_array($key, $editionKeys)) {
        $db   = getDB();
        $stmt = $db->prepare("UPDATE messe_editions SET `$key` = ? WHERE id = ?");
        return $stmt->execute([$value, getActiveEditionId()]);
    }
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}
```

---

## 2. `config.php`

### 2a) Session-Härtung (Security M2)

Den bestehenden `if (session_status() === PHP_SESSION_NONE)` Block **vollständig ersetzen**:

```php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.sid_length', 48);
    ini_set('session.sid_bits_per_character', 6);
    $cookieSecure = getenv('COOKIE_SECURE') ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    ini_set('session.cookie_secure', $cookieSecure);
    session_start();
}
```

### 2b) HTTP-Sicherheitsheader (Security M1)

Am Ende von `config.php` einfügen:

```php
// HTTP-Sicherheitsheader
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
```

---

## 3. `register.php`

### 3a) Rolle fixieren (Security K1)

```php
// ALT:
$role = $_POST['role'] ?? 'student';
// NEU:
$role = 'student'; // Öffentliche Registrierung: nur Student-Rolle erlaubt
```

### 3b) Benutzerliste entfernen (Security M3)

Folgenden Block **komplett löschen** (ca. Zeile 61–63):
```php
$db = getDB();
$stmt = $db->query("SELECT id, username, firstname, lastname, class, role, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
```

Ebenso den gesamten HTML-Ausgabeblock, der `$users` per `foreach` darstellt, **komplett entfernen**.

---

## 4. `login.php`

### 4a) Open-Redirect-Fix an beiden Stellen (Security H1)

Überall wo `strpos($redirect, '/') === 0` als Redirect-Prüfung steht, **ersetzen durch**:
```php
preg_match('#^/[^/]#', $redirect)
```

Konkret zwei Stellen:
1. Bereits-eingeloggt-Block am Anfang (`$redirect = $_GET['redirect'] ?? ''`)
2. Nach erfolgreichem Login (`$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '')`)

### 4b) Rate Limiting (Security K3)

Direkt nach `$error = '';` **vor** dem POST-Handler einfügen:

```php
function checkLoginAttempts(string $username, string $ip): bool {
    try {
        $db = getDB();
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (username = ? OR ip_address = ?)
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([$username, $ip]);
        return (int)$stmt->fetchColumn() < 10;
    } catch (Exception $e) {
        return true; // Im Fehlerfall nicht sperren
    }
}

function recordLoginAttempt(string $username, string $ip): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())");
        $stmt->execute([$username, $ip]);
    } catch (Exception $e) { }
}
```

Im POST-Handler, **nach** der `empty()`-Prüfung, **vor** dem DB-Lookup:
```php
    $clientIp = getClientIp();
    if (!checkLoginAttempts($username, $clientIp)) {
        $error = 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte 15 Minuten warten.';
    } else {
        // [gesamter bestehender DB-Lookup- und Session-Block hierher einrücken]
```

Im `else`-Zweig (Passwort falsch), **zusätzlich**:
```php
            recordLoginAttempt($username, $clientIp);
            logAuditAction('Login_Fehlgeschlagen', "Fehlgeschlagener Login für: $username", 'warning');
```

Geschlossene äußere `else`-Klammer der Rate-Limiting-Bedingung am Ende des POST-Blocks.

---

## 5. `site-auth.php`

### Open-Redirect-Fix an zwei Stellen (Security H1)

**Stelle 1** — Bereits-authentifiziert-Block (GET-Parameter ohne Prüfung):
```php
// ALT:
$redirect = $_GET['redirect'] ?? (BASE_URL . 'login.php');
header('Location: ' . $redirect);
exit();

// NEU:
$redirect = $_GET['redirect'] ?? '';
if (!empty($redirect) && preg_match('#^/[^/]#', $redirect)) {
    header('Location: ' . $redirect);
} else {
    header('Location: ' . BASE_URL . 'login.php');
}
exit();
```

**Stelle 2** — POST-Handler (strpos-Prüfung unzureichend):
```php
// ALT:
if (strpos($redirect, '/') !== 0 && strpos($redirect, BASE_URL) !== 0) {

// NEU:
if (!preg_match('#^/[^/]#', $redirect) && strpos($redirect, BASE_URL) !== 0) {
```

---

## 6. `index.php`

### 6a) Editions-Session-Init und `$activeEditionId` (Feature C)

Direkt nach den `require_once`-Zeilen ganz oben:
```php
// Editions-Session-Cache initialisieren
if (isLoggedIn() && !isset($_SESSION['active_edition_id'])) {
    getActiveEditionId();
}
```

Direkt **vor** dem `switch ($currentPage)` Block:
```php
$activeEditionId = getActiveEditionId();
```

### 6b) Ankündigungs-Rendering (Feature 7)

Im Content-Bereich direkt **vor** dem `switch ($currentPage)` Block (nach `$activeEditionId`):

```php
<?php
$_announcements = isLoggedIn() ? getActiveAnnouncements($_SESSION['role'] ?? 'student') : [];
if (!empty($_announcements)):
?>
<div id="announcementContainer" class="mb-4 space-y-2">
    <?php foreach ($_announcements as $_ann):
        $annColors = [
            'info'    => 'bg-blue-50 border-blue-200 text-blue-800',
            'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
            'success' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'error'   => 'bg-red-50 border-red-200 text-red-800',
        ];
        $annIcons = [
            'info'    => 'fa-info-circle text-blue-500',
            'warning' => 'fa-exclamation-triangle text-amber-500',
            'success' => 'fa-check-circle text-emerald-500',
            'error'   => 'fa-times-circle text-red-500',
        ];
        $colorClass = $annColors[$_ann['type']] ?? $annColors['info'];
        $iconClass  = $annIcons[$_ann['type']]  ?? $annIcons['info'];
    ?>
    <div class="announcement-banner flex items-start gap-3 px-4 py-3 rounded-xl border <?php echo $colorClass; ?>"
         data-announcement-id="<?php echo $_ann['id']; ?>">
        <i class="fas <?php echo $iconClass; ?> mt-0.5 flex-shrink-0"></i>
        <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm"><?php echo htmlspecialchars($_ann['title']); ?></p>
            <?php if (!empty($_ann['body'])): ?>
            <p class="text-xs mt-0.5 opacity-80"><?php echo nl2br(htmlspecialchars($_ann['body'])); ?></p>
            <?php endif; ?>
        </div>
        <button onclick="this.closest('.announcement-banner').remove()"
                class="flex-shrink-0 opacity-50 hover:opacity-100 transition text-lg leading-none"
                aria-label="Schließen">×</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
```

### 6c) Router-Cases (Features 7 und C)

In der `switch`-Anweisung bei den Admin-Cases ergänzen:

```php
case 'admin-announcements':
    if (isAdmin()) {
        include 'pages/admin-announcements.php';
        $pageLoaded = true;
    }
    break;

case 'admin-editions':
    if (isAdmin()) {
        include 'pages/admin-editions.php';
        $pageLoaded = true;
    }
    break;
```

### 6d) Navigations-Links (Features 7 und C)

In der Sidebar, in der „System"-Gruppe, vor `admin-settings`:

```php
<?php if (isAdmin()): ?>
<a href="<?php echo $currentPage === 'admin-editions' ? 'javascript:void(0)' : '?page=admin-editions'; ?>"
   data-page="admin-editions"
   class="nav-link <?php echo $currentPage === 'admin-editions' ? 'active' : ''; ?>">
    <i class="fas fa-layer-group"></i>
    <span>Messe-Editionen</span>
</a>
<?php endif; ?>
```

Nach `admin-settings`, vor `admin-audit-logs`:

```php
<?php if (isAdmin()): ?>
<a href="<?php echo $currentPage === 'admin-announcements' ? 'javascript:void(0)' : '?page=admin-announcements'; ?>"
   data-page="admin-announcements"
   class="nav-link <?php echo $currentPage === 'admin-announcements' ? 'active' : ''; ?>">
    <i class="fas fa-bullhorn"></i>
    <span>Ankündigungen</span>
    <?php
    try {
        $annCount = $db->query("SELECT COUNT(*) FROM announcements
            WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
        if ($annCount > 0): ?>
        <span class="ml-auto w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>
    <?php endif;
    } catch (Exception $e) { } ?>
</a>
<?php endif; ?>
```

### 6e) Editions-Indikator in der Sidebar (Feature C)

In der Sidebar, unterhalb des User-Info-Blocks, vor dem schließenden `</div>`:

```php
<?php if (isAdmin() || isTeacher()): ?>
    <?php $activeEd = getActiveEdition(); ?>
    <div class="px-3 py-2 mb-1 rounded-lg bg-emerald-50 border border-emerald-100 text-xs">
        <div class="text-emerald-600 font-semibold flex items-center gap-1">
            <i class="fas fa-calendar-check text-emerald-500"></i>
            Aktive Messe
        </div>
        <div class="text-gray-700 mt-0.5 truncate font-medium">
            <?php echo htmlspecialchars($activeEd['name']); ?>
        </div>
    </div>
<?php endif; ?>
```

---

## 7. `setup.php`

Alle folgenden Migrationen am Ende, **nach** Migration 11, **vor** dem HTML-Ausgabe-Block einfügen.

### Migration 12a: `messe_editions` Tabelle (Feature C)

```php
// Migration 12a: messe_editions Tabelle erstellen
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `messe_editions` (
        `id`                            INT(11)       NOT NULL AUTO_INCREMENT,
        `name`                          VARCHAR(150)  NOT NULL,
        `year`                          INT(4)        NOT NULL,
        `status`                        ENUM('active','archived') NOT NULL DEFAULT 'archived',
        `registration_start`            DATETIME      DEFAULT NULL,
        `registration_end`              DATETIME      DEFAULT NULL,
        `event_date`                    DATE          DEFAULT NULL,
        `max_registrations_per_student` INT(11)       NOT NULL DEFAULT 3,
        `created_at`                    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $count = $db->query("SELECT COUNT(*) FROM messe_editions")->fetchColumn();
    if ($count == 0) {
        $rs = $db->query("SELECT setting_key, setting_value FROM settings
                          WHERE setting_key IN ('registration_start','registration_end',
                                               'event_date','max_registrations_per_student')")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
        $year = !empty($rs['event_date']) ? (int)date('Y', strtotime($rs['event_date'])) : (int)date('Y');
        $stmt = $db->prepare("INSERT INTO messe_editions
            (name, year, status, registration_start, registration_end, event_date, max_registrations_per_student)
            VALUES (?, ?, 'active', ?, ?, ?, ?)");
        $stmt->execute([
            'Berufsmesse ' . $year, $year,
            $rs['registration_start'] ?? null,
            $rs['registration_end']   ?? null,
            $rs['event_date']         ?? null,
            (int)($rs['max_registrations_per_student'] ?? 3),
        ]);
        $success[] = "Erste Messe-Edition aus Einstellungen erstellt";
    } else {
        $success[] = "messe_editions existiert bereits";
    }
} catch (PDOException $e) {
    $errors[] = "Fehler Migration 12a: " . $e->getMessage();
}
```

### Migration 12b: `edition_id` zu Datentabellen (Feature C)

```php
// Migration 12b: edition_id zu Datentabellen hinzufügen
$editionTables = [
    'registrations', 'exhibitors', 'timeslots', 'rooms',
    'room_slot_capacities', 'attendance', 'qr_tokens',
    'exhibitor_documents', 'exhibitor_orga_team'
];
foreach ($editionTables as $tbl) {
    try {
        $cols = $db->query("SHOW COLUMNS FROM `$tbl` LIKE 'edition_id'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `$tbl`
                ADD COLUMN `edition_id` INT(11) NOT NULL DEFAULT 1
                    COMMENT 'Zugehörige Messe-Edition',
                ADD KEY `idx_edition_id` (`edition_id`)");
            $success[] = "edition_id zu $tbl hinzugefügt";
        } else {
            $success[] = "edition_id in $tbl bereits vorhanden";
        }
    } catch (PDOException $e) {
        $errors[] = "Fehler Migration 12b ($tbl): " . $e->getMessage();
    }
}
```

### Migration 13: `announcements` Tabelle (Feature 7)

```php
// Migration 13: announcements Tabelle erstellen
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `announcements` (
        `id`          INT(11)      NOT NULL AUTO_INCREMENT,
        `title`       VARCHAR(200) NOT NULL,
        `body`        TEXT         NOT NULL,
        `type`        ENUM('info','warning','success','error') NOT NULL DEFAULT 'info',
        `target_role` ENUM('all','student','teacher','admin')  NOT NULL DEFAULT 'all',
        `expires_at`  DATETIME     DEFAULT NULL,
        `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by`  INT(11)      NOT NULL,
        `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_active_role` (`is_active`, `target_role`),
        KEY `idx_expires_at`  (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $success[] = "Tabelle announcements OK";
} catch (PDOException $e) {
    $errors[] = "Fehler Migration 13 (announcements): " . $e->getMessage();
}
```

### Migration 14: `timeslots.is_managed` (Feature 10)

```php
// Migration 14: timeslots.is_managed Spalte
try {
    $cols = $db->query("SHOW COLUMNS FROM `timeslots` LIKE 'is_managed'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE `timeslots`
            ADD COLUMN `is_managed` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT '1 = fester Zuteilungs-Slot, 0 = freie Wahl'");
        $db->exec("UPDATE `timeslots` SET is_managed = 1 WHERE slot_number IN (1, 3, 5)");
        $success[] = "is_managed zu timeslots hinzugefügt, Slots 1/3/5 markiert";
    } else {
        $success[] = "is_managed in timeslots bereits vorhanden";
    }
} catch (PDOException $e) {
    $errors[] = "Fehler Migration 14 (timeslots.is_managed): " . $e->getMessage();
}
```

### Migration 15: `login_attempts` Tabelle (Security K3)

```php
// Migration 15: login_attempts Tabelle für Rate Limiting
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id`           INT(11)      NOT NULL AUTO_INCREMENT,
        `username`     VARCHAR(100) NOT NULL,
        `ip_address`   VARCHAR(45)  NOT NULL,
        `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_username`  (`username`),
        KEY `idx_ip`        (`ip_address`),
        KEY `idx_attempted` (`attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $success[] = "Tabelle login_attempts OK";
} catch (PDOException $e) {
    $errors[] = "Fehler Migration 15 (login_attempts): " . $e->getMessage();
}
```

---

## 8. `update_schema.sql`

Am Ende der Datei (vor `COMMIT`) einfügen:

```sql
-- ============================================================================
-- Migration 12: Mehrjährigkeit
-- ============================================================================

CREATE TABLE IF NOT EXISTS `messe_editions` (
  `id`                            INT(11)       NOT NULL AUTO_INCREMENT,
  `name`                          VARCHAR(150)  NOT NULL,
  `year`                          INT(4)        NOT NULL,
  `status`                        ENUM('active','archived') NOT NULL DEFAULT 'archived',
  `registration_start`            DATETIME      DEFAULT NULL,
  `registration_end`              DATETIME      DEFAULT NULL,
  `event_date`                    DATE          DEFAULT NULL,
  `max_registrations_per_student` INT(11)       NOT NULL DEFAULT 3,
  `created_at`                    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `messe_editions` (name, year, status, registration_start, registration_end, event_date, max_registrations_per_student)
SELECT
  CONCAT('Berufsmesse ', YEAR(COALESCE((SELECT setting_value FROM settings WHERE setting_key='event_date' LIMIT 1), NOW()))),
  YEAR(COALESCE((SELECT setting_value FROM settings WHERE setting_key='event_date' LIMIT 1), NOW())),
  'active',
  (SELECT setting_value FROM settings WHERE setting_key='registration_start' LIMIT 1),
  (SELECT setting_value FROM settings WHERE setting_key='registration_end' LIMIT 1),
  (SELECT setting_value FROM settings WHERE setting_key='event_date' LIMIT 1),
  COALESCE((SELECT setting_value FROM settings WHERE setting_key='max_registrations_per_student' LIMIT 1), 3)
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM messe_editions LIMIT 1);

DROP PROCEDURE IF EXISTS add_edition_id;
DELIMITER //
CREATE PROCEDURE add_edition_id(IN p_table VARCHAR(64))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME='edition_id') THEN
        SET @s = CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `edition_id` INT(11) NOT NULL DEFAULT 1, ADD KEY `idx_edition_id` (`edition_id`)');
        PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_edition_id('registrations'); CALL add_edition_id('exhibitors');
CALL add_edition_id('timeslots');     CALL add_edition_id('rooms');
CALL add_edition_id('room_slot_capacities'); CALL add_edition_id('attendance');
CALL add_edition_id('qr_tokens');    CALL add_edition_id('exhibitor_documents');
CALL add_edition_id('exhibitor_orga_team');
DROP PROCEDURE IF EXISTS add_edition_id;

-- Migration 13: announcements
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL, `type` ENUM('info','warning','success','error') NOT NULL DEFAULT 'info',
  `target_role` ENUM('all','student','teacher','admin') NOT NULL DEFAULT 'all',
  `expires_at` DATETIME DEFAULT NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_active_role` (`is_active`,`target_role`), KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 14: timeslots.is_managed
CALL add_column_if_not_exists('timeslots', 'is_managed',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fester Zuteilungs-Slot'");
UPDATE `timeslots` SET is_managed = 1 WHERE slot_number IN (1,3,5) AND is_managed = 0;

-- Migration 15: login_attempts
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT, `username` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL, `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_username` (`username`), KEY `idx_ip` (`ip_address`), KEY `idx_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 9. `berufsmesse.sql`

- `CREATE TABLE messe_editions` einfügen (vollständige Definition aus Migration 12a)
- `CREATE TABLE announcements` einfügen (vollständige Definition aus Migration 13)
- `CREATE TABLE login_attempts` einfügen (vollständige Definition aus Migration 15)
- In allen `CREATE TABLE`-Statements für `registrations`, `exhibitors`, `timeslots`,
  `rooms`, `room_slot_capacities`, `attendance`, `qr_tokens`, `exhibitor_documents`,
  `exhibitor_orga_team`: Spalte `edition_id INT(11) NOT NULL DEFAULT 1` und
  `KEY idx_edition_id (edition_id)` ergänzen
- In `CREATE TABLE timeslots`: Spalte `is_managed TINYINT(1) NOT NULL DEFAULT 0` ergänzen
- In den INSERT-Statements für `timeslots`: Slots 1, 3, 5 bekommen `is_managed = 1`

---

## 10. `.htaccess` (Wurzelverzeichnis) — Security N1

Nach dem `<Files "database.sql">` Block einfügen:

```apache
<Files "berufsmesse.sql">
    Order Allow,Deny
    Deny from all
</Files>

<Files "migration.sql">
    Order Allow,Deny
    Deny from all
</Files>

<Files "update_schema.sql">
    Order Allow,Deny
    Deny from all
</Files>

<Files "compose.yaml">
    Order Allow,Deny
    Deny from all
</Files>
```

---

## 11. Neue Datei `uploads/.htaccess` — Security M6

```apache
php_flag engine off

<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phps|phar)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

Options -Indexes
```

---

## 12. Hardcodierte Slot-Referenzen ersetzen (Feature 10)

In allen folgenden Dateien jede Stelle mit `IN (1, 3, 5)` / `IN (1,3,5)` /
`[1, 3, 5]` / `MANAGED_SLOTS_COUNT` auf die neuen Funktionen umstellen.

| Datei | Was ersetzen |
|---|---|
| `functions.php` | `IN (1, 3, 5)` → `" . getManagedSlotsSqlIn()` (1 Stelle) |
| `index.php` | `$managedSlots = [1,3,5]` → `getManagedSlotNumbers()`, alle `IN (1,3,5)` → `getManagedSlotsSqlIn()`, `MANAGED_SLOTS_COUNT` → `getManagedSlotCount()` (4–5 Stellen) |
| `api/auto-assign.php` | `$managedSlots = [1,3,5]` und alle `IN` / `MANAGED_SLOTS_COUNT` (4 Stellen) |
| `api/auto-assign-incomplete.php` | identisch zu `auto-assign.php` (5 Stellen) |
| `api/generate-class-pdf.php` | 1 Stelle `IN (1,3,5)` |
| `pages/registration.php` | 1 Stelle `IN (1,3,5)` |
| `pages/dashboard.php` | 1 Stelle `IN (1,3,5)` |
| `pages/admin-dashboard.php` | JS: `in_array($s['slot_number'], [1,3,5])` → `in_array($s['slot_number'], <?php echo json_encode(getManagedSlotNumbers()); ?>)` |
| `pages/admin-attendance.php` | 1 Stelle |
| `pages/teacher-dashboard.php` | 2 Stellen |
| `pages/teacher-class-list.php` | `$managedSlots = [1,3,5]` + 1 `IN`-Query |

---

## 13. `edition_id`-Filter in Queries (Feature C)

### Prinzip

In **jeder** Datei, die editions-gebundene Tabellen abfragt:
1. Am Anfang der Datei (nach `requireLogin()`) sicherstellen dass `$activeEditionId` verfügbar ist.
   - Page-Dateien erben es aus `index.php` (bereits gesetzt in Schritt 6a)
   - API-Dateien, die direkt aufgerufen werden: `$activeEditionId = getActiveEditionId();` oben einfügen
2. Alle `SELECT`-Queries um `AND <tabelle>.edition_id = $activeEditionId` (bzw. `:editionId`) erweitern
3. Alle `INSERT`-Statements in editions-gebundene Tabellen um `edition_id = $activeEditionId` erweitern

### Betroffene Dateien nach Tabellen

| Datei | Tabellen |
|---|---|
| `pages/admin-exhibitors.php` | `exhibitors` |
| `pages/admin-registrations.php` | `registrations`, `exhibitors`, `timeslots` |
| `pages/admin-rooms.php` | `rooms`, `room_slot_capacities`, `exhibitors` |
| `pages/admin-room-capacities.php` | `rooms`, `room_slot_capacities`, `timeslots` |
| `pages/admin-attendance.php` | `attendance`, `exhibitors`, `timeslots` |
| `pages/admin-qr-codes.php` | `qr_tokens`, `exhibitors`, `timeslots` |
| `pages/admin-print.php` | `registrations`, `exhibitors`, `timeslots`, `rooms` |
| `pages/admin-print-export.php` | wie admin-print |
| `pages/exhibitors.php` | `exhibitors` |
| `pages/registration.php` | `exhibitors`, `registrations`, `timeslots` |
| `pages/my-registrations.php` | `registrations`, `exhibitors`, `timeslots` |
| `pages/schedule.php` | `registrations`, `exhibitors`, `timeslots` |
| `pages/teacher-class-list.php` | `registrations`, `users`, `exhibitors` |
| `pages/teacher-dashboard.php` | `registrations`, `exhibitors` |
| `pages/qr-checkin.php` | `qr_tokens`, `attendance` |
| `api/add-room.php` | INSERT in `rooms` → `edition_id = $activeEditionId` |
| `api/assign-room.php` | `exhibitors` |
| `api/auto-assign.php` | `registrations`, `exhibitors`, `timeslots`, `rooms` |
| `api/auto-assign-incomplete.php` | wie auto-assign |
| `api/clear-room-assignments.php` | `exhibitors` |
| `api/delete-room.php` | `rooms`, `room_slot_capacities` |
| `api/edit-room.php` | `rooms` |
| `api/generate-absent-pdf.php` | `registrations`, `timeslots` |
| `api/generate-class-pdf.php` | `registrations`, `exhibitors`, `timeslots` |
| `api/generate-personal-pdf.php` | `registrations`, `exhibitors`, `timeslots` |
| `api/generate-room-assignment-pdf.php` | `rooms`, `exhibitors`, `timeslots` |
| `api/generate-room-pdf.php` | `rooms`, `exhibitors`, `timeslots` |
| `api/get-exhibitor.php` | `exhibitors` |
| `api/manual-attendance.php` | INSERT in `attendance`, `registrations` |
| `api/qr-checkin.php` | `qr_tokens`, INSERT in `attendance` |
| `api/qr-tokens.php` | `qr_tokens`, `exhibitors`, `timeslots` |
| `api/search-users.php` | `registrations` |

---

## 14. Generische Fehlermeldungen in API-Dateien (Security H2/M5)

In **allen** `api/*.php`-Dateien jedes `catch`-Block anpassen.

**Muster für JSON-APIs:**
```php
} catch (Exception $e) {
    logErrorToAudit($e, 'API-<Kurzname>');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
```

**Muster für PDF/Download-Endpunkte:**
```php
} catch (Exception $e) {
    logErrorToAudit($e, 'API-<Kurzname>');
    if (!headers_sent()) http_response_code(500);
    die('Fehler bei der Verarbeitung.');
}
```

Alle `catch (PDOException $e)` auf `catch (Exception $e)` erweitern.
`$e->getMessage()` darf **nie** in der Antwort an den Browser erscheinen.

---

## 15. `api/qr-tokens.php` — Token-Länge (Security H5)

```php
// ALT:
$token = bin2hex(random_bytes(3)); // 6 Zeichen
// NEU:
$token = bin2hex(random_bytes(16)); // 32 Zeichen = 3.4×10^38 Möglichkeiten
```

Alle weiteren Stellen in dieser Datei (z.B. Batch-Generate) ebenfalls auf `random_bytes(16)`.

---

## 16. Feature 5: Countdown in `pages/registration.php`, `pages/dashboard.php`, `pages/teacher-dashboard.php`

### Gemeinsamer PHP-Daten-Block (in jede der drei Dateien ganz oben im HTML-Ausgabebereich)

```php
<script>
const REG_START  = "<?php echo htmlspecialchars(getSetting('registration_start', '')); ?>";
const REG_END    = "<?php echo htmlspecialchars(getSetting('registration_end', '')); ?>";
const REG_STATUS = "<?php echo getRegistrationStatus(); ?>";
</script>
```

### Gemeinsame JS-Funktion (im `<script>`-Block am Seitenende)

```javascript
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
```

### `pages/registration.php` — Countdown-Banner

Direkt nach dem öffnenden Haupt-Container-`<div>`, vor den Aussteller-Karten:

```php
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
```

Im `<script>`-Block am Ende:
```javascript
if (REG_STATUS === 'open')     startCountdown(REG_END,   'regCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'regCountdownValue');
```

### `pages/dashboard.php` — Countdown in bestehendem Status-Badge

Im `open`-Block den `<p>`-Tag ersetzen:
```php
<!-- ALT: -->
<p class="text-xs text-green-600">bis <?php echo formatDateTime($regEnd); ?></p>
<!-- NEU: -->
<p class="text-xs text-green-600">
    noch <span id="dashCountdownValue" class="font-semibold tabular-nums">…</span>
    · bis <?php echo formatDateTime($regEnd); ?>
</p>
```

Im `upcoming`-Block analog (Element-ID `dashCountdownValue`).

Im `<script>`-Block:
```javascript
if (REG_STATUS === 'open')          startCountdown(REG_END,   'dashCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'dashCountdownValue');
```

### `pages/teacher-dashboard.php` — Kompakter Countdown-Block

Sicherstellen dass `$regStatus`, `$regStart`, `$regEnd` am PHP-Anfang geladen sind:
```php
$regStatus = getRegistrationStatus();
$regStart  = getSetting('registration_start');
$regEnd    = getSetting('registration_end');
```

Vor der ersten Tabelle/Kachel einfügen:
```php
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
```

Im `<script>`-Block:
```javascript
if (REG_STATUS === 'open')          startCountdown(REG_END,   'teacherCountdownValue');
else if (REG_STATUS === 'upcoming') startCountdown(REG_START, 'teacherCountdownValue');
```

---

## 17. Feature 10: Zeitslot-Verwaltung in `pages/admin-settings.php`

### POST-Handler (nach bestehenden POST-Handlern, vor HTML-Ausgabe)

```php
// Zeitslot bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_timeslot'])) {
    if (!isAdmin() && !hasPermission('einstellungen_bearbeiten')) die('Keine Berechtigung');
    $slotId    = intval($_POST['slot_id']);
    $slotName  = trim($_POST['slot_name']);
    $startTime = trim($_POST['start_time']);
    $endTime   = trim($_POST['end_time']);
    $isManaged = isset($_POST['is_managed']) ? 1 : 0;
    if (empty($slotName)) {
        $message = ['type' => 'error', 'text' => 'Slot-Name darf nicht leer sein.'];
    } else {
        $db->prepare("UPDATE timeslots SET slot_name=?, start_time=?, end_time=?, is_managed=? WHERE id=?")
           ->execute([$slotName, $startTime ?: null, $endTime ?: null, $isManaged, $slotId]);
        logAuditAction('timeslot_bearbeitet', "Slot #$slotId: '$slotName', managed=$isManaged", 'warning');
        $message = ['type' => 'success', 'text' => 'Zeitslot gespeichert.'];
    }
}

// Zeitslot hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_timeslot'])) {
    if (!isAdmin()) die('Keine Berechtigung');
    $slotName  = trim($_POST['new_slot_name']);
    $startTime = trim($_POST['new_start_time']);
    $endTime   = trim($_POST['new_end_time']);
    $isManaged = isset($_POST['new_is_managed']) ? 1 : 0;
    if (empty($slotName)) {
        $message = ['type' => 'error', 'text' => 'Slot-Name darf nicht leer sein.'];
    } else {
        $maxNum = (int)$db->query("SELECT COALESCE(MAX(slot_number),0) FROM timeslots")->fetchColumn();
        $db->prepare("INSERT INTO timeslots (slot_number,slot_name,start_time,end_time,is_managed) VALUES (?,?,?,?,?)")
           ->execute([$maxNum + 1, $slotName, $startTime ?: null, $endTime ?: null, $isManaged]);
        logAuditAction('timeslot_erstellt', "Neuer Slot '$slotName'");
        $message = ['type' => 'success', 'text' => 'Zeitslot hinzugefügt.'];
    }
}

// Zeitslot löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_timeslot'])) {
    if (!isAdmin()) die('Keine Berechtigung');
    $slotId = intval($_POST['slot_id']);
    $stmtCheck = $db->prepare("
        SELECT (SELECT COUNT(*) FROM registrations WHERE timeslot_id=?) +
               (SELECT COUNT(*) FROM attendance    WHERE timeslot_id=?) +
               (SELECT COUNT(*) FROM qr_tokens     WHERE timeslot_id=?) AS total
    ");
    $stmtCheck->execute([$slotId, $slotId, $slotId]);
    $usageCount = (int)$stmtCheck->fetchColumn();
    if ($usageCount > 0) {
        $message = ['type' => 'error', 'text' => "Slot kann nicht gelöscht werden – $usageCount verknüpfte Einträge."];
    } else {
        $db->prepare("DELETE FROM timeslots WHERE id=?")->execute([$slotId]);
        logAuditAction('timeslot_geloescht', "Slot #$slotId gelöscht", 'warning');
        $message = ['type' => 'success', 'text' => 'Zeitslot gelöscht.'];
    }
}
```

### Daten laden (nach POST-Handlern)

```php
$allTimeslots = $db->query("SELECT * FROM timeslots ORDER BY slot_number ASC")->fetchAll();
```

### Neuer Tab in Tab-Navigation

```html
<button onclick="switchSettingsTab('zeitslots')" data-tab="zeitslots"
        class="settings-tab flex items-center gap-2 px-4 py-3 text-sm font-medium
               whitespace-nowrap border-b-2 border-transparent text-gray-500
               hover:text-gray-700 hover:bg-gray-50 transition-all">
    <i class="fas fa-clock"></i> Zeitslots
</button>
```

### Tab-Content (nach letztem bestehenden Tab-Content-`<div>`)

```html
<div id="tab-zeitslots" class="settings-tab-content hidden p-4 sm:p-6">

    <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        <strong>Achtung:</strong> Änderungen wirken sich sofort auf Anmeldungen, Raumzuteilung
        und automatische Zuteilung aus. Slots mit Anmeldungen können nicht gelöscht werden.
    </div>

    <h4 class="text-sm font-semibold text-gray-800 mb-3">Bestehende Zeitslots</h4>
    <div class="space-y-2 mb-6">
        <?php foreach ($allTimeslots as $slot): ?>
        <form method="POST" class="bg-gray-50 border border-gray-200 rounded-xl p-4">
            <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
                    <input type="text" name="slot_name"
                           value="<?php echo htmlspecialchars($slot['slot_name']); ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Von</label>
                    <input type="time" name="start_time"
                           value="<?php echo substr($slot['start_time'] ?? '', 0, 5); ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Bis</label>
                    <input type="time" name="end_time"
                           value="<?php echo substr($slot['end_time'] ?? '', 0, 5); ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white">
                </div>
                <div class="sm:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_managed" value="1"
                               <?php echo $slot['is_managed'] ? 'checked' : ''; ?>
                               class="w-4 h-4 text-emerald-500 rounded">
                        <span class="text-xs text-gray-700 font-medium">Feste Zuteilung (Managed)</span>
                    </label>
                </div>
                <div class="flex gap-2 sm:col-span-2 justify-end">
                    <?php if (isAdmin() || hasPermission('einstellungen_bearbeiten')): ?>
                    <button type="submit" name="save_timeslot"
                            class="px-3 py-2 bg-emerald-500 text-white text-xs rounded-lg hover:bg-emerald-600 transition font-medium">
                        <i class="fas fa-save mr-1"></i>Speichern
                    </button>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <button type="submit" name="delete_timeslot"
                            onclick="return confirm('Zeitslot wirklich löschen?')"
                            class="px-3 py-2 bg-red-50 border border-red-200 text-red-600 text-xs rounded-lg hover:bg-red-100 transition font-medium">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php endforeach; ?>
    </div>

    <?php if (isAdmin()): ?>
    <h4 class="text-sm font-semibold text-gray-800 mb-3">Neuen Zeitslot hinzufügen</h4>
    <form method="POST" class="bg-blue-50 border border-blue-100 rounded-xl p-4">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Name *</label>
                <input type="text" name="new_slot_name" required placeholder="z.B. Slot 6"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Von</label>
                <input type="time" name="new_start_time" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Bis</label>
                <input type="time" name="new_end_time" class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-white">
            </div>
            <div class="sm:col-span-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="new_is_managed" value="1" class="w-4 h-4 text-blue-500 rounded">
                    <span class="text-xs text-gray-700 font-medium">Feste Zuteilung</span>
                </label>
            </div>
            <div>
                <button type="submit" name="add_timeslot"
                        class="w-full px-4 py-2 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600 transition font-medium">
                    <i class="fas fa-plus mr-1"></i>Hinzufügen
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

</div><!-- /tab-zeitslots -->
```

---

## 18. Feature 3: Export-Sektion in `pages/admin-print.php`

Nach dem schließenden `</div>` der Filter-Sektion einfügen:

```php
<?php if (isAdmin() || hasPermission('berichte_sehen')): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
        <i class="fas fa-download text-gray-400"></i> Datenexport
    </h2>
    <p class="text-xs text-gray-500 mb-4">
        Exportiert die aktuellen Daten. Klassenfilter wird automatisch übernommen.
    </p>
    <div class="flex flex-wrap gap-3">
        <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=registrations&class=<?php echo urlencode($filterClass ?? ''); ?>"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-50 border border-blue-200 text-blue-700 font-medium text-sm rounded-lg hover:bg-blue-100 transition">
            <i class="fas fa-file-csv"></i> Anmeldungen (CSV)
        </a>
        <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=xlsx&type=registrations&class=<?php echo urlencode($filterClass ?? ''); ?>"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 font-medium text-sm rounded-lg hover:bg-green-100 transition">
            <i class="fas fa-file-excel"></i> Anmeldungen (Excel)
        </a>
        <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=attendance&class=<?php echo urlencode($filterClass ?? ''); ?>"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-purple-50 border border-purple-200 text-purple-700 font-medium text-sm rounded-lg hover:bg-purple-100 transition">
            <i class="fas fa-user-check"></i> Check-ins (CSV)
        </a>
        <a href="<?php echo BASE_URL; ?>api/export-registrations.php?format=csv&type=unregistered"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-red-50 border border-red-200 text-red-700 font-medium text-sm rounded-lg hover:bg-red-100 transition">
            <i class="fas fa-user-times"></i> Ohne Anmeldung (CSV)
        </a>
    </div>
</div>
<?php endif; ?>
```

---

## 19. Feature A/B: Dashboard-Charts in `pages/admin-dashboard.php`

### Chart.js laden (ganz oben in der Datei)

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
```

### Zwei neue Tab-Buttons (nach bestehendem `tab-registrations`-Button)

```html
<button onclick="switchTab('timeline')" id="tab-timeline"
    class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent
           text-gray-500 hover:text-gray-700 hover:border-gray-300 transition">
    <i class="fas fa-chart-line mr-2"></i>Registrierungs-Timeline
</button>
<button onclick="switchTab('classes')" id="tab-classes"
    class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent
           text-gray-500 hover:text-gray-700 hover:border-gray-300 transition">
    <i class="fas fa-users mr-2"></i>Klassenbeteiligung
</button>
```

### Tab-Content-Divs (nach letztem bestehenden Tab-Content-`<div>`)

```html
<div id="tab-content-timeline" class="tab-content p-6 hidden">
    <h3 class="text-base font-semibold text-gray-800 mb-1 flex items-center">
        <i class="fas fa-chart-line text-blue-500 mr-2"></i>Registrierungs-Timeline
    </h3>
    <p class="text-xs text-gray-500 mb-4">Einschreibungen pro Tag – manuell vs. automatisch</p>
    <div class="relative" style="height: 280px;">
        <canvas id="timelineChart"></canvas>
        <div id="timelineLoading" class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-80">
            <i class="fas fa-spinner fa-spin text-gray-400 text-2xl"></i>
        </div>
    </div>
</div>

<div id="tab-content-classes" class="tab-content p-6 hidden">
    <h3 class="text-base font-semibold text-gray-800 mb-1 flex items-center">
        <i class="fas fa-users text-purple-500 mr-2"></i>Klassenbeteiligung
    </h3>
    <p class="text-xs text-gray-500 mb-4">Anteil angemeldeter Schüler je Klasse – sortiert nach Beteiligung</p>
    <div class="relative" style="height: 320px;">
        <canvas id="classesChart"></canvas>
        <div id="classesLoading" class="absolute inset-0 flex items-center justify-center bg-white bg-opacity-80">
            <i class="fas fa-spinner fa-spin text-gray-400 text-2xl"></i>
        </div>
    </div>
</div>
```

### JS in bestehende `switchTab()`-Funktion integrieren (am Ende der Funktion, **nicht** überschreiben)

```javascript
let statsLoaded = false;
function loadDashboardStats() {
    if (statsLoaded) return;
    statsLoaded = true;
    fetch('api/dashboard-stats.php')
        .then(r => r.json())
        .then(data => {
            renderTimelineChart(data.timeline || []);
            renderClassesChart(data.classes || []);
        })
        .catch(() => {
            document.getElementById('timelineLoading').innerHTML = '<span class="text-red-400 text-sm">Fehler beim Laden</span>';
            document.getElementById('classesLoading').innerHTML  = '<span class="text-red-400 text-sm">Fehler beim Laden</span>';
        });
}

function renderTimelineChart(data) {
    document.getElementById('timelineLoading').classList.add('hidden');
    new Chart(document.getElementById('timelineChart'), {
        type: 'line',
        data: {
            labels: data.map(d => d.day),
            datasets: [
                { label: 'Manuell',      data: data.map(d => d.manual), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3, pointRadius: 3 },
                { label: 'Automatisch',  data: data.map(d => d.auto),   borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.1)',  fill: true, tension: 0.3, pointRadius: 3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top' } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
}

function renderClassesChart(data) {
    document.getElementById('classesLoading').classList.add('hidden');
    data.sort((a, b) => a.rate - b.rate);
    new Chart(document.getElementById('classesChart'), {
        type: 'bar',
        data: {
            labels: data.map(d => d.class),
            datasets: [{ label: 'Beteiligung (%)', data: data.map(d => d.rate), backgroundColor: data.map(d => d.rate >= 80 ? '#10b981' : d.rate >= 50 ? '#f59e0b' : '#ef4444'), borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => { const d = data[ctx.dataIndex]; return ` ${d.registered} / ${d.total} Schüler (${d.rate}%)`; } } } }, scales: { x: { min: 0, max: 100, ticks: { callback: v => v + '%' } }, y: { grid: { display: false } } } }
    });
}
```

Am **Ende** der bestehenden `switchTab()`-Funktion (letzter Statement vor der schließenden `}`):
```javascript
    if (tabName === 'timeline' || tabName === 'classes') {
        loadDashboardStats();
    }
```

---

## 20. Neue Datei `api/export-registrations.php` (Feature 3)

```php
<?php
require_once '../config.php';
require_once '../functions.php';

requireLogin();
if (!isAdmin() && !hasPermission('berichte_sehen')) {
    http_response_code(403); die('Keine Berechtigung');
}

try {
    $db              = getDB();
    $activeEditionId = getActiveEditionId();

    $format      = in_array($_GET['format'] ?? 'csv', ['csv', 'xlsx']) ? $_GET['format'] : 'csv';
    $type        = in_array($_GET['type']   ?? 'registrations', ['registrations','attendance','unregistered'])
                   ? $_GET['type'] : 'registrations';
    $filterClass = trim($_GET['class']        ?? '');
    $filterEid   = intval($_GET['exhibitor_id'] ?? 0);
    $filterTid   = intval($_GET['timeslot_id']  ?? 0);

    if ($type === 'registrations') {
        $sql = "SELECT u.lastname, u.firstname, u.class,
                       e.name AS exhibitor_name, t.slot_name, t.start_time, t.end_time,
                       r.room_number, reg.registration_type, reg.registered_at
                FROM registrations reg
                JOIN users u      ON reg.user_id      = u.id
                JOIN exhibitors e ON reg.exhibitor_id = e.id  AND e.edition_id = :eid
                JOIN timeslots t  ON reg.timeslot_id  = t.id  AND t.edition_id = :eid
                LEFT JOIN rooms r ON e.room_id         = r.id
                WHERE u.role = 'student' AND reg.edition_id = :eid";
        $params = [':eid' => $activeEditionId];
        if ($filterClass) { $sql .= " AND u.class = :class"; $params[':class'] = $filterClass; }
        if ($filterEid)   { $sql .= " AND reg.exhibitor_id = :feid"; $params[':feid'] = $filterEid; }
        if ($filterTid)   { $sql .= " AND reg.timeslot_id  = :ftid"; $params[':ftid'] = $filterTid; }
        $sql .= " ORDER BY u.class, u.lastname, u.firstname, t.slot_number";
        $headers = ['Nachname','Vorname','Klasse','Aussteller','Slot','Von','Bis','Raum','Typ','Angemeldet am'];
        $typeMap  = ['manual' => 'Manuell', 'automatic' => 'Automatisch', 'admin' => 'Admin'];
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['registration_type'] = $typeMap[$row['registration_type']] ?? $row['registration_type'];
        }
        unset($row);

    } elseif ($type === 'attendance') {
        $sql = "SELECT u.lastname, u.firstname, u.class,
                       e.name AS exhibitor_name, t.slot_name, a.checked_in_at
                FROM attendance a
                JOIN users u      ON a.user_id      = u.id
                JOIN exhibitors e ON a.exhibitor_id = e.id AND e.edition_id = :eid
                JOIN timeslots t  ON a.timeslot_id  = t.id AND t.edition_id = :eid
                WHERE u.role = 'student' AND a.edition_id = :eid";
        $params = [':eid' => $activeEditionId];
        if ($filterClass) { $sql .= " AND u.class = :class"; $params[':class'] = $filterClass; }
        if ($filterEid)   { $sql .= " AND a.exhibitor_id = :feid"; $params[':feid'] = $filterEid; }
        if ($filterTid)   { $sql .= " AND a.timeslot_id  = :ftid"; $params[':ftid'] = $filterTid; }
        $sql .= " ORDER BY t.slot_number, u.class, u.lastname";
        $headers = ['Nachname','Vorname','Klasse','Aussteller','Slot','Check-in um'];
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();

    } else { // unregistered
        $sql = "SELECT u.lastname, u.firstname, u.class, u.username
                FROM users u
                LEFT JOIN registrations reg ON reg.user_id = u.id AND reg.edition_id = :eid
                WHERE u.role = 'student' AND reg.id IS NULL
                ORDER BY u.class, u.lastname, u.firstname";
        $stmt = $db->prepare($sql); $stmt->execute([':eid' => $activeEditionId]);
        $rows    = $stmt->fetchAll();
        $headers = ['Nachname','Vorname','Klasse','Benutzername'];
    }

    // Entities in allen Werten dekodieren
    foreach ($rows as &$row) {
        foreach ($row as &$val) {
            if (is_string($val)) $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    unset($row, $val);

    if ($format === 'csv') {
        $filename = 'Berufsmesse_Export_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Windows-Excel
        fputcsv($out, $headers, ',', '"');
        foreach ($rows as $row) fputcsv($out, array_values($row), ',', '"');
        fclose($out);
        exit();
    }

    // XLSX via ZipArchive
    if (!class_exists('ZipArchive')) {
        // Fallback: CSV ausgeben
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="Berufsmesse_Export_' . date('Y-m-d_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers); foreach ($rows as $row) fputcsv($out, array_values($row));
        fclose($out); exit();
    }

    // Shared strings aufbauen
    $strings = []; $strIndex = [];
    $addStr  = function(string $s) use (&$strings, &$strIndex): int {
        if (!isset($strIndex[$s])) { $strIndex[$s] = count($strings); $strings[] = $s; }
        return $strIndex[$s];
    };
    // Header-Strings registrieren
    foreach ($headers as $h) $addStr($h);
    // Daten-Strings registrieren
    foreach ($rows as $row) foreach ($row as $val) $addStr((string)$val);

    // XML-Strings bauen
    $ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach ($strings as $s) $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1, 'UTF-8').'</t></si>';
    $ssXml .= '</sst>';

    $colLetters = ['A','B','C','D','E','F','G','H','I','J','K','L'];
    $sheetXml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $rowNum = 1;
    $buildRow = function(array $vals) use (&$rowNum, $colLetters, $addStr): string {
        $xml = '<row r="'.$rowNum.'">';
        foreach ($vals as $i => $v) {
            $col = $colLetters[$i] ?? chr(ord('A') + $i);
            $xml .= '<c r="'.$col.$rowNum.'" t="s"><v>'.$addStr((string)$v).'</v></c>';
        }
        $xml .= '</row>'; $rowNum++; return $xml;
    };
    $sheetXml .= $buildRow($headers);
    foreach ($rows as $row) $sheetXml .= $buildRow(array_values($row));
    $sheetXml .= '</sheetData></worksheet>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'bm_export_');
    $zip = new ZipArchive(); $zip->open($tmpFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    $zip->close();

    $filename = 'Berufsmesse_Export_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($tmpFile); unlink($tmpFile); exit();

} catch (Exception $e) {
    logErrorToAudit($e, 'API-Export');
    if (!headers_sent()) http_response_code(500);
    die('Fehler beim Export.');
}
```

---

## 21. Neue Datei `api/dashboard-stats.php` (Features A/B)

```php
<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

requireLogin();
if (!isAdmin() && !hasPermission('berichte_sehen')) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung']); exit;
}

try {
    $db              = getDB();
    $activeEditionId = getActiveEditionId();

    // Timeline: Einschreibungen pro Tag
    $stmt = $db->prepare("
        SELECT DATE(r.registered_at) AS day,
               SUM(r.registration_type = 'manual')    AS manual,
               SUM(r.registration_type = 'automatic') AS auto
        FROM registrations r
        WHERE r.edition_id = ?
        GROUP BY DATE(r.registered_at)
        ORDER BY day ASC
    ");
    $stmt->execute([$activeEditionId]);
    $timeline = $stmt->fetchAll();

    // Klassenbeteiligung
    $stmt = $db->prepare("
        SELECT u.class,
               COUNT(DISTINCT u.id)                                     AS total,
               COUNT(DISTINCT CASE WHEN r.id IS NOT NULL THEN u.id END) AS registered
        FROM users u
        LEFT JOIN registrations r ON r.user_id = u.id AND r.edition_id = ?
        WHERE u.role = 'student' AND u.class IS NOT NULL AND u.class != ''
        GROUP BY u.class
        ORDER BY u.class ASC
    ");
    $stmt->execute([$activeEditionId]);
    $classRows = $stmt->fetchAll();
    $classes = array_map(function($row) {
        $row['rate'] = $row['total'] > 0 ? round($row['registered'] / $row['total'] * 100) : 0;
        return $row;
    }, $classRows);

    echo json_encode(['timeline' => $timeline, 'classes' => $classes]);

} catch (Exception $e) {
    logErrorToAudit($e, 'API-DashboardStats');
    http_response_code(500);
    echo json_encode(['timeline' => [], 'classes' => []]);
}
```

---

## 22. Neue Datei `pages/admin-announcements.php` (Feature 7)

```php
<?php
if (!isAdmin()) die('Keine Berechtigung');

$db      = getDB();
$message = null;

// Erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
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
    $annId = intval($_POST['announcement_id']);
    $db->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?")->execute([$annId]);
    logAuditAction('ankuendigung_toggle', "Ankündigung #$annId umgeschaltet");
    header('Location: ?page=admin-announcements'); exit();
}

// Löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
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
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $ann['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $ann['is_active'] ? 'Aktiv' : 'Inaktiv'; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars(($ann['firstname'] ?? '') . ' ' . ($ann['lastname'] ?? '')); ?></td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 justify-end">
                            <form method="POST" class="inline">
                                <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" name="toggle_announcement"
                                        class="px-2 py-1 text-xs rounded border <?php echo $ann['is_active'] ? 'border-gray-200 text-gray-600 hover:bg-gray-50' : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50'; ?> transition">
                                    <?php echo $ann['is_active'] ? 'Deaktivieren' : 'Aktivieren'; ?>
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Ankündigung wirklich löschen?')">
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
```

---

## 23. Neue Datei `pages/admin-editions.php` (Feature C)

Vollständige Implementierung:

```php
<?php
if (!isAdmin()) die('Keine Berechtigung');
$db      = getDB();
$message = null;

// Edition aktivieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    $edId = intval($_POST['edition_id']);
    if (!confirm_dialog_passed()) { // Schutz via JS-confirm — serverseitig nicht prüfbar, daher nur Berechtigungsprüfung
        $db->exec("UPDATE messe_editions SET status = 'archived'");
        $stmt = $db->prepare("UPDATE messe_editions SET status = 'active' WHERE id = ?");
        $stmt->execute([$edId]);
        invalidateEditionCache();
        $edName = $db->prepare("SELECT name FROM messe_editions WHERE id = ?")->execute([$edId])
                  ? ($db->query("SELECT name FROM messe_editions WHERE id=$edId")->fetchColumn() ?: "#$edId")
                  : "#$edId";
        logAuditAction('edition_aktiviert', "Edition #$edId '$edName' aktiviert", 'warning');
        header('Location: ?page=admin-editions'); exit();
    }
}

// Edition aktivieren (vereinfacht, ohne Hilfsfunktion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    $edId = intval($_POST['edition_id']);
    $db->exec("UPDATE messe_editions SET status = 'archived'");
    $db->prepare("UPDATE messe_editions SET status = 'active' WHERE id = ?")->execute([$edId]);
    invalidateEditionCache();
    logAuditAction('edition_aktiviert', "Edition #$edId aktiviert", 'warning');
    header('Location: ?page=admin-editions'); exit();
}

// Neue Edition erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name    = trim($_POST['name'] ?? '');
    $year    = intval($_POST['year'] ?? date('Y'));
    $evDate  = trim($_POST['event_date'] ?? '') ?: null;
    $regS    = trim($_POST['registration_start'] ?? '') ?: null;
    $regE    = trim($_POST['registration_end']   ?? '') ?: null;
    $maxReg  = intval($_POST['max_registrations_per_student'] ?? 3);
    if (empty($name) || $year < 2000) {
        $message = ['type' => 'error', 'text' => 'Name und Jahr sind Pflichtfelder.'];
    } else {
        $db->prepare("INSERT INTO messe_editions (name,year,status,event_date,registration_start,registration_end,max_registrations_per_student) VALUES (?,?,'archived',?,?,?,?)")
           ->execute([$name, $year, $evDate, $regS, $regE, $maxReg]);
        logAuditAction('edition_erstellt', "Edition '$name' ($year) erstellt");
        $message = ['type' => 'success', 'text' => "Edition '$name' erstellt (Status: archiviert)."];
    }
}

// Edition löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
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
        in diesen Datenbereich. Schüler, Lehrer und alle anderen Nutzer sehen dann ausschließlich
        die Daten dieser Edition.
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
                    <th class="text-left px-4 py-3 font-semibold text-gray-600">Aussteller</th>
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
                            <form method="POST" onsubmit="return confirm('Achtung: Alle Ansichten, Einschreibungen und Daten wechseln zur Edition <?php echo htmlspecialchars($ed[\'name\'], ENT_QUOTES); ?>. Fortfahren?')">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="edition_id" value="<?php echo $ed['id']; ?>">
                                <button type="submit" class="px-3 py-1 bg-emerald-500 text-white text-xs rounded-lg hover:bg-emerald-600 transition font-medium">
                                    Aktivieren
                                </button>
                            </form>
                            <?php if ($ed['cnt_registrations'] == 0 && $ed['cnt_exhibitors'] == 0 && $ed['cnt_checkins'] == 0): ?>
                            <form method="POST" onsubmit="return confirm('Edition wirklich unwiderruflich löschen?')">
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
            <input type="hidden" name="action" value="create">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                <input type="text" name="name" required placeholder="z.B. Berufsmesse 2027"
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
            <div class="sm:col-span-3 flex justify-end">
                <button type="submit"
                        class="px-6 py-2 bg-emerald-500 text-white text-sm font-medium rounded-lg hover:bg-emerald-600 transition">
                    <i class="fas fa-plus mr-2"></i>Edition anlegen (archiviert)
                </button>
            </div>
        </form>
    </div>
</div>
```

---

## Implementierungsreihenfolge

1. **`setup.php`** — Migrationen 12–15 hinzufügen und `setup.php` im Browser ausführen
2. **`functions.php`** — Alle Ergänzungen (1a–1g)
3. **`config.php`** — Session + Header (2a/2b)
4. **`register.php`**, **`login.php`**, **`site-auth.php`** — Security-Fixes
5. **`index.php`** — Edition-Init, Ankündigungen, Router, Nav, Editions-Indikator
6. **`api/qr-tokens.php`** — Token-Länge
7. **Alle `api/*.php`** — Generische Fehlermeldungen + `$activeEditionId` + edition_id-Filter
8. **Alle `pages/*.php`** — Hardcodierte Slots, edition_id-Filter, Countdown
9. **`pages/admin-settings.php`** — Zeitslot-Tab
10. **`pages/admin-dashboard.php`** — Charts
11. **`pages/admin-print.php`** — Export-Sektion
12. **`.htaccess`** — SQL-Dateien schützen
13. **Neue Dateien erstellen** — `uploads/.htaccess`, `api/export-registrations.php`, `api/dashboard-stats.php`, `pages/admin-announcements.php`, `pages/admin-editions.php`
14. **`update_schema.sql`**, **`berufsmesse.sql`** — Konsistenz mit Migrations

---

## Wichtige Implementierungsregeln

1. **Nie anfassen:** `compose.yaml`, `Dockerfile`, `.dockerignore`, `.github/`
2. **Kein Composer, keine npm-Pakete.** XLSX nativ via `ZipArchive`.
3. **Alle DB-Queries:** Prepared Statements mit PDO.
4. **Alle `catch`-Blöcke in API-Dateien:** `logErrorToAudit($e, '...')`, generische Nachricht.
5. **`getManagedSlotsSqlIn()`** gibt ausschließlich Integer aus — direktes SQL-Einbetten sicher.
6. **`invalidateEditionCache()`** nach jedem Edition-Switch oder -Löschen aufrufen.
7. **`$activeEditionId`** in allen API-Direktaufrufen ganz oben nach `requireLogin()` setzen.
8. **Redirect-Validierung:** überall `preg_match('#^/[^/]#', $url)` statt `strpos($url, '/') === 0`.
9. **Neue Editionen** immer im Status `archived` erstellen — nie direkt als `active`.
10. **`berufsmesse.sql`** muss nach allen Änderungen konsistent zu `setup.php` / `update_schema.sql` sein.
11. **`switchTab()`** in `admin-dashboard.php` nicht überschreiben, sondern nur erweitern.
12. **Ankündigungs-Dismiss** ist rein clientseitig (kein localStorage, kein Session-Eintrag).
