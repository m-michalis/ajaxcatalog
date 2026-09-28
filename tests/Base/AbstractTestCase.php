<?php

namespace Tests\Base;

use InternetCode_AjaxCatalog_Helper_Data;
use Mage;
use RuntimeException;
use Mage_Core_Model_Abstract;
use Mage_Core_Model_Resource_Abstract;
use Mage_Eav_Model_Entity_Attribute;
use Mage_Catalog_Model_Product;
use Mage_Eav_Model_Entity_Attribute_Source_Abstract;
use Mage_CatalogInventory_Model_Stock_Item;
use Mage_Core_Model_Config;
use Mage_Core_Model_Resource;
use Varien_Db_Adapter_Interface;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for InternetCode_AjaxCatalog module tests.
 *
 * All test classes should extend this instead of PHPUnit\Framework\TestCase
 * directly. It provides three things:
 *
 *  1. Typed accessors for the module's helper, models and resource models.
 *     Mage::helper()/getModel()/getResourceModel() are `false`-or-loose to
 *     static analysis, so every accessor asserts the contract explicitly and
 *     fails loudly instead of handing a `false` to the caller.
 *
 *  2. A config mutation lifecycle. Every value written through configure() is
 *     recorded and rolled back in tearDown(), so a test can never leak
 *     configuration into the tests that run after it in the same process.
 *
 *  3. Fixture helpers for the two OpenMage traps that fail silently: EAV
 *     option labels (resolveOptionId()) and product stock (setProductStock()).
 */
abstract class AbstractTestCase extends TestCase
{
    /**
     * Module alias used for helper, model and resource model lookups.
     */
    protected const MODULE_ALIAS = 'ajaxcatalog';

    /**
     * Config section the module's settings live under.
     *
     * The module has no section of its own; it adds fields to Catalog > Frontend.
     */
    protected const CONFIG_SECTION = 'catalog';

    /**
     * core_config_data scope used when no store is given.
     */
    private const SCOPE_DEFAULT = 'default';

    /**
     * core_config_data scope used when a store id is given.
     */
    private const SCOPE_STORES = 'stores';

    /**
     * Stock id every single-source OpenMage install uses.
     *
     * Hardcoded rather than read from the catalog inventory model so this file
     * depends on as little of the core API surface as possible.
     */
    private const DEFAULT_STOCK_ID = 1;

    /**
     * Config values captured before the running test mutated them.
     *
     * Keyed by "path|scope|scopeId" so the same path can be tracked
     * independently per scope. `value` is the value that was in effect before
     * the first write; null means "nothing was set", which is restored by
     * deleting the row again rather than writing an empty string.
     *
     * @var array<string, array{path: string, scope: string, scopeId: int, value: null|string}>
     */
    private array $configBackup = [];

    /**
     * Get the module's default helper.
     *
     * Returns the CONCRETE helper type on purpose. Typing this as
     * Mage_Core_Helper_Abstract would make every module-specific method
     * (isEnabled(), etc.) an "undefined method" to PHPStan at level 8.
     *
     * @throws RuntimeException When the alias does not resolve to the module helper
     */
    protected function getHelper(): InternetCode_AjaxCatalog_Helper_Data
    {
        $helper = Mage::helper(self::MODULE_ALIAS);

        if (!$helper instanceof InternetCode_AjaxCatalog_Helper_Data) {
            throw new RuntimeException(sprintf(
                'Helper alias "%s" did not resolve to InternetCode_AjaxCatalog_Helper_Data.',
                self::MODULE_ALIAS,
            ));
        }

        return $helper;
    }

    /**
     * Get a model from the module by short alias.
     *
     * @param string $model Model alias, e.g. 'some_entity'
     *
     * @throws RuntimeException When the alias does not resolve to a model
     */
    protected function getModel(string $model): Mage_Core_Model_Abstract
    {
        $alias = self::MODULE_ALIAS . '/' . $model;
        $instance = Mage::getModel($alias);

        if (!$instance instanceof Mage_Core_Model_Abstract) {
            throw new RuntimeException(sprintf(
                'Model alias "%s" did not resolve to a Mage_Core_Model_Abstract instance.',
                $alias,
            ));
        }

        return $instance;
    }

