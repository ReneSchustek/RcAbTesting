<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Command;

use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'rc:ab:cleanup', description: 'Anonymisiert personenbeziehbare Felder abgelaufener A/B-Daten (DSGVO).')]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly VisitorDataAnonymizer $anonymizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Aufbewahrungsfrist in Tagen.', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('older-than-days');
        if ($days < 1) {
            $io->error('--older-than-days muss eine positive Zahl sein.');

            return self::INVALID;
        }

        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(\sprintf('-%d days', $days));

        $affected = $this->anonymizer->anonymizeOlderThan($threshold);

        $io->success(\sprintf(
            '%d Zeile(n) älter als %d Tage anonymisiert (visitor_id gehasht, customer_id entfernt).',
            $affected,
            $days,
        ));

        return self::SUCCESS;
    }
}
