<?php
/**
 * Berufsmesse - Erweiterte Druckansicht
 * Professionelle Druckfunktion für Schüler und Lehrer
 * Mit Pastel-Design und PDF-optimiertem Layout
 */

// Prüfen ob direkt aufgerufen oder über index.php
if (!isset($db)) {
    require_once '../config.php';
    require_once '../functions.php';
    
    // Session prüfen
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit;
    }
}

// Drucktyp bestimmen
$printMode = $_GET['print_mode'] ?? 'personal';

// Benutzer-Registrierungen laden
$stmt = $db->prepare("
    SELECT 
        r.*,
        e.name as exhibitor_name,
        e.short_description,
        e.website,
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
    LEFT JOIN rooms rm ON e.room_id = rm.id
    WHERE r.user_id = ?
    ORDER BY t.slot_number ASC
");
$stmt->execute([$_SESSION['user_id']]);
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
        'time' => substr($slot['start_time'] ?? '00:00', 0, 5),
        'end'  => substr($slot['end_time'] ?? '00:00', 0, 5),
        'label' => $slot['slot_name'],
        'type' => $isBreak ? 'break' : ($isManaged ? 'assigned' : 'free'),
        'slot' => $isBreak ? null : $slot['slot_number'],
        'icon' => $isBreak ? '☕' : ($isManaged ? '📋' : '👆'),
    ];
}

// Messedatum (kann über Einstellungen gesetzt werden)
$eventDate = getSetting('event_date') ?? date('Y-m-d');

