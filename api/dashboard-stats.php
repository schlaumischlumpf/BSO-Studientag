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
