<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Startet Experimente, deren geplanter Startzeitpunkt erreicht ist (nur wenn die
 * Varianten-Konfiguration gültig ist), und beendet Experimente, deren geplanter
 * Endzeitpunkt erreicht ist. Ein MySQL-Named-Lock verhindert, dass sich zwei
 * überlappende Läufe in die Quere kommen.
 */
#[AsMessageHandler(handles: ExperimentScheduleTask::class)]
final class ExperimentScheduleTaskHandler extends ScheduledTaskHandler
{
    private const LOCK_NAME = 'rc_ab_experiment_schedule';

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<AbExperimentCollection>  $experimentRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $experimentRepository,
        private readonly ExperimentIntegrityValidator $integrityValidator,
        private readonly Connection $connection,
    ) {
        parent::__construct($scheduledTaskRepository, $this->logger);
    }

    public function run(): void
    {
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 0)', ['name' => self::LOCK_NAME]) !== 1) {
            return;
        }

        try {
            $this->processDue(Context::createDefaultContext(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } finally {
            $this->connection->executeStatement('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK_NAME]);
        }
    }

    public function processDue(Context $context, \DateTimeImmutable $now): void
    {
        $updates = [...$this->collectStarts($context, $now), ...$this->collectEnds($context, $now)];
        if ($updates !== []) {
            $this->experimentRepository->update($updates, $context);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectStarts(Context $context, \DateTimeImmutable $now): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('status', [AbExperimentStatus::DRAFT, AbExperimentStatus::PAUSED]));
        $criteria->addFilter(new RangeFilter('scheduledStartAt', [RangeFilter::LTE => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT)]));
        $criteria->addAssociation('variants');

        $updates = [];
        foreach ($this->search($criteria, $context) as $experiment) {
            if ($experiment->getStartedAt() !== null) {
                // Bereits einmal gestartet (typischerweise manuell pausiert): der
                // geplante Start gilt nur für noch nie gestartete Experimente. Sonst
                // würde der Scheduler eine bewusste Pause bei jedem Lauf still aufheben.
                continue;
            }

            $violation = $this->integrityValidator->firstStartViolation($experiment);
            if ($violation !== null) {
                $this->logger->warning('Geplanter Start von {experiment} übersprungen: {reason}', [
                    'experiment' => $experiment->getTechnicalKey(),
                    'reason' => $this->integrityValidator->messageFor($violation),
                ]);

                continue;
            }

            $updates[] = [
                'id' => $experiment->getId(),
                'status' => AbExperimentStatus::RUNNING,
                'startedAt' => $now,
            ];
        }

        return $updates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectEnds(Context $context, \DateTimeImmutable $now): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('status', AbExperimentStatus::RUNNING));
        $criteria->addFilter(new RangeFilter('scheduledEndAt', [RangeFilter::LTE => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT)]));

        $updates = [];
        foreach ($this->search($criteria, $context) as $experiment) {
            $updates[] = [
                'id' => $experiment->getId(),
                'status' => AbExperimentStatus::DONE,
                'endedAt' => $now,
            ];
        }

        return $updates;
    }

    /**
     * @return list<AbExperimentEntity>
     */
    private function search(Criteria $criteria, Context $context): array
    {
        /** @var list<AbExperimentEntity> $experiments */
        $experiments = array_values($this->experimentRepository->search($criteria, $context)->getEntities()->getElements());

        return $experiments;
    }
}
