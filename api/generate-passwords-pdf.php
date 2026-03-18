<?php
/**
 * PDF-Generator: Zugangsdaten für Nutzer ohne Passwort
 *
 * - Generiert für alle Nutzer ohne Passwort ein sicheres Zufallspasswort
 * - Speichert das gehashte Passwort in der Datenbank
 * - Gibt ein PDF aus, sortiert nach: Klasse → Rolle → Nachname
 * - Klartextpasswörter werden NUR in diesem PDF angezeigt – danach nicht mehr abrufbar
 */

require_once '../config.php';
require_once '../functions.php';
require_once '../fpdf/fpdf.php';

// Berechtigungsprüfung
if (!isLoggedIn() || (!isAdmin() && !hasPermission('benutzer_importieren'))) {
    die('Keine Berechtigung');
}

$db = getDB();
$activeEditionId = getActiveEditionId();

// -------------------------------------------------------
// Schritt 1: Alle Nutzer ohne Passwort laden
// -------------------------------------------------------
$stmt = $db->prepare(
    "SELECT id, firstname, lastname, username, role, class
     FROM users
     WHERE (password IS NULL OR password = '')
       AND edition_id = ?
     ORDER BY
       COALESCE(class, 'ZZZZ') ASC,
       role ASC,
       lastname ASC,
       firstname ASC"
);
$stmt->execute([$activeEditionId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    header('Location: ../index.php?page=admin-users&msg=no_passwordless_users');
    exit;
}

// -------------------------------------------------------
// Schritt 2: Passwörter generieren & in DB speichern
// -------------------------------------------------------
$usersWithPasswords = [];

$stmtUpdate = $db->prepare(
    "UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?"
);

foreach ($users as $user) {
    $plainPassword = bin2hex(random_bytes(6));
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmtUpdate->execute([$hashedPassword, $user['id']]);

    $usersWithPasswords[] = array_merge($user, ['plain_password' => $plainPassword]);
}

// Audit-Log
logAuditAction(
    'passwörter_bulk_generiert',
    count($usersWithPasswords) . ' Passwörter generiert und PDF erstellt'
);

// -------------------------------------------------------
// Schritt 3: PDF generieren
// -------------------------------------------------------

// Helper: UTF-8 → Windows-1252 (FPDF benötigt Latin-1)
function conv($str) {
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str ?? '');
}

// Rollennamen auf Deutsch
function roleName($role) {
    return match($role) {
        'student' => 'Schüler/in',
        'teacher' => 'Lehrkraft',
        'admin'   => 'Administrator',
        'orga'    => 'Organisation',
        default   => ucfirst($role),
    };
}

class PasswordPDF extends FPDF {
    protected $title = '';

    function setTitle2($t) { $this->title = $t; }

    function Header() {
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(0, 10, conv($this->title), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, conv('Generiert am ' . date('d.m.Y H:i') . ' Uhr – Passwörter nur einmalig sichtbar!'), 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, conv('Seite ' . $this->PageNo() . ' – Vertraulich'), 0, 0, 'C');
    }
}

$pdf = new PasswordPDF('P', 'mm', 'A4');
$pdf->setTitle2('Zugangsdaten – Generierte Passwörter');
$pdf->SetMargins(15, 25, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// -------------------------------------------------------
// Nutzer nach Klasse gruppieren (Sortierung bereits aus DB)
// -------------------------------------------------------
$groupedByClass = [];
foreach ($usersWithPasswords as $u) {
    $classKey = $u['class'] ?: '– Keine Klasse –';
    $groupedByClass[$classKey][] = $u;
}

// Spaltenbreiten (A4 Inhalt = 180mm)
$colWidths = [50, 55, 50, 25];

$lastClassName = array_key_last($groupedByClass);

foreach ($groupedByClass as $className => $classUsers) {

    // ---- Klassen-Überschrift ----
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(0, 8, conv('Klasse: ' . $className), 0, 1, 'L', true);
    $pdf->Ln(2);

    // ---- Tabellen-Header ----
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(60, 60, 60);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($colWidths[0], 7, conv('Name'), 1, 0, 'L', true);
    $pdf->Cell($colWidths[1], 7, conv('Benutzername'), 1, 0, 'L', true);
    $pdf->Cell($colWidths[2], 7, conv('Passwort'), 1, 0, 'L', true);
    $pdf->Cell($colWidths[3], 7, conv('Rolle'), 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);

    // ---- Zeilen nach Rolle gruppiert anzeigen ----
    $roleOrder = ['student', 'teacher', 'orga', 'admin'];

    $byRole = [];
    foreach ($classUsers as $u) {
        $byRole[$u['role']][] = $u;
    }

    foreach ($roleOrder as $role) {
        if (!isset($byRole[$role])) continue;

        $roleUsers = $byRole[$role];

        foreach ($roleUsers as $index => $u) {
            $fill = ($index % 2 === 0);
            if ($fill) {
                $pdf->SetFillColor(245, 245, 245);
            }

            $pdf->SetFont('Helvetica', '', 9);
            $name = conv($u['lastname'] . ', ' . $u['firstname']);
            $pdf->Cell($colWidths[0], 6, $name, 1, 0, 'L', $fill);

            $pdf->SetFont('Courier', '', 9);
            $pdf->Cell($colWidths[1], 6, conv($u['username']), 1, 0, 'L', $fill);

            $pdf->SetFont('Courier', 'B', 9);
            $pdf->Cell($colWidths[2], 6, conv($u['plain_password']), 1, 0, 'L', $fill);

            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell($colWidths[3], 6, conv(roleName($role)), 1, 1, 'L', $fill);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    $pdf->Ln(6);

    if ($className !== $lastClassName) {
        $pdf->AddPage();
    }
}

// PDF ausgeben
header('Content-Type: application/pdf');
$filename = 'zugangsdaten_' . date('Y-m-d_H-i') . '.pdf';
header('Content-Disposition: attachment; filename="' . $filename . '"');
$pdf->Output('D', $filename);
exit;
