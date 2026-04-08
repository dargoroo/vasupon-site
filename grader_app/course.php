<?php

require_once __DIR__ . '/bootstrap.php';

$params = $_GET;
header('Location: ' . graderapp_path('grader.classroom', $params));
exit;
