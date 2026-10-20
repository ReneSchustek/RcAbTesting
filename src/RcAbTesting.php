<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

/**
 * Plugin-Klasse für A/B-Tests in der Storefront: Twig-, Theme-, Feature-Flag-, Frontend-Schalter-
 * und CMS-Seiten-Varianten.
 *
 * Die Migrationen findet Shopware selbst unter `src/Migration`. Beim Deinstallieren verschwinden
 * die vier Plugin-Tabellen, außer der Betreiber behält die Daten ausdrücklich (`keepUserData`).
 * Blieben sie stehen, lägen Besucherkennungen nach dem Entfernen des Plugins unbefristet in der
 * Datenbank, weil keine Aufbewahrungsfrist mehr läuft.
 */
final class RcAbTesting extends Plugin
{
    /**
     * @var list<string> Kinder vor Eltern, sonst scheitert `DROP TABLE` an den Fremdschlüsseln
     */
    private const TABLES = ['rc_ab_event', 'rc_ab_assignment', 'rc_ab_variant', 'rc_ab_experiment'];

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // `Kernel::getConnection()` statt Container-Lookup: In Lifecycle-Methoden stehen die
        // Dienste des Plugins nicht im Container, die Verbindung des Kerns schon.
        $schemaManager = Kernel::getConnection()->createSchemaManager();
        foreach (self::TABLES as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $schemaManager->dropTable($table);
            }
        }
    }
}
