<?php
require_once 'config.php';

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            error_log("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
            http_response_code(503);
            die("Dienst vorübergehend nicht verfügbar.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Verhindere Klonen
    private function __clone() {}
    
    // Verhindere Unserialisierung
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Konstanten für Kapazitätsberechnung
define('DEFAULT_CAPACITY_DIVISOR', 3); // Standard-Divisor für Raumkapazität

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
        $stmt  = $db->query("SELECT slot_number FROM timeslots WHERE is_managed = 1 AND (is_break = 0 OR is_break IS NULL) ORDER BY slot_number ASC");
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

// Hilfsfunktionen
function getDB() {
    return Database::getInstance()->getConnection();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isTeacher() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
}

function isOrga() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'orga';
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Aktuelle URL merken, damit nach Login dorthin zurückgeleitet wird
        $returnUrl = $_SERVER['REQUEST_URI'] ?? '';
        $loginUrl = BASE_URL . 'login.php';
        if (!empty($returnUrl) && $returnUrl !== '/') {
            $loginUrl .= '?redirect=' . urlencode($returnUrl);
        }
        header('Location: ' . $loginUrl);
        exit();
    }

    // Enforce password change if required by DB flag
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT must_change_password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $res = $stmt->fetch();
        if ($res && $res['must_change_password']) {
            $currentScript = basename($_SERVER['PHP_SELF']);
            // Allow the user to access only the password change page
            if ($currentScript !== 'change-password.php') {
                $_SESSION['force_password_change'] = true;
                header('Location: ' . BASE_URL . 'change-password.php');
                exit();
            }
        }
    } catch (Exception $e) {
        // If DB is temporarily unavailable, do not block access but log
        error_log('requireLogin DB check failed: ' . $e->getMessage());
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

function requireTeacherOrAdmin() {
    requireLogin();
    if (!isTeacher() && !isAdmin()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

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

function formatDate($date) {
    return date('d.m.Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('d.m.Y H:i', strtotime($datetime));
}

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

function isRegistrationOpen() {
    $start = getSetting('registration_start');
    $end = getSetting('registration_end');
    $now = date('Y-m-d H:i:s');
    
    return ($now >= $start && $now <= $end);
}

function getRegistrationStatus() {
    $start = getSetting('registration_start');
    $end = getSetting('registration_end');
    $now = date('Y-m-d H:i:s');
    
    if ($now < $start) {
        return 'upcoming';
    } elseif ($now > $end) {
        return 'closed';
    } else {
        return 'open';
    }
}

function uploadFile($file, $exhibitorId) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Fehler beim Upload'];
    }
    
    // Dateigröße prüfen
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'Datei zu groß (max. 10 MB)'];
    }
    
    // Dateityp prüfen
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_FILE_TYPES)) {
        return ['success' => false, 'message' => 'Dateityp nicht erlaubt'];
    }
    
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

    // Einzigartigen Dateinamen generieren
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = UPLOAD_DIR . $filename;
    
    // Datei verschieben
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'message' => 'Fehler beim Speichern der Datei'];
    }
    
    // In Datenbank eintragen
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO exhibitor_documents (exhibitor_id, filename, original_name, file_type, file_size) 
                          VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $exhibitorId,
        $filename,
        $file['name'],
        $extension,
        $file['size']
    ]);
    
    return ['success' => true, 'filename' => $filename];
}

function deleteFile($documentId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT filename FROM exhibitor_documents WHERE id = ?");
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $filepath = UPLOAD_DIR . $doc['filename'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        $stmt = $db->prepare("DELETE FROM exhibitor_documents WHERE id = ?");
        return $stmt->execute([$documentId]);
    }
    
    return false;
}

