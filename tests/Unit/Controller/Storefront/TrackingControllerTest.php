<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Controller\Storefront;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Controller\Storefront\TrackingController;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Service\TrackRateLimiter;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class TrackingControllerTest extends TestCase
{
    use RunningRegistryTrait;

    public function testTracksValidCustomEvent(): void
    {
        $captured = null;
        $controller = new TrackingController($this->eventTracker($captured), new NullLogger(), $this->rateLimiter());

        $response = $controller->track($this->request('custom.cta_clicked', ['value' => 1.5, 'meta' => ['cta' => 'header']]), $this->salesChannelContext());

        self::assertSame(['ok' => true], json_decode((string) $response->getContent(), true));
        self::assertIsArray($captured);
        self::assertSame('custom.cta_clicked', $captured[0]['eventType']);
        self::assertSame(1.5, $captured[0]['eventValue']);
        self::assertSame(['cta' => 'header'], $captured[0]['meta']);
    }

    public function testRejectsUnknownEventType(): void
    {
        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');
        $controller = new TrackingController(new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()), new NullLogger(), $this->rateLimiter());

        $response = $controller->track($this->request('page.scrolled', []), $this->salesChannelContext());

        self::assertSame(['ok' => false, 'reason' => 'invalid_event_type'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsServerSideEventTypeFromBrowser(): void
    {
        // Server-seitige Funnel-/Order-Typen duerfen NICHT ueber den anonymen
        // Endpunkt einschleusbar sein (Arbeitspaket AB33): sonst koennte ein Besucher
        // gefaelschte Conversions mit beliebigem Umsatz senden.
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');
        $controller = new TrackingController(new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()), new NullLogger(), $this->rateLimiter());

        $response = $controller->track(
            $this->request('checkout.order_placed', ['value' => 1000000.0]),
            $this->salesChannelContext(),
        );

        self::assertSame(['ok' => false, 'reason' => 'invalid_event_type'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsOverlongEventType(): void
    {
        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');
        $controller = new TrackingController(new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()), new NullLogger(), $this->rateLimiter());

        $response = $controller->track($this->request('custom.' . str_repeat('x', 70), []), $this->salesChannelContext());

        self::assertSame(['ok' => false, 'reason' => 'invalid_event_type'], json_decode((string) $response->getContent(), true));
    }

    public function testTrackingFailureIsCaught(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willThrowException(new \RuntimeException('DB weg'));
        $controller = new TrackingController(new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()), new NullLogger(), $this->rateLimiter());

        $response = $controller->track($this->request('custom.cta_clicked', []), $this->salesChannelContext());

        self::assertSame(['ok' => false, 'reason' => 'tracking_failed'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsRequestWithoutVisitor(): void
    {
        $captured = null;
        $controller = new TrackingController($this->eventTracker($captured), new NullLogger(), $this->rateLimiter());

        $request = new Request([], [], [], [], [], [], (string) json_encode(['eventType' => 'page.viewed']));
        $response = $controller->track($request, $this->salesChannelContext());

        self::assertSame(['ok' => false, 'reason' => 'no_visitor'], json_decode((string) $response->getContent(), true));
    }

    public function testRejectsWhenRateLimited(): void
    {
        $captured = null;
        $limiter = $this->rateLimiter();
        // Budget des Clients vorab erschöpfen (gleicher Schlüssel wie im Controller:
        // visitorId + leere Client-IP der Test-Request).
        for ($i = 0; $i < 60; ++$i) {
            $limiter->tooManyRequests('visitor-1|');
        }

        $controller = new TrackingController($this->eventTracker($captured), new NullLogger(), $limiter);
        $response = $controller->track($this->request('custom.click', []), $this->salesChannelContext());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['ok' => false, 'reason' => 'rate_limited'], json_decode((string) $response->getContent(), true));
    }

    private function rateLimiter(): TrackRateLimiter
    {
        return new TrackRateLimiter(new ArrayAdapter());
    }

    /**
     * @param list<array<string, mixed>>|null $captured
     */
    private function eventTracker(?array &$captured): EventTracker
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        return new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger());
    }

    private function assignmentRepository(): EntityRepository
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId('a1');
        $assignment->setExperimentId('exp-a');
        $assignment->setVariantId('var-a');
        $assignment->setVisitorId('visitor-1');

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$assignment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $eventType, array $body): Request
    {
        $content = (string) json_encode(['eventType' => $eventType] + $body);
        $request = new Request([], [], [], [], [], [], $content);
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');

        return $request;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
