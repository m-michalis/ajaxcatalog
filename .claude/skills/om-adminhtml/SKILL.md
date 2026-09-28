---
name: om-adminhtml
description: "OpenMage admin UI — grid/form/tabs block trio, adminhtml controllers, ACL and menu in adminhtml.xml, system.xml fields, secret key URLs, session messages. Load for any admin panel or backend configuration task."
---

# Admin UI in a Module

## Gotchas

- **Menu item is invisible even as the admin user** — the `<menu>` node has no matching `<acl><resources>` entry. OpenMage hides menu items whose ACL resource path doesn't exist. Both blocks live in `etc/adminhtml.xml` and must mirror each other's node names.
- **"Access Denied" page on a controller that should be open** — `_isAllowed()` returns a resource path that isn't declared in the ACL tree. The path is the `<children>` node chain under `<admin>`, slash-separated: `internetcode/internetcode_ajax_catalog/manage`.
- **New ACL nodes exist but the role still can't see them** — admin roles cache their resource list. Re-save the role under System > Permissions > Roles, or flush cache.
- **Grid renders empty with no error** — `_prepareCollection()` didn't `return parent::_prepareCollection()`. The parent applies filters, sorting and paging; skipping it leaves the grid unbound.
- **Grid container renders "invalid block type"** — `_blockGroup` / `_controller` are wrong. The container resolves its child as `{_blockGroup}/{_controller}_grid`. With `_blockGroup = 'internetcode_ajax_catalog'` and `_controller = 'adminhtml_ajax_catalog'` it looks for `InternetCode_AjaxCatalog_Block_Adminhtml_ajax_catalog_Grid`.
- **Controller 404s** — the `<admin><routers>` block in `config.xml` is still commented out, or the controller file isn't under `controllers/Adminhtml/`. Admin controller classes end in `_Adminhtml_XController` and the directory is capitalised `Adminhtml`, unlike lowercase frontend `controllers/`.
- **Save posts but nothing persists** — the form's `<input name="...">` keys must match model field names, and the controller must call `->setData($this->getRequest()->getPost())` then `->save()`. `Varien_Data_Form` with `setUseContainer(false)` renders no `<form>` tag at all, so the POST never happens.
- **Direct admin URL from a script or test gives "Invalid Form Key" or a redirect to dashboard** — admin URLs carry a secret key segment `/key/<hash>/`. Never hardcode admin URLs. Build them with `$this->getUrl('*/*/edit', ['id' => $id])` in PHP; in Cypress, navigate by clicking or use the helpers in `om-cypress` rather than `cy.visit()` on a raw path.
- **`system.xml` section exists but is missing from the config tree** — no ACL node under `admin/system/config/<section>`. The template already ships this for `internetcode_ajax_catalog`; if you rename the section, rename the ACL node too.
- **`source_model` renders an empty dropdown** — the class must implement `toOptionArray(): array`, not `getAllOptions()`. `getAllOptions()` is the EAV source interface (see `om-eav`); `system.xml` uses `toOptionArray()`.
- **Admin success message shows twice or on the wrong page** — `addSuccess()` writes to the session and is consumed on the next render. Adding a message then rendering directly (instead of redirecting) leaves it queued.
- **Config saved in a test but `getStoreConfig()` returns the old value** — `Mage::getConfig()->reinit()` reloads the config tree but not already-instantiated store objects. Also call `Mage::app()->reinitStores()`.

## Block Trio Naming

Containers auto-resolve their children by convention. Get these three right and everything wires itself:

| Block | Class | Set in constructor |
|---|---|---|
| Grid container | `..._Block_Adminhtml_ajax_catalog` | `_blockGroup`, `_controller`, `_headerText` |
| Grid | `..._Block_Adminhtml_ajax_catalog_Grid` | `setId()`, `setDefaultSort()` |
| Form container | `..._Block_Adminhtml_ajax_catalog_Edit` | `_blockGroup`, `_controller`, `_mode = 'edit'` |
| Edit form | `..._Block_Adminhtml_ajax_catalog_Edit_Form` | — resolved as `{group}/{controller}_{mode}_form` |
| Tabs | `..._Block_Adminhtml_ajax_catalog_Edit_Tabs` | `setDestElementId('edit_form')` |

