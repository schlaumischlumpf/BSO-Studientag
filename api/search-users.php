<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

// Nur für Admins oder Benutzer mit Benutzerverwaltung
if (!isAdmin() && !hasPermission('benutzer_sehen')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

try {
    $db = getDB();
    $activeEditionId = getActiveEditionId();
    
    // Parameter abrufen
    $name = $_GET['name'] ?? '';
    $class = $_GET['class'] ?? '';
    $role = $_GET['role'] ?? '';
    $status = $_GET['status'] ?? '';
    
    // Query aufbauen
    $query = "
        SELECT 
            u.id,
            u.username,
            u.firstname,
            u.lastname,
            u.class,
            u.role,
            COUNT(r.id) as registration_count
        FROM users u
        LEFT JOIN registrations r ON u.id = r.user_id AND r.edition_id = ?
        WHERE (u.role = 'admin' OR u.edition_id = ?)
    ";
    
    $params = [$activeEditionId, $activeEditionId];
    
    // Name filtern
    if (!empty($name)) {
        $query .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
        $searchTerm = '%' . $name . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Klasse filtern
    if (!empty($class)) {
        $query .= " AND u.class LIKE ?";
        $params[] = '%' . $class . '%';
    }
    
    // Rolle filtern
    if (!empty($role)) {
        $query .= " AND u.role = ?";
        $params[] = $role;
    }
    
    $query .= " GROUP BY u.id";
    
    // Status filtern (HAVING muss nach GROUP BY kommen)
    if (!empty($status)) {
        if ($status === 'registered') {
            $query .= " HAVING registration_count > 0";
        } elseif ($status === 'not_registered') {
            $query .= " HAVING registration_count = 0";
        }
    }
    
    $query .= " ORDER BY u.lastname ASC, u.firstname ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    // Daten formatieren
    $formattedUsers = array_map(function($user) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'fullname' => $user['firstname'] . ' ' . $user['lastname'],
            'initials' => strtoupper(substr($user['firstname'], 0, 1) . substr($user['lastname'], 0, 1)),
            'class' => $user['class'],
            'role' => $user['role'],
            'registration_count' => (int)$user['registration_count']
        ];
    }, $users);
    
    echo json_encode([
        'success' => true,
        'users' => $formattedUsers,
        'count' => count($formattedUsers)
    ]);
    
} catch (Exception $e) {
    logErrorToAudit($e, 'API-Benutzersuche');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ein interner Fehler ist aufgetreten.']);
}
