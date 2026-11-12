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

namespace Bss\GeoIPAutoSwitchStore\Helper;

use Bss\GeoIPAutoSwitchStore\Model\GeoIpMaxMindFactory;
use Bss\GeoIPAutoSwitchStore\Model\GeoIpMaxMindIPv6Factory;
use Bss\GeoIPAutoSwitchStore\Model\LocationsMaxMindFactory;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Exception\LogicException;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * Const
     */
    const PARAM_NAME = '___store';
    const PARAM_NAME_URL_ENCODED = 'uenc';

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var GeoIpMaxMindFactory
     */
    private $geoIpMaxMind;

    /**
     * @var LocationsMaxMindFactory
     */
    private $locationsMaxMind;

    /**
     * @var GeoIpMaxMindIPv6Factory
     */
    private $geoIpMaxMindIPv6;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * Data constructor.
     * @param Context $context
     * @param GeoIpMaxMindFactory $geoIpMaxMind
     * @param GeoIpMaxMindIPv6Factory $geoIpMaxMindIPv6
     * @param StoreManagerInterface $storeManager
     * @param LocationsMaxMindFactory $locationsMaxMind
     */
    public function __construct(
        Context $context,
        GeoIpMaxMindFactory $geoIpMaxMind,
        GeoIpMaxMindIPv6Factory $geoIpMaxMindIPv6,
        StoreManagerInterface $storeManager,
        LocationsMaxMindFactory $locationsMaxMind,
        StoreRepositoryInterface $storeRepository
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
        $this->geoIpMaxMind = $geoIpMaxMind;
        $this->geoIpMaxMindIPv6 = $geoIpMaxMindIPv6;
        $this->locationsMaxMind = $locationsMaxMind;
        $this->storeRepository = $storeRepository;
    }

    /**
     * @return mixed
     */
    public function returnHttpUserAgent()
    {
        return $this->_request->getServer('HTTP_USER_AGENT');
    }

    /**
     * {@inheritdoc}
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * @param string $code
     * @return StoreInterface
     * @throws NoSuchEntityException
     */
    public function getStoreByCode($code)
    {
        return $this->storeRepository->get($code);
    }

    /**
     * @param string $ipCustomer
     * @return mixed|null
     */
    public function getCountryCodeFromIp($ipCustomer = null)
    {
        if ($ipCustomer == null) {
            $ipCustomer = $this->getIpCustomer();
        }
        if ($this->_request->getHeader('cf-ipcountry')) {
            return $this->_request->getHeader('cf-ipcountry');
        }
        $dataCollection = null;
        //Check if IPv4
        if (filter_var($ipCustomer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipArray = explode('.', $ipCustomer);
            $ipCustomerLong = (16777216 * $ipArray[0]) + (65536 * $ipArray[1]) + (256 * $ipArray[2]) + $ipArray[3];
            //Ip of Customer convert to Long Ip
            $collection = $this->geoIpMaxMind->create()
                ->getCollection()
                ->addFieldToFilter(
                    'begin_ip',
                    ['lteq' => $ipCustomerLong]
                )->addFieldToFilter('end_ip', ['gteq' => $ipCustomerLong]);
            $dataCollection = $collection->getData();
        } elseif (filter_var($ipCustomer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipv6Long = $this->ipv6ToLong($ipCustomer);
            $collection = $this->geoIpMaxMindIPv6->create()
                ->getCollection()
                ->addFieldToFilter(
                    'begin_ip',
                    ['lteq' => $ipv6Long]
                )->addFieldToFilter('end_ip', ['gteq' => $ipv6Long]);
            $dataCollection = $collection->getData();
        }
        $countryCode = $this->getCountryByIpInfo($ipCustomer, $dataCollection);
        return $countryCode;
    }

    /**
     * @param null $ipForTester
     * @return string|null
     */
    public function getIpCustomer($ipForTester = null)
    {
        //If IP For Tester NULL then return current IP of Customer
        $ipCustomer = '';
        if ($ipForTester == null || $ipForTester == '') {
            $ipCustomers = explode(',', $this->getIpAdress());
            if ($ipCustomers) {
                $ipCustomer = $ipCustomers[0];
            }
        } else {
            $ipCustomer = $ipForTester;
        }
        if ($ipCustomer == '127.0.0.1' || $ipCustomer == 'UNKNOWN') {
            //Return a US IP
            $ipCustomer = '23.235.227.106';
        }

        // Fix conflict with docker if ip has suffix port Ex : 101.103.106:308887
        if ($ipCustomer !== null && strpos($ipCustomer, ":") !== false) {
            $ipCustomer = explode(":", $ipCustomer);
            $ipCustomer = $ipCustomer[0];
        }

        return $ipCustomer;
    }

    /**
     * @return string
     */
    protected function getIpAdress()
    {
        if ($this->_request->getServer('HTTP_CLIENT_IP')) {
            $ipAddress = $this->_request->getServer('HTTP_CLIENT_IP');
        } elseif ($this->_request->getServer('HTTP_X_REAL_IP')) {
            $ipAddress = $this->_request->getServer('HTTP_X_REAL_IP');
        } elseif ($this->_request->getServer('HTTP_X_FORWARDED_FOR')) {
            $ipAddress = $this->_request->getServer('HTTP_X_FORWARDED_FOR');
        } elseif ($this->_request->getServer('HTTP_X_FORWARDED')) {
            $ipAddress = $this->_request->getServer('HTTP_X_FORWARDED');
        } elseif ($this->_request->getServer('HTTP_FORWARDED_FOR')) {
            $ipAddress = $this->_request->getServer('HTTP_FORWARDED_FOR');
        } elseif ($this->_request->getServer('HTTP_FORWARDED')) {
            $ipAddress = $this->_request->getServer('HTTP_FORWARDED');
        } elseif ($this->_request->getServer('REMOTE_ADDR')) {
            $ipAddress = $this->_request->getServer('REMOTE_ADDR');
        } else {
            $ipAddress = 'UNKNOWN';
        }

        return $ipAddress;
    }

    /**
     * @param string $ip
     * @return bool|string
     */
    protected function ipv6ToLong($ip)
    {
        // @codingStandardsIgnoreStart
        $pton = inet_pton($ip);
        if (!$pton) {
            return false;
        }
        $number = '';
        foreach (unpack('C*', $pton) as $byte) {
            $number .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        return base_convert(ltrim($number, '0'), 2, 10);
        // @codingStandardsIgnoreEnd
    }

    /**
     * @param string $ipCustomer
     * @param null|array $dataCollection
     * @return mixed|null
     */
    protected function getCountryByIpInfo($ipCustomer, $dataCollection)
    {
        $countryCode = null;

        if ($dataCollection) {
            $network = $dataCollection[0]['geoname_id'];
            $collection = $this->locationsMaxMind->create()
                ->getCollection()
                ->addFieldToFilter(
                    'geoname_id',
                    ['eq' => $network]
                )
                ->addFieldToFilter(
                    'locale_code',
                    ['eq' => 'en']
                );
            $locationCollection = $collection->getData();
            if (isset($locationCollection[0])) {
                $countryCode = $locationCollection[0]['country_iso_code'];
            }
        } else {
            try {
                // @codingStandardsIgnoreStart
                $url = 'http://ip-api.com/json/' . $ipCustomer;
                $timeout = 5;
                $url = str_replace("&amp;", "&", urldecode(trim($url)));
                $ch = curl_init();
                curl_setopt(
                    $ch,
                    CURLOPT_USERAGENT,
                    "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1"
                );
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_ENCODING, "");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                $response = curl_exec($ch);
                curl_close($ch);
                $response = json_decode($response, true);
                // @codingStandardsIgnoreEnd

                if (is_array($response) && isset($response['countryCode'])) {
                    $countryCode = $response['countryCode'];
                }
            } catch (\Exception $e) {
                throw new LogicException(__($e->getMessage()));
            }
        }
        return $countryCode;
    }

    /**
     * @param Store $store
     * @param string $currentUrl
     * @return string
     */
    public function getTargetStorePostData(Store $store, $currentUrl)
    {
        $url = $store->getBaseUrl() . 'stores/store/switch/' . self::PARAM_NAME . '/';
        $url .= $store->getCode() . '/___from_store/';
        $url .= $this->storeManager->getStore()->getCode() . '/' . self::PARAM_NAME_URL_ENCODED . '/';
        $url .= $this->urlEncoder->encode($currentUrl) . '/is_from_popup/true';
        return $url;
    }

    /**
     * @return bool
     */
    public function isRememberPopupStatus()
    {
        return true;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        $baseUrl = $this->_urlBuilder->getUrl();
        return $baseUrl;
    }

    /**
     * @return string
     */
    public function getUrl($param)
    {
        $baseUrl = $this->_urlBuilder->getUrl($param);
        return $baseUrl;
    }

    /**
     * {@inheritdoc}
     */
    protected function getStoreCode()
    {
        return $this->storeManager->getStore()->getCode();
    }
}
