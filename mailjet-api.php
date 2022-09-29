<?php

// Prevent direct execution.
use Rdlv\WordPress\MailjetApi\Setup;

// Prevent direct execution.
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(Setup::class)) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        error_log('You need to install dependencies with `composer install`.');
        return;
    }
}

new Setup();
