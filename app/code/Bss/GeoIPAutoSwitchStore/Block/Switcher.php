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

namespace Bss\GeoIPAutoSwitchStore\Block;

class Switcher extends \Magento\Store\Block\Switcher
{
    const IS_WEBSITE_SWITCHER = '___website_switcher';

    /**
     * @var \Bss\GeoIPAutoSwitchStore\Helper\Config
     */
    protected $geoIpConfig;

    /**
     * Switcher constructor.
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\Data\Helper\PostHelper $postDataHelper
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Bss\GeoIPAutoSwitchStore\Helper\Config $geoIpConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Data\Helper\PostHelper $postDataHelper,
        \Bss\GeoIPAutoSwitchStore\Helper\Config $geoIpConfig,
        array $data = []
    ) {
        $this->geoIpConfig = $geoIpConfig;
        parent::__construct($context, $postDataHelper, $data);
    }

    /**
     * @return \Magento\Store\Model\StoreManagerInterface
     */
    public function getStoreManager()
    {
        return $this->_storeManager;
    }

    /**
     * @return \Bss\GeoIPAutoSwitchStore\Helper\Config
     */
    public function getDataHelper()
    {
        return $this->geoIpConfig;
    }

    /**
     * @param $baseUrl
     * @return string
     */
    public function buildWebsiteUrlParams($baseUrl)
    {
        $arrPath = explode('?', $baseUrl);
        if (count($arrPath) > 1) {
            return trim($baseUrl, '/') . '&' . self::IS_WEBSITE_SWITCHER . '=1';
        }
        return trim($baseUrl, '/') . '?' . self::IS_WEBSITE_SWITCHER . '=1';
    }
}
