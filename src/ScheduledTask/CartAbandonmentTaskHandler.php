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
 * Schreibt für jeden erkannten Warenkorb-Abbruch genau ein `cart.abandoned`. Dass ein Abbruch
 * nicht zweimal zählt, sichert die Erkennung: Der Detektor übergeht Checkouts, für die schon ein
 * Abbruch geschrieben ist.
 *
 * Erkennen und Schreiben sind zwei Schritte. Liefen zwei Läufe gleichzeitig, fänden beide
 * dieselben Abbrüche, bevor einer schreibt; deshalb hält ein benannter MySQL-Lock das Paar
 * zusammen, und ein zweiter Lauf überspringt sich.
 */
#[AsMessageHandler(handles: CartAbandonmentTask::class)]
final class CartAbandonmentTaskHandler extends ScheduledTaskHandler
{
    /**
     * Rückfall, wenn `cartAbandonmentMinutes` fehlt oder nicht positiv ist; dieselben 30 Minuten
     * wie die Vorgabe in der config.xml.
     */
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
        DatabaseLock::runExclusively($this->connection, self::LOCK_NAME, $this->detectAndRecord(...));
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
