<?php

class InternetCode_AjaxCatalog_Block_Webpack extends Mage_Core_Block_Abstract
{
    public const ASSET_UNCRITICAL = 'uncritical_css';

    public const ASSET_CRITICAL = 'critical_css';

    public const ASSET_CSS = 'css';

    public const ASSET_JS = 'js';

    /**
     * @var array<string, array<string, string[]>>
     */
    private $_filesByRoute;

    /**
     * @var string[]
     */
    private $_handles;


    protected function _prepareLayout()
    {
        $this->_filesByRoute = $this->helper('ajaxcatalog')->getWebpackFilesByRoute();
        $this->_handles = Mage::app()->getLayout()->getUpdate()->getHandles();
        return parent::_prepareLayout();
    }


    protected function _toHtml()
    {
        $html = '';

        $validRoutes = array_intersect($this->_handles, array_keys($this->_filesByRoute));

        // if route is found, keep only the route (it contains shared.js and shared.css)
        // otherwise default contains shared.js and shared.css only for generic pages
        $hasMultipleRoutes = count($validRoutes) > 1;
        foreach ($validRoutes as $k => $route) {
            if ($hasMultipleRoutes && $route === 'default') {
                unset($validRoutes[$k]);
            }
        }

        $filesToLoad = [];
        foreach ($validRoutes as $route) {
            $filesToLoad = array_unique(array_merge($this->_filesByRoute[$route][$this->getAssetType()] ?? [], $filesToLoad));
        }

        /** @var InternetCode_AjaxCatalog_Model_Assets $assets */
        $assets = Mage::getSingleton('ajaxcatalog/assets');
        foreach ($filesToLoad as $file) {
            $src = $this->escapeHtml($assets->getUrl($file));

            match ($this->getAssetType()) {
                self::ASSET_JS => $html .= sprintf('<script src="%s" defer="defer"></script>', $src),
                self::ASSET_UNCRITICAL => $html .= sprintf('<link rel="preload" href="%s" as="style" onload="this.rel=\'stylesheet\'">', $src),
                self::ASSET_CSS, self::ASSET_CRITICAL => $html .= sprintf('<link rel="stylesheet" type="text/css" href="%s" media="all">', $src),
                default => $html .= '',
            };
        }

        return $html;
    }
}
