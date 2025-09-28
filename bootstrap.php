<?php

@define('TINYBOARD', 'xD');

use Sudochan\Loader\ConfigLoader;

require __DIR__ . '/vendor/autoload.php';

// Load test base class early so PHPUnit can find it when parsing test files.
if (file_exists(__DIR__ . '/tests/AbstractTestCase.php')) {
    require_once __DIR__ . '/tests/AbstractTestCase.php';
}

// Must be included before any other files
ConfigLoader::loadConfig();

// Include core template, and database files
require_once __DIR__ . '/etc/template.php';
require_once __DIR__ . '/etc/database.php';
