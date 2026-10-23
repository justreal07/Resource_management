<?php
// Status and remarks for one Burial application. Medical & Burial staff never decide one (the page says so):
// only an administrator approves or declines Medical and Burial, through this same form.
// The page itself is includes/program_pages.php: one copy for Medical, Burial and OPD (and the staff side).
require_once '../config.php';
define('UI_AREA', 'staff');   // links into staff/ pages
require_once '../includes/admin_layout.php';
require_once '../includes/program_pages.php';

program_update_page(2);
