<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Schreibt für jeden erkannten Warenkorb-Abbruch genau ein `cart.abandoned`-
 * Event. Die fachliche Idempotenz liegt in der Erkennung (der Detektor schließt
 * Besucher mit bereits geschriebenem Abbruch für dieselbe Checkout-Sitzung aus).
 * Da Erkennung (Lesen) und Schreiben zwei Schritte sind, sichert ein
 * MySQL-Named-Lock das Paar gegen überlappende Läufe ab: ein zweiter,
 * gleichzeitiger Lauf bekommt den Lock nicht und überspringt sich, statt
 * dieselben Abbrüche ein zweites Mal zu schreiben.
 */
#[AsMessageHandler(handles: CartAbandonmentTask::class)]
final class CartAbandonmentTaskHandler extends ScheduledTaskHandler
{
    private const DEFAULT_THRESHOLD_MINUTES = 30;
    private const CONFIG_KEY = 'RcAbTesting.config.cartAbandonmentMinutes';
    private const LOCK_NAME = 'rc_ab_cart_abandonment';

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<AbEventCollection>       $eventRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly CartAbandonmentDetector $detector,
        private readonly EntityRepository $eventRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly Connection $connection,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        // Ohne Wartezeit anfordern (Timeout 0): überlappt ein zweiter Lauf,
        // überspringt er sich sofort, statt zu blockieren.
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 0)', ['name' => self::LOCK_NAME]) !== 1) {
            return;
        }

        try {
            $this->detectAndRecord();
        } finally {
            $this->connection->executeStatement('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK_NAME]);
        }
    }

    private function detectAndRecord(): void
    {
        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(\sprintf('-%d minutes', $this->thresholdMinutes()));

        $abandoned = $this->detector->detect($threshold);
        if ($abandoned === []) {
            return;
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $payload = array_map(
            static fn (array $row): array => [
                'id' => Uuid::randomHex(),
                'experimentId' => $row['experimentId'],
                'variantId' => $row['variantId'],
                'visitorId' => $row['visitorId'],
                'eventType' => AbEventType::CART_ABANDONED,
                'occurredAt' => $occurredAt,
            ],
            $abandoned,
        );

        $this->eventRepository->create($payload, Context::createDefaultContext());
    }

    private function thresholdMinutes(): int
    {
        $configured = $this->systemConfig->getInt(self::CONFIG_KEY);

        return $configured > 0 ? $configured : self::DEFAULT_THRESHOLD_MINUTES;
    }
}
