<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'rc:ab:stats', description: 'Zeigt die statistische Auswertung eines Experiments.')]
final class ExperimentStatsCommand extends Command
{
    public function __construct(
        private readonly ExperimentLookup $experimentLookup,
        private readonly ExperimentStatsAggregator $statsAggregator,
        private readonly StatisticsCalculator $statisticsCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('experiment-key', InputArgument::REQUIRED, 'technical_key des Experiments.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $key = (string) $input->getArgument('experiment-key');
        $experiment = $this->experimentLookup->byTechnicalKey($key, $context);
        if ($experiment === null) {
            $io->error(\sprintf('Experiment "%s" nicht gefunden.', $key));

            return self::FAILURE;
        }

        $io->title(\sprintf('Experiment: %s (%s)', $experiment->getTechnicalKey(), $experiment->getStatus()));

        $variants = $this->variants($experiment);
        $stats = $this->statsAggregator->aggregate($experiment, $context);

        $io->table(['Variante', 'Control', 'Zuordnungen', 'Conversions', 'Rate'], array_map(
            fn (AbVariantEntity $variant): array => $this->variantRow($variant, $stats[$variant->getId()] ?? ['assignments' => 0, 'conversions' => 0]),
            $variants,
        ));

        $this->renderSignificance($io, $experiment, $variants, $stats);

        return self::SUCCESS;
    }

    /**
     * @param array{assignments: int, conversions: int} $variantStats
     *
     * @return array{0: string, 1: string, 2: int, 3: int, 4: string}
     */
    private function variantRow(AbVariantEntity $variant, array $variantStats): array
    {
        return [
            $variant->getTechnicalKey(),
            $variant->isControl() ? 'ja' : '',
            $variantStats['assignments'],
            $variantStats['conversions'],
            $this->formatRate($variantStats['assignments'], $variantStats['conversions']),
        ];
    }

    /**
     * @param list<AbVariantEntity>                                       $variants
     * @param array<string, array{assignments: int, conversions: int}>    $stats
     */
    private function renderSignificance(SymfonyStyle $io, AbExperimentEntity $experiment, array $variants, array $stats): void
    {
        if (\count($variants) !== 2) {
            $io->note('Signifikanztest nur für genau zwei Varianten.');

            return;
        }

        [$control, $treatment] = $this->orderControlFirst($variants);
        $a = $stats[$control->getId()] ?? ['assignments' => 0, 'conversions' => 0];
        $b = $stats[$treatment->getId()] ?? ['assignments' => 0, 'conversions' => 0];

        $result = $this->statisticsCalculator->calculate($a['assignments'], $a['conversions'], $b['assignments'], $b['conversions'], $experiment->getTargetSignificance());
        if ($result['p_value'] === null || $result['lift'] === null) {
            $io->note('Zu wenig Daten für einen Signifikanztest.');

            return;
        }

        $io->writeln(\sprintf('Konfidenzniveau: %.1f %%', (1.0 - $result['alpha']) * 100));
        $io->writeln(\sprintf('Lift (%s ggü. %s): %+.1f %%', $treatment->getTechnicalKey(), $control->getTechnicalKey(), $result['lift'] * 100));
        $io->writeln(\sprintf('p-Value: %.4f', $result['p_value']));
        $io->writeln($result['significant']
            ? \sprintf('<info>Ergebnis: signifikant (p < %.3f)</info>', $result['alpha'])
            : \sprintf('Ergebnis: nicht signifikant (p >= %.3f)', $result['alpha']));
    }

    /**
     * @param list<AbVariantEntity> $variants
     *
     * @return array{0: AbVariantEntity, 1: AbVariantEntity}
     */
    private function orderControlFirst(array $variants): array
    {
        return $variants[0]->isControl() ? [$variants[0], $variants[1]] : [$variants[1], $variants[0]];
    }

    private function formatRate(int $assignments, int $conversions): string
    {
        if ($assignments === 0) {
            return '–';
        }

        return \sprintf('%.2f %%', $conversions / $assignments * 100);
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variants(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
