<?php
require_once 'config.php';
require_once 'functions.php';

// Seitenpasswort prüfen
checkSitePassword();

// Prüfen ob Registrierungsseite aktiviert ist
if (getSetting('registration_page_enabled', '0') !== '1') {
    header('Location: login.php');
    exit();
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstname = sanitize($_POST['firstname'] ?? '');
    $lastname = sanitize($_POST['lastname'] ?? '');
    $class = sanitize($_POST['class'] ?? '');
    $role = 'student'; // Öffentliche Registrierung: nur Student-Rolle erlaubt
    
    // Validierung
    if (empty($username) || empty($password) || empty($firstname) || empty($lastname)) {
        $message = 'Bitte alle Felder ausfüllen';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Passwort muss mindestens 6 Zeichen lang sein';
        $messageType = 'error';
    } else {
        // Prüfen ob Benutzername in aktueller Edition bereits existiert
        $db = getDB();
        $activeEditionId = getActiveEditionId();
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND edition_id = ?");
        $stmt->execute([$username, $activeEditionId]);
        
        if ($stmt->fetch()) {
            $message = 'Benutzername bereits vergeben';
            $messageType = 'error';
        } else {
            // Benutzer anlegen (mit edition_id)
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, firstname, lastname, class, role, edition_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$username, $hashedPassword, $firstname, $lastname, $class, $role, $activeEditionId])) {
                $message = "Benutzer erfolgreich angelegt! Du kannst dich jetzt mit '$username' anmelden.";
                $messageType = 'success';
                
                // Formular zurücksetzen
                $_POST = [];
            } else {
                $message = 'Fehler beim Anlegen des Benutzers';
                $messageType = 'error';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzer Registrierung - BSO-Tag an der FU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'display': ['Plus Jakarta Sans', 'Inter', 'system-ui', 'sans-serif'],
                        'body': ['Inter', 'system-ui', 'sans-serif']
                    },
                    colors: {
                        primary: {
                            50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 300: '#86efac',
                            400: '#4ade80', 500: '#22c55e', 600: '#16a34a', 700: '#15803d',
                            800: '#166534', 900: '#14532d'
                        },
                        accent: {
                            50: '#faf5ff', 100: '#f3e8ff', 200: '#e9d5ff', 300: '#d8b4fe',
                            400: '#c084fc', 500: '#a855f7', 600: '#9333ea', 700: '#7e22ce',
                            800: '#6b21a8', 900: '#581c87'
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .font-display {
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
        }
        
        .animated-bg {
            background: linear-gradient(-45deg, #7c3aed, #8b5cf6, #a855f7, #6366f1, #4f46e5);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        @keyframes gradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .floating {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        
        .input-glow:focus {
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.3);
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-slideUp {
            animation: slideUp 0.5s ease-out;
        }
    </style>
</head>
<body class="animated-bg min-h-screen py-8 px-4">
    <!-- Floating Decorations -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-10 w-72 h-72 bg-white/10 rounded-full blur-3xl floating"></div>
        <div class="absolute bottom-20 right-10 w-96 h-96 bg-white/10 rounded-full blur-3xl floating" style="animation-delay: -3s;"></div>
        <div class="absolute top-1/2 left-1/3 w-64 h-64 bg-white/5 rounded-full blur-2xl floating" style="animation-delay: -5s;"></div>
    </div>
    
    <div class="max-w-7xl mx-auto relative">
        <!-- Warning Banner -->
        <div class="glass-card border border-amber-200 text-amber-900 px-6 py-5 rounded-2xl shadow-xl mb-8 animate-slideUp">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-amber-100 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-amber-500 text-2xl"></i>
                </div>
                <div>
                    <h2 class="font-bold text-lg font-display">⚠️ Temporäre Test-Seite</h2>
                    <p class="text-sm text-amber-700">Diese Seite ist nur für Tests gedacht. Löschen Sie diese Datei (register.php) vor dem Produktivbetrieb!</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-8">
            <!-- Registration Form -->
            <div class="glass-card rounded-3xl shadow-2xl p-8 animate-slideUp" style="animation-delay: 100ms;">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-accent-500 to-purple-600 rounded-3xl shadow-xl shadow-accent-500/30 mb-5">
                        <i class="fas fa-user-plus text-white text-3xl"></i>
                    </div>
                    <h1 class="text-3xl font-extrabold text-gray-800 font-display mb-2">Neuen Benutzer anlegen</h1>
                    <p class="text-gray-500">Erstellen Sie Test-Accounts für Schüler oder Admins</p>
                </div>

                <?php if ($message): ?>
                <div class="mb-6 animate-slideUp">
                    <?php if ($messageType === 'success'): ?>
                    <div class="bg-gradient-to-r from-primary-50 to-emerald-50 border border-primary-200 p-5 rounded-2xl">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-check-circle text-primary-500 text-xl"></i>
                            </div>
                            <p class="text-primary-700 font-medium"><?php echo $message; ?></p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-gradient-to-r from-red-50 to-rose-50 border border-red-200 p-5 rounded-2xl">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                            </div>
                            <p class="text-red-700 font-medium"><?php echo $message; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <!-- Role Selection -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-user-tag text-accent-500 mr-2"></i>Rolle *
                        </label>
                        <select name="role" required class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow">
                            <option value="student" <?php echo ($_POST['role'] ?? '') === 'student' ? 'selected' : ''; ?>>👨‍🎓 Schüler</option>
                            <option value="teacher" <?php echo ($_POST['role'] ?? '') === 'teacher' ? 'selected' : ''; ?>>👨‍🏫 Lehrer</option>
                            <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>👑 Administrator</option>
                        </select>
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-user text-accent-500 mr-2"></i>Benutzername *
                        </label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            required
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            placeholder="z.B. max.mueller"
                            class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow"
                        >
                        <p class="text-xs text-gray-400 mt-2">Nur Kleinbuchstaben, Zahlen, Punkt und Unterstrich</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-lock text-accent-500 mr-2"></i>Passwort *
                        </label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            minlength="6"
                            placeholder="Mindestens 6 Zeichen"
                            class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow"
                        >
                    </div>

                    <!-- Names Row -->
                    <div class="grid grid-cols-2 gap-4">
                        <!-- First Name -->
                        <div>
                            <label for="firstname" class="block text-sm font-bold text-gray-700 mb-2">
                                <i class="fas fa-id-card text-accent-500 mr-2"></i>Vorname *
                            </label>
                            <input 
                                type="text" 
                                id="firstname" 
                                name="firstname" 
                                required
                                value="<?php echo htmlspecialchars($_POST['firstname'] ?? ''); ?>"
                                placeholder="Vorname"
                                class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow"
                            >
                        </div>

                        <!-- Last Name -->
                        <div>
                            <label for="lastname" class="block text-sm font-bold text-gray-700 mb-2">
                                Nachname *
                            </label>
                            <input 
                                type="text" 
                                id="lastname" 
                                name="lastname" 
                                required
                                value="<?php echo htmlspecialchars($_POST['lastname'] ?? ''); ?>"
                                placeholder="Nachname"
                                class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow"
                            >
                        </div>
                    </div>

                    <!-- Class (Klasse) - only for students -->
                    <div id="classField">
                        <label for="class" class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-school text-accent-500 mr-2"></i>Klasse
                        </label>
                        <input 
                            type="text" 
                            id="class" 
                            name="class" 
                            value="<?php echo htmlspecialchars($_POST['class'] ?? ''); ?>"
                            placeholder="z.B. 10A, 11B"
                            class="w-full px-5 py-3.5 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-accent-500 focus:border-transparent focus:bg-white transition-all duration-200 font-medium input-glow"
                        >
                        <p class="text-xs text-gray-400 mt-2">Optional - nur für Schüler/Lehrer relevant</p>
                    </div>

                    <!-- Submit Button -->
                    <button 
                        type="submit"
                        class="w-full bg-gradient-to-r from-accent-500 to-purple-600 text-white font-bold py-4 px-6 rounded-xl hover:shadow-xl hover:shadow-accent-500/30 transform hover:scale-[1.02] transition-all duration-300"
                    >
                        <i class="fas fa-user-plus mr-2"></i>Benutzer anlegen
                    </button>
                </form>

                <!-- Quick Actions -->
                <div class="mt-8 pt-6 border-t border-gray-100">
                    <p class="text-sm text-gray-500 mb-4 font-semibold">Schnellzugriff:</p>
                    <div class="flex gap-3">
                        <a href="login.php" class="flex-1 text-center bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 text-blue-700 px-4 py-3 rounded-xl hover:shadow-lg transition-all duration-300 font-semibold">
                            <i class="fas fa-sign-in-alt mr-2"></i>Zum Login
                        </a>
                        <a href="index.php" class="flex-1 text-center bg-gradient-to-r from-primary-50 to-emerald-50 border border-primary-100 text-primary-700 px-4 py-3 rounded-xl hover:shadow-lg transition-all duration-300 font-semibold">
                            <i class="fas fa-home mr-2"></i>Zur App
                        </a>
                    </div>
                </div>
            </div>


        </div>

        <!-- Info Box -->
        <div class="mt-8 glass-card rounded-3xl shadow-2xl p-8 animate-slideUp" style="animation-delay: 300ms;">
            <h3 class="text-xl font-extrabold text-gray-800 font-display mb-6 flex items-center gap-3">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-xl flex items-center justify-center shadow-lg">
                    <i class="fas fa-info-circle text-white"></i>
                </div>
                Hinweise zur Nutzung
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-2xl p-5">
                    <h4 class="font-bold text-blue-900 mb-3 flex items-center gap-2">
                        <i class="fas fa-user-graduate text-blue-500"></i>Schüler-Accounts
                    </h4>
                    <ul class="space-y-2 text-sm text-blue-700">
                        <li class="flex items-start gap-2"><i class="fas fa-check text-blue-400 mt-1"></i>Aussteller durchsuchen</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-blue-400 mt-1"></i>Für Aussteller einschreiben</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-blue-400 mt-1"></i>Automatische Zuteilung</li>
                    </ul>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-100 rounded-2xl p-5">
                    <h4 class="font-bold text-amber-900 mb-3 flex items-center gap-2">
                        <i class="fas fa-chalkboard-teacher text-amber-500"></i>Lehrer-Accounts
                    </h4>
                    <ul class="space-y-2 text-sm text-amber-700">
                        <li class="flex items-start gap-2"><i class="fas fa-check text-amber-400 mt-1"></i>Klassenlisten einsehen</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-amber-400 mt-1"></i>Schüler verwalten</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-amber-400 mt-1"></i>Pläne drucken</li>
                    </ul>
                </div>
                <div class="bg-gradient-to-br from-red-50 to-rose-50 border border-red-100 rounded-2xl p-5">
                    <h4 class="font-bold text-red-900 mb-3 flex items-center gap-2">
                        <i class="fas fa-crown text-red-500"></i>Admin-Accounts
                    </h4>
                    <ul class="space-y-2 text-sm text-red-700">
                        <li class="flex items-start gap-2"><i class="fas fa-check text-red-400 mt-1"></i>Voller Zugriff</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-red-400 mt-1"></i>Aussteller verwalten</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check text-red-400 mt-1"></i>Einstellungen ändern</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Security Warning -->
        <div class="mt-8 bg-gradient-to-r from-red-600 to-rose-600 text-white rounded-3xl shadow-2xl p-8 animate-slideUp" style="animation-delay: 400ms;">
            <div class="flex items-start gap-5">
                <div class="w-16 h-16 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-shield-alt text-3xl"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-extrabold font-display mb-3">🔒 Sicherheitshinweis</h3>
                    <p class="mb-4 text-white/90">Diese Registrierungsseite ist <strong>nicht für den Produktivbetrieb</strong> gedacht!</p>
                    <ul class="space-y-2 text-sm text-white/80">
                        <li class="flex items-center gap-2">
                            <i class="fas fa-trash-alt"></i>
                            Löschen Sie die Datei <code class="bg-white/20 px-2 py-1 rounded-lg font-mono">register.php</code> vor dem Live-Gang
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-lock"></i>
                            Oder schützen Sie sie mit einem starken Passwort
                        </li>
                        <li class="flex items-center gap-2">
                            <i class="fas fa-user-shield"></i>
                            In Produktion sollten Accounts nur vom Admin angelegt werden
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
