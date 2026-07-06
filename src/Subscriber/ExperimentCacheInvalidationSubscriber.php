<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantDefinition;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Leert den Registry-Cache, sobald ein Experiment geschrieben wird. Ohne diese
 * Invalidierung würde ein per Admin/API/CLI gestartetes oder beendetes
 * Experiment bis zu fünf Minuten (Cache-TTL) verzögert wirken — ein beendetes
 * Experiment würde weiter Besucher zuordnen. Deckt alle Schreibpfade ab, weil
 * sie alle über die DAL laufen. Varianten-Änderungen (Gewicht, Schlüssel)
 * invalidieren ebenfalls, damit das Bucketing nicht mit veralteten Gewichten
 * weiterläuft.
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
