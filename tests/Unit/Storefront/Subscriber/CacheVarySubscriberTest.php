<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CacheVarySubscriber;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CacheVarySubscriberTest extends TestCase
{
    public function testDisablesCacheForExperimentPages(): void
    {
        $request = new Request();
        $request->attributes->set(RequestVariantResolver::IN_EXPERIMENT_ATTRIBUTE, true);
        $event = new BeforeSendResponseEvent($request, new Response());

        (new CacheVarySubscriber())->onBeforeSendResponse($event);

        // ResponseHeaderBag normalisiert die Direktiv-Reihenfolge, daher auf Enthaltensein prüfen.
        $cacheControl = (string) $event->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testLeavesNonExperimentPagesUntouched(): void
    {
        $event = new BeforeSendResponseEvent(new Request(), new Response());

        (new CacheVarySubscriber())->onBeforeSendResponse($event);

        self::assertStringNotContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
    }
}
