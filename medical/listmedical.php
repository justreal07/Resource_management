<?php
// All Medical applications.
// The page itself is includes/program_pages.php: one copy for Medical, Burial and OPD (and the OPD staff side).
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/program_pages.php';

program_list_page(1, 'all');
