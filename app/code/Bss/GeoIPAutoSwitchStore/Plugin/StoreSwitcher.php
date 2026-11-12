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
namespace Bss\GeoIPAutoSwitchStore\Plugin;

use Magento\Store\Model\Store as StoreModel;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreSwitcherInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Url\EncoderInterface;

class StoreSwitcher
{
    /**
     * @var StoreModel
     */
    protected $storeModel;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var StoreSwitcherInterface
     */
    protected $storeSwitcher;

    /**
     * @var EncoderInterface
     */
    protected $encoder;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * StoreSwitcher constructor.
     * @param StoreModel $storeModel
     * @param UrlInterface $urlBuilder
     * @param StoreSwitcherInterface $storeSwitcher
     * @param EncoderInterface $encoder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        StoreModel $storeModel,
        UrlInterface $urlBuilder,
        StoreSwitcherInterface $storeSwitcher,
        EncoderInterface $encoder,
        StoreManagerInterface $storeManager
    ) {
        $this->storeModel = $storeModel;
        $this->urlBuilder = $urlBuilder;
        $this->storeSwitcher = $storeSwitcher;
        $this->encoder = $encoder;
        $this->storeManager = $storeManager;
    }

    /**
     * @param \Magento\Store\Api\Data\StoreInterface $fromStore
     * @param \Magento\Store\Api\Data\StoreInterface $toStore
     * @param \Magento\Framework\App\RequestInterface $request
     * @return string
     * @throws \Magento\Store\Model\StoreSwitcher\CannotSwitchStoreException
     */
    public function getUrlRedirect($fromStore, $toStore, $request)
    {
        // Get redirect url
        // Redirect to origin path instead of base store view url
        // Redirect if Use Store Code in Url = Yes/No
        $currentPath = ltrim($request->getOriginalPathInfo(), '/');
        $defaultStore = $this->storeManager->getDefaultStoreView();

        if ($toStore->getCode() === $defaultStore->getCode()) {
            $toUrl = $toStore->getBaseUrl();
            if ($currentPath && strlen($currentPath) && $currentPath !== '/') {
                $toUrl = trim($toStore->getBaseUrl(), '/') . '/' . $currentPath;
            }
            return $this->storeSwitcher->switch(
                $fromStore,
                $toStore,
                $toUrl
            );
        }

        $this->urlBuilder->setScope($toStore->getId());
        $targetUrl = $this->urlBuilder->getUrl(
            $currentPath,
            [
                '_current' => false,
                '_nosid' => true,
                '_query' => [
                    StoreManagerInterface::PARAM_NAME => $toStore
                ]
            ]
        );
        $href = $this->urlBuilder->getUrl(
            'stores/store/redirect',
            [
                '_current' => false,
                '_nosid' => true,
                '_query' => $this->prepareRequestQuery($toStore->getCode(), $targetUrl)
            ]
        );
        return $href;
    }

    /**
     * Prepare request query
     * param $store is store code
     *
     * @param string $store
     * @param string $href
     * @return array
     */
    private function prepareRequestQuery($store, $href)
    {
        $storeView = $this->storeManager->getDefaultStoreView();
        $query = [
            StoreManagerInterface::PARAM_NAME => $store,
            \Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED => $this->encoder->encode($href)
        ];
        if (null !== $storeView && $storeView->getCode() !== $store) {
            $query['___from_store'] = $storeView->getCode();
        }

        return $query;
    }
}
