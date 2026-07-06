<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentIntegrityValidatorTest extends TestCase
{
    private ExperimentIntegrityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExperimentIntegrityValidator();
    }

    public function testValidConfigurationHasNoViolation(): void
    {
        $violation = $this->validator->firstViolationForVariants([
            $this->variant(50, true),
            $this->variant(50, false),
        ]);

        self::assertNull($violation);
    }

    public function testRejectsFewerThanTwoVariants(): void
    {
        self::assertSame(
            ExperimentIntegrityValidator::VIOLATION_TOO_FEW_VARIANTS,
            $this->validator->firstViolationForVariants([$this->variant(100, true)]),
        );
    }

    public function testRejectsWeightSumNotHundred(): void
    {
        self::assertSame(
            ExperimentIntegrityValidator::VIOLATION_WEIGHT_SUM,
            $this->validator->firstViolationForVariants([
                $this->variant(60, true),
                $this->variant(60, false),
            ]),
        );
    }

    public function testRejectsNegativeWeight(): void
    {
        self::assertSame(
            ExperimentIntegrityValidator::VIOLATION_NEGATIVE_WEIGHT,
            $this->validator->firstViolationForVariants([
                $this->variant(-10, true),
                $this->variant(110, false),
            ]),
        );
    }

    public function testRejectsWrongControlCount(): void
    {
        $twoControls = $this->validator->firstViolationForVariants([
            $this->variant(50, true),
            $this->variant(50, true),
        ]);
        $noControl = $this->validator->firstViolationForVariants([
            $this->variant(50, false),
            $this->variant(50, false),
        ]);

        self::assertSame(ExperimentIntegrityValidator::VIOLATION_CONTROL_COUNT, $twoControls);
        self::assertSame(ExperimentIntegrityValidator::VIOLATION_CONTROL_COUNT, $noControl);
    }

    public function testMessageForReturnsGermanText(): void
    {
        self::assertStringContainsString('100', $this->validator->messageFor(ExperimentIntegrityValidator::VIOLATION_WEIGHT_SUM));
        self::assertStringContainsString('Control', $this->validator->messageFor(ExperimentIntegrityValidator::VIOLATION_CONTROL_COUNT));
    }

    private function variant(int $weight, bool $isControl): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey('v');
        $variant->setName('v');
        $variant->setWeight($weight);
        $variant->setIsControl($isControl);

        return $variant;
    }
}
