<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gibt die zu einem Kunden gespeicherten A/B-Daten als JSON aus (Auskunft nach Art. 15 DSGVO).
 * Erfasst ist alles, was über `customer_id` am Kunden hängt. Zeilen aus Besuchen ohne Anmeldung
 * tragen keinen Kundenbezug und fehlen deshalb, ebenso Zeilen, die die Aufbewahrungsfrist schon
 * anonymisiert hat.
 */
#[AsCommand(name: 'rc:ab:export', description: 'Exportiert die zu einem Kunden gespeicherten A/B-Daten (DSGVO Art.
#15).')]
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
        $this->addOption(
            'customer',
            null,
            InputOption::VALUE_REQUIRED,
            'Kunden-ID (hex), deren A/B-Daten exportiert werden.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getOption('customer');
        if (!\is_string($customerId) || !Uuid::isValid($customerId)) {
            (new SymfonyStyle($input, $output))->error('Bitte --customer=<Kunden-ID> angeben (32 Zeichen hex).');

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
        $found = $this->assignmentRepository->search($criteria, $context)->getEntities();
        foreach ($found as $assignment) {
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
        $found = $this->eventRepository->search($criteria, $context)->getEntities();
        foreach ($found as $event) {
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
