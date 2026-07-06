<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbAssignment;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Anämische Entity für `rc_ab_assignment`. Die Sticky-Garantie liegt in der
 * UNIQUE-Constraint (experiment_id, visitor_id), nicht in der Entity selbst.
 */
final class AbAssignmentEntity extends Entity
{
    use EntityIdTrait;

    protected string $experimentId;

    protected ?AbExperimentEntity $experiment = null;

    protected string $variantId;

    protected ?AbVariantEntity $variant = null;

    protected string $visitorId;

    protected ?string $customerId = null;

    protected ?CustomerEntity $customer = null;

    protected string $salesChannelId;

    protected ?SalesChannelEntity $salesChannel = null;

    protected string $languageId;

    protected ?LanguageEntity $language = null;

    protected \DateTimeInterface $assignedAt;

    protected \DateTimeInterface $lastSeenAt;

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

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(?SalesChannelEntity $salesChannel): void
    {
        $this->salesChannel = $salesChannel;
    }

    public function getLanguageId(): string
    {
        return $this->languageId;
    }

    public function setLanguageId(string $languageId): void
    {
        $this->languageId = $languageId;
    }

    public function getLanguage(): ?LanguageEntity
    {
        return $this->language;
    }

    public function setLanguage(?LanguageEntity $language): void
    {
        $this->language = $language;
    }

    public function getAssignedAt(): \DateTimeInterface
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(\DateTimeInterface $assignedAt): void
    {
        $this->assignedAt = $assignedAt;
    }

    public function getLastSeenAt(): \DateTimeInterface
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeInterface $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }
}
