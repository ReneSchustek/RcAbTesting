<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\PageViewedSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PageViewedSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksHtmlGetPageView(): void
    {
        $event = $this->responseEvent('GET', '/', 200, 'text/html', true, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::PAGE_VIEWED, $this->capturedEvents[0]['eventType']);
    }

    public function testSkipsSubRequest(): void
    {
        $event = $this->responseEvent('GET', '/', 200, 'text/html', true, false);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    public function testSkipsNonGet(): void
    {
        $event = $this->responseEvent('POST', '/', 200, 'text/html', true, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    public function testSkipsNon200(): void
    {
        $event = $this->responseEvent('GET', '/', 404, 'text/html', true, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    public function testSkipsNonHtml(): void
    {
        $event = $this->responseEvent('GET', '/', 200, 'application/json', true, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    public function testSkipsAdminPath(): void
    {
        $event = $this->responseEvent('GET', '/admin/dashboard', 200, 'text/html', true, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    public function testSkipsWhenNoSalesChannelContext(): void
    {
        $event = $this->responseEvent('GET', '/', 200, 'text/html', false, true);

        (new PageViewedSubscriber($this->recorderWithAssignment()))->onResponse($event);

        self::assertNull($this->capturedEvents);
    }

    private function responseEvent(string $method, string $path, int $status, string $contentType, bool $withContext, bool $mainRequest): ResponseEvent
    {
        $request = Request::create($path, $method);
        if ($withContext) {
            $salesChannelContext = $this->createMock(SalesChannelContext::class);
            $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);
        }

        $response = new Response('<html></html>', $status, ['Content-Type' => $contentType]);

        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        );
    }
}
