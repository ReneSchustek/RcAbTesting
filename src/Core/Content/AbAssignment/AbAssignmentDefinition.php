<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbAssignment;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentDefinition;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * Definition für `rc_ab_assignment` — die sticky Zuordnung Visitor → Variante
 * pro Experiment. UNIQUE-Constraints auf (experiment_id, visitor_id) und
 * (experiment_id, customer_id) sind DB-seitig gesetzt; parallele Erst-Zuordnungen
 * fängt der Service über den `UniqueConstraintViolationException`-Fallback ab.
 */
final class AbAssignmentDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ab_assignment';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AbAssignmentEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AbAssignmentCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('experiment_id', 'experimentId', AbExperimentDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new FkField('variant_id', 'variantId', AbVariantDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new StringField('visitor_id', 'visitorId', 64))->addFlags(new ApiAware(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new ApiAware()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new FkField('language_id', 'languageId', LanguageDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new DateTimeField('assigned_at', 'assignedAt'))->addFlags(new ApiAware(), new Required()),
            (new DateTimeField('last_seen_at', 'lastSeenAt'))->addFlags(new ApiAware(), new Required()),

            (new ManyToOneAssociationField('experiment', 'experiment_id', AbExperimentDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new ManyToOneAssociationField('variant', 'variant_id', AbVariantDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('language', 'language_id', LanguageDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
        ]);
    }
}
