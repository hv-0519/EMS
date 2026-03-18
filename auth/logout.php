<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn()) {
    logActivity('Logged out', 'User signed out', 'auth');
    logoutUser();
}
redirect(APP_URL . '/auth/login.php');
