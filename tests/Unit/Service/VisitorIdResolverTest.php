<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class VisitorIdResolverTest extends TestCase
{
    public function testReturnsExistingValidCookieWithoutRewriting(): void
    {
        $visitorId = Uuid::randomHex();
        $request = new Request();
        $request->cookies->set(VisitorIdResolver::COOKIE_NAME, $visitorId);
        $response = new Response();
        $resolver = $this->resolver($this->grantingGate());

        $resolved = $resolver->resolveForRequest($request);
        $resolver->writeCookieIfNeeded($request, $response);

        self::assertSame($visitorId, $resolved);
        self::assertSame($visitorId, $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
        self::assertNull($this->findCookie($response));
    }

    public function testGeneratesAndPersistsWhenCookieMissing(): void
    {
        $request = new Request();
        $response = new Response();
        $resolver = $this->resolver($this->grantingGate());

        $resolved = $resolver->resolveForRequest($request);
        $resolver->writeCookieIfNeeded($request, $response);

        self::assertTrue(Uuid::isValid($resolved));
        $cookie = $this->findCookie($response);
        self::assertNotNull($cookie);
        self::assertSame($resolved, $cookie->getValue());
        self::assertFalse($cookie->isHttpOnly(), 'Storefront-JS muss den Cookie lesen können.');
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function testDeniedConsentSkipsCookieButKeepsId(): void
    {
        $request = new Request();
        $response = new Response();
        $resolver = $this->resolver($this->denyingGate());

        $resolved = $resolver->resolveForRequest($request);
        $resolver->writeCookieIfNeeded($request, $response);

        self::assertTrue(Uuid::isValid($resolved));
        self::assertSame($resolved, $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE));
        self::assertNull($this->findCookie($response));
    }

    public function testCorruptCookieIsReplacedAndLogged(): void
    {
        $request = new Request();
        $request->cookies->set(VisitorIdResolver::COOKIE_NAME, 'nicht-eine-uuid');
        $response = new Response();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $resolver = new VisitorIdResolver($this->grantingGate(), $logger);
        $resolved = $resolver->resolveForRequest($request);
        $resolver->writeCookieIfNeeded($request, $response);

        self::assertTrue(Uuid::isValid($resolved));
        self::assertNotSame('nicht-eine-uuid', $resolved);
        self::assertNotNull($this->findCookie($response));
    }

    public function testGetCurrentVisitorIdReturnsNullBeforeResolve(): void
    {
        self::assertNull($this->resolver($this->grantingGate())->getCurrentVisitorId(new Request()));
    }

    private function resolver(ConsentGate $gate): VisitorIdResolver
    {
        return new VisitorIdResolver($gate, new NullLogger());
    }

    private function grantingGate(): ConsentGate
    {
        $gate = $this->createMock(ConsentGate::class);
        $gate->method('allowsTrackingCookie')->willReturn(true);

        return $gate;
    }

    private function denyingGate(): ConsentGate
    {
        $gate = $this->createMock(ConsentGate::class);
        $gate->method('allowsTrackingCookie')->willReturn(false);

        return $gate;
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
