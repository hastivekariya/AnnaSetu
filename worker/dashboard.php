
<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker']);

require __DIR__ . '/../includes/dashboard-view.php';
