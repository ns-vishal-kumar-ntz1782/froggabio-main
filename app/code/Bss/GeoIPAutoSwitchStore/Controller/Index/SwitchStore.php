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
 * @copyright  Copyright (c) 2016-2017 BSS Commerce Co. ( http://bsscommerce.com )
 * @license    http://bsscommerce.com/Bss-Commerce-License.txt
 */

namespace Bss\GeoIPAutoSwitchStore\Controller\Index;

use Magento\Framework\App\Action\Context;
use Magento\Store\Model\Store;
use Bss\GeoIPAutoSwitchStore\Cookie\GeoSession;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class SwitchStore extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    public $storeManager;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\Data
     */
    public $moduleHelper;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\Config
     */
    public $geoIpConfig;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    public $resultJsonFactory;

    /**
     * @var \Magento\Directory\Model\CountryFactory
     */
    public $countryFactory;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\GeoIPData
     */
    private $geoIpHelper;

    /**
     * @var null|Store
     */
    protected $storeObject = null;

    /**
     * @var GeoSession
     */
    protected $geoSession;

    /**
     * SwitchStore constructor.
     * @param Context $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Bss\GeoIPAutoSwitchStore\Helper\Data $moduleHelper
     * @param \Bss\GeoIPAutoSwitchStore\Helper\Config $geoIpConfig
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param \Bss\GeoIPAutoSwitchStore\Helper\GeoIPData $geoIpHelper
     */
    public function __construct(
        Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Bss\GeoIPAutoSwitchStore\Helper\Data $moduleHelper,
        \Bss\GeoIPAutoSwitchStore\Helper\Config $geoIpConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Bss\GeoIPAutoSwitchStore\Helper\GeoIPData $geoIpHelper,
        GeoSession $geoSession
    ) {
        $this->storeManager = $storeManager;
        $this->moduleHelper = $moduleHelper;
        $this->geoIpConfig = $geoIpConfig;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->countryFactory = $countryFactory;
        $this->geoIpHelper = $geoIpHelper;
        $this->geoSession = $geoSession;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $redirectScope = $this->geoIpConfig->getRedirectScope();
        $this->getStoreObject();
        $openPopup = $this->geoSession->getSession(
            GeoSession::COOKIE_CUSTOMER_HAS_OPEN_POPUP
        );
        $this->geoSession->setSession(
            GeoSession::COOKIE_CUSTOMER_HAS_OPEN_POPUP,
            1
        );

        if (!$this->getRequest()->isAjax()) {
            return $result;
        }
        $currentUrl = $this->getRequest()->getPost('current_url');
        $currentPath = $this->getRequest()->getPost('current_path');
        $status = false;
        $result->setData([]);

        $countryCode = $this->geoSession->getSession(
            GeoSession::COOKIE_COUNTRY
        );
        if (!$countryCode) {
            $countryCode = $this->moduleHelper->getCountryCodeFromIp();
        }
        $dataCountry = [];
        $sysMessage = $countryCode;
        $assignedStore = null;
        if (!$openPopup) {
            if ($countryCode) {
                try {
                    $countryName = $this->getCountryName($countryCode);
                    $assignedStore = $this->getStoreByCountryCode($countryCode, $redirectScope);

                    if ($assignedStore && $assignedStore->getId()) {
                        $returnData = $this->setData($currentPath, $currentUrl, $assignedStore, $countryName);
                        $status = $returnData['status'];
                        $dataCountry = $returnData['data'];
                    }

                } catch (\Exception $e) {
                    $sysMessage = $e->getMessage();
                }

            }
            if ($assignedStore &&
                $assignedStore->getId() != $this->storeManager->getStore()->getId()) {
                $dataSelectors = $this->getStoresAsArray(
                    $countryCode,
                    $redirectScope,
                    $currentPath,
                    $currentUrl
                );

                $countryName = $this->getCountryName($countryCode);
                $storeInCountry = $this->getStoreByCountryCode($countryCode, $redirectScope);

                $dataResult = [
                    'status' => $status,
                    'message' => $sysMessage,
                    'data' => $dataCountry
                ];
                $dataResult = $this->doMergeDataAfterRender(
                    $dataResult,
                    $dataSelectors,
                    $countryName,
                    $storeInCountry,
                    $currentUrl
                );

                $result->setData($dataResult);
            }
        } else {
            $result->setData([]);
        }
        return $result;
    }

    /**
     * @param $countryCode
     * @param $redirectScope
     * @param $currentPath
     * @param $currentUrl
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStoresAsArray(
        $countryCode,
        $redirectScope,
        $currentPath,
        $currentUrl
    ) {
        $dataSelectors = [];
        $allWebsite = $this->storeManager->getWebsites();
        $allGroups = $this->storeManager->getGroups();
        $allStores = $this->geoIpHelper->getAssignedStoresByScope(
            $this->storeObject,
            $countryCode,
            $redirectScope
        );
        $countryName = $this->getCountryName($countryCode);
        $storeInCountry = $this->getStoreByCountryCode($countryCode, $redirectScope);
        foreach ($allWebsite as $website) {
            $wsCode = $website->getCode();
            $dataSelectors[$wsCode] = [];
            $dataSelectors[$wsCode]['info'] = ['name' => $website->getName()];
            $dataSelectors[$wsCode]['groups'] = [];
            foreach ($allGroups as $group) {
                if ($group->getWebsiteId() != $website->getId()) {
                    continue;
                }
                $gCode = $group->getCode();
                $dataSelectors[$wsCode]['groups'][$gCode]['info'] = ['name' => $group->getName()];
                $dataSelectors[$wsCode]['groups'][$gCode]['stores'] = [];
                foreach ($allStores as $store) {
                    if ($store->getStoreGroupId() != $group->getId()) {
                        continue;
                    }
                    $selected = 0;
                    if ($store->getId() == $this->doGetSelectedStoreCountry($storeInCountry)) {
                        $selected = 1;
                    }
                    $dataSelectors[$wsCode]['groups'][$gCode]['stores'][] = [
                        'store' => $store->getCode(),
                        'name' => $store->getName(),
                        '_active' => $store->getIsActive(),
                        'selected' => $selected,
                        'data' => $store->getIsActive() ? $this->setData(
                            $currentPath,
                            $currentUrl,
                            $store,
                            $countryName
                        ) : []
                    ];
                }
            }
        }
        return $dataSelectors;
    }

    /**
     * Get selected store view
     *
     * @param bool|\Magento\Store\Api\Data\StoreInterface $storeCountry
     * @return int|bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function doGetSelectedStoreCountry($storeCountry)
    {
        if ($storeCountry) {
            if ($storeCountry->getId()) {
                return $storeCountry->getId();
            }
            return $this->storeObject->getId();
        }
        return $storeCountry;
    }

    /**
     * Merge data
     *
     * @param array $dataResult
     * @param array $dataSelectors
     * @param string $countryName
     * @param bool|\Magento\Store\Api\Data\StoreInterface $storeCountry
     * @param string $currentUrl
     * @return array
     */
    protected function doMergeDataAfterRender(
        $dataResult,
        $dataSelectors,
        $countryName,
        $storeCountry,
        $currentUrl
    ) {
        if ($storeCountry) {
            return array_merge_recursive($dataResult, [
                'selectors' => $dataSelectors,
                'dataMessage' => $this->geoIpConfig->getPopupMessage($storeCountry->getId()),
                'dataCountry' => $countryName,
                'dataButton' => $this->geoIpConfig->getPopupButton($storeCountry->getId()),
                'dataStoreName' => $storeCountry->getName(),
                'dataPost' => $this->moduleHelper->getTargetStorePostData($storeCountry, $currentUrl)
            ]);
        }
        return $dataResult;
    }

    /**
     * @param string $currentPath
     * @param string $currentUrl
     * @param Store $assignedStore
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function setData($currentPath, $currentUrl, $assignedStore, $countryName)
    {
        $returnResult['status'] = false;
        $returnResult['data'] = [];
        $assignedStoreId = $assignedStore->getId();
        $assignedStoreBaseUrl = $assignedStore->getBaseUrl();
        $assignedStoreName = $assignedStore->getName();

        //Get Redirects Scope to Redirects
        $redirectScope = $this->geoIpConfig->getRedirectScope();
        if ($redirectScope == 'website') {
            $storeViewIdScope = $this->getStoreIdFromWebsite();
            if (!in_array($assignedStoreId, $storeViewIdScope)) {
                return $returnResult;
            }
        }

        if ($redirectScope == 'store') {
            $storeViewIdScope = $this->getStoreIdFromGroup();
            if (!in_array($assignedStoreId, $storeViewIdScope)) {
                return $returnResult;
            }
        }

        $currentPath = $this->geoIpHelper->getCurrentPath(
            $currentPath,
            $currentUrl,
            $this->storeObject->getId(),
            $assignedStoreId
        );

        $currentUrl = $assignedStoreBaseUrl . $currentPath;

        $baseUrl = $this->moduleHelper->getBaseUrl();
        $dataPost = $this->moduleHelper->getTargetStorePostData($assignedStore, $currentUrl);
        $message = $this->geoIpConfig->getPopupMessage($assignedStoreId);
        $button = $this->geoIpConfig->getPopupButton($assignedStoreId);
        $returnResult['data'] = [
            'base_url' => $baseUrl,
            'data_post' => $dataPost,
            'message' => $message,
            'country_name' => $countryName,
            'button' => $button,
            'store_name' => $assignedStoreName
        ];
        $returnResult['status'] = true;
        return $returnResult;
    }

    /**
     * @param string $countryCode
     * @return string
     */
    protected function getCountryName($countryCode)
    {
        $country = $this->countryFactory->create()->loadByCode($countryCode);
        return $country->getName();
    }

    /**
     * @param string $countryCode
     * @param string $redirectScope
     * @return bool|null|\Magento\Store\Api\Data\StoreInterface
     */
    protected function getStoreByCountryCode($countryCode, $redirectScope)
    {
        return $this->geoIpHelper->getStoreByCountryCode(
            $this->storeObject,
            $countryCode,
            $redirectScope
        );
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStoreIdFromGroup()
    {
        return $this->getListingStoreFromScope('group', 'ids');
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStoreIdFromWebsite()
    {
        return $this->getListingStoreFromScope('website', 'ids');
    }

    /**
     * @return \Magento\Store\Api\Data\StoreInterface|Store|null
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStoreObject()
    {
        if (!$this->storeObject) {
            $this->storeObject = $this->storeManager->getStore();
        }
        return $this->storeObject;
    }

    /**
     * @param string $scope
     * @param string $key
     * @return array
     */
    private function getListingStoreFromScope($scope = 'website', $key = 'obj')
    {
        return $this->geoIpHelper->getListingStoreFromScope(
            $this->storeObject,
            $scope,
            $key
        );
    }
}
