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

class SkipRedirect extends GeoIp implements SkipRedirectInterface
{
    /**
     * @var string
     */
    protected $ip; // Type = 1

    /**
     * @var string
     */
    protected $url; // Type = 2

    /**
     * @var string
     */
    protected $httpAgent; // Type = 3

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
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setHttpAgent($httpAgent)
    {
        $this->httpAgent = $httpAgent;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validate($type)
    {
        $result = false;
        switch ($type) {
            case 1:
                $result = $this->validateIp();
                break;
            case 2:
                $result = $this->validateUrl();
                break;
            case 3:
                $result = $this->validateAgent();
                break;
        }
        return $result;
    }

    /**
     * Validate Ip
     * @return bool
     */
    private function validateIp()
    {
        $restrictionIps = $this->geoIpConfig->getRestrictIp();
        if ($restrictionIps !== null) {
            $restrictionIps = explode("\n", $restrictionIps);
        } else {
            return false;
        }

        $valid = filter_var($this->ip, FILTER_VALIDATE_IP);
        if ($valid) {
            foreach ($restrictionIps as $restrictionIp) {
                if ($restrictionIp !== null) {
                    $restrictionIp = rtrim($restrictionIp, "\n");
                    $restrictionIp = rtrim($restrictionIp, "\r");
                    $restrictionIp = rtrim($restrictionIp, " ");
                    $restrictionIp = ltrim($restrictionIp, "\n");
                    $restrictionIp = ltrim($restrictionIp, "\r");
                    $restrictionIp = ltrim($restrictionIp, ' ');
                } else {
                    $restrictionIp = '';
                }

                if ($this->ip == $restrictionIp) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /**
     * Validate Url
     * @return bool
     */
    private function validateUrl()
    {
        $skipUrls = $this->geoIpConfig->getRestrictUrl();

        if ($skipUrls) {
            $urlArray = explode("\n", $skipUrls);
            $currentPath = $this->url;
            if ($currentPath !== null) {
                $currentPath = ltrim($currentPath, '/');
                $currentPath = rtrim($currentPath, '/');
            } else {
                $currentPath = '';
            }

            if (strpos($currentPath, '?') !== false) {
                $currentPath = strstr($currentPath, '?', true);
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

                if ($myUrl == $currentPath || ($myUrl != '' && strpos($currentPath, $myUrl) !== false)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Count user agent
     * @return int
     */
    private function validateAgent()
    {
        $countUserBot = 0;
        $http_user_agent = $this->httpAgent;
        $userBots = $this->geoIpConfig->getRestrictUserAgent();

        if ($userBots && (bool)$http_user_agent) {
            $userBots = explode(',', $userBots);
            foreach ($userBots as $userBot) {
                $userBot = rtrim($userBot, ' ');
                $userBot = ltrim($userBot, ' ');
                if ($userBot == 'Google') {
                    if (strpos(strtolower($http_user_agent), 'GOOGLE') !== false) {
                        $countUserBot++;
                    }
                }
                if (strstr(strtolower($http_user_agent), strtolower($userBot))) {
                    $countUserBot++;
                }
            }
        }

        return $countUserBot;
    }
}
