<?php
// Side-by-side review (photos left, details right, Approve / Disapprove below): admin, every program.
// The page itself is includes/review_page.php; the staff side has its own copy at staff/review.php.
require_once '../config.php';
require_once '../includes/admin_layout.php';
require_once '../includes/review_page.php';

review_page_run();
