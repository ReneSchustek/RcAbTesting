<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

// Kernel-Integrationstests brauchen ein VOLLES Shopware-Projekt (config/bundles.php,
// vollständiger Vendor). Der isolierte Plugin-Vendor reicht dafür nicht. Über
// SHOPWARE_PROJECT_ROOT wird auf eine echte Shopware-Installation gezeigt, in der
// das Plugin installiert/aktiv ist; ohne die Variable Fallback auf das Plugin
// (nur dann nutzbar, wenn das Plugin selbst in einem Projekt-Skelett liegt).
$projectRoot = getenv('SHOPWARE_PROJECT_ROOT');
if (!\is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = __DIR__ . '/../..';
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException(\sprintf('vendor/autoload.php fehlt unter %s — SHOPWARE_PROJECT_ROOT auf eine Shopware-Installation setzen.', $projectRoot));
}

$loader = require $autoload;

// Test-Namespace der (ggf. eigenständigen) Plugin-Quelle registrieren, falls das
// Ziel-Projekt ohne autoload-dev installiert ist (Prod-Install der Instanz).
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Ruhrcoder\\RcAbTesting\\Tests\\', \dirname(__DIR__));
    $loader->register();
}

(new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->setPlatformEmbedded(false)
    ->setLoadEnvFile(false)
    ->bootstrap();
