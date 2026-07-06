<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
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

    public function testCmsExperimentRejectsVariantWithoutCmsPage(): void
    {
        $experiment = $this->experiment(AbExperimentTestType::CMS_PAGE, [
            $this->variantWithConfig(50, true, ['cmsPageId' => Uuid::randomHex()]),
            $this->variantWithConfig(50, false, null),
        ]);

        self::assertSame(
            ExperimentIntegrityValidator::VIOLATION_CMS_PAGE_MISSING,
            $this->validator->firstStartViolation($experiment),
        );
    }

    public function testCmsExperimentWithCmsPageOnEachVariantIsValid(): void
    {
        $experiment = $this->experiment(AbExperimentTestType::CMS_PAGE, [
            $this->variantWithConfig(50, true, ['cmsPageId' => Uuid::randomHex()]),
            $this->variantWithConfig(50, false, ['cmsPageId' => Uuid::randomHex()]),
        ]);

        self::assertNull($this->validator->firstStartViolation($experiment));
    }

    public function testNonCmsExperimentDoesNotRequireCmsPage(): void
    {
        $experiment = $this->experiment(AbExperimentTestType::TWIG, [
            $this->variantWithConfig(50, true, null),
            $this->variantWithConfig(50, false, null),
        ]);

        self::assertNull($this->validator->firstStartViolation($experiment));
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

    /**
     * @param array<string, mixed>|null $config
     */
    private function variantWithConfig(int $weight, bool $isControl, ?array $config): AbVariantEntity
    {
        $variant = $this->variant($weight, $isControl);
        $variant->setConfig($config);

        return $variant;
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    private function experiment(string $testType, array $variants): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTestType($testType);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }
}
