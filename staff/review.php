<?php
// Side-by-side review (photos left, details right, Approve / Disapprove below): staff side, OPD requests.
// The page itself is includes/review_page.php; the admin side has its own copy at Main_dashboard/review.php.
require_once '../config.php';
define('UI_AREA', 'staff');   // menu, tabs and links stay on the staff side
require_once '../includes/admin_layout.php';
require_once '../includes/review_page.php';

review_page_run();