    /**
     * Get a resource model from the module by short alias.
     *
     * @param string $model Resource model alias, e.g. 'some_entity'
     *
     * @throws RuntimeException When the alias does not resolve to a resource model
     */
    protected function getResourceModel(string $model): Mage_Core_Model_Resource_Abstract
    {
        $alias = self::MODULE_ALIAS . '/' . $model;
        $instance = Mage::getResourceModel($alias);

        if (!$instance instanceof Mage_Core_Model_Resource_Abstract) {
            throw new RuntimeException(sprintf(
                'Resource model alias "%s" did not resolve to a Mage_Core_Model_Resource_Abstract instance.',
                $alias,
            ));
        }

        return $instance;
    }

    /**
     * Read a module config value.
     *
     * @param string   $path    Config path relative to the module section, e.g. 'frontend/split_frontend_catalog'
     * @param null|int $storeId Store to read from; null uses the current store
     *
     * @throws RuntimeException When the stored value cannot be represented as a string
     */
    protected function getConfig(string $path, ?int $storeId = null): ?string
    {
        $fullPath = $this->configPath($path);
        $value = Mage::getStoreConfig($fullPath, $storeId);

        if ($value === null) {
            return null;
        }

        return $this->normalizeConfigValue($value, $fullPath);
    }

    /**
     * Set a module config value for the duration of the current test.
     *
     * The value in effect before the first write to a given path+scope is
     * captured and restored by tearDown(), so mutations cannot leak into the
     * tests that follow.
     *
     * Both Mage::getConfig()->reinit() and Mage::app()->reinitStores() run
     * after the write. reinit() rebuilds the config tree, but already
     * instantiated Mage_Core_Model_Store objects keep their own cached values —
     * that is the number one reason a test "saves the config" and then still
     * reads the old value back through Mage::getStoreConfig().
     *
     * @param string      $path    Config path relative to the module section, e.g. 'frontend/split_frontend_catalog'
     * @param null|string $value   New value; null removes the value at this scope
     * @param null|int    $storeId Store to write to; null writes the default scope
     *
     * @throws RuntimeException When the value currently stored cannot be captured
     */
    protected function configure(string $path, ?string $value, ?int $storeId = null): void
    {
        $fullPath = $this->configPath($path);
        $scope = $this->scopeName($storeId);
        $scopeId = $this->scopeId($storeId);
        $key = sprintf('%s|%s|%d', $fullPath, $scope, $scopeId);

        if (!array_key_exists($key, $this->configBackup)) {
            $this->configBackup[$key] = [
                'path' => $fullPath,
                'scope' => $scope,
                'scopeId' => $scopeId,
                'value' => $this->readRawConfigValue($fullPath, $scope, $scopeId),
            ];
        }

        $this->writeConfigValue($fullPath, $value, $scope, $scopeId);
        $this->flushConfig();
    }

    /**
     * Set a module config value for testing.
     *
     * @param string      $path    Config path relative to the module section
     * @param null|string $value   New value; null removes the value at this scope
     * @param null|int    $storeId Store to write to; null writes the default scope
     *
     * @throws RuntimeException When the value currently stored cannot be captured
     *
     * @deprecated Use configure(); this alias only exists so older tests keep working
     */
    protected function setConfig(string $path, ?string $value, ?int $storeId = null): void
    {
        $this->configure($path, $value, $storeId);
    }

    /**
     * Assert that a module config path resolves to the expected value.
     *
     * @param null|string $expected Expected value; null asserts "nothing is set"
     * @param string      $path     Config path relative to the module section
     * @param null|int    $storeId  Store to read from; null uses the current store
     *
     * @throws RuntimeException When the stored value cannot be represented as a string
     */
    protected function assertConfigEquals(?string $expected, string $path, ?int $storeId = null): void
    {
        self::assertSame(
            $expected,
            $this->getConfig($path, $storeId),
            sprintf(
                'Config "%s" in %s did not match the expected value.',
                $this->configPath($path),
                $this->scopeLabel($storeId),
            ),
        );
    }

