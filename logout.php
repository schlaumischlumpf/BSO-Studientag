<?php
require_once 'config.php';
require_once 'functions.php';

logAuditAction('Logout', 'Benutzer hat sich abgemeldet');

// Session-Daten löschen
$_SESSION = [];

// Persistentes Session-Cookie im Browser löschen
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();
header('Location: ' . BASE_URL . 'login.php');
exit();
?>
