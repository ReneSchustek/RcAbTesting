<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentEvaluator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zeigt die Kennzahlen eines Experiments je Variante und darunter das Urteil des
 * `ExperimentEvaluator`, mit derselben Entscheidungskennzahl wie in der Verwaltung.
 */
#[AsCommand(name: 'rc:ab:stats', description: 'Zeigt die statistische Auswertung eines Experiments.')]
final class ExperimentStatsCommand extends Command
{
    public function __construct(
        private readonly ExperimentLookup $experimentLookup,
        private readonly ExperimentStatsAggregator $statsAggregator,
        private readonly ExperimentEvaluator $evaluator,
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

        $variants = $experiment->getVariantList();
        $stats = $this->statsAggregator->aggregate($experiment);

        $io->table(['Variante', 'Control', 'Zuordnungen', 'Conversions', 'Rate'], array_map(
            fn (AbVariantEntity $variant): array => $this->variantRow(
                $variant,
                $stats[$variant->getId()] ?? [
        'assignments' => 0,
        'conversions' => 0,
    ]
            ),
            $variants,
        ));

        $this->renderSignificance($io, $experiment, $stats);

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
     * Gibt das Urteil so aus, wie die Verwaltung es zeigt: über den {@see ExperimentEvaluator}, mit der
     * Entscheidungskennzahl des Experiments und den Gründen für einen zurückgehaltenen Gewinner. Ein
     * eigener Test der Conversion-Rate an dieser Stelle widerspräche der Verwaltung, sobald das
     * Experiment nach Umsatz je Besucher entscheidet.
     *
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     */
    private function renderSignificance(SymfonyStyle $io, AbExperimentEntity $experiment, array $stats): void
    {
        $evaluation = $this->evaluator->evaluate($experiment, $stats);

        $io->writeln(\sprintf('Entscheidungskennzahl: %s', $evaluation['decisionMetric']));
        $io->writeln(\sprintf('Konfidenzniveau: %.1f %%', $evaluation['confidenceLevel'] * 100));

        foreach ($evaluation['comparisons'] as $comparison) {
            if ($comparison['pValue'] === null || $comparison['lift'] === null) {
                $io->writeln(
                    \sprintf(
                        '%s ggü. %s: zu wenig Daten',
                        $comparison['variantKey'],
                        $evaluation['controlKey'] ?? '-'
                    )
                );

                continue;
            }

            $io->writeln(\sprintf(
                '%s ggü. %s: Lift %+.1f %%, p-Value %.4f, %s',
                $comparison['variantKey'],
                $evaluation['controlKey'] ?? '-',
                $comparison['lift'] * 100,
                $comparison['pValue'],
                $comparison['significant'] ? 'signifikant' : 'nicht signifikant',
            ));
        }

        if ($evaluation['winnerKey'] !== null) {
            $io->writeln(\sprintf('<info>Gewinner: %s</info>', $evaluation['winnerKey']));
        } elseif ($evaluation['winnerWithheldReasons'] !== []) {
            $io->writeln(\sprintf('Gewinner zurückgehalten: %s', implode(', ', $evaluation['winnerWithheldReasons'])));
        }
    }

    private function formatRate(int $assignments, int $conversions): string
    {
        if ($assignments === 0) {
            return '–';
        }

        return \sprintf('%.2f %%', $conversions / $assignments * 100);
    }
}
