#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Src\Config\Config;

Config::load(__DIR__ . '/..');

require __DIR__ . '/../vendor/doctrine/migrations/bin/doctrine-migrations.php';
