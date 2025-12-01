<?php
/**
 * Logout Page
 *
 * Logs out the user and redirects to login page.
 */

// Simple redirect to login with logout action
header('Location: login.php?logged_out=1');
exit;
