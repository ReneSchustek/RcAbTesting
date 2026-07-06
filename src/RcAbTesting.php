<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

/**
 * RcAbTesting — A/B-Tests für Shopware (Twig-/Theme-/Feature-Flag-Varianten).
 *
 * Migrations laufen automatisch über den Migration-Quellpfad. Bei der
 * Deinstallation werden die plugin-eigenen Tabellen entfernt, sofern der Nutzer
 * die Daten nicht ausdrücklich behalten will (`keepUserData`).
 */
final class RcAbTesting extends Plugin
{
    /**
     * @var list<string> Reihenfolge respektiert die Foreign-Keys (Kinder zuerst).
     */
    private const TABLES = ['rc_ab_event', 'rc_ab_assignment', 'rc_ab_variant', 'rc_ab_experiment'];

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Kernel::getConnection() statt Container-Lookup: in Lifecycle-Methoden sind
        // plugin-eigene Services nicht im DI-Container; die Core-Connection ist es.
        $schemaManager = Kernel::getConnection()->createSchemaManager();
        foreach (self::TABLES as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $schemaManager->dropTable($table);
            }
        }
    }
}
