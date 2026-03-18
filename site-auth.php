<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Wenn Seitenpasswort nicht aktiv, direkt weiterleiten
if (getSetting('site_password_enabled', '0') !== '1' || empty(getSetting('site_password', ''))) {
    header('Location: ' . BASE_URL . 'login.php');
    exit();
}

// Bereits authentifiziert?
if (isset($_SESSION['site_authenticated']) && $_SESSION['site_authenticated'] === true) {
    $redirect = $_GET['redirect'] ?? '';
    if (!empty($redirect) && preg_match('#^/[^/]#', $redirect)) {
        header('Location: ' . $redirect);
    } else {
        header('Location: ' . BASE_URL . 'login.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $enteredPassword = $_POST['site_password'] ?? '';
    $storedHash = getSetting('site_password', '');

    if (!empty($storedHash) && password_verify($enteredPassword, $storedHash)) {
        $_SESSION['site_authenticated'] = true;
        $redirect = $_POST['redirect'] ?? (BASE_URL . 'login.php');
        // Sicherheitscheck: Nur relative URLs erlauben
        if (!preg_match('#^/[^/]#', $redirect) && strpos($redirect, BASE_URL) !== 0) {
            $redirect = BASE_URL . 'login.php';
        }
        header('Location: ' . $redirect);
        exit();
    } else {
        $error = 'Falsches Zugangspasswort';
    }
}

$redirect = $_GET['redirect'] ?? (BASE_URL . 'login.php');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zugangscode - Berufsmesse</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --color-pastel-mint: #a8e6cf;
            --color-pastel-mint-light: #d4f5e4;
            --color-pastel-lavender: #c3b1e1;
            --color-pastel-lavender-light: #e8dff5;
            --color-pastel-peach: #ffb7b2;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, var(--color-pastel-mint-light) 50%, var(--color-pastel-lavender-light) 100%);
            min-height: 100vh;
        }

        .auth-card {
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .input-field {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-field:focus {
            border-color: var(--color-pastel-mint);
            box-shadow: 0 0 0 4px rgba(168, 230, 207, 0.3);
        }

        .btn-auth {
            background: linear-gradient(135deg, var(--color-pastel-mint) 0%, #6bc4a6 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(168, 230, 207, 0.4);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md relative z-10">
        <!-- Logo/Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-2xl shadow-xl mb-4" style="background: linear-gradient(135deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);">
                <i class="fas fa-lock text-white text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Berufsmesse</h1>
            <p class="text-gray-500">Bitte geben Sie den Zugangscode ein.</p>
        </div>

        <!-- Auth Card -->
        <div class="auth-card bg-white/90 backdrop-blur-lg rounded-3xl shadow-2xl p-8 border border-white/50">
            <h2 class="text-xl font-bold text-gray-800 mb-6 text-center">Zugangscode</h2>

            <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl border" style="background: var(--color-pastel-peach); border-color: rgba(255, 183, 178, 0.5);">
                <div class="flex items-center">
                    <div class="w-8 h-8 rounded-lg bg-white/50 flex items-center justify-center mr-3">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                    </div>
                    <p class="text-gray-800 text-sm font-medium"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

                <div>
                    <label for="site_password" class="block text-sm font-medium text-gray-700 mb-2">
                        Zugangscode
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-key text-gray-400"></i>
                        </div>
                        <input
                            type="password"
                            id="site_password"
                            name="site_password"
                            required
                            autofocus
                            class="input-field w-full pl-11 pr-4 py-3.5 border-2 border-gray-200 rounded-xl focus:outline-none text-gray-800"
                            placeholder="Zugangscode eingeben"
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn-auth w-full text-gray-800 font-semibold py-3.5 px-6 rounded-xl shadow-lg flex items-center justify-center gap-2"
                >
                    <i class="fas fa-unlock-alt"></i>
                    <span>Zugang freischalten</span>
                </button>
            </form>
        </div>

        <div class="text-center mt-8 text-gray-500 text-sm">
            <p>&copy; 2026 Berufsmesse. Alle Rechte vorbehalten.</p>
        </div>
    </div>
</body>
</html>
