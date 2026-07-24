<?php
/**
 * Index Page – Entry point for the ASB Fashion system.
 * Redirects to dashboard if logged in, otherwise to login page.
 */

// Include configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Ensure session is started (startSession() is now defined)
startSession();

// Redirect based on login status
if (isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;