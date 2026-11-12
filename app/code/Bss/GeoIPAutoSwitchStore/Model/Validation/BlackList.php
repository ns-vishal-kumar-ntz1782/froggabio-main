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

class BlackList extends GeoIp implements BlackListInterface
{
    /**
     * @var string
     */
    protected $ip; // Type = 1

    /**
     * @var array|string
     */
    protected $countries; // Type = 2

    /**
     * @inheritDoc
     */
    public function setIp($ip)
    {
        $this->ip = $ip;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setCountries($countries)
    {
        $this->countries = $countries;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validate($type)
    {
        $result = false;
        if ($this->geoIpConfig->isEnabledBlackList()) {
            switch ($type) {
                case 1:
                    $result = $this->validateIp();
                    break;
                case 2:
                    $result = $this->validateCountries();
                    break;
            }
        }
        return $result;
    }

    /**
     * Validate Ip
     * @return bool
     */
    public function validateIp()
    {
        $ipBlocks = $this->geoIpConfig->getIpBlackList();
        $enabledBlackList = $this->geoIpConfig->isEnabledBlackList();
        $valid = filter_var($this->ip, FILTER_VALIDATE_IP);
        if ($enabledBlackList && $valid && !empty($ipBlocks)) {
            $ipList = array_map('trim', explode("\n", $ipBlocks));
            return in_array($this->ip, $ipList);
        }
        return false;
    }

    /**
     * Validate Countries
     * @return bool
     */
    private function validateCountries()
    {
        $countriesBlackList = $this->geoIpConfig->getCountriesBlackList();
        if ($countriesBlackList !== null) {
            $countriesBlackListArr = explode(',', $countriesBlackList);
            $countriesBlackListArr = array_map('trim', $countriesBlackListArr);
        } else {
            return false;
        }

        return in_array($this->countries, $countriesBlackListArr) &&
            $this->geoIpConfig->isEnabledBlackList();
    }
}
