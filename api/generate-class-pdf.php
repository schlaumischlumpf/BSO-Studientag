<?php
/**
 * PDF-Generator für Klassenübersichten
 * Tabellenformat, sortiert nach Nachname, keine Farben
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
$filterClass = $_GET['class'] ?? '';

// Timeslots laden (für Spaltenüberschriften) - nur Managed-Slots
$stmt = $db->prepare("SELECT * FROM timeslots WHERE slot_number " . getManagedSlotsSqlIn() . " AND timeslots.edition_id = ? ORDER BY start_time ASC, slot_number ASC");
$stmt->execute([$activeEditionId]);
$timeslots = $stmt->fetchAll();

// Daten laden
$query = "
    SELECT
        u.id as user_id, u.firstname, u.lastname, u.class,
        e.name as exhibitor_name,
        t.slot_name, t.slot_number, t.start_time, t.end_time,
        r.room_number
    FROM registrations reg
    JOIN users u ON reg.user_id = u.id
    JOIN exhibitors e ON reg.exhibitor_id = e.id
    JOIN timeslots t ON reg.timeslot_id = t.id
    LEFT JOIN rooms r ON e.room_id = r.id AND r.edition_id = ?
    WHERE u.role = 'student'
    AND reg.edition_id = ? AND e.edition_id = ? AND t.edition_id = ?
";

$params = [$activeEditionId, $activeEditionId, $activeEditionId, $activeEditionId];
if ($filterClass) {
    $query .= " AND u.class = ?";
    $params[] = $filterClass;
}

$query .= " ORDER BY u.class, u.lastname, u.firstname, t.slot_number";

$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Nach Klasse → Schüler (user_id) gruppieren, Slots indexieren
$groupedByClass = [];
foreach ($registrations as $reg) {
    $class = $reg['class'] ?: 'Keine Klasse';
    $uid   = $reg['user_id'];
    if (!isset($groupedByClass[$class][$uid])) {
        $groupedByClass[$class][$uid] = [
            'name'  => $reg['lastname'] . ', ' . $reg['firstname'],
            'slots' => [],
        ];
    }
    $groupedByClass[$class][$uid]['slots'][$reg['slot_number']] = [
        'exhibitor' => $reg['exhibitor_name'],
        'room'      => $reg['room_number'] ?? '-',
        'slot_name' => $reg['slot_name'],
        'start'     => $reg['start_time'],
        'end'       => $reg['end_time'],
    ];
}
ksort($groupedByClass);

// Helper: UTF-8 zu Latin-1 konvertieren
function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str ?? '');
}

// Titel bestimmen
$docTitle = $filterClass ? "Klasse $filterClass" : 'Alle Klassen';
$eventDate = getSetting('event_date') ?? date('Y-m-d');

// -------------------------------------------------------------------------
// PDF Klasse
// -------------------------------------------------------------------------
class ClassPDF extends FPDF {
    public $eventDate;
    public $docTitle;

    function Header() {
        // Schwarze Rahmenlinie oben
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.5);

        $pageW = $this->GetPageWidth();

        // Titel links
        $this->SetFont('Arial', 'B', 14);
        $this->SetXY(10, 10);
        $this->Cell($pageW - 100, 7, conv("BSO-Tag an der FU " . date('Y', strtotime($this->eventDate)) . "  \u{2013}  " . $this->docTitle), 0, 0);

        // Datum rechts
        $this->SetFont('Arial', '', 9);
        $this->SetXY($pageW - 80, 10);
        $this->Cell(70, 4, date('d.m.Y', strtotime($this->eventDate)), 0, 1, 'R');
        $this->SetXY($pageW - 80, 14);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(70, 3, 'Erstellt: ' . date('d.m.Y H:i') . ' Uhr', 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);

        // Trennlinie
        $this->SetY(20);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(10, $this->GetY(), $pageW - 10, $this->GetY());
        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-13);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $pageW = $this->GetPageWidth();
        $this->Line(10, $this->GetY(), $pageW - 10, $this->GetY());
        $this->Ln(1);
        $this->Cell(0, 5, conv('BSO-Tag an der FU ' . date('Y', strtotime($this->eventDate)) . ' – ' . $this->docTitle . ' – Seite ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Tabellenüberschrift für eine Klasse inkl. Spaltenköpfen
     */
    function ClassTableHeader($className, $studentCount, $timeslots) {
        // Klassenzeile – einfache Überschrift ohne Box
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8,
            conv($className) . '  (' . $studentCount . ' ' . conv('Schüler') . ')',
            0, 1, 'L', false);
        $this->Ln(1);

        // Spaltenköpfe
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(240, 240, 240);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);

        // Breiten: Querformat (277mm nutzbar)
        $nameW  = 55;
        $rest   = $this->GetPageWidth() - 20 - $nameW;
        $slotW  = $rest / max(1, count($timeslots));

        // Name-Spalte (volle Höhe)
        $this->Cell($nameW, 12, conv('Name'), 1, 0, 'L', true);
        
        // Slot-Spalten mit zweizeiligem Header
        $startX = $this->GetX();
        $startY = $this->GetY();
        foreach ($timeslots as $idx => $ts) {
            $slotNum = $ts['slot_number'];
            $times = '';
            if (!empty($ts['start_time']) && !empty($ts['end_time'])) {
                $times = '(' . date('H:i', strtotime($ts['start_time'])) . '-' . date('H:i', strtotime($ts['end_time'])) . ')';
            }
            
            $xPos = $startX + ($idx * $slotW);
            
            // Hintergrund-Rechteck für die gesamte Zelle
            $this->Rect($xPos, $startY, $slotW, 12, 'FD');
            
            // Zeile 1: Slot-Nummer
            $this->SetXY($xPos, $startY + 2);
            $this->Cell($slotW, 4, conv($ts['slot_name']), 0, 0, 'C', false);
            
            // Zeile 2: Zeiten
            $this->SetXY($xPos, $startY + 6);
            $this->Cell($slotW, 4, $times, 0, 0, 'C', false);
        }
        
        $this->SetXY($startX, $startY + 12);
        $this->Ln();
    }

    /**
     * Eine Schülerzeile in der Tabelle
     */
    function StudentRow($studentName, $slots, $timeslots, $nameW, $slotW, $rowAlt) {
        $fill = $rowAlt ? 250 : 255; // leicht abwechselnde Zeilen (fast weiß)
        $this->SetFillColor($fill, $fill, $fill);
        $this->SetFont('Arial', '', 8);
        $this->SetDrawColor(160, 160, 160);
        $this->SetLineWidth(0.2);

        // Zeilenhöhe
        $h = 6;

        $this->Cell($nameW, $h, conv($studentName), 1, 0, 'L', true);
        foreach ($timeslots as $ts) {
            $sn   = $ts['slot_number'];
            $cell = '';
            if (isset($slots[$sn])) {
                $info = $slots[$sn];
                $cell = conv($info['exhibitor']);
                // Raum wird nicht mehr angezeigt
            }
            $this->Cell($slotW, $h, $cell, 1, 0, 'C', true);
        }
        $this->Ln();
    }
}

