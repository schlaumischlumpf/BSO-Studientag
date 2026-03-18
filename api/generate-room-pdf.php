<?php
/**
 * PDF-Generator für Raumübersichten
 * Verwendet FPDF zur Erstellung professioneller PDFs
 */
require_once '../config.php';
require_once '../functions.php';
require_once '../fpdf/fpdf.php';

// Berechtigungsprüfung
if (!isLoggedIn() || (!isAdmin() && !isTeacher() && !hasPermission('berichte_drucken'))) {
    die('Keine Berechtigung');
}

try {

$db = getDB();
$activeEditionId = getActiveEditionId();

// Filter
$filterRoom = $_GET['room'] ?? '';

// Daten laden
$query = "
    SELECT
        r.room_number,
        e.name as exhibitor_name,
        t.slot_name, t.slot_number, t.start_time, t.end_time,
        u.firstname, u.lastname, u.class
    FROM registrations reg
    JOIN users u ON reg.user_id = u.id
    JOIN exhibitors e ON reg.exhibitor_id = e.id
    JOIN timeslots t ON reg.timeslot_id = t.id
    JOIN rooms r ON e.room_id = r.id
    WHERE reg.edition_id = ? AND e.edition_id = ? AND t.edition_id = ? AND r.edition_id = ?
";

$params = [$activeEditionId, $activeEditionId, $activeEditionId, $activeEditionId];
if ($filterRoom) {
    $query .= " AND r.id = ?";
    $params[] = intval($filterRoom);
}

$query .= " ORDER BY r.room_number, t.slot_number, u.lastname, u.firstname";

$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Räume für Titel
$stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE rooms.edition_id = ? ORDER BY room_number");
$stmt->execute([$activeEditionId]);
$rooms = $stmt->fetchAll();

// Nach Raum und Slot gruppieren
$groupedByRoom = [];
foreach ($registrations as $reg) {
    $roomKey = $reg['room_number'];
    $slotKey = $reg['slot_number'];
    if (!isset($groupedByRoom[$roomKey][$slotKey])) {
        $groupedByRoom[$roomKey][$slotKey] = [
            'info' => $reg,
            'students' => []
        ];
    }
    $groupedByRoom[$roomKey][$slotKey]['students'][] = $reg;
}
ksort($groupedByRoom);

// Helper: UTF-8 zu Latin-1 konvertieren
function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
}

// Titel bestimmen
if ($filterRoom) {
    $roomData = array_filter($rooms, fn($r) => $r['id'] == $filterRoom);
    $docTitle = !empty($roomData) ? 'Hörsaal ' . array_values($roomData)[0]['room_number'] : conv('Hörsaalübersicht');
} else {
    $docTitle = conv('Alle Hörsäle');
}

$eventDate = getSetting('event_date') ?? date('Y-m-d');

// PDF Klasse
class RoomPDF extends FPDF {
    public $eventDate;
    public $docTitle;

