<?php

date_default_timezone_set('Europe/London');

ini_set('error_reporting', E_ALL); // or error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// No composer as ourselves are a composer package too, also we are depending on nothing except stock PHP's mysqli and redis
require_once(__DIR__ . '/../src/AutoDbException.php');
require_once(__DIR__ . '/../src/AutoDb.php');
require_once(__DIR__ . '/../src/AutoRecord.php');

// GITIGNORED FILE - ADD YOUR OWN TO TEST YOURSELF. SAMPLE:
/*
    define('MYSQL_HOST', 'localhost');
    define('MYSQL_USER', 'youruser');
    define('MYSQL_PASSWORD', 'yourpassword');
 */

require_once(__DIR__ . '/../test_mysql_connection_credentials.php');