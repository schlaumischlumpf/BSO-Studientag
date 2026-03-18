<?php
/**
 * PDF-Generator für Raumzuteilungen (Schulleitung)
 * Schlichtes Design, Mindestens Schriftgröße 12
 */
session_start();
require_once '../config.php';
require_once '../functions.php';
require_once '../fpdf/fpdf.php';

if (!isLoggedIn() || (!isAdmin() && !isTeacher() && !hasPermission('berichte_drucken'))) {
    die('Keine Berechtigung');
}

try {

$db = getDB();
$activeEditionId = getActiveEditionId();

$stmt = $db->prepare("
    SELECT r.room_number, e.name as exhibitor_name
    FROM exhibitors e
    JOIN rooms r ON e.room_id = r.id
    WHERE e.active = 1
    AND e.edition_id = ? AND r.edition_id = ?
    ORDER BY r.room_number, e.name
");
$stmt->execute([$activeEditionId, $activeEditionId]);
$data = $stmt->fetchAll();

$eventDate = getSetting('event_date') ?? date('Y-m-d');

function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
}

class RoomAssignPDF extends FPDF {
    public $eventDate;

    public function SetCellMargin($margin) {
        $this->cMargin = $margin;
    }

    function Header() {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, conv('BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)) . ' - Hörsaalzuteilung'), 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 7, conv('Veranstaltungsdatum: ' . date('d.m.Y', strtotime($this->eventDate))), 0, 1, 'C');
        $this->Ln(5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, 'Seite ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new RoomAssignPDF();
$pdf->eventDate = $eventDate;
$pdf->SetTitle('BSO-Tag an der FU - Hörsaalzuteilung');
$pdf->SetAuthor($_SESSION['firstname'] . ' ' . $_SESSION['lastname']);
$pdf->AliasNbPages();
$pdf->AddPage();

if (empty($data)) {
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 20, conv('Keine Raumzuteilungen vorhanden.'), 0, 1, 'C');
} else {
    // Tabellenkopf
    $pdf->SetCellMargin(4);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(35, 14, 'Hörsaal', 1, 0, 'C', true);
    $pdf->Cell(155, 14, 'Fachbereich', 1, 1, 'L', true);

    $pdf->SetFont('Arial', '', 12);
    $lastRoom = '';
    $alt = false;

    foreach ($data as $row) {
        // Seitenumbruch prüfen
        if ($pdf->GetY() + 14 > 270) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Cell(35, 14, 'Hörsaal', 1, 0, 'C', true);
            $pdf->Cell(155, 14, 'Fachbereich', 1, 1, 'L', true);
            $pdf->SetFont('Arial', '', 12);
        }

        $roomDisplay = ($row['room_number'] !== $lastRoom) ? $row['room_number'] : '';
        if ($row['room_number'] !== $lastRoom) {
            $alt = !$alt;
            $lastRoom = $row['room_number'];
        }

        if ($alt) {
            $pdf->SetFillColor(245, 245, 245);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $pdf->Cell(35, 14, conv($roomDisplay), 1, 0, 'C', true);
        $pdf->Cell(155, 14, conv(html_entity_decode($row['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 1, 1, 'L', true);
    }
}

$pdf->Output('D', 'BSO_Hoersaalzuteilung_' . date('Y-m-d') . '.pdf');

} catch (Exception $e) {
    logErrorToAudit($e, 'PDF-Raumzuteilung');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Fehler beim Erstellen des PDFs.');
}
