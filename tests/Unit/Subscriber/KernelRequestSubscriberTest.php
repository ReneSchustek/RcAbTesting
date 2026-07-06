<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Subscriber\KernelRequestSubscriber;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KernelRequestSubscriberTest extends TestCase
{
    public function testResolvesVisitorOnMainStorefrontRequest(): void
    {
        $request = Request::create('/');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::MAIN_REQUEST));

        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        self::assertIsString($visitorId);
        self::assertTrue(Uuid::isValid($visitorId));
    }

    public function testSkipsSubRequest(): void
    {
        $request = Request::create('/');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::SUB_REQUEST));

        self::assertNull($request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
    }

    public function testSkipsAdminPath(): void
    {
        $request = Request::create('/admin/dashboard');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
    }

    private function subscriber(): KernelRequestSubscriber
    {
        return new KernelRequestSubscriber(new VisitorIdResolver(new DefaultConsentGate(), new NullLogger()));
    }

    private function event(Request $request, int $requestType): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $requestType);
    }
}
