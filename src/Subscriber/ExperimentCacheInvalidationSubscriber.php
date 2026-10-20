<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantDefinition;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Leert den Registry-Cache, sobald ein Experiment oder eine Variante geschrieben wird. Ohne das
 * wirkte ein Start oder ein Ende bis zu fünf Minuten verspätet, so lange lebt der Cache-Eintrag,
 * und ein beendetes Experiment ordnete in dieser Zeit weiter Besucher zu. Geänderte Gewichte oder
 * Schlüssel einer Variante kommen auf demselben Weg sofort im Bucketing an.
 *
 * Verwaltung, API, Befehle und Planer schreiben alle über die DAL, das Ereignis erreicht also
 * jeden dieser Wege. Ein direkter SQL-Schreibzugriff auf die Tabellen ginge daran vorbei.
 */
final class ExperimentCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AbExperimentDefinition::ENTITY_NAME . '.written' => 'onWritten',
            AbVariantDefinition::ENTITY_NAME . '.written' => 'onWritten',
        ];
    }

    public function onWritten(EntityWrittenEvent $event): void
    {
        $this->experimentRegistry->invalidate();
    }
}
