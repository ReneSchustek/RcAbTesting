<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
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

#[AsCommand(name: 'rc:ab:list', description: 'Listet alle A/B-Experimente mit Kennzahlen.')]
final class ListExperimentsCommand extends Command
{
    /**
     * @param EntityRepository<AbExperimentCollection> $experimentRepository
     */
    public function __construct(
        private readonly EntityRepository $experimentRepository,
        private readonly ExperimentStatsAggregator $statsAggregator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Nur Experimente mit diesem Status anzeigen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addAssociation('variants');
        $status = $input->getOption('status');
        if (\is_string($status) && $status !== '') {
            $criteria->addFilter(new EqualsFilter('status', $status));
        }

        $experiments = $this->experimentRepository->search($criteria, $context)->getEntities();
        if ($experiments->count() === 0) {
            $io->warning('Keine Experimente gefunden.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($experiments as $experiment) {
            $rows[] = $this->row($experiment);
        }

        $io->table(['Schlüssel', 'Status', 'Varianten', 'Zuordnungen', 'Conversions'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: int, 4: int}
     */
    private function row(AbExperimentEntity $experiment): array
    {
        $stats = $this->statsAggregator->aggregate($experiment);
        $assignments = array_sum(array_column($stats, 'assignments'));
        $conversions = array_sum(array_column($stats, 'conversions'));

        return [
            $experiment->getTechnicalKey(),
            $experiment->getStatus(),
            $experiment->getVariants()?->count() ?? 0,
            $assignments,
            $conversions,
        ];
    }
}
