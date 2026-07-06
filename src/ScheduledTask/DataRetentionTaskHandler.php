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
 * Setzt die Aufbewahrungsfrist periodisch durch: anonymisiert Zuordnungen und
 * Events, die älter als die konfigurierte Frist sind. Die Frist ist als
 * Plugin-Config hinterlegt; unkonfiguriert (leer/null) gilt der Default von 90
 * Tagen, eine ausdrückliche `0` schaltet die automatische Anonymisierung ab
 * (manueller `rc:ab:cleanup` bleibt in beiden Fällen möglich).
 */
#[AsMessageHandler(handles: DataRetentionTask::class)]
final class DataRetentionTaskHandler extends ScheduledTaskHandler
{
    private const DEFAULT_RETENTION_DAYS = 90;
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
        // Unkonfiguriert (null) → Default; explizite 0 → bewusst abgeschaltet;
        // negativ → defensiv auf Default.
        $raw = $this->systemConfig->get(self::CONFIG_KEY);
        if ($raw === null) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $raw;

        return $days < 0 ? self::DEFAULT_RETENTION_DAYS : $days;
    }
}