Requires the `<blocks>` block in `config.xml` uncommented.

## Grid

```php
<?php
class InternetCode_AjaxCatalog_Block_Adminhtml_ajax_catalog_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('internetcode_ajax_catalog_grid');
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection(): self
    {
        $this->setCollection(Mage::getResourceModel('internetcode_ajax_catalog/example_entity_collection'));
        return parent::_prepareCollection();
    }

    protected function _prepareColumns(): self
    {
        $this->addColumn('entity_id', [
            'header' => Mage::helper('internetcode_ajax_catalog')->__('ID'),
            'type'   => 'number',
            'width'  => '50px',
            'index'  => 'entity_id',
        ]);
        $this->addColumn('name', [
            'header' => Mage::helper('internetcode_ajax_catalog')->__('Name'),
            'type'   => 'text',
            'index'  => 'name',
        ]);
        return parent::_prepareColumns();
    }

    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/edit', ['id' => $row->getId()]);
    }
}
```

`_prepareMassaction()` is optional; when present, set `setMassactionIdField()` before adding items or the checkbox column has no value.

## Adminhtml Controller

`controllers/Adminhtml/AjaxCatalogController.php` — class name must match the router module prefix from `config.xml`:

```php
<?php
class InternetCode_AjaxCatalog_Adminhtml_AjaxCatalogController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed('internetcode/internetcode_ajax_catalog/manage');
    }

    public function indexAction(): void
    {
        $this->loadLayout()
            ->_setActiveMenu('internetcode/internetcode_ajax_catalog')
            ->renderLayout();
    }

    public function saveAction(): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        try {
            Mage::getModel('internetcode_ajax_catalog/example_entity')
                ->addData($this->getRequest()->getPost())
                ->save();
            $session->addSuccess(Mage::helper('internetcode_ajax_catalog')->__('Saved.'));
        } catch (Exception $e) {
            $session->addError($e->getMessage());
        }
        $this->_redirect('*/*/index');
    }
}
```

Uncomment the `<admin><routers>` block in `config.xml` to register `InternetCode_AjaxCatalog_Adminhtml` before `Mage_Adminhtml`.

## Session Messages

`Mage::getSingleton('adminhtml/session')` exposes `addSuccess()`, `addError()`, `addWarning()`, `addNotice()` — each takes a translated string.

Always `_redirect()` after adding a message. Rendering in the same request queues it for the following page instead.

## system.xml Fields

The template ships a working `internetcode_ajax_catalog` section with a `general/enabled` yes/no field. Add fields inside `<groups><general><fields>`:

```xml
<mode translate="label comment">
    <label>Mode</label>
    <frontend_type>select</frontend_type>
    <source_model>internetcode_ajax_catalog/system_config_source_mode</source_model>
    <sort_order>20</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</mode>
```

- `source_model` — class with `toOptionArray(): array` returning `['value' => ..., 'label' => ...]` rows.
- `frontend_model` — full block class rendering the whole row; extend `Mage_Adminhtml_Block_System_Config_Form_Field` and implement `_getElementHtml(Varien_Data_Form_Element_Abstract $element)`.
- `backend_model` — transforms the value on save (e.g. `adminhtml/system_config_backend_encrypted`).
- `show_in_store = 0` makes the field website-scope only; a store-view admin sees nothing there.

Read values with `Mage::getStoreConfig('internetcode_ajax_catalog/general/enabled', $storeId)`, or `$this->getConfig('general/enabled')` in tests (`om-phpunit`).

## ACL and Menu

Both live in `etc/adminhtml.xml`, both already stubbed in this template. Uncomment the `<internetcode>` ACL branch AND the `<menu>` block together — one without the other produces the invisible-menu or access-denied symptoms above. Menu `<action>` is `adminhtml/ajax_catalog/index`; the ACL path used by `_isAllowed()` is `internetcode/internetcode_ajax_catalog/manage`.

## Related

- `om-cypress` — testing admin grids and forms without hardcoding secret-key URLs
- `om-eav` — surfacing an EAV attribute in a custom admin form
- `om-ddev` — cache flush after ACL or layout changes
