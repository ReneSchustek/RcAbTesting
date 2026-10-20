<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentCookie;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Subscriber\KernelResponseSubscriber;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Prüft, wann die Antwort den Besucher-Cookie setzt: in der Hauptanfrage für einen neuen
 * Besucher mit Einwilligung, in Teilanfragen nie. Die Hauptanfrage setzt ihn einmal für die
 * ganze Seite; ein zweiter Cookie aus einer Teilanfrage könnte ihn mit anderer Kennung
 * überschreiben.
 */
final class KernelResponseSubscriberTest extends TestCase
{
    public function testWritesCookieForNewVisitor(): void
    {
        $request = $this->storefrontRequest();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, Uuid::randomHex());
        $request->attributes->set(VisitorIdResolver::NEW_FLAG_ATTRIBUTE, true);
        // Ohne Einwilligung wird kein Cookie geschrieben; der Marker aus dem Cookie-Banner ist
        // deshalb Voraussetzung dieses Falls.
        $request->cookies->set(ConsentCookie::NAME, ConsentCookie::GRANTED_VALUE);
        $response = new Response();

        $this->subscriber()->onResponse($this->event($request, $response, HttpKernelInterface::MAIN_REQUEST));

        self::assertNotNull($this->findCookie($response));
    }

    public function testSkipsSubRequest(): void
    {
        $request = $this->storefrontRequest();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, Uuid::randomHex());
        $request->attributes->set(VisitorIdResolver::NEW_FLAG_ATTRIBUTE, true);
        $response = new Response();

        $this->subscriber()->onResponse($this->event($request, $response, HttpKernelInterface::SUB_REQUEST));

        self::assertNull($this->findCookie($response));
    }

    public function testSkipsRoutesOutsideTheStorefront(): void
    {
        $request = Request::create('/store-api/product');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, ['store-api']);
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, Uuid::randomHex());
        $request->attributes->set(VisitorIdResolver::NEW_FLAG_ATTRIBUTE, true);
        $request->cookies->set(ConsentCookie::NAME, ConsentCookie::GRANTED_VALUE);
        $response = new Response();

        $this->subscriber()->onResponse($this->event($request, $response, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($this->findCookie($response));
    }

    private function storefrontRequest(): Request
    {
        $request = Request::create('/');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [StorefrontRouteScope::ID]);

        return $request;
    }

    private function subscriber(): KernelResponseSubscriber
    {
        return new KernelResponseSubscriber(new VisitorIdResolver(new DefaultConsentGate(), new NullLogger()));
    }

    private function event(Request $request, Response $response, int $requestType): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $requestType, $response);
    }

    private function findCookie(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === VisitorIdResolver::COOKIE_NAME) {
                return $cookie;
            }
        }

        return null;
    }
}
