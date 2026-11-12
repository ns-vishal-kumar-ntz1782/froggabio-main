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
namespace Bss\GeoIPAutoSwitchStore\Cookie;

class GeoSession
{
    /**
     * Name of cookie that holds private content version
     */
    const COOKIE_COUNTRY = 'country_code';
    const COOKIE_POPUP = 'remember_popup';
    const COOKIE_LAST_STORE_ID_VISITED = 'last_store_id_visited';
    const COOKIE_CUSTOMER_HAS_REDIRECTED = 'has_redirected';
    const COOKIE_CUSTOMER_HAS_OPEN_POPUP = 'has_open_popup';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_WEBSITE_SWITCHER = 'switch_by_website_switcher';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_STORE_SWITCHER = 'redirect_by_store_switcher';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_CURRENCY_SWITCHER = 'redirect_by_currency_switcher';

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $sessionManager;

    /**
     * GeoSession constructor.
     * @param \Magento\Customer\Model\Session $sessionManager
     */
    public function __construct(
        \Magento\Customer\Model\Session $sessionManager
    ) {
        $this->sessionManager = $sessionManager;
    }

    /**
     * Retrieve store session object
     *
     * @return \Magento\Customer\Model\Session
     */
    protected function _getSession()
    {
        return $this->sessionManager;
    }

    /**
     * @param string $key
     * @param null|string|int|bool $value
     */
    public function setSession($key, $value)
    {
        if ($key !== null) {
            $key = str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($key))));
            if (isset($key[0])) {
                $key = 'set' . $key;
                $session = $this->_getSession();
                $session->$key($value);
            }
        }
    }

    /**
     * @param string $key
     * @return null|string|int|bool
     */
    public function getSession($key)
    {
        $session = $this->_getSession();
        return $session->getData($key, false);
    }
}