// Helper-Funktion für Raumkapazität pro Slot (Issue #4)
function getRoomSlotCapacity($roomId, $timeslotId, $priority = 2) {
    $db = getDB();
    
    // Erst prüfen ob spezifische Kapazität definiert ist
    $stmt = $db->prepare("SELECT capacity FROM room_slot_capacities WHERE room_id = ? AND timeslot_id = ?");
    $stmt->execute([$roomId, $timeslotId]);
    $result = $stmt->fetch();
    
    if ($result) {
        $customCapacity = intval($result['capacity']);
        
        // Bei Priorität 1 (hoch): Volle Raumkapazität nutzen
        if ($priority == 1) {
            $stmt = $db->prepare("SELECT capacity FROM rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $room = $stmt->fetch();
            return $room ? max($customCapacity, intval($room['capacity'])) : $customCapacity;
        }
        
        return $customCapacity;
    }
    
    // Fallback: Standard ist 25, bei Priorität 1 die volle Raumkapazität
    $stmt = $db->prepare("SELECT capacity FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if ($room && $room['capacity']) {
        $roomCapacity = intval($room['capacity']);
        
        // Bei Priorität 1 (hohe Nachfrage): Volle Raumkapazität
        if ($priority == 1) {
            return $roomCapacity;
        }
        
        // Standard: Max 25 Schüler pro Slot (oder Raumkapazität wenn kleiner)
        return min(25, $roomCapacity);
    }
    
    return 0;
}

// Helper-Funktion für Gesamt-Kapazität eines Ausstellers (über alle Slots)
function getExhibitorTotalCapacity($exhibitorId) {
    $db = getDB();
    
    // Raum des Ausstellers ermitteln
    $stmt = $db->prepare("SELECT room_id FROM exhibitors WHERE id = ?");
    $stmt->execute([$exhibitorId]);
    $exhibitor = $stmt->fetch();
    
    if (!$exhibitor || !$exhibitor['room_id']) {
        return 0;
    }
    
    // Alle verwalteten Zeitslots (1, 3, 5)
    $stmt = $db->query("SELECT id FROM timeslots WHERE slot_number " . getManagedSlotsSqlIn() . "");
    $timeslots = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $totalCapacity = 0;
    foreach ($timeslots as $timeslotId) {
        $totalCapacity += getRoomSlotCapacity($exhibitor['room_id'], $timeslotId);
    }
    
    return $totalCapacity;
}

// Berechtigungssystem (Issue #10)
function hasPermission($permission) {
    // Admins haben immer alle Berechtigungen
    if (isAdmin()) {
        return true;
    }
    
    if (!isLoggedIn()) {
        return false;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission = ?");
    $stmt->execute([$_SESSION['user_id'], $permission]);
    return $stmt->fetchColumn() > 0;
}

function hasAnyPermission(...$permissions) {
    foreach ($permissions as $permission) {
        if (hasPermission($permission)) {
            return true;
        }
    }
    return false;
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        die('Keine Berechtigung für diese Aktion');
    }
}

function getUserPermissions($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT permission FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Abhängigkeitsbaum für Berechtigungen
function getPermissionDependencies() {
    return [
        // Aussteller
        'aussteller_erstellen'            => ['aussteller_sehen'],
        'aussteller_bearbeiten'           => ['aussteller_sehen'],
        'aussteller_loeschen'             => ['aussteller_sehen'],
        'orga_team_sehen'                 => ['aussteller_sehen'],
        'orga_team_bearbeiten'            => ['orga_team_sehen'],
        // Branchen
        'branchen_bearbeiten'             => ['branchen_sehen'],
        // Anwesenheit
        'attendance_bearbeiten'           => ['qr_codes_sehen'],
        'aussteller_dokumente_verwalten'  => ['aussteller_sehen'],
        // Branchen (in Aussteller-Tab)
        'branchen_verwalten'              => ['branchen_sehen'],
        // Räume
        'raeume_erstellen'                => ['raeume_sehen'],
        'raeume_bearbeiten'               => ['raeume_sehen'],
        'raeume_loeschen'                 => ['raeume_sehen'],
        // Kapazitäten
        'kapazitaeten_sehen'              => ['raeume_sehen'],
        'kapazitaeten_bearbeiten'         => ['kapazitaeten_sehen', 'raeume_sehen'],
        // Benutzer
        'benutzer_erstellen'              => ['benutzer_sehen'],
        'benutzer_bearbeiten'             => ['benutzer_sehen'],
        'benutzer_loeschen'               => ['benutzer_sehen'],
        'benutzer_importieren'            => ['benutzer_sehen'],
        'benutzer_passwort_zuruecksetzen' => ['benutzer_sehen'],
        // Berechtigungen
        'berechtigungen_vergeben'          => ['berechtigungen_sehen'],
        'berechtigungsgruppen_verwalten'   => ['berechtigungen_sehen'],
        // Einstellungen
        'einstellungen_bearbeiten'         => ['einstellungen_sehen'],
        // Berichte
        'berichte_drucken'                 => ['berichte_sehen'],
        // QR-Codes
        'qr_codes_erstellen'               => ['qr_codes_sehen'],
        // Anmeldungen
        'anmeldungen_erstellen'            => ['anmeldungen_sehen'],
        'anmeldungen_loeschen'             => ['anmeldungen_sehen'],
        // Zuteilung
        'zuteilung_ausfuehren'             => ['dashboard_sehen'],
        'zuteilung_zuruecksetzen'          => ['zuteilung_ausfuehren', 'dashboard_sehen'],
    ];
}

function grantPermission($userId, $permission, &$visited = []) {
    if (!isAdmin() && !hasPermission('berechtigungen_vergeben')) {
        return false;
    }

    // Non-admins can only grant permissions they have themselves (checks current session user)
    if (!isAdmin() && !hasPermission($permission)) {
        return false;
    }

    // Prevent infinite recursion
    if (in_array($permission, $visited)) {
        return true;
    }
    $visited[] = $permission;

    $db = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO user_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
    $result = $stmt->execute([$userId, $permission, $_SESSION['user_id']]);

    // Auto-grant dependencies
    $deps = getPermissionDependencies();
    if (isset($deps[$permission])) {
        foreach ($deps[$permission] as $dep) {
            grantPermission($userId, $dep, $visited);
        }
    }

    return $result;
}

function revokePermission($userId, $permission, &$visited = []) {
    if (!isAdmin() && !hasPermission('berechtigungen_vergeben')) {
        return false;
    }

    // Prevent infinite recursion
    if (in_array($permission, $visited)) {
        return true;
    }
    $visited[] = $permission;

    $db = getDB();
    $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission = ?");
    $result = $stmt->execute([$userId, $permission]);

    // Auto-revoke higher permissions that depend on this one
    $deps = getPermissionDependencies();
    foreach ($deps as $dependent => $requiredList) {
        if (in_array($permission, $requiredList)) {
            revokePermission($userId, $dependent, $visited);
        }
    }

    return $result;
}

// Verfügbare Berechtigungen (gruppiert nach Seitenbereich)
function getAvailablePermissions() {
    return [
        'Admin-Dashboard' => [
            'dashboard_sehen' => 'Admin-Dashboard mit Statistiken und Übersicht anzeigen',
        ],
        'Aussteller-Verwaltung' => [
            'aussteller_sehen'               => 'Ausstellerliste im Admin-Bereich einsehen',
            'aussteller_erstellen'           => 'Neue Aussteller anlegen (erfordert: aussteller_sehen)',
            'aussteller_bearbeiten'          => 'Bestehende Aussteller bearbeiten (erfordert: aussteller_sehen)',
            'aussteller_loeschen'            => 'Aussteller löschen (erfordert: aussteller_sehen)',
            'aussteller_dokumente_verwalten' => 'Dokumente zu Ausstellern hochladen/löschen (erfordert: aussteller_sehen)',
            'branchen_sehen'                 => 'Branchen-Liste im Admin-Bereich einsehen',
            'branchen_verwalten'             => 'Branchen anlegen, bearbeiten und löschen (erfordert: branchen_sehen)',
        ],
        'Branchen-Verwaltung' => [
            'branchen_sehen'      => 'Branchen/Kategorien im Aussteller-Bereich einsehen',
            'branchen_bearbeiten' => 'Branchen anlegen, bearbeiten und löschen (erfordert: branchen_sehen)',
        ],
        'Orga-Team' => [
            'orga_team_sehen'      => 'Orga-Team Tab auf der Aussteller-Seite einsehen (erfordert: aussteller_sehen)',
            'orga_team_bearbeiten' => 'Orga-Mitglieder Ausstellern zuweisen/entfernen (erfordert: orga_team_sehen)',
        ],
        'Raum-Verwaltung' => [
            'raeume_sehen'      => 'Raumliste und Raumplan einsehen',
            'raeume_erstellen'  => 'Neue Räume anlegen (erfordert: raeume_sehen)',
            'raeume_bearbeiten' => 'Räume bearbeiten und Aussteller zuordnen (erfordert: raeume_sehen)',
            'raeume_loeschen'   => 'Räume löschen (erfordert: raeume_sehen)',
        ],
        'Raumkapazitäten' => [
            'kapazitaeten_sehen'      => 'Slot-Kapazitäten der Räume einsehen (erfordert: raeume_sehen)',
            'kapazitaeten_bearbeiten' => 'Slot-Kapazitäten ändern (erfordert: kapazitaeten_sehen)',
        ],
        'Benutzer-Verwaltung' => [
            'benutzer_sehen'                  => 'Benutzerliste einsehen',
            'benutzer_erstellen'              => 'Neue Benutzer anlegen (erfordert: benutzer_sehen)',
            'benutzer_bearbeiten'             => 'Benutzer bearbeiten (erfordert: benutzer_sehen)',
            'benutzer_loeschen'               => 'Benutzer löschen (erfordert: benutzer_sehen)',
            'benutzer_importieren'            => 'Benutzer per CSV importieren (erfordert: benutzer_sehen)',
            'benutzer_passwort_zuruecksetzen' => 'Passwörter zurücksetzen (erfordert: benutzer_sehen)',
        ],
        'Berechtigungen' => [
            'berechtigungen_sehen'           => 'Berechtigungen anderer Benutzer einsehen',
            'berechtigungen_vergeben'         => 'Berechtigungen vergeben/entziehen (erfordert: berechtigungen_sehen)',
            'berechtigungsgruppen_verwalten'  => 'Berechtigungsgruppen erstellen/bearbeiten/löschen (erfordert: berechtigungen_sehen)',
        ],
        'Einstellungen' => [
            'einstellungen_sehen'      => 'Systemeinstellungen einsehen',
            'einstellungen_bearbeiten' => 'Einstellungen ändern (erfordert: einstellungen_sehen)',
        ],
        'Druckzentrale / Berichte' => [
            'berichte_sehen'   => 'Berichte und Druckansichten einsehen',
            'berichte_drucken' => 'PDFs generieren und drucken (erfordert: berichte_sehen)',
        ],
        'QR-Codes' => [
            'qr_codes_sehen'    => 'QR-Code Übersicht einsehen',
            'qr_codes_erstellen' => 'QR-Codes und Tokens generieren (erfordert: qr_codes_sehen)',
        ],
        'Anwesenheit' => [
            'attendance_bearbeiten' => 'Anwesenheit manuell eintragen/entfernen (erfordert: qr_codes_sehen)',
        ],
        'Anmeldungen' => [
            'anmeldungen_sehen'    => 'Alle Schüler-Anmeldungen einsehen',
            'anmeldungen_erstellen' => 'Schüler manuell anmelden (erfordert: anmeldungen_sehen)',
            'anmeldungen_loeschen'  => 'Anmeldungen löschen (erfordert: anmeldungen_sehen)',
        ],
        'Automatische Zuteilung' => [
            'zuteilung_ausfuehren'    => 'Automatische Slot-Zuteilung starten (erfordert: dashboard_sehen)',
            'zuteilung_zuruecksetzen' => 'Alle Slot-Zuordnungen zurücksetzen (erfordert: zuteilung_ausfuehren)',
        ],
        'Audit Logs' => [
            'audit_logs_sehen' => 'Audit-Protokolle einsehen',
        ],
    ];
}

// Gibt alle Berechtigungs-Keys als flaches Array zurück
function getAllPermissionKeys() {
    $flat = [];
    foreach (getAvailablePermissions() as $permissions) {
        foreach ($permissions as $key => $description) {
            $flat[$key] = $description;
        }
    }
    return $flat;
}

/**
 * Prüft ob eine IP-Adresse in einem CIDR-Bereich liegt (IPv4 und IPv6).
 */
function ipInRange(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false) {
        return false;
    }

    // Beide müssen die gleiche Adressfamilie haben
    if (strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    // Bitweise Maske berechnen und vergleichen
    $ipHex = bin2hex($ipBin);
    $subnetHex = bin2hex($subnetBin);
    $ipBits = '';
    $subnetBits = '';
    for ($i = 0; $i < strlen($ipHex); $i++) {
        $ipBits .= str_pad(base_convert($ipHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
        $subnetBits .= str_pad(base_convert($subnetHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
    }

    return substr($ipBits, 0, $bits) === substr($subnetBits, 0, $bits);
}

/**
 * Ermittelt die echte Client-IP-Adresse, auch hinter Reverse-Proxies.
 */
function getClientIp(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    // IPv4-mapped IPv6 normalisieren (z.B. ::ffff:192.168.1.1 -> 192.168.1.1)
    $normalizeIp = function(string $ip): string {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $matches)) {
            return $matches[1];
        }
        return $ip;
    };

    $remoteAddr = $normalizeIp($remoteAddr);

    // Prüfen ob REMOTE_ADDR ein Trusted Proxy ist
    $isTrustedProxy = false;
    $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
    foreach ($trustedProxies as $proxy) {
        if (ipInRange($remoteAddr, $proxy)) {
            $isTrustedProxy = true;
            break;
        }
    }

    // Nur wenn Trusted Proxy: Proxy-Header auswerten
    if ($isTrustedProxy) {
        $headersToCheck = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
        ];

        foreach ($headersToCheck as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }

            if ($header === 'HTTP_X_FORWARDED_FOR') {
                // Kommaseparierte Liste: ersten Nicht-Proxy-Eintrag verwenden
                $ips = array_map('trim', explode(',', $_SERVER[$header]));
                foreach ($ips as $ip) {
                    $ip = $normalizeIp($ip);
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        error_log('getClientIp: Ungültige IP in ' . $header . ': ' . $ip);
                        continue;
                    }
                    // Trusted-Proxy-IPs überspringen
                    $isProxy = false;
                    foreach ($trustedProxies as $proxy) {
                        if (ipInRange($ip, $proxy)) {
                            $isProxy = true;
                            break;
                        }
                    }
                    if (!$isProxy) {
                        return $ip;
                    }
                }
            } else {
                $ip = $normalizeIp(trim($_SERVER[$header]));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                } else {
                    error_log('getClientIp: Ungültige IP in ' . $header . ': ' . $_SERVER[$header]);
                }
            }
        }
    }

    // Fallback auf REMOTE_ADDR
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return '0.0.0.0';
}