// -------------------------------------------------------------------------
// PDF erzeugen
// -------------------------------------------------------------------------
$pdf = new ClassPDF();
$pdf->eventDate = $eventDate;
$pdf->docTitle  = $docTitle;
$pdf->SetTitle('BSO-Tag an der FU - ' . $docTitle);
$pdf->SetAuthor($_SESSION['firstname'] . ' ' . $_SESSION['lastname']);
$pdf->SetCreator('BSO-Tag System');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 18);

// Spaltenbreiten vorab berechnen (Querformat: 297mm - 20mm Ränder = 277mm)
$nameW = 55;
$rest  = 222;
$slotW = count($timeslots) > 0 ? $rest / count($timeslots) : $rest;

if (empty($groupedByClass)) {
    $pdf->AddPage('L'); // Querformat
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 20, conv('Keine Registrierungen gefunden.'), 0, 1, 'C');
} else {
    foreach ($groupedByClass as $class => $studentsById) {
        // Schüler nach Name (Nachname, Vorname) sortieren
        uasort($studentsById, fn($a, $b) => strcmp($a['name'], $b['name']));

        $pdf->AddPage('L'); // Querformat
        $pdf->ClassTableHeader($class, count($studentsById), $timeslots);

        $rowAlt = false;
        foreach ($studentsById as $studentData) {
            // Seitenumbruch: Header-Wiederholung auf neuer Seite
            if ($pdf->GetY() + 7 > $pdf->GetPageHeight() - 18) {
                $pdf->AddPage('L');
                $pdf->ClassTableHeader($class . ' (Forts.)', count($studentsById), $timeslots);
            }
            $pdf->StudentRow($studentData['name'], $studentData['slots'], $timeslots, $nameW, $slotW, $rowAlt);
            $rowAlt = !$rowAlt;
        }
    }
}

// PDF ausgeben
$filename = 'BSO_' . ($filterClass ? str_replace(' ', '_', $filterClass) : 'Alle_Klassen') . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
} catch (Exception $e) {
    logErrorToAudit($e, 'PDF-Klassenuebersicht');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Fehler beim Erstellen des PDFs.');
}