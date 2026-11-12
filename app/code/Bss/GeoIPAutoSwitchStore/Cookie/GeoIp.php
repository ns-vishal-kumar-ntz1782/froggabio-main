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
namespace Bss\GeoIPAutoSwitchStore\Cookie;

use Bss\GeoIPAutoSwitchStore\Helper\Config as GeoIpConfig;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Session\GenericFactory;
use \Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class GeoIp
{
    /**
     * Name of cookie that holds private content version
     */
    const COOKIE_DURATION = 86400;
    const COOKIE_COUNTRY = 'country_code';
    const COOKIE_POPUP = 'remember_popup';
    const COOKIE_LAST_STORE_ID_VISITED = 'last_store_id_visited';
    const COOKIE_LAST_FULL_URL_VISITED = 'last_full_url_visited';
    const COOKIE_CUSTOMER_HAS_REDIRECTED = 'customer_has_redirected';
    const COOKIE_CUSTOMER_HAS_OPEN_POPUP = 'customer_has_open_popup';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_WEBSITE_SWITCHER = 'customer_has_switch_by_website_switcher';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_STORE_SWITCHER = 'customer_has_redirect_from_store_switcher';
    const COOKIE_CUSTOMER_HAS_SWITCH_BY_CURRENCY_SWITCHER = 'customer_has_redirect_from_currency_switcher';

    /**
     * CookieManager
     *
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * @var GeoIpConfig
     */
    private $geoIpConfig;

    /**
     * @var JsonSerializer
     */
    protected $jsonSerializer;

    /**
     * GeoIp constructor.
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param SessionManagerInterface $sessionManager
     * @param GeoIpConfig $geoIpConfig
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        SessionManagerInterface $sessionManager,
        GeoIpConfig $geoIpConfig,
        JsonSerializer $jsonSerializer
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->sessionManager = $sessionManager;
        $this->geoIpConfig = $geoIpConfig;
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * @inheritDoc
     */
    public function getCookie($name)
    {
        return $this->cookieManager->getCookie($name);
    }

    /**
     * @inheritDoc
     */
    public function setCookie(
        $name,
        $value,
        $duration = 86400
    ) {
        $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setHttpOnly(false)
            ->setDuration($duration)
            ->setPath("/")
            ->setDomain($this->sessionManager->getCookieDomain());

        $this->cookieManager->setPublicCookie(
            $name,
            $value,
            $cookieMetadata
        );
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function delete($name, $duration = 86400)
    {
        $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setHttpOnly(false)
            ->setDuration($duration)
            ->setPath("/")
            ->setDomain($this->sessionManager->getCookieDomain());
        $this->cookieManager->deleteCookie($name, $cookieMetadata);
    }

    /**
     * @param string $countryCode
     * @param string $enableCookie
     * @param string $ipForTester
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Stdlib\Cookie\CookieSizeLimitReachedException
     * @throws \Magento\Framework\Stdlib\Cookie\FailureToSendException
     * @return $this
     */
    public function setCountryCookie(
        $countryCode,
        $enableCookie,
        $ipForTester
    ) {
        if ($enableCookie && $ipForTester == null) {
            $timeCookie = (int)$this->geoIpConfig->getCookieDuration();
            $timeCookie = $timeCookie * 24 * 60 * 60;
            $this->setCookie(
                self::COOKIE_COUNTRY,
                $countryCode,
                $timeCookie
            );
        }

        return $this;
    }
}
