<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('admin');
// Admin shares all pages with owner — the owner pages handle both roles
$file = __DIR__ . '/../owner/' . basename(__FILE__);
require $file;
