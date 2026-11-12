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
namespace Bss\GeoIPAutoSwitchStore\Helper;

use Magento\Store\Model\ScopeInterface;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const GEOIP_ENABLE = 'bss_geoip/general/enable';
    const POPUP = 'bss_geoip/general/popup';
    const GEOIP_ALLOW_SWITCH = 'bss_geoip/general/allow_switch';
    const GEOIP_RESTRICTION_DEFAULT_REDIRECT = 'bss_geoip/general/default_redirect';
    const GEOIP_REDIRECT_SCOPE = 'bss_geoip/general/redirect_scope';
    const ENABLE_SWITCH_WEBSITE = 'bss_geoip/general/enable_switch_website';
    const GEOIP_ENABLE_COOKIE = 'bss_geoip/general/enable_cookie';
    const GEOIP_TIME_COOKIE = 'bss_geoip/general/time_cookie';
    const GEOIP_IP_FOR_TESTER = 'bss_geoip/general/tester_ip';
    const GEOIP_COUNTRIES = 'bss_geoip/general/country';
    const MESSAGE = 'bss_geoip/general/popup_message';
    const BUTTON = 'bss_geoip/general/popup_button';

    const GEOIP_ENABLE_BLACK_LIST = 'bss_geoip/black_list/enable';
    const GEOIP_COUNTRIES_BLACK_LIST = 'bss_geoip/black_list/country';
    const GEOIP_IP_BLACK_LIST = 'bss_geoip/black_list/ip';
    const GEOIP_URL_BLACK_LIST = 'bss_geoip/black_list/url';

    const GEOIP_RESTRICTION_RESTRICTION_IP = 'bss_geoip/black_list/restriction_ip';
    const GEOIP_RESTRICTION_URL = 'bss_geoip/black_list/restriction_url';
    const GEOIP_RESTRICTION_USER_AGENT = 'bss_geoip/black_list/restriction_user_agent';

    const GEOIP_URL_CUSTOM = 'bss_geoip_update/update/file_url';
    const GEOIP_FILE_CUSTOM = 'bss_geoip_update/update/file_upload';
    const GEOIP_URL_CUSTOM_IPV6 = 'bss_geoip_update/update_ipv6/file_url_ipv6';
    const GEOIP_FILE_CUSTOM_IPV6 = 'bss_geoip_update/update_ipv6/file_upload_ipv6';

    const XML_PATH_BSS_AUTOSW_CODES = 'bss_geoip_currency/currency/codes';
    const XML_PATH_BSS_AUTOSW_ENABLE = 'bss_geoip_currency/currency/enable';

    // <!-- Start section: General/GeoIP Block/Varnish -->

    /**
     * @return boolean
     */
    public function isEnabled()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_ENABLE,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @param boolean $storeId
     * @return mixed
     */
    public function isEnabledPopup()
    {
        return $this->scopeConfig->getValue(
            self::POPUP,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @param string $storeId
     * @return \Magento\Framework\Phrase|string
     */
    public function getPopupMessage($storeId)
    {
        $popupMessage = $this->scopeConfig->getValue(
            self::MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($popupMessage == '' || $popupMessage == null) {
            return __("We think you are in [country], do you want to switch store?");
        }
        return $popupMessage;
    }

    /**
     * @param string $storeId
     * @return \Magento\Framework\Phrase|string
     */
    public function getPopupButton($storeId)
    {
        $buttonTitle = $this->scopeConfig->getValue(
            self::BUTTON,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($buttonTitle == '' || $buttonTitle == null) {
            return __("Switch Store");
        }
        return $buttonTitle;
    }

    /**
     * @param int|string $storeId
     * @return array|null|string
     */
    public function getCountries($storeId)
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_COUNTRIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return string|null
     */
    public function getRestrictUrl()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_RESTRICTION_URL,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getDefaultRedirect()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_RESTRICTION_DEFAULT_REDIRECT,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return array|null|string
     */
    public function getRestrictUserAgent()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_RESTRICTION_USER_AGENT,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getRestrictIp()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_RESTRICTION_RESTRICTION_IP,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string|int|null
     */
    public function getAllowSwitch()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_ALLOW_SWITCH,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getIpForTester()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_IP_FOR_TESTER,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return boolean|int|null
     */
    public function isEnabledSwitchWebsite()
    {
        return $this->scopeConfig->getValue(
            self::ENABLE_SWITCH_WEBSITE,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return string|int|null
     */
    public function isEnabledCookie()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_ENABLE_COOKIE,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string|int
     */
    public function getCookieDuration()
    {
        $result = $this->scopeConfig->getValue(
            self::GEOIP_TIME_COOKIE,
            ScopeInterface::SCOPE_WEBSITES
        );
        if ($result == null || $result == '') {
            $result = 1;
        }
        if ((int)$result > 3650) {
            $result = 3650; // max is 3650 days
        }
        return $result;
    }

    /**
     * @return string
     */
    public function getRedirectScope()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_REDIRECT_SCOPE,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string|int|null
     */
    public function isEnabledBlackList()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_ENABLE_BLACK_LIST,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return array|null|string
     */
    public function getCountriesBlackList()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_COUNTRIES_BLACK_LIST,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getUrlBlackList()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_URL_BLACK_LIST,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getIpBlackList()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_IP_BLACK_LIST,
            ScopeInterface::SCOPE_WEBSITES
        );
    }
    // <!-- End section: General/GeoIP Block/Varnish -->

    // <!-- Start section: Update Database GEOIP Country CSV file -->

    /**
     * @return string
     */
    public function getUpdateUrl()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_URL_CUSTOM,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return string
     */
    public function getUrlCustom()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_FILE_CUSTOM,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return mixed
     */
    public function getUrlCustomIPv6()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_URL_CUSTOM_IPV6,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return mixed
     */
    public function getFileCustomIPv6()
    {
        return $this->scopeConfig->getValue(
            self::GEOIP_FILE_CUSTOM_IPV6,
            ScopeInterface::SCOPE_STORE
        );
    }
    // <!-- End section: Update Database GEOIP Country CSV file -->

    // <!-- Start section: Country Code & Currency Code -->
    /**
     * @return string|int|null
     */
    public function isEnabledCurrency()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_BSS_AUTOSW_ENABLE,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    /**
     * @return array|string
     */
    public function getCurrencyCodes()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_BSS_AUTOSW_CODES,
            ScopeInterface::SCOPE_WEBSITES
        );
    }

    // <!-- End section: Country Code & Currency Code -->
}
