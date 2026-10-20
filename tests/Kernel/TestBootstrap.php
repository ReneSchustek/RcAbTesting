<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

// Kernel-Tests brauchen ein vollständiges Shopware-Projekt mit `config/bundles.php` und
// dem ganzen Vendor; der Vendor des Plugins allein bootet keinen Kernel.
// SHOPWARE_PROJECT_ROOT zeigt auf eine Installation, in der das Plugin installiert und
// aktiv ist. Ohne die Variable wird das Plugin-Verzeichnis selbst versucht, was nur
// trägt, wenn es in einem Projektgerüst liegt.
$projectRoot = getenv('SHOPWARE_PROJECT_ROOT');
if (!\is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = __DIR__ . '/../..';
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException(
        \sprintf(
            'vendor/autoload.php fehlt unter %s — SHOPWARE_PROJECT_ROOT auf eine Shopware-Installation setzen.',
            $projectRoot
        )
    );
}

$loader = require $autoload;

// Eine Installation ohne `autoload-dev` kennt den Test-Namensraum des Plugins nicht;
// er wird deshalb hier von Hand angemeldet.
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Ruhrcoder\\RcAbTesting\\Tests\\', \dirname(__DIR__));
    $loader->register();
}

(new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->setPlatformEmbedded(false)
    ->setLoadEnvFile(false)
    ->bootstrap();
