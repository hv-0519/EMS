<?php
require_once __DIR__ . '/includes/auth.php';
redirect(isLoggedIn() ? APP_URL . '/dashboard/dashboard.php' : APP_URL . '/auth/login.php');
