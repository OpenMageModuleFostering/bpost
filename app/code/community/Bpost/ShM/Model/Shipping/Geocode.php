<?php

/**
 * Created by PHPro
 *
 * @package      Bpost
 * @subpackage   ShM
 * @author       PHPro (info@phpro.be)
 */

/**
 * Class Bpost_ShM_Model_Shipping_Geocode
 */
class Bpost_ShM_Model_Shipping_Geocode
{
    protected $address_line;
    /**
     * @var Bpost_ShM_Helper_Data
     */
    protected $success = false;
    protected $xml;

    /**
     * Make the call to google geocode
     * @param $gMapsAddress
     * @param $latitude
     * @param $longitude
     * @return $this|boolean
     */
    public function callGoogleMaps($gMapsAddress, $latitude = false, $longitude = false)
    {
        $configHelper = Mage::helper("bpost_shm/system_config");
        $key = $configHelper->getBpostShippingConfig("server_api_key");

        if($gMapsAddress) {
            $this->address_line = $gMapsAddress;
            $url = 'https://maps.googleapis.com/maps/api/geocode/xml?address=' . urlencode($gMapsAddress);
        } else {
            $coords = $latitude.','.$longitude;
            $this->address_line = $coords;
            $url = 'https://maps.googleapis.com/maps/api/geocode/xml?latlng=' . urlencode($coords);
        }
        $url .= '&language=nl';
        if($key) {
            $url .= '&key='.$key;
        }
        try{
            $xml = simplexml_load_file($url);
            switch($xml->status){
                case "OK":
                    $this->success = true;
                    $this->xml = $xml;
                    Mage::helper('bpost_shm')->log("Geocode: OK ".$this->address_line." to xml" ,Zend_Log::DEBUG);
                    return $this;
                    break;
                case "ZERO_RESULTS":
                    Mage::helper('bpost_shm')->log("Geocode: no results found for ".$this->address_line,Zend_Log::DEBUG);
                    return false;
                    break;
                case "OVER_QUERY_LIMIT":
                    Mage::helper('bpost_shm')->log("Geocode: Over Query Limit. check your api console",Zend_Log::WARN);
                    return false;
                    break;
                case "REQUEST_DENIED":
                    Mage::helper('bpost_shm')->log("Geocode: Request denied",Zend_Log::WARN);
                    return false;
                    break;
                case "INVALID_REQUEST":
                    Mage::helper('bpost_shm')->log("Geocode: invalid request , address missing?",Zend_Log::WARN);
                    return false;
                    break;
                case "UNKNOWN_ERROR":
                    Mage::helper('bpost_shm')->log("Geocode: unknown Error",Zend_Log::WARN);
                    return false;
                    break;
                default:
                    Mage::helper('bpost_shm')->log("Geocode: unknown Status",Zend_Log::WARN);
                    return false;
                    break;
            }
        }catch (Exception $e){
            Mage::helper('bpost_shm')->log("Geocode: ". $e->getMessage() ,Zend_Log::ERR);
            return false;
        }
    }

    /**
     * Extract useful address information from the geocode xml
     * @return string|boolean
     */
    protected function _extractFromAdress($components, $type)
    {
        foreach ($components->address_component as $component) {
            if($component->type == $type) {
                return $component->long_name;
            }
        }
        return false;
    }

    /**
     * Get the coordinates from the xml
     * @return array|boolean
     */
    public function getLatLng()
    {
        if($this->success){
            $lat = (string)$this->xml->result[0]->geometry->location->lat;
            $lng = (string)$this->xml->result[0]->geometry->location->lng;

            if(isset($lat) && isset($lng)) {
                return array('lat' => $lat, 'lng' => $lng);
            }
        }
        return false;
    }

    /**
     * Get the postal code/city from the xml
     * @return string|boolean
     */
    public function getPostalCode()
    {
        if($this->success){
            $postalCode = $this->_extractFromAdress($this->xml->result[0], 'postal_code');
            $locality = $this->_extractFromAdress($this->xml->result[0], 'locality');

            if($postalCode) {
                return (string)$postalCode;
            } elseif($locality) {
                return (string)$locality;
            }
        }
        return false;
    }
}
