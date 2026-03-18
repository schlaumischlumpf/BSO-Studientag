<?php
/**
 * Berufsmesse - Admin Print Export
 * Professionelle Druckansicht für Lehrkräfte und Administratoren
 * Mit Pastel-Design und PDF-optimiertem Layout
 */

require_once '../config.php';
require_once '../functions.php';

// Ensure DB connection is available when opened directly
$db = getDB();
$activeEditionId = getActiveEditionId();

// Session prüfen
if (!isLoggedIn() || (!isAdmin() && !isTeacher())) {
    header('Location: ../login.php');
    exit;
}

// Filter
$printType = $_GET['type'] ?? 'all';
$filterClass = $_GET['class'] ?? '';
$filterRoom = $_GET['room'] ?? '';

// Daten laden
if ($printType === 'all' || $printType === 'class') {
    $query = "
        SELECT 
            u.firstname, u.lastname, u.class,
            e.name as exhibitor_name,
            t.slot_name, t.slot_number, t.start_time, t.end_time,
            r.room_number
        FROM registrations reg
        JOIN users u ON reg.user_id = u.id
        JOIN exhibitors e ON reg.exhibitor_id = e.id
        JOIN timeslots t ON reg.timeslot_id = t.id
        LEFT JOIN rooms r ON e.room_id = r.id
        WHERE u.role = 'student'
        AND reg.edition_id = ? AND e.edition_id = ?
    ";
    
    $params = [$activeEditionId, $activeEditionId];
    if ($filterClass) {
        $query .= " AND u.class = ?";
        $params[] = $filterClass;
    }
    
    $query .= " ORDER BY u.class, u.lastname, u.firstname, t.slot_number";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
    
} elseif ($printType === 'rooms') {
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
        WHERE reg.edition_id = ? AND e.edition_id = ?
    ";
    
    $params = [$activeEditionId, $activeEditionId];
    if ($filterRoom) {
        $query .= " AND r.id = ?";
        $params[] = intval($filterRoom);
    }
    
    $query .= " ORDER BY r.room_number, t.slot_number, u.lastname, u.firstname";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
}

// Pausen-Slots laden (für Tagesplan in Druckansicht)
$stmtBreaks = $db->prepare("SELECT * FROM timeslots WHERE edition_id = ? AND is_break = 1 ORDER BY start_time ASC, slot_number ASC");
$stmtBreaks->execute([$activeEditionId]);
$breakSlots = $stmtBreaks->fetchAll();

// Räume für Titel
$stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE edition_id = ? ORDER BY room_number");
$stmt->execute([$activeEditionId]);
$rooms = $stmt->fetchAll();

// Titel bestimmen
if ($printType === 'all') {
    $docTitle = 'Gesamtübersicht';
} elseif ($printType === 'class') {
    $docTitle = $filterClass ? "Klasse $filterClass" : 'Alle Klassen';
} elseif ($printType === 'rooms') {
    if ($filterRoom) {
        $roomData = array_filter($rooms, fn($r) => $r['id'] == $filterRoom);
        $docTitle = !empty($roomData) ? 'Hörsaal ' . array_values($roomData)[0]['room_number'] : 'Hörsaalübersicht';
    } else {
        $docTitle = 'Alle Hörsäle';
    }
}

