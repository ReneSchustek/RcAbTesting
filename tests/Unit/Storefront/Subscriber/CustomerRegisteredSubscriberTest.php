<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CustomerRegisteredSubscriber;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Framework\Context;

final class CustomerRegisteredSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksCustomerRegistered(): void
    {
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn('cust-1');

        $event = $this->createMock(CustomerRegisterEvent::class);
        $event->method('getCustomer')->willReturn($customer);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        (new CustomerRegisteredSubscriber($this->recorderWithAssignment()))->onCustomerRegistered($event);

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::CUSTOMER_REGISTERED, $this->capturedEvents[0]['eventType']);
        self::assertSame(['customer_id' => 'cust-1'], $this->capturedEvents[0]['meta']);
    }
}
