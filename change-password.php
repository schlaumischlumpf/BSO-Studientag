<?php
require_once 'config.php';
require_once 'functions.php';

// Zugriff nur erlauben wenn angemeldet und das Flag in der DB gesetzt ist
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit();
}

// Prüfe DB-Flag
$db = getDB();
$stmt = $db->prepare("SELECT must_change_password FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !$user['must_change_password']) {
    // Kein erzwungener Passwortwechsel nötig
    header('Location: ' . BASE_URL . 'index.php');
    exit();
}

// Setze Session-Flag für Kompatibilität mit anderen Teilen der App
$_SESSION['force_password_change'] = true;

$error = '';
$success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($newPassword) || empty($confirmPassword)) {
            $error = 'Bitte beide Passwortfelder ausfüllen';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Das Passwort muss mindestens 8 Zeichen lang sein';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Die Passwörter stimmen nicht überein';
        } else {
            $db = getDB();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            
            if ($stmt->execute([$hashedPassword, $_SESSION['user_id']])) {
                $success = 'Passwort erfolgreich geändert! Du wirst weitergeleitet...';
                unset($_SESSION['force_password_change']);
                header('refresh:2;url=' . BASE_URL . 'index.php');
            } else {
                $error = 'Fehler beim Ändern des Passworts';
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort ändern - Berufsmesse</title>
    
    <!-- Fonts -->
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
        }
        
        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, var(--color-pastel-mint-light) 50%, var(--color-pastel-lavender-light) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        html, body {
            height: 100%;
            width: 100%;
        }

        .container {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
            width: 100%;
            z-index: 9999;
        }
        
        .card {
            position: relative;
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 450px;
            padding: 2.5rem;
            margin: 0 auto;
            max-height: calc(100vh - 40px);
            overflow: auto;
        }
        
        .card-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .card-header-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, var(--color-pastel-mint) 0%, var(--color-pastel-lavender) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        
        .card-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .card-header p {
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 0.75rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--color-pastel-mint);
            box-shadow: 0 0 0 3px rgba(168, 230, 207, 0.1);
        }
        
        .requirements {
            margin-top: 0.75rem;
            font-size: 0.85rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .requirements i {
            color: #ef4444;
        }
        
        .requirements.met i {
            color: #10b981;
        }
        
        .btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--color-pastel-mint) 0%, #6bc4a6 100%);
            color: white;
            font-weight: 600;
            border: none;
            border-radius: 0.75rem;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(168, 230, 207, 0.4);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.95rem;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }
        
        .alert i {
            flex-shrink: 0;
            margin-top: 0.125rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">🔐</div>
                <h1>Passwort ändern</h1>
                <p>Willkommen! Bitte ändere dein Passwort beim ersten Login.</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="form-group">
                        <label for="new_password">Neues Passwort</label>
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            placeholder="Mindestens 8 Zeichen"
                            onchange="checkPassword()"
                            oninput="checkPassword()"
                            required
                        >
                        <div class="requirements" id="length-check">
                            <i class="fas fa-circle"></i>
                            <span>Mindestens 8 Zeichen</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Passwort wiederholen</label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            placeholder="Passwort wiederholen"
                            oninput="checkPassword()"
                            required
                        >
                        <div class="requirements" id="match-check">
                            <i class="fas fa-circle"></i>
                            <span>Passwörter stimmen überein</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-check mr-2"></i> Passwort ändern
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function checkPassword() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const lengthCheck = document.getElementById('length-check');
            const matchCheck = document.getElementById('match-check');
            
            // Length check
            if (password.length >= 8) {
                lengthCheck.classList.add('met');
            } else {
                lengthCheck.classList.remove('met');
            }
            
            // Match check
            if (password && confirmPassword && password === confirmPassword) {
                matchCheck.classList.add('met');
            } else {
                matchCheck.classList.remove('met');
            }
        }
        
        // Check on page load
        document.addEventListener('DOMContentLoaded', checkPassword);
    </script>
</body>
</html>
