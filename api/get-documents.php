<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

requireAdmin();
$activeEditionId = getActiveEditionId();

$exhibitorId = intval($_GET['exhibitor_id'] ?? 0);

if (!$exhibitorId) {
    echo json_encode(['documents' => []]);
    exit();
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM exhibitor_documents WHERE exhibitor_id = ? AND exhibitor_documents.edition_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$exhibitorId, $activeEditionId]);
$documents = $stmt->fetchAll();

echo json_encode(['documents' => $documents]);
?>
