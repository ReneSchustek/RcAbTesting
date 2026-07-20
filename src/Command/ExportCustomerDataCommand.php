<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gibt die zu einem Kunden gespeicherten A/B-Daten als JSON aus (DSGVO Art. 15,
 * Auskunftsrecht). Exportiert werden alle Zuordnungen und Events, die über die
 * `customer_id` mit der identifizierten Person verknüpft sind. Anonyme Besucher-
 * Zeilen ohne Kundenbezug sind nicht Teil der Auskunft.
 */
#[AsCommand(name: 'rc:ab:export', description: 'Exportiert die zu einem Kunden gespeicherten A/B-Daten (DSGVO Art. 15).')]
final class ExportCustomerDataCommand extends Command
{
    /**
     * @param EntityRepository<AbAssignmentCollection> $assignmentRepository
     * @param EntityRepository<AbEventCollection>       $eventRepository
     */
    public function __construct(
        private readonly EntityRepository $assignmentRepository,
        private readonly EntityRepository $eventRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Kunden-ID (hex), deren A/B-Daten exportiert werden.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getOption('customer');
        if (!\is_string($customerId) || $customerId === '') {
            (new SymfonyStyle($input, $output))->error('Bitte --customer=<Kunden-ID> angeben.');

            return self::INVALID;
        }

        $context = Context::createDefaultContext();
        $export = [
            'customerId' => $customerId,
            'assignments' => $this->assignments($customerId, $context),
            'events' => $this->events($customerId, $context),
        ];

        $json = json_encode($export, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            (new SymfonyStyle($input, $output))->error('Export konnte nicht als JSON kodiert werden.');

            return self::FAILURE;
        }

        $output->writeln($json);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignments(string $customerId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $rows = [];
        foreach ($this->assignmentRepository->search($criteria, $context)->getEntities() as $assignment) {
            \assert($assignment instanceof AbAssignmentEntity);
            $rows[] = [
                'experimentId' => $assignment->getExperimentId(),
                'variantId' => $assignment->getVariantId(),
                'visitorId' => $assignment->getVisitorId(),
                'device' => $assignment->getDevice(),
                'salesChannelId' => $assignment->getSalesChannelId(),
                'languageId' => $assignment->getLanguageId(),
                'assignedAt' => $assignment->getAssignedAt()->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $assignment->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(string $customerId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $rows = [];
        foreach ($this->eventRepository->search($criteria, $context)->getEntities() as $event) {
            \assert($event instanceof AbEventEntity);
            $rows[] = [
                'experimentId' => $event->getExperimentId(),
                'variantId' => $event->getVariantId(),
                'visitorId' => $event->getVisitorId(),
                'eventType' => $event->getEventType(),
                'eventValue' => $event->getEventValue(),
                'meta' => $event->getMeta(),
                'occurredAt' => $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
