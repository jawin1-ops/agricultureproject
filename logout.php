<?php

/**
 * logout.php
 * Destroys the session and redirects to login.
 */

require_once __DIR__ . '/includes/auth.php';

logout();
header('Location: login.html');
exit;
