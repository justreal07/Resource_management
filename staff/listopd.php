<?php
// All OPD requests (staff).
// The page itself is includes/program_pages.php: one copy for Medical, Burial and OPD (and the OPD staff side).
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';
require_once '../includes/program_pages.php';

program_list_page(3, 'all');
