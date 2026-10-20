<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\ExperimentTouchState;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CacheVarySubscriber;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft, dass eine Seite, die ein Experiment berührt hat, nicht in einen gemeinsamen Cache
 * gerät. Sonst lieferte der Reverse-Proxy die Variante eines Besuchers an alle anderen aus,
 * und die Gruppen wären vermischt. Seiten ohne Experiment bleiben cachebar.
 */
final class CacheVarySubscriberTest extends TestCase
{
    public function testDisablesCacheWhenAnExperimentWasTouched(): void
    {
        $event = new BeforeSendResponseEvent(new Request(), new Response());

        (new CacheVarySubscriber($this->touchState(true)))->onBeforeSendResponse($event);

        // `ResponseHeaderBag` ordnet die Direktiven neu, deshalb wird nur auf Enthaltensein geprüft.
        $cacheControl = (string) $event->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testLeavesNonExperimentPagesUntouched(): void
    {
        $event = new BeforeSendResponseEvent(new Request(), new Response());

        (new CacheVarySubscriber($this->touchState(false)))->onBeforeSendResponse($event);

        self::assertStringNotContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    private function touchState(bool $touched): ExperimentTouchState
    {
        $state = $this->createMock(ExperimentTouchState::class);
        $state->method('hasTouchedExperiment')->willReturn($touched);

        return $state;
    }
}
