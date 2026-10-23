<?php
// One Burial application's record (Medical & Burial staff): every detail, including the amount on an approved one.
// The page itself is includes/program_pages.php: one copy for Medical, Burial and OPD (and the staff side).
require_once '../config.php';
define('UI_AREA', 'staff');   // links into staff/ pages
require_once '../includes/admin_layout.php';
require_once '../includes/program_pages.php';

program_view_page(2);
