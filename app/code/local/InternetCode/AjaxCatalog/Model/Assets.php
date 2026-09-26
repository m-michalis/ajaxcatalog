<?php

/**
 * Resolves webpack build output in {base_dir}/assets.
 *
 * Uses assets/manifest.json when present (e.g. webpack-manifest-plugin: {"home.js": "home.[hash].js"}),
 * otherwise falls back to scanning the directory and parsing the hashed file names
 * ({entry}.[hash].{ext}), newest file first so stale builds lose.
 */
class InternetCode_AjaxCatalog_Model_Assets
{
    const ASSET_DIR = 'assets';
    const MANIFEST_FILE = 'manifest.json';
    const MEDIA_DIR = 'media';

    /**
     * @var string|null
     */
    private $_assetDir;

    /**
     * @var array<string, string>|false|null manifest key => file, false when missing or invalid
     */
    private $_manifest;

    /**
     * @var array<int, array{key: string, file: string}>|null
     */
    private $_files;

    /**
     * @var array<string, array>
     */
    private $_filesByRoute = [];

    /**
     * @var array<string, string[]>
     */
    private $_directoryListings = [];

    /**
     * @param string|null $dir null to use the default {base_dir}/assets
     * @return $this
     */
    public function setAssetDir($dir)
    {
        $this->_assetDir = $dir;
        $this->_manifest = null;
        $this->_files = null;
        $this->_filesByRoute = [];
        $this->_directoryListings = [];

        return $this;
    }

    /**
     * @return string
     */
    public function getAssetDir()
    {
        return $this->_assetDir ?? Mage::getBaseDir() . DS . self::ASSET_DIR;
    }

    /**
     * Public URL of a build file (as found in the manifest or the asset directory).
     *
     * @param string $file
     * @return string
     */
    public function getUrl($file)
    {
        if (preg_match('#^(https?:)?//#i', $file)) {
            return $file;
        }

        return Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . self::ASSET_DIR . '/' . ltrim($file, '/');
    }

    /**
     * @return array<string, string>|null manifest key => file relative to the asset directory
     */
    public function getManifest()
    {
        if ($this->_manifest === null) {
            $this->_manifest = $this->_loadManifest();
        }

        return $this->_manifest ?: null;
    }

    /**
     * Asset files grouped by layout handle and asset type.
     *
     * @param bool $inclCritical
     * @return array<string, array<string, string[]>>
     */
    public function getFilesByRoute($inclCritical = true)
    {
        $cacheKey = $inclCritical ? 'critical' : 'plain';
        if (!isset($this->_filesByRoute[$cacheKey])) {
            $this->_filesByRoute[$cacheKey] = $this->_buildFilesByRoute($this->_getFiles(), $inclCritical);
        }

        return $this->_filesByRoute[$cacheKey];
    }

    /**
     * Hashed URL of an image copied by webpack's CopyPlugin ("./media/**" => "[path][name].[contenthash][ext]").
     *
     * @param string $imagePath path relative to assets/media
     * @return string
     */
    public function getImageUrl($imagePath)
    {
        $relativePath = self::MEDIA_DIR . '/' . ltrim($imagePath, '/');
        if (strpos($relativePath, '..') !== false) {
            return $this->_getMissingImageUrl($relativePath);
        }

        $manifest = $this->getManifest();
        if ($manifest !== null && isset($manifest[$relativePath])) {
            return $this->getUrl($manifest[$relativePath]);
        }

        $requested = pathinfo($relativePath);
        $requestedExtension = $requested['extension'] ?? '';
        foreach ($this->_listDirectory($requested['dirname']) as $file) {
            $info = pathinfo($file);
            $name = explode('.', $info['filename'])[0];
            if ($name === $requested['filename'] && ($info['extension'] ?? '') === $requestedExtension) {
                return $this->getUrl($requested['dirname'] . '/' . $file);
            }
        }

        return $this->_getMissingImageUrl($relativePath);
    }

    /**
     * @param string $relativePath
     * @return string
     */
    private function _getMissingImageUrl($relativePath)
    {
        return sprintf(
            'https://dummyimage.com/1200x1200/FF0000/ffffff.png?text=MISSING%%20IMAGE:%s',
            Mage::helper('core')->urlEncode(self::ASSET_DIR . '/' . $relativePath)
        );
    }

    /**
     * @return array<string, string>|false
     */
    private function _loadManifest()
    {
        $path = $this->getAssetDir() . DS . self::MANIFEST_FILE;
        if (!is_file($path)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest)) {
            Mage::log(sprintf('Invalid webpack manifest %s, falling back to directory listing', $path), Zend_Log::WARN);
            return false;
        }

        $files = [];
        foreach ($manifest as $key => $file) {
            if (is_string($key) && is_string($file) && $file !== '') {
                $files[ltrim($key, '/')] = $this->_normalizeManifestFile($file);
            }
        }

