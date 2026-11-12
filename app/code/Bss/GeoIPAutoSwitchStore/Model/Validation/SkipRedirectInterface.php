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
namespace Bss\GeoIPAutoSwitchStore\Model\Validation;

interface SkipRedirectInterface
{
    /**
     * @param string $ip
     * @return $this
     */
    public function setIp($ip);

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url);

    /**
     * @param string $httpAgent
     * @return $this
     */
    public function setHttpAgent($httpAgent);

    /**
     * @param int $type
     * @return bool|int
     */
    public function validate($type);
}