// Audit Log System (Issue #21)
function logAuditAction($action, $details = '', $severity = 'info') {
    try {
        $db = getDB();
        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'System';
        $ipAddress = getClientIp();

        // Sanitize severity – only allow known values
        $allowedSeverities = ['info', 'warning', 'error'];
        $severity = in_array($severity, $allowedSeverities, true) ? $severity : 'info';

        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, username, action, details, severity, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $username, $action, $details, $severity, $ipAddress]);
    } catch (Exception $e) {
        error_log('Audit Log Error: ' . $e->getMessage());
    }
}

/**
 * Lädt alle aktiven, nicht abgelaufenen Ankündigungen für die gegebene Rolle.
 * Gibt leeres Array zurück wenn die Tabelle noch nicht existiert.
 */
function getActiveAnnouncements(string $role): array {
    try {
        $db   = getDB();
        $now  = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            SELECT id, title, body, type, target_role
            FROM   announcements
            WHERE  is_active   = 1
              AND  (expires_at IS NULL OR expires_at > ?)
              AND  (target_role = 'all' OR target_role = ?)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$now, $role]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Logs a caught exception or error to the audit log with severity = 'error'.
 * Also calls PHP's error_log() so server logs remain unchanged.
 *
 * @param Throwable $e         The caught exception or error.
 * @param string    $context   Short label for where the error occurred, e.g. 'Registrierung', 'Admin-Benutzer'.
 * @param string    $extraInfo Optional additional detail string to append.
 */
function logErrorToAudit(Throwable $e, string $context = 'Unbekannt', string $extraInfo = ''): void {
    // Always preserve the original error_log behaviour
    error_log('[' . $context . '] ' . get_class($e) . ': ' . $e->getMessage());

    $details = '[' . $context . '] '
        . get_class($e) . ': '
        . $e->getMessage()
        . ' | Datei: ' . basename($e->getFile())
        . ':' . $e->getLine();

    if ($extraInfo !== '') {
        $details .= ' | Info: ' . $extraInfo;
    }

    // Truncate details to prevent oversized DB entries
    $details = mb_substr($details, 0, 2000);

    logAuditAction('error', $details, 'error');
}

// Branchen/Kategorien aus DB laden
function getIndustries() {
    try {
        $db = getDB();
        $stmt = $db->query("SELECT id, name, sort_order FROM industries ORDER BY sort_order, name");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Berechtigungsgruppen (Issue #26)
function getPermissionGroups() {
    try {
        $db = getDB();
        $stmt = $db->query("SELECT * FROM permission_groups ORDER BY name ASC");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getPermissionGroupPermissions($groupId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT permission FROM permission_group_items WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function applyPermissionGroup($userId, $groupId) {
    $db = getDB();

    // Gruppen-Zuordnung speichern
    $stmt = $db->prepare("INSERT IGNORE INTO user_permission_groups (user_id, group_id) VALUES (?, ?)");
    $stmt->execute([$userId, $groupId]);

    // Alle Berechtigungen der Gruppe anwenden
    $permissions = getPermissionGroupPermissions($groupId);
    foreach ($permissions as $permission) {
        grantPermission($userId, $permission);
    }
}

// Lade zugeordnete Gruppen eines Benutzers
function getUserPermissionGroups($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT group_id FROM user_permission_groups WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ==========================================
// Exhibitor-Orga-Team Functions
// ==========================================

/**
 * Checks if a user is assigned to an exhibitor's orga team
 */
function isExhibitorOrgaMember($userId, $exhibitorId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM exhibitor_orga_team WHERE user_id = ? AND exhibitor_id = ?");
        $stmt->execute([$userId, $exhibitorId]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Gets all exhibitor IDs assigned to an orga team member
 */
function getOrgaExhibitors($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT exhibitor_id FROM exhibitor_orga_team WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Gets all orga team members for a specific exhibitor
 */
function getExhibitorOrgaMembers($exhibitorId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.firstname, u.lastname, u.email, eot.assigned_at
            FROM exhibitor_orga_team eot
            JOIN users u ON eot.user_id = u.id
            WHERE eot.exhibitor_id = ?
            ORDER BY u.lastname, u.firstname
        ");
        $stmt->execute([$exhibitorId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Assigns a user to an exhibitor's orga team
 */
function assignExhibitorOrgaMember($userId, $exhibitorId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT IGNORE INTO exhibitor_orga_team (user_id, exhibitor_id) VALUES (?, ?)");
        $stmt->execute([$userId, $exhibitorId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Removes a user from an exhibitor's orga team
 */
function removeExhibitorOrgaMember($userId, $exhibitorId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM exhibitor_orga_team WHERE user_id = ? AND exhibitor_id = ?");
        $stmt->execute([$userId, $exhibitorId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Checks if user can access QR codes for an exhibitor
 * Admins and regular orga can access all, exhibitor-orga can only access their assigned ones
 */
// Seitenpasswort-Schutz prüfen
function checkSitePassword() {
    // Prüfen ob Seitenpasswort aktiviert ist
    if (getSetting('site_password_enabled', '0') !== '1') {
        return;
    }

    $sitePassword = getSetting('site_password', '');
    if (empty($sitePassword)) {
        return;
    }

    // Bereits authentifiziert?
    if (isset($_SESSION['site_authenticated']) && $_SESSION['site_authenticated'] === true) {
        return;
    }

    // Redirect zur Passwort-Eingabeseite
    $returnUrl = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: ' . BASE_URL . 'site-auth.php?redirect=' . urlencode($returnUrl));
    exit();
}

function canAccessExhibitorQR($userId, $exhibitorId) {
    // Admins can access everything
    if (isAdmin()) {
        return true;
    }

    // Regular orga (with qr_codes_verwalten permission) can access everything
    if (hasPermission('qr_codes_verwalten')) {
        return true;
    }

    // Exhibitor-specific orga can only access their assigned exhibitors
    return isExhibitorOrgaMember($userId, $exhibitorId);
}

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
?>
