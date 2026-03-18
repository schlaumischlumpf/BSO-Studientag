<?php
/**
 * PDF-Generator für fehlende Schüler
 * Zeigt alle angemeldeten Schüler die nicht per QR eingecheckt haben (alle Slots)
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
    SELECT u.firstname, u.lastname, u.class, e.name as exhibitor_name,
           t.slot_name, t.slot_number, r.room_number
    FROM registrations reg
    JOIN users u ON reg.user_id = u.id
    JOIN exhibitors e ON reg.exhibitor_id = e.id
    JOIN timeslots t ON reg.timeslot_id = t.id
    LEFT JOIN rooms r ON e.room_id = r.id AND r.edition_id = ?
    LEFT JOIN attendance a ON a.user_id = reg.user_id AND a.exhibitor_id = reg.exhibitor_id AND a.timeslot_id = reg.timeslot_id AND a.edition_id = ?
    WHERE reg.timeslot_id IS NOT NULL AND a.id IS NULL AND u.role = 'student'
    AND reg.edition_id = ? AND e.edition_id = ? AND t.edition_id = ?
    ORDER BY t.slot_number, u.class, u.lastname, u.firstname
");
$stmt->execute([$activeEditionId, $activeEditionId, $activeEditionId, $activeEditionId, $activeEditionId]);
$data = $stmt->fetchAll();

$eventDate = getSetting('event_date') ?? date('Y-m-d');

function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
}

class AbsentPDF extends FPDF {
    public $eventDate;

    function Header() {
        $this->SetFillColor(255, 200, 200);
        $this->Rect(10, 10, 190, 18, 'F');

        $this->SetFont('Arial', 'B', 14);
        $this->SetXY(15, 12);
        $this->Cell(100, 7, conv('Fehlende Schüler - BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate))), 0, 1);

        $this->SetFont('Arial', '', 10);
        $this->SetXY(15, 19);
        $this->Cell(100, 5, date('d.m.Y', strtotime($this->eventDate)), 0, 1);

        $this->SetFont('Arial', '', 8);
        $this->SetXY(140, 15);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(55, 4, 'Erstellt am ' . date('d.m.Y, H:i') . ' Uhr', 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);

        $this->Ln(12);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'Fehlende Schueler - Seite ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new AbsentPDF();
$pdf->eventDate = $eventDate;
$pdf->SetTitle('BSO-Tag an der FU - Fehlende Schueler');
$pdf->SetAuthor($_SESSION['firstname'] . ' ' . $_SESSION['lastname']);
$pdf->AliasNbPages();
$pdf->AddPage();

if (empty($data)) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 20, conv('Alle Schüler anwesend!'), 0, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, conv(count($data) . ' fehlende Eintraege (angemeldet, nicht gescannt)'), 0, 1, 'L');
    $pdf->Ln(3);

    // Nach Slot gruppieren
    $grouped = [];
    foreach ($data as $row) {
        $grouped[$row['slot_name']][] = $row;
    }

    foreach ($grouped as $slotName => $students) {
        // Seitenumbruch prüfen
        if ($pdf->GetY() + 20 > 270) {
            $pdf->AddPage();
        }

        // Slot-Header
        $pdf->SetFillColor(255, 220, 220);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, conv($slotName . ' (' . count($students) . ' fehlend)'), 1, 1, 'L', true);

        // Tabellenkopf
        $pdf->SetFillColor(243, 244, 246);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(10, 6, 'Nr.', 1, 0, 'C', true);
        $pdf->Cell(55, 6, conv('Schüler'), 1, 0, 'L', true);
        $pdf->Cell(25, 6, 'Klasse', 1, 0, 'L', true);
        $pdf->Cell(65, 6, 'Fachbereich', 1, 0, 'L', true);
        $pdf->Cell(25, 6, 'Hörsaal', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        $alt = false;
        foreach ($students as $idx => $s) {
            if ($pdf->GetY() + 6 > 270) {
                $pdf->AddPage();
                $pdf->SetFillColor(243, 244, 246);
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(10, 6, 'Nr.', 1, 0, 'C', true);
                $pdf->Cell(55, 6, conv('Schüler'), 1, 0, 'L', true);
                $pdf->Cell(25, 6, 'Klasse', 1, 0, 'L', true);
                $pdf->Cell(65, 6, 'Fachbereich', 1, 0, 'L', true);
                $pdf->Cell(25, 6, 'Hörsaal', 1, 1, 'C', true);
                $pdf->SetFont('Arial', '', 9);
            }

            $pdf->SetFillColor($alt ? 249 : 255, $alt ? 250 : 255, $alt ? 251 : 255);
            $pdf->Cell(10, 6, ($idx + 1), 1, 0, 'C', true);
            $pdf->Cell(55, 6, conv($s['lastname'] . ', ' . $s['firstname']), 1, 0, 'L', true);
            $pdf->Cell(25, 6, conv($s['class'] ?? '-'), 1, 0, 'L', true);
            $name = html_entity_decode($s['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen($name) > 35) $name = substr($name, 0, 32) . '...';
            $pdf->Cell(65, 6, conv($name), 1, 0, 'L', true);
            $pdf->Cell(25, 6, conv($s['room_number'] ?? '-'), 1, 1, 'C', true);
            $alt = !$alt;
        }

        $pdf->Ln(5);
    }
}

$pdf->Output('D', 'BSO_Fehlende_Schueler_' . date('Y-m-d') . '.pdf');

} catch (Exception $e) {
    logErrorToAudit($e, 'PDF-FehlendeSchueler');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Fehler beim Erstellen des PDFs.');
}
