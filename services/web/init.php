<?php

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ConfigGenerator.php';

use App\Database;
use App\ConfigGenerator;

echo "Initializing Lightbox Server database and configurations...\n";

try {
    // This will trigger database schema creation and seeding
    $db = Database::getInstance();
    
    // Generate all default configurations so bind9, dnsmasq, chrony, and samba have working configs immediately
    $generator = new ConfigGenerator($db);
    $generator->generateAll();
    
    echo "Initialization complete successfully!\n";
} catch (Exception $e) {
    echo "Error during initialization: " . $e->getMessage() . "\n";
    exit(1);
}
