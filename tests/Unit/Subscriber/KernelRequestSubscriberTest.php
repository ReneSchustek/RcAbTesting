<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Subscriber\KernelRequestSubscriber;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Prüft, wo eine Besucherkennung vergeben wird: nur in der Hauptanfrage der Storefront.
 * Bekäme `/admin` eine, zählte jeder Klick in der Administration als Besucher; bekäme jede
 * Teilanfrage eine eigene, zerfiele ein Seitenaufruf in mehrere Besucher.
 */
final class KernelRequestSubscriberTest extends TestCase
{
    public function testResolvesVisitorOnMainStorefrontRequest(): void
    {
        $request = $this->routed('/', 'storefront');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::MAIN_REQUEST));

        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        self::assertIsString($visitorId);
        self::assertTrue(Uuid::isValid($visitorId));
    }

    public function testSkipsSubRequest(): void
    {
        $request = $this->routed('/', 'storefront');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::SUB_REQUEST));

        self::assertNull($request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
    }

    public function testSkipsAdminPath(): void
    {
        $request = $this->routed('/admin/dashboard', 'administration');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
    }

    /**
     * Ein Aufruf der Store-API bekommt keine Besucherkennung. `/store-api` beginnt nicht mit `/api`; ein reiner
     * Präfixvergleich gäbe ihr eine.
     */
    public function testSkipsStoreApi(): void
    {
        $request = $this->routed('/store-api/product', 'store-api');
        $this->subscriber()->onRequest($this->event($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
    }

    private function routed(string $path, string $scope): Request
    {
        $request = Request::create($path);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [$scope]);

        return $request;
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
