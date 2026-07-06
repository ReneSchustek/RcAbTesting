<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbVariant;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Anämische Entity für `rc_ab_variant`. Validierung der Gewichts-Summe über
 * alle Varianten eines Experiments erfolgt im Service-Layer.
 */
final class AbVariantEntity extends Entity
{
    use EntityIdTrait;

    protected string $experimentId;

    protected ?AbExperimentEntity $experiment = null;

    protected string $technicalKey;

    protected string $name;

    protected ?string $description = null;

    protected int $weight;

    protected bool $isControl;

    /** @var array<string, mixed>|null */
    protected ?array $config = null;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): void
    {
        $this->weight = $weight;
    }

    public function isControl(): bool
    {
        return $this->isControl;
    }

    public function setIsControl(bool $isControl): void
    {
        $this->isControl = $isControl;
    }

    /** @return array<string, mixed>|null */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): void
    {
        $this->config = $config;
    }
}
