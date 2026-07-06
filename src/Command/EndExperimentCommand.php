<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'rc:ab:end', description: 'Beendet ein Experiment (Status done) und setzt optional den Gewinner.')]
final class EndExperimentCommand extends Command
{
    /**
     * @param EntityRepository<AbExperimentCollection> $experimentRepository
     */
    public function __construct(
        private readonly ExperimentLookup $experimentLookup,
        private readonly EntityRepository $experimentRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('experiment-key', InputArgument::REQUIRED, 'technical_key des Experiments.');
        $this->addOption('winner', null, InputOption::VALUE_REQUIRED, 'technical_key der Gewinner-Variante.');
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

        $winnerOption = $input->getOption('winner');
        $winnerId = null;
        if (\is_string($winnerOption) && $winnerOption !== '') {
            $winnerId = $this->resolveWinnerId($experiment, $winnerOption);
            if ($winnerId === null) {
                $io->error(\sprintf('Variante "%s" gehört nicht zu Experiment "%s".', $winnerOption, $key));

                return self::FAILURE;
            }
        }

        if (!$io->confirm(\sprintf('Experiment "%s" wirklich beenden?', $key), true)) {
            $io->warning('Abgebrochen.');

            return self::SUCCESS;
        }

        $this->experimentRepository->update([[
            'id' => $experiment->getId(),
            'status' => AbExperimentStatus::DONE,
            'endedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'winnerVariantId' => $winnerId,
        ]], $context);

        $io->success(\sprintf('Experiment "%s" beendet%s.', $key, $winnerId !== null ? \sprintf(' (Gewinner: %s)', $winnerOption) : ''));

        return self::SUCCESS;
    }

    private function resolveWinnerId(AbExperimentEntity $experiment, string $variantKey): ?string
    {
        foreach ($experiment->getVariants()?->getElements() ?? [] as $variant) {
            if ($variant instanceof AbVariantEntity && $variant->getTechnicalKey() === $variantKey) {
                return $variant->getId();
            }
        }

        return null;
    }
}