        return $files;
    }

    /**
     * Strip the publicPath prefix so values are relative to the asset directory.
     *
     * @param string $file
     * @return string
     */
    private function _normalizeManifestFile($file)
    {
        if (preg_match('#^(https?:)?//#i', $file)) {
            return $file;
        }
        $file = ltrim($file, '/');
        $prefix = self::ASSET_DIR . '/';
        if (strpos($file, $prefix) === 0) {
            $file = substr($file, strlen($prefix));
        }

        return $file;
    }

    /**
     * @return array<int, array{key: string, file: string}> key is the logical ({entry}.{ext}) name
     */
    private function _getFiles()
    {
        if ($this->_files !== null) {
            return $this->_files;
        }

        $this->_files = [];
        $manifest = $this->getManifest();
        if ($manifest !== null) {
            foreach ($manifest as $key => $file) {
                $this->_files[] = ['key' => basename($key), 'file' => $file];
            }
        }

        foreach ($this->_listDirectory('') as $file) {
            // critical css is generated after the webpack build, so the manifest may not list it
            if ($manifest === null || $this->_isCriticalFile($file)) {
                $this->_files[] = ['key' => $file, 'file' => $file];
            }
        }

        return $this->_files;
    }

    /**
     * @param string $file
     * @return bool
     */
    private function _isCriticalFile($file)
    {
        return strpos($file, 'critical.') === 0 || strpos($file, 'uncritical.') === 0;
    }

    /**
     * Files (not directories) in a sub directory of the asset dir, newest first.
     *
     * @param string $subDir
     * @return string[]
     */
    private function _listDirectory($subDir)
    {
        $subDir = trim($subDir, '/.');
        if (isset($this->_directoryListings[$subDir])) {
            return $this->_directoryListings[$subDir];
        }

        $dir = rtrim($this->getAssetDir() . DS . $subDir, DS);
        $mtimes = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $entry) {
                $path = $dir . DS . $entry;
                if ($entry[0] !== '.' && is_file($path)) {
                    $mtimes[$entry] = (int) filemtime($path);
                }
            }
        }
        // newest first; equal mtimes keep a stable, name-based order
        ksort($mtimes);
        uksort($mtimes, static function ($a, $b) use ($mtimes) {
            return ($mtimes[$b] <=> $mtimes[$a]) ?: strcmp($a, $b);
        });

        return $this->_directoryListings[$subDir] = array_keys($mtimes);
    }

    /**
     * @param array<int, array{key: string, file: string}> $files
     * @param bool $inclCritical
     * @return array<string, array<string, string[]>>
     */
    private function _buildFilesByRoute(array $files, $inclCritical)
    {
        $entries = InternetCode_AjaxCatalog_Helper_Data::getEntries();
        $handleFiles = [];

        if ($inclCritical) {
            $this->_addCriticalFiles($handleFiles, $files, $entries);
        }
        $this->_addEntryFiles($handleFiles, $files, $entries);
        $this->_addSharedFiles($handleFiles, $files);

        // with critical css, the regular css is loaded asynchronously as "uncritical";
        // an explicit uncritical file replaces it (and must include shared css, as the critical endpoint's css list does)
        foreach ($handleFiles as $handle => $filesByType) {
            if (!isset($filesByType[InternetCode_AjaxCatalog_Block_Webpack::ASSET_CRITICAL])) {
                continue;
            }
            if (empty($filesByType[InternetCode_AjaxCatalog_Block_Webpack::ASSET_UNCRITICAL])) {
                $handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_UNCRITICAL]
                    = $filesByType[InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS] ?? [];
            }
            unset($handleFiles[$handle][InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS]);
        }

        return $handleFiles;
    }

    /**
     * critical.{entry}.[hash].css / uncritical.{entry}.[hash].css — one file per entry and type (the first listed)
     */
    private function _addCriticalFiles(array &$handleFiles, array $files, array $entries)
    {
        foreach ($files as $file) {
            $parts = explode('.', $file['key']);
            if (!isset($parts[1]) || $this->_getExtension($file['key']) !== 'css') {
                continue;
            }
            switch ($parts[0]) {
                case 'critical':
                    $assetType = InternetCode_AjaxCatalog_Block_Webpack::ASSET_CRITICAL;
                    break;
                case 'uncritical':
                    $assetType = InternetCode_AjaxCatalog_Block_Webpack::ASSET_UNCRITICAL;
                    break;
                default:
                    continue 2;
            }
            foreach ($entries[$parts[1]] ?? [] as $handle) {
                if (!isset($handleFiles[$handle][$assetType])) {
                    $handleFiles[$handle][$assetType][] = $file['file'];
                }
            }
        }
    }

    /**
     * {entry}.[hash].js / {entry}.[hash].css — one file per entry and type (the newest one)
     */
    private function _addEntryFiles(array &$handleFiles, array $files, array $entries)
    {
        foreach ($files as $file) {
            $assetType = $this->_getAssetType($file['key']);
            if ($assetType === null) {
                continue;
            }
            $entry = explode('.', $file['key'])[0];
            foreach ($entries[$entry] ?? [] as $handle) {
                if (!isset($handleFiles[$handle][$assetType])) {
                    $handleFiles[$handle][$assetType][] = $file['file'];
                }
            }
        }
    }

    /**
     * shared.[hash].js / shared.[hash].css are added to every handle
     */
    private function _addSharedFiles(array &$handleFiles, array $files)
    {
        $shared = [];
        foreach ($files as $file) {
            $assetType = $this->_getAssetType($file['key']);
            if ($assetType !== null && explode('.', $file['key'])[0] === 'shared' && !isset($shared[$assetType])) {
                $shared[$assetType] = $file['file'];
            }
        }

        foreach ($handleFiles as $handle => $filesByType) {
            foreach ($shared as $assetType => $sharedFile) {
                if (!in_array($sharedFile, $filesByType[$assetType] ?? [], true)) {
                    $handleFiles[$handle][$assetType][] = $sharedFile;
                }
            }
        }
    }

    /**
     * @param string $name
     * @return string|null
     */
    private function _getAssetType($name)
    {
        switch ($this->_getExtension($name)) {
            case 'css':
                return InternetCode_AjaxCatalog_Block_Webpack::ASSET_CSS;
            case 'js':
                return InternetCode_AjaxCatalog_Block_Webpack::ASSET_JS;
            default:
                return null;
        }
    }

    /**
     * @param string $name
     * @return string
     */
    private function _getExtension($name)
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
