<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbEvent;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Anämische Entity für `rc_ab_event` — ein einzelnes Funnel-Ereignis. Die
 * Auswertung übernehmen ExperimentStatsAggregator und ExperimentEvaluator, nicht
 * die Entity.
 */
final class AbEventEntity extends Entity
{
    use EntityIdTrait;

    protected string $experimentId;

    protected ?AbExperimentEntity $experiment = null;

    protected string $variantId;

    protected ?AbVariantEntity $variant = null;

    protected string $visitorId;

    protected ?string $customerId = null;

    protected ?CustomerEntity $customer = null;

    protected string $eventType;

    protected ?float $eventValue = null;

    /** @var array<string, mixed>|null */
    protected ?array $meta = null;

    protected ?string $sessionId = null;

    protected \DateTimeInterface $occurredAt;

    public function getExperimentId(): string
    {
        return $this->experimentId;
    }

    public function setExperimentId(string $experimentId): void
    {
        $this->experimentId = $experimentId;
    }

    public function getExperiment(): ?AbExperimentEntity
    {
        return $this->experiment;
    }

    public function setExperiment(?AbExperimentEntity $experiment): void
    {
        $this->experiment = $experiment;
    }

    public function getVariantId(): string
    {
        return $this->variantId;
    }

    public function setVariantId(string $variantId): void
    {
        $this->variantId = $variantId;
    }

    public function getVariant(): ?AbVariantEntity
    {
        return $this->variant;
    }

    public function setVariant(?AbVariantEntity $variant): void
    {
        $this->variant = $variant;
    }

    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    public function setVisitorId(string $visitorId): void
    {
        $this->visitorId = $visitorId;
    }

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function getEventValue(): ?float
    {
        return $this->eventValue;
    }

    public function setEventValue(?float $eventValue): void
    {
        $this->eventValue = $eventValue;
    }

    /** @return array<string, mixed>|null */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /** @param array<string, mixed>|null $meta */
    public function setMeta(?array $meta): void
    {
        $this->meta = $meta;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getOccurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeInterface $occurredAt): void
    {
        $this->occurredAt = $occurredAt;
    }
}