    /**
     * Resolve a catalog_product select/multiselect option LABEL to its option id.
     *
     * Select and multiselect attributes store option ids, never labels.
     * Assigning the raw label ("Blue") is accepted by the model without any
     * complaint and silently stores garbage: the save succeeds, the attribute
     * ends up holding a value that matches no option, and the damage only
     * surfaces much later as a blank value on the frontend. Run every label
     * through here before assigning it.
     *
     * @param string $attributeCode Product attribute code, e.g. 'color'
     * @param string $label         Admin option label to resolve
     *
     * @throws \Mage_Core_Exception When the attribute exists but has no usable source model
     * @throws RuntimeException     When the attribute or the label is unknown
     */
    protected function resolveOptionId(string $attributeCode, string $label): int
    {
        $attribute = Mage::getModel('eav/entity_attribute');

        if (!$attribute instanceof Mage_Eav_Model_Entity_Attribute) {
            throw new RuntimeException(
                'Alias "eav/entity_attribute" did not resolve to a Mage_Eav_Model_Entity_Attribute instance.',
            );
        }

        $attribute->loadByCode(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
        $attributeId = $attribute->getId();

        if (!is_numeric($attributeId) || (int) $attributeId < 1) {
            throw new RuntimeException(sprintf(
                'Product attribute "%s" does not exist.',
                $attributeCode,
            ));
        }

        $source = $attribute->getSource();

        if (!$source instanceof Mage_Eav_Model_Entity_Attribute_Source_Abstract) {
            throw new RuntimeException(sprintf(
                'Product attribute "%s" has no option source, so it has no labels to resolve.',
                $attributeCode,
            ));
        }

        $optionId = $source->getOptionId($label);

        if (is_int($optionId)) {
            return $optionId;
        }

        if (is_string($optionId) && $optionId !== '' && ctype_digit($optionId)) {
            return (int) $optionId;
        }

        throw new RuntimeException(sprintf(
            'Product attribute "%s" has no option labelled "%s".',
            $attributeCode,
            $label,
        ));
    }

    /**
     * Write stock data for a saved product.
     *
     * qty and is_in_stock are NOT product attributes — they live in
     * cataloginventory_stock_item. Setting them on the product object is
     * accepted without error and then silently dropped on save, leaving the
     * product with its old quantity and still out of stock. The stock item has
     * to be loaded and saved on its own, which is what this does.
     *
     * @param \Mage_Catalog_Model_Product $product Product that has already been saved
     * @param int                         $qty     Quantity to store
     * @param bool                        $inStock Stock availability flag
     *
     * @throws Exception        When the stock item cannot be saved
     * @throws RuntimeException When the product has no id or the stock item cannot be created
     */
    protected function setProductStock(Mage_Catalog_Model_Product $product, int $qty, bool $inStock = true): void
    {
        $rawProductId = $product->getId();

        if (!is_numeric($rawProductId)) {
            throw new RuntimeException('The product must be saved before stock can be assigned to it.');
        }

        $productId = (int) $rawProductId;
        $stockItem = Mage::getModel('cataloginventory/stock_item');

        if (!$stockItem instanceof Mage_CatalogInventory_Model_Stock_Item) {
            throw new RuntimeException(
                'Alias "cataloginventory/stock_item" did not resolve to a Mage_CatalogInventory_Model_Stock_Item instance.',
            );
        }

        $stockItem->loadByProduct($productId);

        if ($stockItem->getId() === null) {
            $stockItem->setData('stock_id', self::DEFAULT_STOCK_ID);
        }

        $stockItem->setData('product_id', $productId);
        $stockItem->setData('qty', $qty);
        $stockItem->setData('is_in_stock', $inStock ? 1 : 0);
        $stockItem->save();
    }

    /**
     * Per-class cleanup hook, run by tearDown() after the config rollback.
     *
     * Override it in a concrete test case to remove fixtures that test created.
     * Never call Mage::reset() from it: that tears the application down for the
     * whole PHP process, and every test that runs afterwards fails.
     */
    protected function resetState(): void {}

    /**
     * Roll back everything the test changed, then let PHPUnit clean up.
     *
     * Every value captured by configure() is restored — the original is written
     * back, or the row is deleted when the path had no value before the test —
     * and the config caches are flushed so the next test starts from a clean
     * scope.
     */
    protected function tearDown(): void
    {
        if ($this->configBackup !== []) {
            foreach ($this->configBackup as $backup) {
                $this->writeConfigValue(
                    $backup['path'],
                    $backup['value'],
                    $backup['scope'],
                    $backup['scopeId'],
                );
            }

            $this->flushConfig();

            $this->configBackup = [];
        }

        $this->resetState();

        parent::tearDown();
    }

    /**
     * Build a full config path from a path relative to the module section.
     *
     * @param string $path Config path relative to the module section
     */
    private function configPath(string $path): string
    {
        return self::CONFIG_SECTION . '/' . ltrim($path, '/');
    }

    /**
     * core_config_data scope for a store id.
     *
     * @param null|int $storeId Store id, or null for the default scope
     */
    private function scopeName(?int $storeId): string
    {
        return $storeId === null ? self::SCOPE_DEFAULT : self::SCOPE_STORES;
    }

    /**
     * core_config_data scope id for a store id.
     *
     * @param null|int $storeId Store id, or null for the default scope
     */
    private function scopeId(?int $storeId): int
    {
        return $storeId ?? 0;
    }

    /**
     * Human readable scope, used in assertion messages.
     *
     * @param null|int $storeId Store id, or null for the default scope
     */
    private function scopeLabel(?int $storeId): string
    {
        return $storeId === null ? 'the default scope' : sprintf('store scope %d', $storeId);
    }

    /**
     * Write, or remove, a raw config value at an explicit scope.
     *
     * A null value is deleted instead of saved: core_config_data has no
     * meaningful "null" row, and deleting the row restores inheritance from the
     * parent scope, which is the only sane meaning of "unset this value".
     *
     * @param string      $fullPath Full config path, module section included
     * @param null|string $value    Value to store; null deletes the row
     * @param string      $scope    core_config_data scope
     * @param int         $scopeId  core_config_data scope id
     */
    private function writeConfigValue(string $fullPath, ?string $value, string $scope, int $scopeId): void
    {
        $config = $this->getMageConfig();

        if ($value === null) {
            $config->deleteConfig($fullPath, $scope, $scopeId);

            return;
        }

        $config->saveConfig($fullPath, $value, $scope, $scopeId);
    }

    /**
     * The core_config_data row for exactly this scope, or null when there is none.
     *
     * Mage::getStoreConfig() cannot be used for the backup: it returns the
     * inherited or config.xml value, and restoring that would write a row that
     * breaks inheritance instead of deleting the one the test created.
     *
     * @param string $fullPath Full config path, module section included
     * @param string $scope    core_config_data scope
     * @param int    $scopeId  core_config_data scope id
     *
     * @throws RuntimeException When the core resource or its read connection is unavailable
     */
    private function readRawConfigValue(string $fullPath, string $scope, int $scopeId): ?string
    {
        $resource = Mage::getSingleton('core/resource');

        if (!$resource instanceof Mage_Core_Model_Resource) {
            throw new RuntimeException('Alias "core/resource" did not resolve to Mage_Core_Model_Resource.');
        }

        $read = $resource->getConnection('core_read');

        if (!$read instanceof Varien_Db_Adapter_Interface) {
            throw new RuntimeException('The core_read connection is unavailable.');
        }

        $select = $read->select()
            ->from($resource->getTableName('core/config_data'), ['value'])
            ->where('path = ?', $fullPath)
            ->where('scope = ?', $scope)
            ->where('scope_id = ?', $scopeId);
        $row = $read->fetchRow($select);

        if (!is_array($row)) {
            return null;
        }

        return $row['value'] === null ? null : (string) $row['value'];
    }

    /**
     * Mage::getConfig() is typed as nullable, so every call site would otherwise
     * trip PHPStan's method.nonObject. Resolve and assert it in exactly one place.
     *
     * @throws RuntimeException When the config singleton is unavailable
     */
    private function getMageConfig(): Mage_Core_Model_Config
    {
        $config = Mage::getConfig();

        if (!$config instanceof Mage_Core_Model_Config) {
            throw new RuntimeException('Mage::getConfig() is unavailable; was Mage::app() bootstrapped?');
        }

        return $config;
    }

    /**
     * Rebuild the config tree and refresh the already instantiated stores.
     *
     * Both calls are required. reinit() refreshes the config tree only; the
     * store objects that Mage::app() has already created keep serving their own
     * cached values until reinitStores() replaces them.
     */
    private function flushConfig(): void
    {
        $this->getMageConfig()->reinit();
        Mage::app()->reinitStores();
    }

    /**
     * Coerce a raw config value into the string the config API is documented to return.
     *
     * The parameter is deliberately typed `mixed`: the value comes out of the
     * merged config tree, where scalars other than strings do occur, and the
     * checks below have to stay meaningful whatever the core signature claims.
     *
     * @param mixed  $value    Raw value taken from the config tree
     * @param string $fullPath Full config path, used for the error message
     *
     * @throws RuntimeException When the value is not a scalar
     */
    private function normalizeConfigValue(mixed $value, string $fullPath): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value) || is_float($value) || is_int($value)) {
            return (string) $value;
        }

        throw new RuntimeException(sprintf(
            'Config path "%s" holds a %s, which cannot be read as a string.',
            $fullPath,
            get_debug_type($value),
        ));
    }
}
