
<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['helper']);

require __DIR__ . '/../includes/dashboard-view.php';
