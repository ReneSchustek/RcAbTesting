<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
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

/**
 * Beendet ein Experiment von der Kommandozeile und trägt auf Wunsch den Gewinner ein. Der Gewinner
 * wird über seinen `technical_key` angegeben und gegen die Varianten des Experiments geprüft, weil
 * `winner_variant_id` in der Datenbank keinen Fremdschlüssel hat.
 *
 * Beenden lässt sich nur ein Experiment, das läuft oder pausiert (`AbExperimentStatus::ENDABLE`); ein Entwurf
 * oder ein archiviertes Experiment bleibt, wie es ist.
 */
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

        if (!\in_array($experiment->getStatus(), AbExperimentStatus::ENDABLE, true)) {
            $io->error(\sprintf(
                'Experiment "%s" steht auf "%s"; beenden lassen sich nur laufende oder pausierte Experimente.',
                $key,
                $experiment->getStatus(),
            ));

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

        // Vorgabe „ja“: Mit `--no-interaction` läuft der Befehl durch, statt an der Rückfrage zu
        // hängen.
        if (!$io->confirm(\sprintf('Experiment "%s" wirklich beenden?', $key), true)) {
            $io->warning('Abgebrochen.');

            return self::SUCCESS;
        }

        // Ohne `--winner` bleibt ein schon eingetragener Gewinner stehen, wie beim Beenden in der
        // Verwaltung. Stünde das Feld trotzdem im Schreibauftrag, schriebe der Befehl null und löschte ihn.
        $changes = [
            'id' => $experiment->getId(),
            'status' => AbExperimentStatus::DONE,
            'endedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
        if ($winnerId !== null) {
            $changes['winnerVariantId'] = $winnerId;
        }
        $this->experimentRepository->update([$changes], $context);

        $io->success(
            \sprintf(
                'Experiment "%s" beendet%s.',
                $key,
                $winnerId !== null
                    ? \sprintf(' (Gewinner: %s)', $winnerOption)
                    : ''
            )
        );

        return self::SUCCESS;
    }

    private function resolveWinnerId(AbExperimentEntity $experiment, string $variantKey): ?string
    {
        foreach ($experiment->getVariantList() as $variant) {
            if ($variant->getTechnicalKey() === $variantKey) {
                return $variant->getId();
            }
        }

        return null;
    }
}
