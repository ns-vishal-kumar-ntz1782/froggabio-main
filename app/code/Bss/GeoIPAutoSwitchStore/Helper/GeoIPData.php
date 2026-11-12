<?php
/**
 * BSS Commerce Co.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://bsscommerce.com/Bss-Commerce-License.txt
 *
 * @category   BSS
 * @package    Bss_GeoIPAutoSwitchStore
 * @author     Extension Team
 * @copyright  Copyright (c) 2016-2022 BSS Commerce Co. ( http://bsscommerce.com )
 * @license    http://bsscommerce.com/Bss-Commerce-License.txt
 */
namespace Bss\GeoIPAutoSwitchStore\Helper;

use Magento\Store\Api\Data\StoreInterface;

class GeoIPData
{
    /**
     * @var Config
     */
    public $geoIpConfig;

    /**
     * @var \Magento\Store\Model\StoreFactory
     */
    private $storeFactory;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Model\UrlRewriteFactory
     */
    protected $urlRewriteFactory;

    /**
     * GeoIPData constructor.
     * @param Config $geoIpConfig
     * @param \Magento\Store\Model\StoreFactory $storeFactory
     * @param \Bss\GeoIPAutoSwitchStore\Model\UrlRewriteFactory $urlRewriteFactory
     */
    public function __construct(
        \Bss\GeoIPAutoSwitchStore\Helper\Config $geoIpConfig,
        \Magento\Store\Model\StoreFactory $storeFactory,
        \Bss\GeoIPAutoSwitchStore\Model\UrlRewriteFactory $urlRewriteFactory
    ) {
        $this->geoIpConfig = $geoIpConfig;
        $this->storeFactory = $storeFactory;
        $this->urlRewriteFactory = $urlRewriteFactory;
    }

    /**
     * @param string $countryCode
     * @return String | bool
     */
    public function getCurrencyByCountryCode($countryCode)
    {
        $codes = $this->geoIpConfig->getCurrencyCodes();
        $additionalData = json_decode($codes, true);
        $additionalData = array_values($additionalData);

        foreach ($additionalData as $item) {
            if ($item['country_code'] == $countryCode) {
                return $item['currency_code'];
            }
        }
        return false;
    }

    /**
     * @param string $currentPath
     * @param string $url
     * @param null $currentStore
     * @param null $targetStore
     * @return string
     */
    public function getCurrentPath($currentPath, $url, $currentStore = null, $targetStore = null)
    {
        $currentPathAfter = $currentPath;
        $originalPath = $currentPath !== null ? trim($currentPath, "/") : '';

        if ($currentStore && $targetStore && $originalPath) {
            $currentPathAfter = $this->getUrlObjectFromPath($originalPath, $currentStore, $targetStore);
        }

        $currentPath = $currentPath !== null ? ltrim($currentPath, '/') : '';
        $currentPathAfter = $currentPathAfter !== null ? ltrim($currentPathAfter, '/') : '';
        $url = $url !== null ? $url : '';

        if ($currentPath !== '') {
            $paramPath = strstr($url, "?");
            if ($paramPath) {
                $currentPath = $currentPathAfter . $paramPath;
            } else {
                $currentPath = $currentPathAfter;
            }
        } else {
            $currentPath = strstr($url, '?');
        }
        return $currentPath;
    }

    /**
     * @param string $requestPath
     * @param string  $requestStoreId
     * @param string $targetStoreId
     * @return mixed
     */
    public function getUrlObjectFromPath($requestPath, $requestStoreId, $targetStoreId)
    {
        $collection = $this->urlRewriteFactory->create()
            ->getCollection()
            ->addFieldToFilter('request_path', $requestPath)
            ->addFieldToFilter('store_id', $requestStoreId);

        if ($collection->getData()) {
            $collectionData = $collection->getData()[0];
            $targetPath = $collectionData['target_path'];
            $targetCollection = $this->urlRewriteFactory->create()
                ->getCollection()
                ->addFieldToFilter('target_path', $targetPath)
                ->addFieldToFilter('store_id', $targetStoreId);

            if ($targetCollection->getData()) {
                $targetCollectionData = $targetCollection->getData()[0];
                return $targetCollectionData['request_path'];
            }
        }
        return $requestPath;
    }

    /**
     * @param StoreInterface $storeObject
     * @param string $scope
     * @param string $key
     * @return array
     */
    public function getListingStoreFromScope(
        $storeObject,
        $scope = 'website',
        $key = 'obj'
    ) {
        $scopeFilterId = null;
        $scopeFilter = "";
        if ($scope == 'store') {
            $scopeFilter = 'group_id';
            $scopeFilterId = $storeObject->getGroupId();
        } elseif ($scope == 'website') {
            $scopeFilter = 'website_id';
            $scopeFilterId = $storeObject->getWebsiteId();
        }
        $stores = $this->storeFactory
            ->create()
            ->getCollection();
        if ($scopeFilterId) {
            $stores->addFieldToFilter($scopeFilter, $scopeFilterId);
        }

        // The below code ensure that priority of smaller store order > greater store order
        $stores->setOrder('sort_order', 'ASC');
        $stores->setOrder('store_id', 'ASC');

        $listingStoreView = [];
        $listingStoreViewIds = [];

        foreach ($stores as $myStore) {
            $listingStoreView[] = $myStore;
            $listingStoreViewIds[] = $myStore->getId();
        }

        if ($key == 'obj') {
            // Return object with ids are keys
            return $listingStoreView;
        }
        // Return ids only
        return $listingStoreViewIds;
    }

    /**
     * @param StoreInterface $currentStoreObject
     * @param string $countryCode
     * @param string $redirectScope
     * @return bool|null|\Magento\Store\Api\Data\StoreInterface
     */
    public function getStoreByCountryCode(
        $currentStoreObject,
        $countryCode,
        $redirectScope
    ) {
        // Get specification store which was assign to $countryCode
        // This resolve issue if admin try config scope redirect = 'website'
        // Customer visit on 2nd website, but 1st website has a store that config to current country
        $assignedStores = $this->getAssignedStoresByScope(
            $currentStoreObject,
            $countryCode,
            $redirectScope
        );

        $customerVisitStoreId = $currentStoreObject->getId();
        if (is_array($assignedStores) &&
            !empty($assignedStores)) {
            if (array_key_exists($customerVisitStoreId, $assignedStores)) {
                return $assignedStores[$customerVisitStoreId];
            }
            return $assignedStores[array_key_first($assignedStores)];
        }
        return false;
    }

    /**
     * @param $currentStoreObject
     * @param $countryCode
     * @param $redirectScope
     * @return array
     */
    public function getAssignedStoresByScope(
        $currentStoreObject,
        $countryCode,
        $redirectScope
    ) {
        // Get specification store which was assign to $countryCode
        // This resolve issue if admin try config scope redirect = 'website'
        // Customer visit on 2nd website, but 1st website has a store that config to current country
        $stores = $this->getListingStoreFromScope(
            $currentStoreObject,
            $redirectScope,
            'obj'
        );
        $assignedStores = [];

        foreach ($stores as $store) {
            if (!$store->isActive()) {
                continue;
            }

            $countryStore = $this->geoIpConfig->getCountries($store->getId());
            if ($countryStore !== null && strpos($countryStore, $countryCode) !== false) {
                $assignedStores[$store->getId()] = $store;
            }
        }
        return $assignedStores;
    }
}
