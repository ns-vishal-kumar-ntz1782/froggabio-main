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
namespace Bss\GeoIPAutoSwitchStore\Model\Validation;

class DefaultRedirect extends GeoIp implements DefaultRedirectInterface
{
    /**
     * @var string
     */
    protected $url;

    /**
     * @inheritDoc
     */
    public function setUrl($url)
    {
        return $this->url = $url;
    }

    /**
     * @inheritDoc
     */
    public function validate()
    {
        $currentUrl = $this->url;
        $defaultUrl = $this->geoIpConfig->getDefaultRedirect();
        if ($defaultUrl !== null) {
            $urlArray = explode("\n", $defaultUrl);
        } else {
            return false;
        }

        if ($currentUrl !== null) {
            $currentUrl = ltrim($currentUrl, '/');
            $currentUrl = rtrim($currentUrl, '/');
        } else {
            $currentUrl = '';
        }

        foreach ($urlArray as $myUrl) {
            if ($myUrl !== null) {
                $myUrl = rtrim($myUrl, "\n");
                $myUrl = rtrim($myUrl, "\r");
                $myUrl = rtrim($myUrl, " ");
                $myUrl = ltrim($myUrl, "\n");
                $myUrl = ltrim($myUrl, "\r");
                $myUrl = ltrim($myUrl, ' ');
                $myUrl = ltrim($myUrl, '/');
            } else {
                $myUrl = '';
            }

            if ($myUrl == $currentUrl) {
                return true;
            }
        }
        return false;
    }
}
