<?php
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit();
}

try {

$activeEditionId = getActiveEditionId();
$exhibitorId = intval($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'info';

if (!$exhibitorId) {
    echo json_encode(['success' => false, 'message' => 'Ungültige ID']);
    exit();
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM exhibitors WHERE id = ? AND exhibitors.edition_id = ?");
$stmt->execute([$exhibitorId, $activeEditionId]);
$exhibitor = $stmt->fetch();

if (!$exhibitor) {
    echo json_encode(['success' => false, 'message' => 'Aussteller nicht gefunden']);
    exit();
}

// Raum-Kapazität abrufen
$stmt = $db->prepare("
    SELECT r.capacity 
    FROM exhibitors e 
    LEFT JOIN rooms r ON e.room_id = r.id 
    WHERE e.id = ? AND e.edition_id = ?
");
$stmt->execute([$exhibitorId, $activeEditionId]);
$roomData = $stmt->fetch();

$roomCapacity = $roomData && $roomData['capacity'] ? intval($roomData['capacity']) : 0;
$totalCapacity = $roomCapacity > 0 ? floor($roomCapacity / 3) * 3 : 0;

// Registrierungsstatistik
$stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM registrations WHERE exhibitor_id = ? AND registrations.edition_id = ?");
$stmt->execute([$exhibitorId, $activeEditionId]);
$registeredCount = $stmt->fetch()['count'];

$content = '';

switch ($tab) {
    case 'info':
        $content = generateInfoTab($exhibitor, $registeredCount, $totalCapacity);
        break;
    case 'documents':
        $content = generateDocumentsTab($exhibitorId);
        break;
    case 'contact':
        $content = generateContactTab($exhibitor);
        break;
    case 'details':
        $content = generateDetailsTab($exhibitor);
        break;
}

echo json_encode([
    'success' => true,
    'exhibitor' => $exhibitor,
    'content' => $content
]);

} catch (Exception $e) {
    logErrorToAudit($e, 'API-AusstellerInfo');
    echo json_encode(['success' => false, 'message' => 'Fehler beim Laden des Ausstellers.']);
}

// Neue Funktion für Schüler-Detailansicht
function generateDetailsTab($exhibitor) {
    // Angebot aus offer_types JSON laden (Fallback: aus Beschreibung parsen)
    $angebot = [];
    if (!empty($exhibitor['offer_types'])) {
        $offerData = json_decode($exhibitor['offer_types'], true);
        if ($offerData && !empty($offerData['selected'])) {
            $angebot = $offerData['selected'];
        }
        $offerCustom = (!empty($offerData['custom'])) ? $offerData['custom'] : '';
    } else {
        $desc = strtolower($exhibitor['short_description'] . ' ' . $exhibitor['description']);
        if (strpos($desc, 'ausbildung') !== false) $angebot[] = 'Ausbildung';
        if (strpos($desc, 'studium') !== false || strpos($desc, 'dual') !== false) $angebot[] = 'Duales Studium';
        if (strpos($desc, 'praktikum') !== false) $angebot[] = 'Praktikum';
        $offerCustom = '';
    }
    if (empty($angebot) && empty($offerCustom)) $angebot[] = 'Ausbildung';
    
    // Sichtbare Felder
    $visibleFields = isset($exhibitor['visible_fields']) ? json_decode($exhibitor['visible_fields'], true) : ['name', 'short_description', 'description', 'category', 'website', 'offer_types'];
    if (!is_array($visibleFields)) {
        $visibleFields = ['name', 'short_description', 'description', 'category', 'website', 'offer_types'];
    }
    $isVisible = function($field) use ($visibleFields) {
        return in_array($field, $visibleFields);
    };
    
    // Berufe/Tätigkeiten (aus jobs Feld oder Description parsen)
    $jobs = $exhibitor['jobs'] ?? '';
    
    // Kategorien dekodieren (wird als JSON-Array gespeichert)
    $categoryRaw = $exhibitor['category'] ?? '';
    $categoryLabels = [];
    if ($categoryRaw) {
        $decoded = json_decode($categoryRaw, true);
        $categoryLabels = is_array($decoded) ? $decoded : [$categoryRaw];
    }
    $categoryDisplay = !empty($categoryLabels) ? implode(', ', $categoryLabels) : 'Allgemein';
    
    // Besonderheiten
    $besonderheiten = $exhibitor['features'] ?? '';
    
    ob_start();
    ?>
    <div class="space-y-6">
        <!-- Firmenname und Logo -->
        <div class="flex items-center space-x-4 pb-5 border-b border-gray-100">
            <div class="w-16 h-16 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0">
                <?php if ($exhibitor['logo']): ?>
                    <img src="<?php echo BASE_URL . 'uploads/' . $exhibitor['logo']; ?>" 
                         alt="<?php echo htmlspecialchars($exhibitor['name']); ?>" 
                         class="w-14 h-14 object-contain">
                <?php else: ?>
                    <i class="fas fa-building text-gray-300 text-2xl"></i>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($exhibitor['name']); ?></h3>
                <span class="inline-flex items-center px-2.5 py-1 mt-2 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700">
                    <i class="fas fa-tag mr-1.5"></i> <?php echo htmlspecialchars($categoryDisplay); ?>
                </span>
            </div>
        </div>

        <!-- Kurzbeschreibung -->
        <?php if ($exhibitor['short_description']): ?>
        <div>
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Kurzbeschreibung</h4>
            <p class="text-gray-700 leading-relaxed"><?php echo htmlspecialchars($exhibitor['short_description']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Branche -->
        <div>
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Branche</h4>
            <?php if (!empty($categoryLabels)): ?>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($categoryLabels as $cat): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-emerald-50 text-emerald-700">
                    <i class="fas fa-tag mr-2"></i><?php echo htmlspecialchars($cat); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-400 italic">Keine Angabe</p>
            <?php endif; ?>
        </div>

        <!-- Typische Berufe/Tätigkeiten -->
        <div>
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Typische Berufe / Tätigkeiten</h4>
            <?php if ($jobs): ?>
                <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($jobs)); ?></p>
            <?php else: ?>
                <p class="text-gray-400 italic">Keine Angabe</p>
            <?php endif; ?>
        </div>

        <!-- Angebot (Ausbildung/Studium/Praktikum) -->
        <?php if ($isVisible('offer_types') && (!empty($angebot) || !empty($offerCustom))): ?>
        <div>
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Angebot für Schüler</h4>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($angebot as $a): ?>
                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-emerald-50 text-emerald-700">
                    <i class="fas fa-graduation-cap mr-2"></i> <?php echo htmlspecialchars($a); ?>
                </span>
                <?php endforeach; ?>
                <?php if (!empty($offerCustom)): ?>
                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-blue-50 text-blue-700">
                    <i class="fas fa-star mr-2"></i> <?php echo htmlspecialchars($offerCustom); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Besonderheiten -->
        <div>
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Besonderheiten</h4>
            <?php if ($besonderheiten): ?>
                <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($besonderheiten)); ?></p>
            <?php elseif ($exhibitor['description']): ?>
                <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($exhibitor['description'])); ?></p>
            <?php else: ?>
                <p class="text-gray-400 italic">Keine Angabe</p>
            <?php endif; ?>
        </div>

        <!-- Website -->
        <?php if ($exhibitor['website']): ?>
        <div class="pt-4 border-t border-gray-100">
            <a href="http://<?php echo htmlspecialchars($exhibitor['website']); ?>" 
               target="_blank" 
               class="inline-flex items-center text-emerald-600 hover:text-emerald-700 font-medium">
                <i class="fas fa-globe mr-2"></i>
                Website besuchen
                <i class="fas fa-external-link-alt ml-2 text-xs"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php
        // Dokumente für Schüler anzeigen (nur visible_for_students = 1)
        $db = getDB();
        $activeEditionId = getActiveEditionId();
        $stmtDocs = $db->prepare("SELECT * FROM exhibitor_documents WHERE exhibitor_id = ? AND visible_for_students = 1 AND exhibitor_documents.edition_id = ? ORDER BY uploaded_at DESC");
        $stmtDocs->execute([$exhibitor['id'], $activeEditionId]);
        $visibleDocuments = $stmtDocs->fetchAll();
        if (!empty($visibleDocuments)):
        ?>
        <div class="pt-4 border-t border-gray-100">
            <h4 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                <i class="fas fa-file-alt mr-1"></i> Dokumente
            </h4>
            <div class="space-y-2">
                <?php foreach ($visibleDocuments as $doc):
                    $iconClass = match($doc['file_type']) {
                        'pdf' => 'fa-file-pdf text-red-500',
                        'doc', 'docx' => 'fa-file-word text-blue-500',
                        'ppt', 'pptx' => 'fa-file-powerpoint text-orange-500',
                        'jpg', 'jpeg', 'png', 'gif' => 'fa-file-image text-green-500',
                        default => 'fa-file text-gray-500'
                    };
                    $fileSize = round($doc['file_size'] / 1024, 2);
                ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <i class="fas <?php echo $iconClass; ?> text-2xl flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($doc['original_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo $fileSize; ?> KB</p>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>api/download-document.php?id=<?php echo intval($doc['id']); ?>"
                       class="ml-3 bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition text-sm flex-shrink-0">
                        <i class="fas fa-download mr-1"></i>Download
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function generateInfoTab($exhibitor, $registeredCount, $totalCapacity) {
    $availableSlots = $totalCapacity - $registeredCount;
    $percentage = ($totalCapacity > 0) ? ($registeredCount / $totalCapacity * 100) : 0;
    
    // Sichtbare Felder ermitteln (Issue #9)
    $visibleFields = isset($exhibitor['visible_fields']) ? json_decode($exhibitor['visible_fields'], true) : ['name', 'short_description', 'description', 'category', 'website'];
    if (!is_array($visibleFields)) {
        $visibleFields = ['name', 'short_description', 'description', 'category', 'website'];
    }
    
    // Helper-Funktion für Sichtbarkeitsprüfung
    $isVisible = function($field) use ($visibleFields) {
        return in_array($field, $visibleFields);
    };
    
    ob_start();
    ?>
    <div class="space-y-6">
        <!-- Status Card -->
        <div class="bg-gradient-to-r from-blue-50 to-blue-50 rounded-xl p-6 border border-blue-200">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="text-center">
                    <div class="text-3xl font-bold text-blue-600"><?php echo $totalCapacity; ?></div>
                    <div class="text-sm text-gray-600 mt-1">Gesamtplätze</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-600"><?php echo $availableSlots; ?></div>
                    <div class="text-sm text-gray-600 mt-1">Verfügbar</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-gray-600"><?php echo $registeredCount; ?></div>
                    <div class="text-sm text-gray-600 mt-1">Angemeldet</div>
                </div>
            </div>
            
            <div class="mt-4">
                <div class="flex items-center justify-between text-sm mb-2">
                    <span class="text-gray-600">Auslastung</span>
                    <span class="font-semibold text-gray-800"><?php echo round($percentage); ?>%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <?php 
                    $colorClass = $percentage >= 90 ? 'bg-red-500' : ($percentage >= 70 ? 'bg-yellow-500' : 'bg-green-500');
                    ?>
                    <div class="<?php echo $colorClass; ?> h-3 rounded-full transition-all duration-500" 
                         style="width: <?php echo min($percentage, 100); ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Kategorie (wenn sichtbar) -->
        <?php if ($isVisible('category') && $exhibitor['category']): ?>
        <?php
            $catRaw2 = $exhibitor['category'];
            $catArr2 = json_decode($catRaw2, true);
            $catArr2 = is_array($catArr2) ? $catArr2 : [$catRaw2];
        ?>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($catArr2 as $cat2): ?>
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm bg-purple-100 text-purple-800">
                <i class="fas fa-tag mr-2"></i><?php echo htmlspecialchars($cat2); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Kurzbeschreibung (wenn sichtbar) -->
        <?php if ($isVisible('short_description') && $exhibitor['short_description']): ?>
        <div class="bg-gray-50 rounded-lg p-4 border-l-4 border-blue-500">
            <p class="text-gray-700 font-medium"><?php echo htmlspecialchars($exhibitor['short_description']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Beschreibung (wenn sichtbar) -->
        <?php if ($isVisible('description')): ?>
        <div>
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-3"></i>
                Über uns
            </h3>
            <div class="prose max-w-none text-gray-700 leading-relaxed">
                <?php echo nl2br(htmlspecialchars($exhibitor['description'] ?? 'Keine Beschreibung verfügbar.')); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Website Link (wenn sichtbar) -->
        <?php if ($isVisible('website') && $exhibitor['website']): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-globe text-blue-600 text-xl mr-3"></i>
                    <div>
                        <div class="font-semibold text-gray-800">Webseite besuchen</div>
                        <div class="text-sm text-gray-600"><?php echo htmlspecialchars($exhibitor['website']); ?></div>
                    </div>
                </div>
                <a href="http://<?php echo htmlspecialchars($exhibitor['website']); ?>" 
                   target="_blank" 
                   class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                    Öffnen <i class="fas fa-external-link-alt ml-2"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function generateDocumentsTab($exhibitorId) {
    $db = getDB();
    $activeEditionId = getActiveEditionId();
    $stmt = $db->prepare("SELECT * FROM exhibitor_documents WHERE exhibitor_id = ? AND visible_for_students = 1 AND exhibitor_documents.edition_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$exhibitorId, $activeEditionId]);
    $documents = $stmt->fetchAll();
    
    ob_start();
    ?>
    <div class="space-y-4">
        <?php if (empty($documents)): ?>
        <div class="text-center py-12">
            <i class="fas fa-folder-open text-6xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 text-lg">Keine Dokumente verfügbar</p>
        </div>
        <?php else: ?>
            <?php foreach ($documents as $doc): 
                $iconClass = match($doc['file_type']) {
                    'pdf' => 'fa-file-pdf text-red-500',
                    'doc', 'docx' => 'fa-file-word text-blue-500',
                    'ppt', 'pptx' => 'fa-file-powerpoint text-orange-500',
                    'jpg', 'jpeg', 'png', 'gif' => 'fa-file-image text-green-500',
                    default => 'fa-file text-gray-500'
                };
                $fileSize = round($doc['file_size'] / 1024, 2);
            ?>
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <div class="flex items-center space-x-4 flex-1 min-w-0">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $iconClass; ?> text-3xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-gray-800 truncate">
                            <?php echo htmlspecialchars($doc['original_name']); ?>
                        </p>
                        <p class="text-sm text-gray-500">
                            <?php echo $fileSize; ?> KB · <?php echo formatDateTime($doc['uploaded_at']); ?>
                        </p>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>uploads/<?php echo htmlspecialchars($doc['filename']); ?>" 
                   download 
                   class="ml-4 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex-shrink-0">
                    <i class="fas fa-download mr-2"></i>Download
                </a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function generateContactTab($exhibitor) {
    // Sichtbare Felder ermitteln (Issue #9)
    $visibleFields = isset($exhibitor['visible_fields']) ? json_decode($exhibitor['visible_fields'], true) : ['name', 'short_description', 'description', 'category', 'website'];
    if (!is_array($visibleFields)) {
        $visibleFields = ['name', 'short_description', 'description', 'category', 'website'];
    }
    
    // Helper-Funktion für Sichtbarkeitsprüfung
    $isVisible = function($field) use ($visibleFields) {
        return in_array($field, $visibleFields);
    };
    
    ob_start();
    ?>
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Kontaktperson -->
            <?php if ($isVisible('contact_person') && $exhibitor['contact_person']): ?>
            <div class="bg-gradient-to-br from-blue-50 to-blue-50 rounded-xl p-6 border border-blue-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-blue-600 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-user text-white text-xl"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800">Ansprechpartner</h4>
                        <p class="text-sm text-gray-600">Ihr Kontakt</p>
                    </div>
                </div>
                <p class="text-lg font-semibold text-gray-800">
                    <?php echo htmlspecialchars($exhibitor['contact_person']); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Email -->
            <?php if ($isVisible('email') && $exhibitor['email']): ?>
            <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-6 border border-blue-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-blue-600 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-envelope text-white text-xl"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800">E-Mail</h4>
                        <p class="text-sm text-gray-600">Kontaktiere uns</p>
                    </div>
                </div>
                <a href="mailto:<?php echo htmlspecialchars($exhibitor['email']); ?>" 
                   class="text-lg text-blue-600 hover:text-blue-700 font-semibold break-all">
                    <?php echo htmlspecialchars($exhibitor['email']); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Telefon -->
            <?php if ($isVisible('phone') && $exhibitor['phone']): ?>
            <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-6 border border-green-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-green-600 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-phone text-white text-xl"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800">Telefon</h4>
                        <p class="text-sm text-gray-600">Rufe uns an</p>
                    </div>
                </div>
                <a href="tel:<?php echo htmlspecialchars($exhibitor['phone']); ?>" 
                   class="text-lg text-green-600 hover:text-green-700 font-semibold">
                    <?php echo htmlspecialchars($exhibitor['phone']); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Website -->
            <?php if ($isVisible('website') && $exhibitor['website']): ?>
            <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-xl p-6 border border-orange-200">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-orange-600 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-globe text-white text-xl"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800">Webseite</h4>
                        <p class="text-sm text-gray-600">Mehr erfahren</p>
                    </div>
                </div>
                <a href="http://<?php echo htmlspecialchars($exhibitor['website']); ?>" 
                   target="_blank"
                   class="text-lg text-orange-600 hover:text-orange-700 font-semibold break-all">
                    <?php echo htmlspecialchars($exhibitor['website']); ?>
                    <i class="fas fa-external-link-alt ml-2 text-sm"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Karte oder zusätzliche Infos -->
        <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
            <h4 class="font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                Hinweis
            </h4>
            <p class="text-gray-600">
                Bei Fragen oder Interesse kannst Du den Aussteller direkt über die oben angegebenen Kontaktdaten erreichen. 
                Nutze die Berufsmesse, um Dich persönlich zu informieren und deine Fragen direkt zu stellen.
            </p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
