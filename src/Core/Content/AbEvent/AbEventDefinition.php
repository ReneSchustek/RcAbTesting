<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbEvent;

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
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Definition für `rc_ab_event` — ein Funnel-Event (z.B. page.viewed,
 * checkout.order_placed). Hot-Query-Pfad ist (experiment_id, event_type,
 * occurred_at); der Index ist DB-seitig gesetzt.
 */
final class AbEventDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'rc_ab_event';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AbEventEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AbEventCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('experiment_id', 'experimentId', AbExperimentDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new FkField('variant_id', 'variantId', AbVariantDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new StringField('visitor_id', 'visitorId', 64))->addFlags(new ApiAware(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new ApiAware()),
            (new StringField('event_type', 'eventType', 64))->addFlags(new ApiAware(), new Required()),
            // DECIMAL(20,4) im Schema, DAL als float — für Order-Values bis ~1e16 ausreichend.
            (new FloatField('event_value', 'eventValue'))->addFlags(new ApiAware()),
            (new JsonField('meta', 'meta'))->addFlags(new ApiAware()),
            (new StringField('session_id', 'sessionId', 64))->addFlags(new ApiAware()),
            (new DateTimeField('occurred_at', 'occurredAt'))->addFlags(new ApiAware(), new Required()),

            (new ManyToOneAssociationField('experiment', 'experiment_id', AbExperimentDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new ManyToOneAssociationField('variant', 'variant_id', AbVariantDefinition::class, 'id', false))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false))
                ->addFlags(new ApiAware()),
        ]);
    }
}
