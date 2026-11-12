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
namespace Bss\GeoIPAutoSwitchStore\Observer;

use Magento\Framework\Event\ObserverInterface;

class HideStoreView implements ObserverInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\Data
     */
    private $dataHelper;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\Config
     */
    private $gepIpConfig;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Cookie\GeoSession
     */
    protected $geoSession;

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Model\Validation\SkipRedirectInterface
     */
    private $skipRedirect;

    /**
     * HideStoreView constructor.
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Bss\GeoIPAutoSwitchStore\Helper\Data $dataHelper
     * @param \Bss\GeoIPAutoSwitchStore\Helper\Config $gepIpConfig
     * @param \Bss\GeoIPAutoSwitchStore\Cookie\GeoSession $geoSession
     * @param \Bss\GeoIPAutoSwitchStore\Model\Validation\SkipRedirectInterface $skipRedirect
     */
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Bss\GeoIPAutoSwitchStore\Helper\Data $dataHelper,
        \Bss\GeoIPAutoSwitchStore\Helper\Config $gepIpConfig,
        \Bss\GeoIPAutoSwitchStore\Cookie\GeoSession $geoSession,
        \Bss\GeoIPAutoSwitchStore\Model\Validation\SkipRedirectInterface $skipRedirect
    ) {
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->dataHelper = $dataHelper;
        $this->gepIpConfig = $gepIpConfig;
        $this->geoSession = $geoSession;
        $this->skipRedirect = $skipRedirect;
    }

    /**
     * @return int
     */
    protected function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Add New Layout handle
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return self
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $layout = $observer->getData('layout');
        $alowSwitch = $this->gepIpConfig->getAllowSwitch();
        $isEnabledSwitchWs = $this->gepIpConfig->isEnabledSwitchWebsite();
        $redirectScope = $this->gepIpConfig->getRedirectScope();
        $moduleEnable = $this->gepIpConfig->isEnabled();


        $testerIp = $this->request->getParam('ipTester');
        $customerIp = $this->dataHelper->getIpCustomer($testerIp);
        $countryCode = null;

        if (!$testerIp) {
            $countryCode = $this->geoSession->getSession(
                \Bss\GeoIPAutoSwitchStore\Cookie\GeoSession::COOKIE_COUNTRY
            );
            if (!$countryCode) {
                $countryCode = $this->dataHelper->getCountryCodeFromIp($customerIp);
            }
        }

        if ($this->skipRedirect->setIp($customerIp)->validate(1)) {
            $layout->getUpdate()->addHandle('hide_website');
            return $this;
        }

        if ($moduleEnable) {
            if (!$alowSwitch) {
                $layout->getUpdate()->addHandle('hide_storeview');
                $layout->getUpdate()->addHandle('hide_website');
            } elseif (!$isEnabledSwitchWs || $redirectScope != 'global') {
                $layout->getUpdate()->addHandle('hide_website');
                if (!$alowSwitch) {
                    $layout->getUpdate()->addHandle('hide_storeview');
                }
            } elseif ($alowSwitch && (!$isEnabledSwitchWs || $redirectScope != 'global')) {
                $layout->getUpdate()->addHandle('hide_website');
            } else {
                return $this;
            }
        } else {
            $layout->getUpdate()->addHandle('hide_website');
        }
        return $this;
    }
}