$eventDate = getSetting('event_date') ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSO-Tag an der FU - <?php echo htmlspecialchars($docTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --color-mint: #a8e6cf;
            --color-mint-light: #d4f5e4;
            --color-lavender: #c3b1e1;
            --color-lavender-light: #e8dff5;
            --color-sky: #b5deff;
            --color-sky-light: #e3f3ff;
            --color-peach: #ffb7b2;
            --color-peach-light: #ffe5e2;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: white;
            color: #1f2937;
            line-height: 1.5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        /* Screen Controls */
        .screen-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, var(--color-mint) 0%, var(--color-lavender) 100%);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .screen-controls h1 {
            color: #1f2937;
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .control-buttons {
            display: flex;
            gap: 0.75rem;
        }
        
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #059669;
            color: white;
        }
        
        .btn-primary:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 2px solid #E5E7EB;
        }
        
        .btn-secondary:hover {
            background: #F9FAFB;
        }
        
        /* Print Container */
        .print-container {
            max-width: 210mm;
            margin: 5rem auto 2rem;
            padding: 1.5rem;
        }
        
        /* Document Header */
        .doc-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding-bottom: 1.5rem;
            border-bottom: 3px solid var(--color-mint);
            margin-bottom: 1.5rem;
        }
        
        .doc-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--color-mint) 0%, var(--color-lavender) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        
        .logo-text h1 {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .logo-text p {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .doc-meta {
            text-align: right;
        }
        
        .meta-date {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .meta-info {
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        /* Section Styling */
        .section {
            margin-bottom: 2rem;
            page-break-inside: avoid;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--color-mint-light) 0%, var(--color-sky-light) 100%);
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .section-header.room {
            background: linear-gradient(135deg, var(--color-sky-light) 0%, var(--color-lavender-light) 100%);
        }
        
        .section-icon {
            width: 36px;
            height: 36px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .section-count {
            font-size: 0.75rem;
            color: #6b7280;
            background: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            margin-left: auto;
        }
        
        /* Student Card */
        .student-card {
            background: #fafafa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--color-mint);
        }
        
        .student-name {
            font-weight: 700;
            font-size: 1rem;
            color: #1f2937;
            margin-bottom: 0.75rem;
        }
        
        /* Table Styling */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        
        .data-table thead th {
            background: #f3f4f6;
            padding: 0.5rem 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .data-table tbody td {
            padding: 0.625rem 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Slot Badge */
        .slot-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.6875rem;
            font-weight: 600;
            background: var(--color-lavender-light);
            color: #5b21b6;
        }
        
        .slot-badge.assigned {
            background: var(--color-mint-light);
            color: #065f46;
        }
        
        .slot-badge.break-badge {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Slot Section (for rooms view) */
        .slot-section {
            margin-bottom: 1.5rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .slot-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--color-lavender-light) 0%, #f3e8ff 100%);
            border-bottom: 1px solid #e5e7eb;
        }
        
        .slot-time {
            font-weight: 700;
            color: #5b21b6;
        }
        
        .slot-exhibitor {
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        /* Footer */
        .doc-footer {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        /* Print Styles */
        @media print {
            /* Verstecke alle UI-Elemente beim Drucken */
            .screen-controls,
            .fas.fa-cog,
            .fas.fa-ellipsis-v,
            button[class*="settings"],
            .settings-icon {
                display: none !important;
            }
            
            .print-container {
                margin: 0;
                padding: 0;
                max-width: none;
            }
            
            /* Entferne Browser-Kopf- und Fußzeilen */
            @page {
                size: A4 portrait;
                margin: 12mm;
                /* Entfernt automatisch Browser-Kopf-/Fußzeilen */
                marks: none;
            }
            
            .section {
                page-break-inside: avoid;
            }
            
            .student-card {
                page-break-inside: avoid;
            }
        }
        
        @media screen and (max-width: 640px) {
            .screen-controls {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .print-container {
                margin-top: 8rem;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Screen Controls -->
    <div class="screen-controls">
        <h1><i class="fas fa-file-alt mr-2"></i> <?php echo htmlspecialchars($docTitle); ?></h1>
        <div class="control-buttons">
            <a href="<?php echo BASE_URL; ?>?page=admin-print" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Zurück
            </a>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Drucken / PDF
            </button>
        </div>
    </div>
    
    <!-- Print Content -->
    <div class="print-container">
        <!-- Document Header -->
        <header class="doc-header">
            <div class="doc-logo">
                <div class="logo-icon">🎓</div>
                <div class="logo-text">
                    <h1>BSO-Tag an der FU <?php echo date('Y'); ?></h1>
                    <p><?php echo htmlspecialchars($docTitle); ?></p>
                </div>
            </div>
            <div class="doc-meta">
                <div class="meta-date"><?php echo formatDate($eventDate); ?></div>
                <div class="meta-info">Erstellt am <?php echo date('d.m.Y, H:i'); ?> Uhr</div>
                <div class="meta-info"><?php echo count($registrations); ?> Einträge</div>
            </div>
        </header>
        
        <?php if ($printType === 'all' || $printType === 'class'): ?>
            <?php
            // Nach Klasse gruppieren
            $groupedByClass = [];
            foreach ($registrations as $reg) {
                $class = $reg['class'] ?: 'Keine Klasse';
                $studentKey = $reg['lastname'] . ', ' . $reg['firstname'];
                $groupedByClass[$class][$studentKey][] = $reg;
            }
            ksort($groupedByClass);
            ?>
            
            <?php foreach ($groupedByClass as $class => $students): ?>
                <div class="section">
                    <div class="section-header">
                        <div class="section-icon">🎓</div>
                        <div class="section-title"><?php echo htmlspecialchars($class); ?></div>
                        <div class="section-count"><?php echo count($students); ?> Schüler</div>
                    </div>
                    
                    <?php 
                    ksort($students);
                    foreach ($students as $studentName => $regs): 
                        usort($regs, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
                    ?>
                        <div class="student-card">
                            <div class="student-name"><?php echo htmlspecialchars($studentName); ?></div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Slot</th>
                                        <th style="width: 100px;">Zeit</th>
                                        <th>Fachbereich</th>
                                        <th style="width: 100px;">Hörsaal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // Merge registrations with break slots and sort by start_time
                                    $fullSchedule = [];
                                    foreach ($regs as $reg) {
                                        $fullSchedule[] = ['type' => 'reg', 'start_time' => $reg['start_time'], 'data' => $reg];
                                    }
                                    foreach ($breakSlots as $brk) {
                                        $fullSchedule[] = ['type' => 'break', 'start_time' => $brk['start_time'], 'data' => $brk];
                                    }
                                    usort($fullSchedule, fn($a, $b) => strcmp($a['start_time'] ?? '', $b['start_time'] ?? ''));
                                    foreach ($fullSchedule as $entry): 
                                        if ($entry['type'] === 'break'): $brk = $entry['data']; ?>
                                    <tr style="background: #fffbeb;">
                                        <td>
                                            <span class="slot-badge break-badge">
                                                ☕ <?php echo htmlspecialchars($brk['slot_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo substr($brk['start_time'], 0, 5) . ' - ' . substr($brk['end_time'], 0, 5); ?></td>
                                        <td colspan="2" style="color: #92400e; font-style: italic;">Pause</td>
                                    </tr>
                                    <?php else: $reg = $entry['data']; ?>
                                    <tr>
                                        <td>
                                            <span class="slot-badge assigned">
                                                <?php echo htmlspecialchars($reg['slot_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('H:i', strtotime($reg['start_time'])) . ' - ' . date('H:i', strtotime($reg['end_time'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></strong></td>
                                        <td><?php echo htmlspecialchars($reg['room_number'] ?? '—'); ?></td>
                                    </tr>
                                    <?php endif; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            
        <?php elseif ($printType === 'rooms'): ?>
            <?php
            // Nach Hörsaal und Slot gruppieren
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
            ?>
            
            <?php foreach ($groupedByRoom as $roomNum => $slots): ?>
                <div class="section">
                    <div class="section-header room">
                        <div class="section-icon">🚪</div>
                        <div class="section-title">Hörsaal <?php echo htmlspecialchars($roomNum); ?></div>
                        <div class="section-count"><?php echo array_sum(array_map(fn($s) => count($s['students']), $slots)); ?> Besuche</div>
                    </div>
                    
                    <?php 
                    ksort($slots);
                    foreach ($slots as $slotNum => $slotData): 
                        $info = $slotData['info'];
                        usort($slotData['students'], fn($a, $b) => strcmp($a['lastname'], $b['lastname']));
                    ?>
                        <div class="slot-section">
                            <div class="slot-header">
                                <span class="slot-time">
                                    <?php echo htmlspecialchars($info['slot_name']); ?> 
                                    (<?php echo date('H:i', strtotime($info['start_time'])) . ' - ' . date('H:i', strtotime($info['end_time'])); ?>)
                                </span>
                                <span class="slot-exhibitor">— <?php echo htmlspecialchars(html_entity_decode($info['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></span>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">Nr.</th>
                                        <th>Name</th>
                                        <th style="width: 120px;">Klasse</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($slotData['students'] as $idx => $student): ?>
                                    <tr>
                                        <td><strong><?php echo $idx + 1; ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['lastname'] . ', ' . $student['firstname']); ?></td>
                                        <td><?php echo htmlspecialchars($student['class'] ?? '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Footer -->
        <footer class="doc-footer">
            <div>
                <p>BSO-Tag an der FU <?php echo date('Y'); ?> · <?php echo htmlspecialchars($docTitle); ?></p>
                <p>Erstellt von: <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></p>
            </div>
            <div style="text-align: right;">
                <p>Seite 1</p>
            </div>
        </footer>
    </div>
    
    <script>
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>
