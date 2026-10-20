<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Anonymisiert Zuordnungen und Ereignisse, die älter sind als `dataRetentionDays`. Fehlt der Wert
 * oder ist das Feld leer, gelten 90 Tage; nur eine ausdrücklich eingetragene `0` schaltet die
 * automatische Anonymisierung ab. `rc:ab:cleanup` bleibt in
 * jedem Fall möglich.
 */
#[AsMessageHandler(handles: DataRetentionTask::class)]
final class DataRetentionTaskHandler extends ScheduledTaskHandler
{
    /** Dieselben 90 Tage wie die Vorgabe in der config.xml. */
    public const DEFAULT_RETENTION_DAYS = 90;
    private const CONFIG_KEY = 'RcAbTesting.config.dataRetentionDays';

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly VisitorDataAnonymizer $anonymizer,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $days = $this->retentionDays();
        if ($days < 1) {
            return;
        }

        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(\sprintf('-%d days', $days));

        $this->anonymizer->anonymizeOlderThan($threshold);
    }

    private function retentionDays(): int
    {
        // Fehlt der Wert, ist das Feld geleert oder steht keine Zahl darin, gilt die Vorgabe. Über
        // `(int)` würde ein geleertes Feld zu 0 und schaltete die Anonymisierung still ab. Negative
        // Werte fallen ebenfalls auf die Vorgabe zurück.
        $raw = $this->systemConfig->get(self::CONFIG_KEY);
        if (!is_numeric($raw)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $raw;

        return $days < 0 ? self::DEFAULT_RETENTION_DAYS : $days;
    }
}
