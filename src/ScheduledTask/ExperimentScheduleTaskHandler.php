<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Startet Experimente, deren geplanter Start erreicht ist, sofern ihre Varianten den Start-Wächter
 * bestehen, und beendet laufende und pausierte Experimente, deren geplantes Ende erreicht ist. Wer nur
 * laufende abfragt, lässt ein pausiertes Experiment über sein Ende hinaus stehen, und es endet nie.
 *
 * Ein benannter MySQL-Lock hält überlappende Läufe auseinander, damit nicht zwei Läufe dasselbe
 * Experiment starten oder beenden.
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
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($scheduledTaskRepository, $this->logger);
    }

    public function run(): void
    {
        DatabaseLock::runExclusively(
            $this->connection,
            self::LOCK_NAME,
            fn () => $this->processDue(
                Context::createDefaultContext(),
                $this->clock->now()->setTimezone(
                    new \DateTimeZone('UTC')
                )
            ),
        );
    }

    private function processDue(Context $context, \DateTimeImmutable $now): void
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
        $criteria->addFilter(
            new RangeFilter(
                'scheduledStartAt',
                [
                    RangeFilter::LTE => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            )
        );
        $criteria->addAssociation('variants');
        // Was über der Grenze übrig bliebe, holt der nächste Lauf.
        $criteria->setLimit(ExperimentRegistry::QUERY_LIMIT);

        $updates = [];
        foreach ($this->search($criteria, $context) as $experiment) {
            if ($experiment->getStartedAt() !== null) {
                // Schon einmal gestartet, meist von Hand pausiert. Der geplante Start gilt nur für
                // Experimente, die nie liefen; sonst höbe der Planer jede Pause beim nächsten Lauf
                // still wieder auf.
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
        $criteria->addFilter(new EqualsAnyFilter('status', AbExperimentStatus::ENDABLE));
        $criteria->addFilter(
            new RangeFilter(
                'scheduledEndAt',
                [
                    RangeFilter::LTE => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            )
        );
        $criteria->setLimit(ExperimentRegistry::QUERY_LIMIT);

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
        $experiments = array_values(
            $this->experimentRepository->search(
                $criteria,
                $context
            )->getEntities()->getElements()
        );

        return $experiments;
    }
}