// Helper für Farben
function getPrintColor($type) {
    switch ($type) {
        case 'assigned': return ['bg' => '#E8F5E9', 'border' => '#81C784', 'text' => '#2E7D32'];
        case 'free': return ['bg' => '#F3E5F5', 'border' => '#BA68C8', 'text' => '#7B1FA2'];
        case 'break': return ['bg' => '#FFF8E1', 'border' => '#FFD54F', 'text' => '#F57F17'];
        default: return ['bg' => '#F5F5F5', 'border' => '#BDBDBD', 'text' => '#616161'];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BSO-Tag an der FU - Mein Zeitplan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ============================================
           PRINT-OPTIMIERTE STYLES
           Berufsmesse - Pastel Design System
           ============================================ */
        
        :root {
            --color-mint: #a8e6cf;
            --color-mint-light: #d4f5e4;
            --color-lavender: #c3b1e1;
            --color-lavender-light: #e8dff5;
            --color-peach: #ffb7b2;
            --color-peach-light: #ffe5e2;
            --color-sky: #b5deff;
            --color-sky-light: #e3f3ff;
            --color-butter: #fff3b0;
            --color-butter-light: #fffbe0;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: white;
            color: #1f2937;
            line-height: 1.5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        
        /* Screen Controls - nur auf Bildschirm sichtbar */
        .screen-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, var(--color-mint) 0%, var(--color-sky) 100%);
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
        
        .btn-print {
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-print-primary {
            background: #059669;
            color: white;
        }
        
        .btn-print-primary:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        
        .btn-print-secondary {
            background: white;
            color: #374151;
            border: 2px solid #E5E7EB;
        }
        
        .btn-print-secondary:hover {
            background: #F9FAFB;
            border-color: #D1D5DB;
        }
        
        /* Print Container */
        .print-container {
            max-width: 210mm;
            margin: 5rem auto 2rem;
            padding: 1.5rem;
        }
        
        /* Header Section */
        .print-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding-bottom: 1.5rem;
            border-bottom: 3px solid var(--color-mint);
            margin-bottom: 1.5rem;
        }
        
        .print-logo {
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
            color: #1f2937;
        }
        
        .logo-text p {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .print-meta {
            text-align: right;
        }
        
        .meta-date {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }
        
        .meta-info {
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        /* User Info Card */
        .user-info-card {
            background: linear-gradient(135deg, var(--color-mint-light) 0%, var(--color-sky-light) 100%);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--color-mint) 0%, var(--color-lavender) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        .user-details h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1f2937;
        }
        
        .user-details p {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .user-stats {
            display: flex;
            gap: 1.5rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #059669;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        /* Schedule Table */
        .schedule-section {
            margin-bottom: 1.5rem;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .schedule-table thead th {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            border-bottom: 2px solid #d1d5db;
        }
        
        .schedule-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .schedule-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .schedule-table td {
            padding: 0.875rem 0.75rem;
            vertical-align: middle;
        }
        
        /* Time Cell */
        .time-cell {
            font-weight: 600;
            white-space: nowrap;
            width: 100px;
        }
        
        .time-main {
            font-size: 1rem;
            color: #1f2937;
        }
        
        .time-end {
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        /* Event Cell */
        .event-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .event-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .event-icon.assigned { background: #dcfce7; }
        .event-icon.free { background: #f3e8ff; }
        .event-icon.break { background: #fef3c7; }
        .event-icon.info { background: #f3f4f6; }
        
        .event-content {
            flex: 1;
            min-width: 0;
        }
        
        .event-title {
            font-weight: 600;
            font-size: 0.9375rem;
            color: #1f2937;
        }
        
        .event-subtitle {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        /* Location Cell */
        .location-cell {
            white-space: nowrap;
        }
        
        .location-room {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            color: #1f2937;
        }
        
        .location-building {
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .status-badge.confirmed {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-badge.free-choice {
            background: #f3e8ff;
            color: #7c3aed;
        }
        
        /* Legend */
        .legend-section {
            background: #f9fafb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        
        .legend-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        
        .legend-items {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: #4b5563;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }
        
        .legend-dot.assigned { background: #86efac; }
        .legend-dot.free { background: #d8b4fe; }
        .legend-dot.break { background: #fde047; }
        
        /* Footer */
        .print-footer {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        .qr-code {
            width: 60px;
            height: 60px;
            background: #f3f4f6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
        }
        
        /* ============================================
           PRINT SPECIFIC STYLES
           ============================================ */
        
        @media print {
            /* Verstecke alle UI-Elemente beim Drucken */
            .screen-controls,
            .fas.fa-cog,
            .fas.fa-ellipsis-v,
            button[class*="settings"],
            .settings-icon {
                display: none !important;
            }
            
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .print-container {
                margin: 0;
                padding: 0;
                max-width: none;
            }
            
            /* Entferne Browser-Kopf- und Fußzeilen */
            @page {
                size: A4 portrait;
                margin: 15mm;
                /* Entfernt automatisch Browser-Kopf-/Fußzeilen */
                marks: none;
            }
            
            .schedule-table {
                box-shadow: none;
            }
            
            .schedule-table thead th {
                background: #f3f4f6 !important;
            }
            
            .event-icon {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .user-info-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .status-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .legend-dot {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        /* Responsive für Bildschirmansicht */
        @media screen and (max-width: 640px) {
            .screen-controls {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }
            
            .print-container {
                padding: 1rem;
                margin-top: 8rem;
            }
            
            .print-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .print-meta {
                text-align: left;
            }
            
            .user-info-card {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .user-info {
                flex-direction: column;
            }
            
            .schedule-table {
                font-size: 0.875rem;
            }
            
            .schedule-table thead th {
                padding: 0.5rem;
                font-size: 0.625rem;
            }
            
            .schedule-table td {
                padding: 0.625rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Screen Controls (nicht druckbar) -->
    <div class="screen-controls">
        <h1><i class="fas fa-calendar-alt mr-2"></i> Druckvorschau - Mein Zeitplan</h1>
        <div class="control-buttons">
            <button class="btn-print btn-print-secondary" onclick="history.back()">
                <i class="fas fa-arrow-left"></i> Zurück
            </button>
            <button class="btn-print btn-print-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Drucken / Als PDF speichern
            </button>
        </div>
    </div>
    
    <!-- Druckbarer Inhalt -->
    <div class="print-container">
        <!-- Header -->
        <header class="print-header">
            <div class="print-logo">
                <div class="logo-icon">🎓</div>
                <div class="logo-text">
                    <h1>BSO-Tag an der FU</h1>
                    <p>Persönlicher Zeitplan</p>
                </div>
            </div>
            <div class="print-meta">
                <div class="meta-date"><?php echo formatDate($eventDate); ?></div>
                <div class="meta-info">Erstellt am <?php echo date('d.m.Y, H:i'); ?> Uhr</div>
            </div>
        </header>
        
        <!-- User Info Card -->
        <div class="user-info-card">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1) . substr($_SESSION['lastname'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h2><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></h2>
                    <p><?php echo htmlspecialchars($_SESSION['class'] ?? 'Klasse nicht angegeben'); ?></p>
                </div>
            </div>
            <div class="user-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo count($registrations); ?></div>
                    <div class="stat-label">Anmeldungen</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo count($schedule); ?></div>
                    <div class="stat-label">Zeitslots</div>
                </div>
            </div>
        </div>
        
        <!-- Schedule Section -->
        <div class="schedule-section">
            <h3 class="section-title">
                <i class="fas fa-clock"></i> Tagesablauf
            </h3>
            
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">Zeit</th>
                        <th>Aktivität / Fachbereich</th>
                        <th style="width: 140px;">Hörsaal</th>
                        <th style="width: 100px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedule as $item): 
                        $hasReg = $item['slot'] && isset($regBySlot[$item['slot']]);
                        $reg = $hasReg ? $regBySlot[$item['slot']] : null;
                    ?>
                    <tr>
                        <!-- Zeit -->
                        <td class="time-cell">
                            <div class="time-main"><?php echo $item['time']; ?></div>
                            <div class="time-end"><?php echo $item['end']; ?></div>
                        </td>
                        
                        <!-- Event -->
                        <td>
                            <div class="event-cell">
                                <div class="event-icon <?php echo $item['type']; ?>">
                                    <?php echo $item['icon']; ?>
                                </div>
                                <div class="event-content">
                                    <?php if ($hasReg): ?>
                                        <div class="event-title"><?php echo htmlspecialchars(html_entity_decode($reg['exhibitor_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></div>
                                        <div class="event-subtitle"><?php echo $item['label']; ?> (<?php echo $item['type'] === 'assigned' ? 'Zuteilung' : 'Freie Wahl'; ?>)</div>
                                    <?php elseif ($item['slot']): ?>
                                        <div class="event-title"><?php echo $item['label']; ?></div>
                                        <div class="event-subtitle">
                                            <?php echo $item['type'] === 'free' ? 'Freie Wahl vor Ort' : 'Zuteilung noch ausstehend'; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="event-title"><?php echo $item['label']; ?></div>
                                        <div class="event-subtitle">
                                            <?php echo $item['type'] === 'break' ? 'Zeit für Austausch & Verpflegung' : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        
                        <!-- Location -->
                        <td class="location-cell">
                            <?php if ($hasReg && $reg['room_number']): ?>
                                <div class="location-room">
                                    <i class="fas fa-map-marker-alt" style="color: #059669;"></i>
                                    Raum: <?php echo htmlspecialchars($reg['room_number']); ?>
                                </div>
                            <?php elseif ($item['slot']): ?>
                                <span style="color: #9ca3af;">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Status -->
                        <td>
                            <?php if ($hasReg): ?>
                                <span class="status-badge confirmed">
                                    <i class="fas fa-check mr-1"></i> Bestätigt
                                </span>
                            <?php elseif ($item['type'] === 'free'): ?>
                                <span class="status-badge free-choice">
                                    Vor Ort
                                </span>
                            <?php elseif ($item['type'] === 'assigned'): ?>
                                <span class="status-badge pending">
                                    Ausstehend
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Legend -->
        <div class="legend-section">
            <div class="legend-title">Legende</div>
            <div class="legend-items">
                <div class="legend-item">
                    <span class="legend-dot assigned"></span>
                    <span>Feste Zuteilung</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot free"></span>
                    <span>Freie Wahl vor Ort</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot break"></span>
                    <span>Pause / Verpflegung</span>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="print-footer">
            <div>
                <p>BSO-Tag an der FU <?php echo date('Y'); ?> · <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></p>
                <p>Bei Fragen wende dich an deine Lehrkraft.</p>
            </div>
            <div class="qr-code">
                <i class="fas fa-qrcode fa-2x"></i>
            </div>
        </footer>
    </div>
    
    <script>
        // Keyboard shortcut for printing
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>
