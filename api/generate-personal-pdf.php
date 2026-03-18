<?php
/**
 * PDF-Generator für persönliche Zeitpläne
 * Verwendet FPDF zur Erstellung professioneller PDFs
 */
require_once '../config.php';
require_once '../functions.php';
require_once '../fpdf/fpdf.php';

// Berechtigungsprüfung
if (!isLoggedIn()) {
    die('Nicht eingeloggt');
}

try {

$db = getDB();
$activeEditionId = getActiveEditionId();

// Helper: UTF-8 zu Latin-1 konvertieren
function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
}

// Benutzer-Registrierungen laden
$stmt = $db->prepare("
    SELECT
        r.*,
        e.name as exhibitor_name,
        e.short_description,
        e.room_id,
        rm.room_number,
        rm.floor,
        t.slot_number,
        t.slot_name,
        t.start_time,
        t.end_time,
        r.registration_type
    FROM registrations r
    JOIN exhibitors e ON r.exhibitor_id = e.id
    JOIN timeslots t ON r.timeslot_id = t.id
    LEFT JOIN rooms rm ON e.room_id = rm.id AND rm.edition_id = ?
    WHERE r.user_id = ?
    AND r.edition_id = ? AND e.edition_id = ? AND t.edition_id = ?
    ORDER BY t.slot_number ASC
");
$stmt->execute([$activeEditionId, $_SESSION['user_id'], $activeEditionId, $activeEditionId, $activeEditionId]);
$registrations = $stmt->fetchAll();

// Nach Slot gruppieren
$regBySlot = [];
foreach ($registrations as $reg) {
    $regBySlot[$reg['slot_number']] = $reg;
}

// Tagesablauf dynamisch aus Datenbank laden
$stmtSlots = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmtSlots->execute([$activeEditionId]);
$dbSlots = $stmtSlots->fetchAll();
$schedule = [];
foreach ($dbSlots as $slot) {
    $isBreak   = !empty($slot['is_break']);
    $isManaged = (bool)$slot['is_managed'];
    $schedule[] = [
        'time'  => substr($slot['start_time'] ?? '00:00', 0, 5),
        'end'   => substr($slot['end_time'] ?? '00:00', 0, 5),
        'label' => $slot['slot_name'],
        'type'  => $isBreak ? 'break' : ($isManaged ? 'assigned' : 'free'),
        'slot'  => $isBreak ? null : $slot['slot_number'],
    ];
}

$eventDate = getSetting('event_date') ?? date('Y-m-d');
$userName = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$userClass = $_SESSION['class'] ?? 'Klasse nicht angegeben';

// PDF Klasse
class PersonalPDF extends FPDF {
    public $eventDate;
    public $userName;
    public $userClass;

    function Header() {
        // Mint-farbener Header-Balken
        $this->SetFillColor(168, 230, 207);
        $this->Rect(10, 10, 190, 22, 'F');

        // Titel
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(15, 13);
        $this->Cell(100, 8, 'BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)), 0, 1);

        $this->SetFont('Arial', '', 10);
        $this->SetXY(15, 21);
        $this->Cell(100, 5, conv('Persönlicher Zeitplan'), 0, 1);

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
        $this->Cell(95, 10, 'BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)) . ' - ' . conv($this->userName), 0, 0, 'L');
        $this->Cell(95, 10, 'Bei Fragen wende dich an deine Lehrkraft.', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    function UserInfoCard($regCount) {
        $y = $this->GetY();

        // Hintergrund
        $this->SetFillColor(212, 245, 228);
        $this->Rect(10, $y, 190, 22, 'F');

        // Initialen-Box
        $this->SetFillColor(168, 230, 207);
        $initials = strtoupper(
            substr($this->userName, 0, 1) .
            substr(strstr($this->userName, ' ') ?: '', 1, 1)
        );
        $this->Rect(15, $y + 4, 14, 14, 'F');
        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(15, $y + 7);
        $this->Cell(14, 8, $initials, 0, 0, 'C');

        // Name und Klasse
        $this->SetFont('Arial', 'B', 12);
        $this->SetXY(34, $y + 4);
        $this->Cell(100, 7, conv($this->userName), 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->SetXY(34, $y + 11);
        $this->Cell(100, 6, conv($this->userClass), 0, 1);

        // Statistiken rechts
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(155, $y + 3);
        $this->Cell(40, 10, $regCount, 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->SetXY(155, $y + 13);
        $this->Cell(40, 5, 'Anmeldungen', 0, 1, 'C');

        $this->SetY($y + 28);
    }

    function ScheduleTable($schedule, $regBySlot) {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'Tagesablauf', 0, 1);
        $this->Ln(1);

        // Tabelle Header
        $this->SetFillColor(243, 244, 246);
        $this->SetDrawColor(200, 200, 200);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(28, 7, 'Zeit', 1, 0, 'L', true);
        $this->Cell(72, 7, conv('Aktivität / Fachbereich'), 1, 0, 'L', true);
        $this->Cell(55, 7, 'Hörsaal', 1, 0, 'L', true);
        $this->Cell(25, 7, 'Status', 1, 1, 'C', true);

        // Zeilen
        $this->SetFont('Arial', '', 8);

        foreach ($schedule as $item) {
            $hasReg = $item['slot'] && isset($regBySlot[$item['slot']]);
            $reg = $hasReg ? $regBySlot[$item['slot']] : null;

            // Hintergrundfarbe je nach Typ
            if ($item['type'] === 'assigned') {
                $this->SetFillColor(220, 252, 231);
            } elseif ($item['type'] === 'free') {
                $this->SetFillColor(243, 232, 255);
            } elseif ($item['type'] === 'break') {
                $this->SetFillColor(254, 243, 199);
            } else {
                $this->SetFillColor(245, 245, 245);
            }

            // Zeit
            $this->Cell(28, 8, $item['time'] . ' - ' . $item['end'], 1, 0, 'L', true);

            // Aktivität
            if ($hasReg) {
                $this->SetFont('Arial', 'B', 8);
                $activityText = $reg['exhibitor_name'];
            } elseif ($item['slot']) {
                $this->SetFont('Arial', '', 8);
                $activityText = $item['label'] . ' (' . ($item['type'] === 'free' ? 'Freie Wahl' : 'Ausstehend') . ')';
            } else {
                $this->SetFont('Arial', '', 8);
                $activityText = $item['label'];
            }
            $this->Cell(72, 8, conv($activityText), 1, 0, 'L', true);
            $this->SetFont('Arial', '', 8);

            // Raum
            $roomText = '';
            if ($hasReg && $reg['room_number']) {
                $roomText = 'Hörsaal ' . $reg['room_number'];
            } elseif ($item['slot']) {
                $roomText = '-';
            }
            $this->Cell(55, 8, conv($roomText), 1, 0, 'L', true);

            // Status
            $statusText = '';
            if ($hasReg) {
                $this->SetFont('Arial', 'B', 7);
                $statusText = conv('Bestätigt');
            } elseif ($item['type'] === 'free') {
                $this->SetFont('Arial', '', 7);
                $statusText = 'Vor Ort';
            } elseif ($item['type'] === 'assigned') {
                $this->SetFont('Arial', '', 7);
                $statusText = 'Ausstehend';
            } else {
                $this->SetFont('Arial', '', 7);
            }
            $this->Cell(25, 8, $statusText, 1, 1, 'C', true);
            $this->SetFont('Arial', '', 8);
        }
    }

    function Legend() {
        $this->Ln(6);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 5, 'Legende:', 0, 1);
        $this->Ln(2);

        $this->SetFont('Arial', '', 8);

        // Feste Zuteilung
        $this->SetFillColor(220, 252, 231);
        $this->Rect($this->GetX(), $this->GetY() + 1, 5, 5, 'F');
        $this->Cell(8, 7, '', 0, 0);
        $this->Cell(45, 7, 'Feste Zuteilung', 0, 0);

        // Freie Wahl
        $this->SetFillColor(243, 232, 255);
        $this->Rect($this->GetX(), $this->GetY() + 1, 5, 5, 'F');
        $this->Cell(8, 7, '', 0, 0);
        $this->Cell(45, 7, 'Freie Wahl vor Ort', 0, 0);

        // Pause
        $this->SetFillColor(254, 243, 199);
        $this->Rect($this->GetX(), $this->GetY() + 1, 5, 5, 'F');
        $this->Cell(8, 7, '', 0, 0);
        $this->Cell(45, 7, 'Pause / Verpflegung', 0, 1);
    }
}

// PDF erstellen
$pdf = new PersonalPDF();
$pdf->eventDate = $eventDate;
$pdf->userName = $userName;
$pdf->userClass = $userClass;
$pdf->SetTitle(conv('BSO-Tag an der FU - Persönlicher Zeitplan'));
$pdf->SetAuthor($userName);
$pdf->SetCreator('BSO-Tag System');
$pdf->AddPage();

$pdf->UserInfoCard(count($registrations));
$pdf->ScheduleTable($schedule, $regBySlot);
$pdf->Legend();

// PDF ausgeben
$filename = 'BSO_Zeitplan_' . str_replace(' ', '_', $userName) . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
} catch (Exception $e) {
    logErrorToAudit($e, 'PDF-PersoenicherZeitplan');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Fehler beim Erstellen des PDFs.');
}