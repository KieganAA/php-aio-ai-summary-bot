<?php
require __DIR__ . '/vendor/autoload.php';

use Psr\Log\NullLogger;
use Src\Config\Config;
use Src\Service\Database;

Config::load(__DIR__);
return Database::getConnection(new NullLogger());
