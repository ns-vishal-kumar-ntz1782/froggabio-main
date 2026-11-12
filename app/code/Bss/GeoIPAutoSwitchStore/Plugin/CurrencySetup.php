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
 * @copyright  Copyright (c) 2016-2021 BSS Commerce Co. ( http://bsscommerce.com )
 * @license    http://bsscommerce.com/Bss-Commerce-License.txt
 */
namespace Bss\GeoIPAutoSwitchStore\Plugin;

use Bss\GeoIPAutoSwitchStore\Cookie\GeoSession;
use Bss\GeoIPAutoSwitchStore\Helper\Config as GeoConfig;
use Bss\GeoIPAutoSwitchStore\Helper\Data as GeoDataHelper;
use Bss\GeoIPAutoSwitchStore\Helper\GeoIPData as GeoIpDataHelper;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class CurrencySetup
{
    /**
     * @var GeoConfig
     */
    protected $geoConfig;

    /**
     * @var HttpContext
     */
    protected $httpContext;

    /**
     * Session entity
     *
     * @var \Magento\Framework\Session\SessionManagerInterface
     */
    protected $session;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var GeoDataHelper
     */
    protected $geoDataHelper;

    /**
     * @var GeoIpDataHelper
     */
    protected $geoIPDataHelper;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var GeoSession
     */
    protected $geoSession;

    /**
     * CurrencySetup constructor.
     * @param GeoConfig $geoConfig
     * @param HttpContext $httpContext
     * @param SessionManagerInterface $sessionManager
     * @param StoreManagerInterface $storeManager
     * @param GeoDataHelper $geoDataHelper
     * @param GeoIpDataHelper $geoIPDataHelper
     * @param RequestInterface $request
     * @param GeoSession $geoSession
     */
    public function __construct(
        GeoConfig $geoConfig,
        HttpContext $httpContext,
        SessionManagerInterface $sessionManager,
        StoreManagerInterface $storeManager,
        GeoDataHelper $geoDataHelper,
        GeoIpDataHelper $geoIPDataHelper,
        RequestInterface $request,
        GeoSession $geoSession
    ) {
        $this->geoConfig = $geoConfig;
        $this->httpContext = $httpContext;
        $this->session = $sessionManager;
        $this->storeManager = $storeManager;
        $this->geoDataHelper = $geoDataHelper;
        $this->geoIPDataHelper = $geoIPDataHelper;
        $this->request = $request;
        $this->geoSession = $geoSession;
    }

    /**
     * @param HttpContext $subject
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function beforeGetVaryString(\Magento\Framework\App\Http\Context $subject)
    {
        if (!$this->geoSession->getSession(GeoSession::COOKIE_CUSTOMER_HAS_REDIRECTED) &&
            $this->geoConfig->isEnabled() &&
            $this->geoConfig->isEnabledCurrency()) {
            $defaultCurrencyConfig = $this->getCurrencyConfig();
            $defaultCurrency = $this->storeManager->getStore()->getCurrentCurrency()->getCode();
            $subject->setValue(
                \Magento\Framework\App\Http\Context::CONTEXT_CURRENCY,
                $defaultCurrencyConfig,
                $defaultCurrency
            );
            $this->setStoreCurrencySession($this->storeManager->getStore(), $defaultCurrencyConfig);
        }
    }

    /**
     * @return bool|String
     */
    private function getCurrencyConfig()
    {
        $testerIp = $this->request->getParam('ipTester');
        $customerIp = $this->geoDataHelper->getIpCustomer($testerIp);
        $countryCode = $this->geoSession->getSession(GeoSession::COOKIE_COUNTRY);
        if (!$countryCode) {
            $countryCode = $this->geoDataHelper->getCountryCodeFromIp($customerIp);
        }
        $configCurrency = $this->geoIPDataHelper->getCurrencyByCountryCode($countryCode);
        return $configCurrency;
    }

    /**
     * @param Store $store
     * @return SessionManagerInterface
     */
    protected function _getSession($store)
    {
        if (!$this->session->isSessionExists()) {
            $this->session->setName('store_' . $store->getCode());
            $this->session->start();
        }
        return $this->session;
    }

    /**
     * @param Store $store
     * @param string $code
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function setStoreCurrencySession($store, $code)
    {
        $this->_getSession($store)->setCurrencyCode($code);
        $defaultCode = ($this->storeManager->getStore() !== null)
            ? $this->storeManager->getStore()->getDefaultCurrency()->getCode()
            : $this->storeManager->getWebsite()->getDefaultStore()->getDefaultCurrency()->getCode();

        $this->httpContext->setValue(HttpContext::CONTEXT_CURRENCY, $code, $defaultCode);
    }
}
