<?php

declare(strict_types=1);

define('SEMITEXA_PROJECT_ROOT', __DIR__);

require_once __DIR__ . '/vendor/autoload.php';

\Semitexa\Core\Server\SwooleBootstrap::run();
