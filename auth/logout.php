<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : auth/logout.php
 *  MODULE  : 1 -- Authentication & Account
 *  PURPOSE : End the session and return to the public site.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

do_logout();

// A fresh session is started so the farewell message survives the
// redirect -- do_logout() destroyed the previous one entirely.
session_start();
session_regenerate_id(true);
flash('success', 'You have been signed out.');

redirect('auth/login.php');
