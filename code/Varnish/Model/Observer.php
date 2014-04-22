<?php

class Magneto_Varnish_Model_Observer
{
    /**
     * Adapter instance
     *
     * @var Varien_Db_Adapter_Interface
     */
    protected $_connection;

    /**
     * Resource instance
     *
     * @var Mage_Core_Model_Resource
     */
    protected $_resource;

    /**
     * Default store id
     *
     * @var int
     */
    protected $_defaultStoreId;

    public function __construct()
    {
        $this->_resource       = Mage::getSingleton('core/resource');
        $this->_connection     = $this->_resource->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);
        $this->_defaultStoreId = Mage::app()->getDefaultStoreView()->getId();
    }

    /**
     * This method is called when http_response_send_before event is triggered to identify
     * if current page can be cached and set correct cookies for varnish.
     *
     * @return bool
     */
    public function varnish()
    {
        /* @var $helper Magneto_Varnish_Helper_Cacheable */
        $helper = Mage::helper('varnish/cacheable');

        // Cache disabled in Admin / System / Cache Management
        if (!Mage::app()->useCache('varnish')) {
            $helper->turnOffVarnishCache();
            return false;
        }

        if ($helper->isNoCacheStable()) {
            return false;
        }

        if ($helper->pollVerification()) {
            $helper->setNoCacheStable();
            return false;
        }


        if ($helper->quoteHasItems() || $helper->isCustomerLoggedIn() || $helper->hasCompareItems()) {
            $helper->turnOffVarnishCache();

            return false;
        }

        $helper->turnOnVarnishCache();

        return true;
    }

    /**
     * @see Mage_Core_Model_Cache
     *
     * @param Mage_Core_Model_Observer $observer
     * @return Magneto_Varnish_Model_Observer
     */
    public function onCategorySave($observer)
    {
        $category = $observer->getCategory();
        /* @var $category Mage_Catalog_Model_Category */
        if ($category->getData('include_in_menu')) {
            // notify user that varnish needs to be refreshed
            Mage::app()->getCacheInstance()->invalidateType(array('varnish'));
        }

        return $this;
    }

    /**
     * Listens to application_clean_cache event and gets notified when a product/category/cms
     * model is saved.
     *
     * @param $observer Mage_Core_Model_Observer
     */
    public function purgeCache($observer)
    {
        // If Varnish is not enabled on admin don't do anything
        if (!Mage::app()->useCache('varnish')) {
            return;
        }

        $tags = $observer->getTags();
        $urls = array();

        if ($tags == array()) {
            $errors = Mage::helper('varnish')->purgeAll();
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError("Varnish Purge failed");
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess("The Varnish cache storage has been flushed.");
            }
            return;
        }

        // compute the urls for affected entities
        foreach ((array) $tags as $tag) {
            //catalog_product_100 or catalog_category_186
            $tagFields = explode('_', $tag);
            if (count($tagFields) == 3) {
                if ($tagFields[1] == 'product') {
                    // get urls for product
                    /** @var Mage_Catalog_Model_Product $product */
                    $product = Mage::getModel('catalog/product')->load($tagFields[2]);
                    $urls    = array_merge($urls, $this->_getUrlsForProduct($product));
                } elseif ($tagFields[1] == 'category') {
                    /** @var Mage_Catalog_Model_Category $category */
                    $category      = Mage::getModel('catalog/category')->load($tagFields[2]);
                    $category_urls = $this->_getUrlsForCategory($category);
                    $urls          = array_merge($urls, $category_urls);
                } elseif ($tagFields[1] == 'page') {
                    $urls = $this->_getUrlsForCmsPage($tagFields[2]);
                }
            }
        }

        // Transform urls to relative urls
        $relativeUrls = array();
        foreach (array_unique($urls) as $url) {
            $relativeUrls[] = parse_url($url, PHP_URL_PATH);
        }

        if (!empty($relativeUrls)) {
            $errors = Mage::helper('varnish')->purge($relativeUrls);
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError(
                    "Some Varnish purges failed: <br/>" . implode("<br/>", $errors)
                );
            } else {
                $count = count($relativeUrls);
                if ($count > 5) {
                    $relativeUrls   = array_slice($relativeUrls, 0, 5);
                    $relativeUrls[] = '...';
                    $relativeUrls[] = "(Total number of purged urls: $count)";
                }
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    "Purges have been submitted successfully:<br/>" . implode("<br />", $relativeUrls)
                );
            }
        }
    }

    /**
     * Returns all the urls related to product
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _getUrlsForProduct(Mage_Catalog_Model_Product $product)
    {
        $urls   = array();
        $urls[] = Mage::getUrl('catalog/product/view',
            array(
                'id'     => $product->getId(),
                's'      => $product->getUrlKey(),
                '_store' => $product->getStoreId() ? : $this->_defaultStoreId
            )
        );

        // collect all rewrites
        $coreUrlRewrites = $this->_getProductCoreUrlRewrites($product);

        /** @var Magneto_Varnish_Helper_Data $helper */
        $helper                 = Mage::helper('varnish');
        $enterpriseUrlRewrites  = array();
        $enterpriseUrlRedirects = array();
        if ($helper->isModuleEnabled('Enterprise_Catalog')) {
            $enterpriseUrlRewrites  = $this->_getProductEnterpriseUrlRewrites($product);
            $enterpriseUrlRedirects = $this->_getProductEnterpriseUrlRedirects($product);
        }

        $rewrites = array_merge($coreUrlRewrites, $enterpriseUrlRewrites, $enterpriseUrlRedirects);
        foreach ($rewrites as $r) {
            $urls[] = Mage::getUrl('',
                array(
                    '_direct' => $r['request_path'],
                    '_store'  => $r['store_id'] ? : $this->_defaultStoreId
                )
            );
            $urls[] = Mage::getUrl('',
                array(
                    '_direct' => $r['target_path'],
                    '_store'  => $r['store_id'] ? : $this->_defaultStoreId
                )
            );
        }

        return $urls;
    }

    /**
     * Returns all the urls pointing to the category
     *
     * @param Mage_Catalog_Model_Category $category
     * @return array
     */
    protected function _getUrlsForCategory(Mage_Catalog_Model_Category $category)
    {
        $urls   = array();
        $urls[] = Mage::getUrl('catalog/category/view',
            array(
                'id'     => $category->getId(),
                's'      => $category->getUrlKey(),
                '_store' => $category->getStoreId() ? : $this->_defaultStoreId
            )
        );

                // collect all rewrites
        $coreUrlRewrites = $this->_getCategoryCoreUrlRewrites($category);

        /** @var Magneto_Varnish_Helper_Data $helper */
        $helper                 = Mage::helper('varnish');
        $enterpriseUrlRewrites  = array();
        $enterpriseUrlRedirects = array();
        if ($helper->isModuleEnabled('Enterprise_Catalog')) {
            $enterpriseUrlRewrites  = $this->_getCategoryEnterpriseUrlRewrites($category);
            $enterpriseUrlRedirects = $this->_getCategoryEnterpriseUrlRedirects($category);
        }

        $rewrites = array_merge($coreUrlRewrites, $enterpriseUrlRewrites, $enterpriseUrlRedirects);
        foreach ($rewrites as $r) {
            $urls[] = Mage::getUrl('',
                array(
                    '_direct' => $r['request_path'],
                    '_store'  => $r['store_id'] ? : $this->_defaultStoreId
                )
            );
            $urls[] = Mage::getUrl('',
                array(
                    '_direct' => $r['target_path'],
                    '_store'  => $r['store_id'] ? : $this->_defaultStoreId
                )
            );
        }

        return $urls;
    }

    /**
     * Returns all urls related to this cms page
     *
     * @param string $cmsPageId
     * @return array
     */
    protected function _getUrlsForCmsPage($cmsPageId)
    {
        $urls = array();
        $page = Mage::getModel('cms/page')->load($cmsPageId);
        if ($page->getId()) {
            $urls[] = '/' . $page->getIdentifier();
        }

        return $urls;
    }

    /**
     * Workaround for bug in magento ee 1.13
     *
     * @link https://github.com/tim-bezhashvyly/Sandfox_SitemapFix/wiki
     * @param array $row
     * @return array
     */
    protected function _fixRequestPathSuffix(array $row)
    {
        if (isset($row['store_id']) && isset($row['request_path'])) {
            $storeId = $row['store_id'] ? : $this->_defaultStoreId;
            $suffix  = Mage::getStoreConfig(Mage_Catalog_Helper_Product::XML_PATH_PRODUCT_URL_SUFFIX, $storeId);

            if ($suffix && substr($row['request_path'], -mb_strlen($suffix)) != $suffix) {
                $row['request_path'] .= '.' . ltrim($suffix, '.');
            }
        }

        return $row;
    }

    /**
     * Get product url rewrites from core_url_rewrite table
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _getProductCoreUrlRewrites(Mage_Catalog_Model_Product $product)
    {
        $select = $this->_connection->select()
            ->from($this->_resource->getTableName('core/url_rewrite'))
            ->where('product_id = ?', $product->getId());

        $rewrites = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $rewrites[] = $row;
        }

        return $rewrites;
    }

    /**
     * Get url redirects from enterprise_url_rewrite_redirect table
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _getProductEnterpriseUrlRedirects(Mage_Catalog_Model_Product $product)
    {
        $select = $this->_connection->select()
            ->from(array('e' => $this->_resource->getTableName('enterprise_urlrewrite/redirect')),
                array('request_path' => 'identifier', 'target_path', 'store_id')
            )
            ->where('e.product_id = ?', $product->getId());

        $redirects = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $redirects[] = $row;
        }

        return $redirects;
    }

    /**
     * Get product url rewrites from enterprise_url_rewrite table
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _getProductEnterpriseUrlRewrites(Mage_Catalog_Model_Product $product)
    {
        $requestPath = $this->_connection->getIfNullSql('url_rewrite.request_path', 'default_ur.request_path');
        $targetPath  = $this->_connection->getIfNullSql('url_rewrite.target_path', 'default_ur.target_path');

        $select = $this->_connection->select()
            ->from(array('e' => $this->_resource->getTableName('catalog/product')),
                array('product_id' => 'entity_id')
            )
            ->where('e.entity_id = ?', $product->getId())
            ->joinLeft(array('url_rewrite_product' => $this->_resource->getTableName('enterprise_catalog/product')),
                'url_rewrite_product.product_id = e.entity_id',
                array(''))
            ->joinLeft(array('url_rewrite' => $this->_resource->getTableName('enterprise_urlrewrite/url_rewrite')),
                'url_rewrite_product.url_rewrite_id = url_rewrite.url_rewrite_id AND url_rewrite.is_system = 1',
                array(''))
            ->joinLeft(array('default_urp' => $this->_resource->getTableName('enterprise_catalog/product')),
                'default_urp.product_id = e.entity_id AND default_urp.store_id = 0',
                array(''))
            ->joinLeft(array('default_ur' => $this->_resource->getTableName('enterprise_urlrewrite/url_rewrite')),
                'default_ur.url_rewrite_id = default_urp.url_rewrite_id',
                array('request_path' => $requestPath, 'target_path' => $targetPath, 'store_id')
            );

        $rewrites = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $rewrites[] = $this->_fixRequestPathSuffix($row);
        }

        return $rewrites;
    }

    /**
     * Get product category url rewrites from core_url_rewrite table
     *
     * @param Mage_Catalog_Model_Category $category
     * @return array
     */
    protected function _getCategoryCoreUrlRewrites(Mage_Catalog_Model_Category $category)
    {
        $select = $this->_connection->select()
            ->from($this->_resource->getTableName('core/url_rewrite'))
            ->where('category_id = ?', $category->getId());

        $rewrites = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $rewrites[] = $row;
        }

        return $rewrites;
    }

    /**
     * Get category url redirects from enterprise_url_rewrite_redirect table
     *
     * @param Mage_Catalog_Model_Category $category
     * @return array
     */
    protected function _getCategoryEnterpriseUrlRedirects(Mage_Catalog_Model_Category $category)
    {
        $select = $this->_connection->select()
            ->from(array('e' => $this->_resource->getTableName('enterprise_urlrewrite/redirect')),
                array('request_path' => 'identifier', 'target_path', 'store_id')
            )
            ->where('e.category_id = ?', $category->getId());

        $redirects = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $redirects[] = $row;
        }

        return $redirects;
    }

    /**
     * Get category url rewrites from enterprise_url_rewrite table
     *
     * @param Mage_Catalog_Model_Category $category
     * @return array
     */
    protected function _getCategoryEnterpriseUrlRewrites(Mage_Catalog_Model_Category $category)
    {
        $requestPath = $this->_connection->getIfNullSql('url_rewrite.request_path', 'default_ur.request_path');
        $targetPath  = $this->_connection->getIfNullSql('url_rewrite.target_path', 'default_ur.target_path');

        $select = $this->_connection->select()
            ->from(array('e' => $this->_resource->getTableName('catalog/category')),
                array('category_id' => 'entity_id')
            )
            ->where('e.entity_id = ?', $category->getId())
            ->joinLeft(array('url_rewrite_category' => $this->_resource->getTableName('enterprise_catalog/category')),
                'url_rewrite_category.category_id = e.entity_id',
                array(''))
            ->joinLeft(array('url_rewrite' => $this->_resource->getTableName('enterprise_urlrewrite/url_rewrite')),
                'url_rewrite_category.url_rewrite_id = url_rewrite.url_rewrite_id AND url_rewrite.is_system = 1',
                array(''))
            ->joinLeft(array('default_urp' => $this->_resource->getTableName('enterprise_catalog/category')),
                'default_urp.category_id = e.entity_id AND default_urp.store_id = 0',
                array(''))
            ->joinLeft(array('default_ur' => $this->_resource->getTableName('enterprise_urlrewrite/url_rewrite')),
                'default_ur.url_rewrite_id = default_urp.url_rewrite_id',
                array('request_path' => $requestPath, 'target_path' => $targetPath, 'store_id')
            );

        $rewrites = array();
        foreach ($this->_connection->fetchAll($select) as $row) {
            $rewrites[] = $this->_fixRequestPathSuffix($row);
        }

        return $rewrites;
    }
}
