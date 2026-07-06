<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantDefinition;
use Shopware\Core\Content\Rule\RuleDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\User\UserDefinition;

/**
 * Definition für `rc_ab_experiment` — eine Test-Definition mit Lifecycle, Metriken
 * und optionalem Targeting (Sales-Channel, Rule). Status- und Test-Type-Werte sind
 * String-Felder; gültige Werte liefert {@see AbExperimentStatus} bzw.
 * {@see AbExperimentTestType}.
 */
final class AbExperimentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ab_experiment';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AbExperimentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AbExperimentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('technical_key', 'technicalKey', 64))->addFlags(new ApiAware(), new Required()),
            (new StringField('name', 'name'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('hypothesis', 'hypothesis'))->addFlags(new ApiAware()),
            (new StringField('status', 'status', 32))->addFlags(new ApiAware(), new Required()),
            (new StringField('test_type', 'testType', 32))->addFlags(new ApiAware(), new Required()),
            (new StringField('primary_metric', 'primaryMetric', 64))->addFlags(new ApiAware()),
            (new JsonField('secondary_metrics', 'secondaryMetrics'))->addFlags(new ApiAware()),
            (new IntField('traffic_allocation_pct', 'trafficAllocationPct'))->addFlags(new ApiAware(), new Required()),
            (new IntField('min_sample_size', 'minSampleSize'))->addFlags(new ApiAware()),
            // DECIMAL(4,3) im Schema (Wertebereich 0.000..9.999), DAL-seitig als float —
            // drei Nachkomma-Stellen reichen für Signifikanz-Schwellen wie 0.950/0.990.
            (new FloatField('target_significance', 'targetSignificance'))->addFlags(new ApiAware(), new Required()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new ApiAware()),
            (new FkField('rule_id', 'ruleId', RuleDefinition::class))->addFlags(new ApiAware()),
            (new DateTimeField('started_at', 'startedAt'))->addFlags(new ApiAware()),
            (new DateTimeField('ended_at', 'endedAt'))->addFlags(new ApiAware()),
            (new DateTimeField('scheduled_start_at', 'scheduledStartAt'))->addFlags(new ApiAware()),
            (new DateTimeField('scheduled_end_at', 'scheduledEndAt'))->addFlags(new ApiAware()),
            // FK auf rc_ab_variant absichtlich NICHT als DB-Constraint — verhindert
            // Henne-Ei beim ersten Migration-Lauf. Konsistenz garantiert Service-Layer.
            (new FkField('winner_variant_id', 'winnerVariantId', AbVariantDefinition::class))->addFlags(new ApiAware()),
            (new FkField('created_by_id', 'createdById', UserDefinition::class))->addFlags(new ApiAware()),
            (new LongTextField('notes', 'notes'))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('rule', 'rule_id', RuleDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('createdBy', 'created_by_id', UserDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('winnerVariant', 'winner_variant_id', AbVariantDefinition::class, 'id', false))->addFlags(new ApiAware()),
            (new OneToManyAssociationField('variants', AbVariantDefinition::class, 'experiment_id'))->addFlags(new ApiAware()),
        ]);
    }
}
