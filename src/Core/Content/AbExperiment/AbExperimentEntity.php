<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\User\UserEntity;

/**
 * Anämische Entity für `rc_ab_experiment`. Validierung der Status- und
 * TestType-Werte erfolgt im Service-Layer, nicht in der Entity.
 */
final class AbExperimentEntity extends Entity
{
    use EntityIdTrait;

    protected string $technicalKey;

    protected string $name;

    protected ?string $hypothesis = null;

    protected string $status;

    protected string $testType;

    protected ?string $primaryMetric = null;

    /** @var list<string>|null */
    protected ?array $secondaryMetrics = null;

    protected ?string $decisionMetric = null;

    protected int $trafficAllocationPct;

    protected ?int $minSampleSize = null;

    protected float $targetSignificance;

    protected ?string $salesChannelId = null;

    protected ?SalesChannelEntity $salesChannel = null;

    protected ?string $ruleId = null;

    protected ?RuleEntity $rule = null;

    protected ?\DateTimeInterface $startedAt = null;

    protected ?\DateTimeInterface $endedAt = null;

    protected ?\DateTimeInterface $scheduledStartAt = null;

    protected ?\DateTimeInterface $scheduledEndAt = null;

    protected ?string $winnerVariantId = null;

    protected ?AbVariantEntity $winnerVariant = null;

    protected ?string $createdById = null;

    protected ?UserEntity $createdBy = null;

    protected ?string $notes = null;

    protected ?AbVariantCollection $variants = null;

    public function getTechnicalKey(): string
    {
        return $this->technicalKey;
    }

    public function setTechnicalKey(string $technicalKey): void
    {
        $this->technicalKey = $technicalKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getHypothesis(): ?string
    {
        return $this->hypothesis;
    }

    public function setHypothesis(?string $hypothesis): void
    {
        $this->hypothesis = $hypothesis;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getTestType(): string
    {
        return $this->testType;
    }

    public function setTestType(string $testType): void
    {
        $this->testType = $testType;
    }

    public function getPrimaryMetric(): ?string
    {
        return $this->primaryMetric;
    }

    public function setPrimaryMetric(?string $primaryMetric): void
    {
        $this->primaryMetric = $primaryMetric;
    }

    /** @return list<string>|null */
    public function getSecondaryMetrics(): ?array
    {
        return $this->secondaryMetrics;
    }

    /** @param list<string>|null $secondaryMetrics */
    public function setSecondaryMetrics(?array $secondaryMetrics): void
    {
        $this->secondaryMetrics = $secondaryMetrics;
    }

    public function getDecisionMetric(): ?string
    {
        return $this->decisionMetric;
    }

    public function setDecisionMetric(?string $decisionMetric): void
    {
        $this->decisionMetric = $decisionMetric;
    }

    public function getTrafficAllocationPct(): int
    {
        return $this->trafficAllocationPct;
    }

    public function setTrafficAllocationPct(int $trafficAllocationPct): void
    {
        $this->trafficAllocationPct = $trafficAllocationPct;
    }

    public function getMinSampleSize(): ?int
    {
        return $this->minSampleSize;
    }

    public function setMinSampleSize(?int $minSampleSize): void
    {
        $this->minSampleSize = $minSampleSize;
    }

    public function getTargetSignificance(): float
    {
        return $this->targetSignificance;
    }

    public function setTargetSignificance(float $targetSignificance): void
    {
        $this->targetSignificance = $targetSignificance;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(?string $salesChannelId): void
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

    public function getRuleId(): ?string
    {
        return $this->ruleId;
    }

    public function setRuleId(?string $ruleId): void
    {
        $this->ruleId = $ruleId;
    }

    public function getRule(): ?RuleEntity
    {
        return $this->rule;
    }

    public function setRule(?RuleEntity $rule): void
    {
        $this->rule = $rule;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getEndedAt(): ?\DateTimeInterface
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeInterface $endedAt): void
    {
        $this->endedAt = $endedAt;
    }

    public function getScheduledStartAt(): ?\DateTimeInterface
    {
        return $this->scheduledStartAt;
    }

    public function setScheduledStartAt(?\DateTimeInterface $scheduledStartAt): void
    {
        $this->scheduledStartAt = $scheduledStartAt;
    }

    public function getScheduledEndAt(): ?\DateTimeInterface
    {
        return $this->scheduledEndAt;
    }

    public function setScheduledEndAt(?\DateTimeInterface $scheduledEndAt): void
    {
        $this->scheduledEndAt = $scheduledEndAt;
    }

    public function getWinnerVariantId(): ?string
    {
        return $this->winnerVariantId;
    }

    public function setWinnerVariantId(?string $winnerVariantId): void
    {
        $this->winnerVariantId = $winnerVariantId;
    }

    public function getWinnerVariant(): ?AbVariantEntity
    {
        return $this->winnerVariant;
    }

    public function setWinnerVariant(?AbVariantEntity $winnerVariant): void
    {
        $this->winnerVariant = $winnerVariant;
    }

    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    public function setCreatedById(?string $createdById): void
    {
        $this->createdById = $createdById;
    }

    public function getCreatedBy(): ?UserEntity
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?UserEntity $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function getVariants(): ?AbVariantCollection
    {
        return $this->variants;
    }

    public function setVariants(?AbVariantCollection $variants): void
    {
        $this->variants = $variants;
    }

    /**
     * Varianten als indizierte Liste (leer, wenn nicht geladen). Zentralisiert das
     * zuvor fünffach kopierte `array_values(getVariants()->getElements())`-Muster
     * (Arbeitspaket AB44).
     *
     * @return list<AbVariantEntity>
     */
    public function getVariantList(): array
    {
        return $this->variants === null ? [] : array_values($this->variants->getElements());
    }

    /**
     * Die Control-Variante des Experiments oder null.
     */
    public function getControlVariant(): ?AbVariantEntity
    {
        foreach ($this->getVariantList() as $variant) {
            if ($variant->isControl()) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Die Primär-Metrik (welches Event als Conversion zählt) oder der Default
     * `checkout.order_placed`, wenn keine gesetzt ist. Zentralisiert den zuvor in
     * drei Aggregatoren wiederholten Fallback (Arbeitspaket AB44).
     */
    public function getPrimaryMetricOrDefault(): string
    {
        return $this->primaryMetric ?? AbEventType::CHECKOUT_ORDER_PLACED;
    }
}
