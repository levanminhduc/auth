<?php

declare(strict_types=1);

use Hoatho\SharedAuth\EnvConfig;
use Hoatho\SharedAuth\SessionBootstrap;

require __DIR__ . '/../bootstrap.php';

SessionBootstrap::destroy();

header('Location: ' . EnvConfig::loginUrl(), true, 302);
exit;
