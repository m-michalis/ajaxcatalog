<?php

/**
 * Seed product EAV attributes: one text field and one select with options.
 *
 * Uses Mage_Catalog_Model_Resource_Setup explicitly. The default setup class
 * (Mage_Core_Model_Resource_Setup) has no addAttribute() at all, which is the
 * usual cause of "Call to undefined method addAttribute()".
 *
 * Idempotent on two axes: the attribute is only created when getIdByCode()
 * comes back empty, and it is only attached to the default set when it is not
 * attached already. The second guard matters because eav_entity_attribute has
 * a unique key on (attribute_set_id, attribute_id) and a repeat insert throws.
 *
 * Usage: ddev seed attributes
 */

require_once __DIR__ . '/lib.php';

// tests/fixtures/seed holds standalone CLI scripts, not a library of
// classes. Plain functions are the right shape here: a seeder is a file you
// run, and `require_once lib.php` is the whole of its dependency graph.
// Wrapping these in a static class would add indirection and buy nothing.
// phpcs:disable Squiz.Functions.GlobalFunction.Found

/**
 * EAV entity type these attributes belong to.
 */
const SEED_ATTRIBUTE_ENTITY = 'catalog_product';

/**
 * Attribute group inside the default attribute set.
 */
const SEED_ATTRIBUTE_GROUP = 'General';

/**
 * Attribute definitions, in code => addAttribute() payload order.
 *
 * @var array<string, array<string, mixed>>
 */
$attributes = [
    SEED_CODE_PREFIX . 'supplier_code' => [
        'type'                    => 'varchar',
        'input'                   => 'text',
        'label'                   => SEED_LABEL_PREFIX . 'Supplier Code',
        'required'                => false,
        'user_defined'            => true,
        // SCOPE_STORE so per-store-view overrides can be exercised. Changing
        // scope after values exist does NOT migrate the existing rows.
        'global'                  => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE,
        'visible'                 => true,
        'searchable'              => false,
        'filterable'              => false,
        'comparable'              => false,
        'visible_on_front'        => false,
        'used_in_product_listing' => true,
        'group'                   => SEED_ATTRIBUTE_GROUP,
        'sort_order'              => 100,
    ],
    SEED_CODE_PREFIX . 'grade' => [
        // A select input stores an int option id, not the label. Reading it
        // back with getQaGrade() yields "17"; use getAttributeText('qa_grade').
        'type'                    => 'int',
        'input'                   => 'select',
        // Mandatory for a user-defined select: without a source model
        // $attribute->getSource() throws Mage_Eav_Exception('Source model ""
        // not found'), and the admin form renders an empty dropdown.
        'source'                  => 'eav/entity_attribute_source_table',
        'label'                   => SEED_LABEL_PREFIX . 'Grade',
        'required'                => false,
        'user_defined'            => true,
        'global'                  => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
        'visible'                 => true,
        'searchable'              => false,
        'filterable'              => true,
        'comparable'              => false,
        'visible_on_front'        => true,
        'used_in_product_listing' => true,
        'option'                  => ['values' => ['Gold', 'Silver', 'Bronze']],
        'group'                   => SEED_ATTRIBUTE_GROUP,
        'sort_order'              => 110,
    ],
];

/**
 * Is this attribute already a member of the given attribute set?
 *
 * @param int    $attributeSetId Attribute set to inspect
 * @param string $code           Attribute code to look for
 */
function seed_attribute_is_in_set(int $attributeSetId, string $code): bool
{
    $collection = seed_resource(
        'eav/entity_attribute_collection',
        Mage_Eav_Model_Resource_Entity_Attribute_Collection::class,
    );

    // setAttributeSetFilter() joins eav_entity_attribute, which is the table
    // that actually records set membership.
    $collection->setAttributeSetFilter($attributeSetId);
    $collection->addFieldToFilter('main_table.attribute_code', $code);

    return $collection->getSize() > 0;
}

$setup = seed_catalog_setup();

$setup->startSetup();

$entityTypeId   = seed_id($setup->getEntityTypeId(SEED_ATTRIBUTE_ENTITY), 'catalog_product entity type');
$attributeSetId = seed_default_attribute_set_id();

$created  = 0;
$existing = 0;
$attached = 0;

foreach ($attributes as $code => $definition) {
    if (seed_attribute_id(SEED_ATTRIBUTE_ENTITY, $code) === null) {
        $setup->addAttribute(SEED_ATTRIBUTE_ENTITY, $code, $definition);
        seed_log(sprintf('  attributes: created %s', $code));
        $created++;
    } else {
        seed_log(sprintf('  attributes: %s already exists, skipping', $code));
        $existing++;
    }

    $attributeId = seed_attribute_id(SEED_ATTRIBUTE_ENTITY, $code);

    if ($attributeId === null) {
        seed_warn(sprintf('Attribute "%s" could not be resolved after creation.', $code));

        continue;
    }

    if (seed_attribute_is_in_set($attributeSetId, $code)) {
        continue;
    }

    // addAttribute() only auto-attaches when 'group' is present AND the
    // attribute is new, so an attribute restored from a partial clean can
    // exist while being orphaned from every set.
    $setup->addAttributeToSet($entityTypeId, $attributeSetId, SEED_ATTRIBUTE_GROUP, $attributeId);
    seed_log(sprintf('  attributes: attached %s to attribute set %d', $code, $attributeSetId));
    $attached++;
}

$setup->endSetup();

// eav/config caches attribute metadata per process; without this the very next
// script in the same PHP run would still not see the new attributes.
seed_reset_eav_cache();

seed_refresh_config();

seed_log(sprintf(
    '  attributes: %d created, %d already present, %d newly attached to the default set',
    $created,
    $existing,
    $attached,
));

exit(0);
