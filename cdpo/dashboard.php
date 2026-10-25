
<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['cdpo']);

require __DIR__ . '/../includes/dashboard-view.php';
