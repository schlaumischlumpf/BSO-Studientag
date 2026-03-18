<?php
require_once '../config.php';
require_once '../functions.php';

requireLogin();
if (!isAdmin() && !hasPermission('berichte_sehen')) {
    http_response_code(403); die('Keine Berechtigung');
}

try {
    $db              = getDB();
    $activeEditionId = getActiveEditionId();

    $format      = in_array($_GET['format'] ?? 'csv', ['csv', 'xlsx']) ? $_GET['format'] : 'csv';
    $type        = in_array($_GET['type']   ?? 'registrations', ['registrations','attendance','unregistered'])
                   ? $_GET['type'] : 'registrations';
    $filterClass = trim($_GET['class']        ?? '');
    $filterEid   = intval($_GET['exhibitor_id'] ?? 0);
    $filterTid   = intval($_GET['timeslot_id']  ?? 0);

    if ($type === 'registrations') {
        $sql = "SELECT u.lastname, u.firstname, u.class,
                       e.name AS exhibitor_name, t.slot_name, t.start_time, t.end_time,
                       r.room_number, reg.registration_type, reg.registered_at
                FROM registrations reg
                JOIN users u      ON reg.user_id      = u.id
                JOIN exhibitors e ON reg.exhibitor_id = e.id  AND e.edition_id = ?
                JOIN timeslots t  ON reg.timeslot_id  = t.id  AND t.edition_id = ?
                LEFT JOIN rooms r ON e.room_id         = r.id
                WHERE u.role = 'student' AND reg.edition_id = ?";
        $params = [$activeEditionId, $activeEditionId, $activeEditionId];
        if ($filterClass) { $sql .= " AND u.class = ?"; $params[] = $filterClass; }
        if ($filterEid)   { $sql .= " AND reg.exhibitor_id = ?"; $params[] = $filterEid; }
        if ($filterTid)   { $sql .= " AND reg.timeslot_id  = ?"; $params[] = $filterTid; }
        $sql .= " ORDER BY u.class, u.lastname, u.firstname, t.slot_number";
        $headers = ['Nachname','Vorname','Klasse','Aussteller','Slot','Von','Bis','Raum','Typ','Angemeldet am'];
        $typeMap  = ['manual' => 'Manuell', 'automatic' => 'Automatisch', 'admin' => 'Admin'];
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['registration_type'] = $typeMap[$row['registration_type']] ?? $row['registration_type'];
        }
        unset($row);

    } elseif ($type === 'attendance') {
        $sql = "SELECT u.lastname, u.firstname, u.class,
                       e.name AS exhibitor_name, t.slot_name, a.checked_in_at
                FROM attendance a
                JOIN users u      ON a.user_id      = u.id
                JOIN exhibitors e ON a.exhibitor_id = e.id AND e.edition_id = ?
                JOIN timeslots t  ON a.timeslot_id  = t.id AND t.edition_id = ?
                WHERE u.role = 'student' AND a.edition_id = ?";
        $params = [$activeEditionId, $activeEditionId, $activeEditionId];
        if ($filterClass) { $sql .= " AND u.class = ?"; $params[] = $filterClass; }
        if ($filterEid)   { $sql .= " AND a.exhibitor_id = ?"; $params[] = $filterEid; }
        if ($filterTid)   { $sql .= " AND a.timeslot_id  = ?"; $params[] = $filterTid; }
        $sql .= " ORDER BY t.slot_number, u.class, u.lastname";
        $headers = ['Nachname','Vorname','Klasse','Aussteller','Slot','Check-in um'];
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();

    } else { // unregistered
        $sql = "SELECT u.lastname, u.firstname, u.class, u.username
                FROM users u
                LEFT JOIN registrations reg ON reg.user_id = u.id AND reg.edition_id = ?
                WHERE u.role = 'student' AND reg.id IS NULL
                ORDER BY u.class, u.lastname, u.firstname";
        $stmt = $db->prepare($sql); $stmt->execute([$activeEditionId]);
        $rows    = $stmt->fetchAll();
        $headers = ['Nachname','Vorname','Klasse','Benutzername'];
    }

    // Entities in allen Werten dekodieren
    foreach ($rows as &$row) {
        foreach ($row as &$val) {
            if (is_string($val)) $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    unset($row, $val);

    if ($format === 'csv') {
        $filename = 'BSO_Export_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Windows-Excel
        fputcsv($out, $headers, ',', '"');
        foreach ($rows as $row) fputcsv($out, array_values($row), ',', '"');
        fclose($out);
        exit();
    }

    // XLSX via ZipArchive
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="BSO_Export_' . date('Y-m-d_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers); foreach ($rows as $row) fputcsv($out, array_values($row));
        fclose($out); exit();
    }

    // Shared strings aufbauen
    $strings = []; $strIndex = [];
    $addStr  = function(string $s) use (&$strings, &$strIndex): int {
        if (!isset($strIndex[$s])) { $strIndex[$s] = count($strings); $strings[] = $s; }
        return $strIndex[$s];
    };
    foreach ($headers as $h) $addStr($h);
    foreach ($rows as $row) foreach ($row as $val) $addStr((string)$val);

    $ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
    foreach ($strings as $s) $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1, 'UTF-8').'</t></si>';
    $ssXml .= '</sst>';

    $colLetters = ['A','B','C','D','E','F','G','H','I','J','K','L'];
    $sheetXml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $rowNum = 1;
    $buildRow = function(array $vals) use (&$rowNum, $colLetters, $addStr): string {
        $xml = '<row r="'.$rowNum.'">';
        foreach ($vals as $i => $v) {
            $col = $colLetters[$i] ?? chr(ord('A') + $i);
            $xml .= '<c r="'.$col.$rowNum.'" t="s"><v>'.$addStr((string)$v).'</v></c>';
        }
        $xml .= '</row>'; $rowNum++; return $xml;
    };
    $sheetXml .= $buildRow($headers);
    foreach ($rows as $row) $sheetXml .= $buildRow(array_values($row));
    $sheetXml .= '</sheetData></worksheet>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'bm_export_');
    $zip = new ZipArchive(); $zip->open($tmpFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);
    $zip->close();

    $filename = 'BSO_Export_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($tmpFile); unlink($tmpFile); exit();

} catch (Exception $e) {
    logErrorToAudit($e, 'API-Export');
    if (!headers_sent()) http_response_code(500);
    die('Fehler beim Export.');
}
