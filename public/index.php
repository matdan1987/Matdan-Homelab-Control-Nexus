<?php
/**
 * Index Page
 *
 * Entry point - redirects to dashboard or login.
 */

// Start session
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