    function Header() {
        // Sky-farbener Header-Balken
        $this->SetFillColor(181, 222, 255);
        $this->Rect(10, 10, 190, 22, 'F');

        // Titel
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(15, 13);
        $this->Cell(100, 8, 'BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)), 0, 1);

        $this->SetFont('Arial', '', 10);
        $this->SetXY(15, 21);
        $this->Cell(100, 5, $this->docTitle, 0, 1);

        // Datum rechts
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY(140, 13);
        $this->Cell(55, 6, date('d.m.Y', strtotime($this->eventDate)), 0, 1, 'R');

        $this->SetFont('Arial', '', 8);
        $this->SetXY(140, 19);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(55, 4, 'Erstellt am ' . date('d.m.Y, H:i') . ' Uhr', 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);

        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)) . ' - ' . $this->docTitle . ' - Seite ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function RoomHeader($roomNum, $totalVisits) {
        $this->SetFillColor(227, 243, 255);
        $this->SetDrawColor(181, 222, 255);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, conv('Hörsaal ' . $roomNum . ' (' . $totalVisits . ' Besuche)'), 1, 1, 'L', true);
        $this->Ln(3);
    }

    function SlotSection($slotInfo, $students) {
        // Slot Header
        $this->SetFillColor(232, 223, 245);
        $this->SetDrawColor(195, 177, 225);
        $this->SetFont('Arial', 'B', 9);
        $slotText = $slotInfo['slot_name'] . ' (' .
                    date('H:i', strtotime($slotInfo['start_time'])) . ' - ' .
                    date('H:i', strtotime($slotInfo['end_time'])) . ')';
        $this->Cell(95, 7, conv($slotText), 1, 0, 'L', true);
        $this->SetFont('Arial', 'I', 9);
        $this->Cell(80, 7, conv($slotInfo['exhibitor_name']), 1, 1, 'L', true);

        // Tabelle Header
        $this->SetFillColor(243, 244, 246);
        $this->SetDrawColor(200, 200, 200);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(15, 6, 'Nr.', 1, 0, 'C', true);
        $this->Cell(100, 6, 'Name', 1, 0, 'L', true);
        $this->Cell(30, 6, 'Klasse', 1, 1, 'L', true);

        // Schüler
        $this->SetFont('Arial', '', 8);
        usort($students, fn($a, $b) => strcmp($a['lastname'], $b['lastname']));

        $alt = false;
        foreach ($students as $idx => $student) {
            if ($alt) {
                $this->SetFillColor(249, 250, 251);
            } else {
                $this->SetFillColor(255, 255, 255);
            }
            $this->Cell(15, 6, ($idx + 1), 1, 0, 'C', true);
            $this->Cell(100, 6, conv($student['lastname'] . ', ' . $student['firstname']), 1, 0, 'L', true);
            $this->Cell(30, 6, conv($student['class'] ?? '-'), 1, 1, 'L', true);
            $alt = !$alt;
        }

        $this->Ln(4);
    }
}

// PDF erstellen
$pdf = new RoomPDF();
$pdf->eventDate = $eventDate;
$pdf->docTitle = $docTitle;
$pdf->SetTitle('BSO-Tag an der FU - ' . $docTitle);
$pdf->SetAuthor($_SESSION['firstname'] . ' ' . $_SESSION['lastname']);
$pdf->SetCreator('BSO-Tag System');
$pdf->AliasNbPages();

if (empty($groupedByRoom)) {
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 20, conv('Keine Registrierungen gefunden.'), 0, 1, 'C');
} else {
    foreach ($groupedByRoom as $roomNum => $slots) {
        $pdf->AddPage();
        $totalVisits = array_sum(array_map(fn($s) => count($s['students']), $slots));
        $pdf->RoomHeader($roomNum, $totalVisits);

        ksort($slots);
        foreach ($slots as $slotNum => $slotData) {
            // Seitenumbruch prüfen
            $neededHeight = 7 + 6 + (count($slotData['students']) * 6) + 4;
            if ($pdf->GetY() + $neededHeight > 270) {
                $pdf->AddPage();
            }
            $pdf->SlotSection($slotData['info'], $slotData['students']);
        }
    }
}

// PDF ausgeben
if ($filterRoom) {
    $roomData = array_filter($rooms, fn($r) => $r['id'] == $filterRoom);
    $roomName = !empty($roomData) ? 'Hoersaal_' . array_values($roomData)[0]['room_number'] : 'Hoersaal';
    $filename = 'BSO_' . $roomName . '_' . date('Y-m-d') . '.pdf';
} else {
    $filename = 'BSO_Alle_Hoersaele_' . date('Y-m-d') . '.pdf';
}

$pdf->Output('D', $filename);

} catch (Exception $e) {
    logErrorToAudit($e, 'PDF-Raumuebersicht');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Fehler beim Erstellen des PDFs.');
}
