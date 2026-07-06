<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Subscriber\KernelResponseSubscriber;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KernelResponseSubscriberTest extends TestCase
{
    public function testWritesCookieForNewVisitor(): void
    {
        $request = Request::create('/');
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, Uuid::randomHex());
        $request->attributes->set(VisitorIdResolver::NEW_FLAG_ATTRIBUTE, true);
        $response = new Response();

        $this->subscriber()->onResponse($this->event($request, $response, HttpKernelInterface::MAIN_REQUEST));

        self::assertNotNull($this->findCookie($response));
    }

    public function testSkipsSubRequest(): void
    {
        $request = Request::create('/');
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, Uuid::randomHex());
        $request->attributes->set(VisitorIdResolver::NEW_FLAG_ATTRIBUTE, true);
        $response = new Response();

        $this->subscriber()->onResponse($this->event($request, $response, HttpKernelInterface::SUB_REQUEST));

        self::assertNull($this->findCookie($response));
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
