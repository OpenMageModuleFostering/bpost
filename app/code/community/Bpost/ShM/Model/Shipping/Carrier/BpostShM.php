<?php

/**
 * Created by PHPro
 *
 * @package      Bpost
 * @subpackage   ShM
 * @author       PHPro (info@phpro.be)
 */

/**
 * Class Bpost_ShM_Model_Shipping_Carrier_BpostShM
 */
class Bpost_ShM_Model_Shipping_Carrier_BpostShM extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{
    const SHIPMENT_TRACK_DOMAIN = "http://track.bpost.be/";

    protected $_code = 'bpostshm';
    protected $_isFixed = true;

    /**
     * Edited collectRates function for bpost carriers
     *
     * @param Mage_Shipping_Model_Rate_Request $request
     * @return bool|Mage_Shipping_Model_Rate_Result|null
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        $numberOfDisabledShippingMethods = 0;
        $result = Mage::getModel('bpost_shm/shipping_rate_result');

        $helper = Mage::helper("bpost_shm");
        $configHelper = Mage::helper("bpost_shm/system_config");
        $checkoutSession = Mage::getSingleton('checkout/session');

        $quote = $checkoutSession->getQuote();
        $ratePriceByMethod = array();

        foreach ($this->getAllowedMethods() as $shippingMethodCode => $shippingMethodName) {
            if (!$this->getBpostConfigData('active', $shippingMethodCode) || !$this->checkAvailableBpostShipCountries($request, $shippingMethodCode)) {
                $numberOfDisabledShippingMethods++;
                continue;
            }

            $method = Mage::getModel('shipping/rate_result_method');
            if (!$this->getBpostConfigData('rate_type', $shippingMethodCode)) {
                $price = $this->getBpostConfigData('flat_rate_price', $shippingMethodCode);
                if ($request->getFreeShipping() === true ||
                    ($this->getBpostConfigData('free_shipping', $shippingMethodCode) &&
                        $request->getPackageValue() >= $this->getBpostConfigData('free_shipping_from', $shippingMethodCode))
                ) {
                    $price = 0;
                }
            } else {
                $freeQty = 0;
                if ($request->getAllItems()) {
                    $freePackageValue = 0;
                    foreach ($request->getAllItems() as $item) {
                        if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                            continue;
                        }

                        if ($item->getHasChildren() && $item->isShipSeparately()) {
                            foreach ($item->getChildren() as $child) {
                                if ($child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                                    $freeShipping = is_numeric($child->getFreeShipping()) ? $child->getFreeShipping() : 0;
                                    $freeQty += $item->getQty() * ($child->getQty() - $freeShipping);
                                }
                            }
                        } elseif ($item->getFreeShipping()) {
                            $freeShipping = is_numeric($item->getFreeShipping()) ? $item->getFreeShipping() : 0;
                            $freeQty += $item->getQty() - $freeShipping;
                            $freePackageValue += $item->getBaseRowTotal();
                        }
                    }
                    $oldValue = $request->getPackageValue();
                    $request->setPackageValue($oldValue - $freePackageValue);
                }

                if ($freePackageValue) {
                    $request->setPackageValue($request->getPackageValue() - $freePackageValue);
                }

                $conditionName = $this->getBpostConfigData('condition_name', $shippingMethodCode);
                $request->setConditionName($conditionName ? $conditionName : $this->_default_condition_name);

                $oldWeight = $request->getPackageWeight();
                $oldQty = $request->getPackageQty();

                $request->setPackageWeight($request->getFreeMethodWeight());
                $request->setPackageQty($oldQty - $freeQty);

                $rate = $this->getRate($request, $shippingMethodCode);

                $request->setPackageWeight($oldWeight);
                $request->setPackageQty($oldQty);

                if (!empty($rate) && $rate['price'] >= 0) {
                    if ($request->getFreeShipping() === true ||
                        ($request->getPackageQty() == $freeQty) ||
                        ($this->getBpostConfigData('free_shipping', $shippingMethodCode) &&
                            $request->getPackageValue() >= $this->getBpostConfigData('free_shipping_from', $shippingMethodCode))
                    ) {
                        $price = 0;
                    } else {
                        $price = $rate['price'];
                    }
                } elseif (empty($rate) && $request->getFreeShipping() === true) {
                    $request->setPackageValue($freePackageValue);
                    $request->setPackageQty($freeQty);
                    $rate = $this->getRate($request, $shippingMethodCode);

                    if (!empty($rate) && $rate['price'] >= 0) {
                        $price = 0;
                    }
                } else {
                    $result->append($this->_getCarriersErrorMessage($shippingMethodCode));
                    continue;
                }
            }

            $postCodeValidationResult = Mage::helper('bpost_shm')->validatePostcode($request->getDestCountryId(), $request->getDestPostcode());
            if (($shippingMethodCode == 'bpost_homedelivery' || $shippingMethodCode == 'bpost_international') && $postCodeValidationResult != 'passed') {
                $error = Mage::getModel('shipping/rate_result_error');
                $error->setCarrier($this->_code);
                $error->setCarrierTitle('Bpost ShippingManager');
                $error->setData('error_message', Mage::helper('bpost_shm')->__('The Bpost shipping method "%s" is not available because your postal code is not correct. For your country the format should be like "%s". Please correct the postal code in your shipping address.', $shippingMethodName, $postCodeValidationResult));
                $result->append($error);
                continue;
            }

            $saturdayDeliveryCost = $helper->formatSaturdayDeliveryCost($configHelper->getBpostShippingConfig("saturday_delivery_cost", Mage::app()->getStore()->getId()));
            $saturdayDeliveryActiveByCarrier = $configHelper->getBpostCarriersConfig("saturday_delivery", $shippingMethodCode, Mage::app()->getStore()->getId());
            $ratePriceByMethod[$this->_code . "_" . $shippingMethodCode] = $price;
            if ((bool)$saturdayDeliveryActiveByCarrier &&
                (bool)Mage::helper("bpost_shm/system_config")->getBpostShippingConfig("display_delivery_date") &&
                !(bool)$checkoutSession->getQuote()->getData("bpost_disable_saturday_delivery")
            ) {
                $price = $price + $saturdayDeliveryCost;
                $quote->setBpostSaturdayCostApplied(true)->save();
            } else {
                $quote->setBpostSaturdayCostApplied(false)->save();
            }
            $method->setCarrier($this->_code);
            $method->setMethod($shippingMethodCode);
            $method->setMethodTitle($helper->__($this->getBpostConfigData('name', $shippingMethodCode)));
            $method->setCarrierTitle('Bpost');
            $method->setPrice($price);
            $method->setCost($price);
            $result->append($method);
        }

        if ($numberOfDisabledShippingMethods == 4) {
            return false;
        }

        $checkoutSession->setOriginalRatePrices($ratePriceByMethod);

        return $result;
    }

    /**
     * Function returns the current shipping method's error object
     *
     * @param $shippingMethodCode
     * @return Mage_Shipping_Model_Rate_Result_Error
     */
    protected function _getCarriersErrorMessage($shippingMethodCode)
    {
        $error = Mage::getModel('shipping/rate_result_error');
        $msg = Mage::getStoreConfig("carriers/$shippingMethodCode/specificerrmsg");

        if ($msg && $msg != '') {
            $error->setData("error_message", $msg);
        }

        $error->setCarrier($this->_code);
        $error->setCarrierTitle($this->getBpostConfigData('title', $shippingMethodCode));

        return $error;
    }

    /**
     * Get allowed bpost methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        $allowedMethods = array(
            'bpost_homedelivery' => Mage::getStoreConfig('carriers/bpost_homedelivery/name', $this->getStore()),
            'bpost_international' => Mage::getStoreConfig('carriers/bpost_international/name', $this->getStore()),
            'bpost_parcellocker' => Mage::getStoreConfig('carriers/bpost_parcellocker/name', $this->getStore()),
            'bpost_pickuppoint' => Mage::getStoreConfig('carriers/bpost_pickuppoint/name', $this->getStore())
        );

        return $allowedMethods;
    }

    /**
     * Get system config carrier information
     *
     * @param $field
     * @param $code
     * @return string
     */
    public function getBpostConfigData($field, $code)
    {
        if (empty($code)) {
            return false;
        }
        $path = 'carriers/' . $code . '/' . $field;
        return Mage::getStoreConfig($path, $this->getStore());
    }

    /**
     * Get tracking result object.
     *
     * @param string $tracking_number
     * @return Mage_Shipping_Model_Tracking_Result $tracking_result
     */
    public function getTrackingInfo($tracking_number)
    {
        $tracking_result = $this->getTracking($tracking_number);

        if ($tracking_result instanceof Mage_Shipping_Model_Tracking_Result) {
            $trackings = $tracking_result->getAllTrackings();
            if (is_array($trackings) && count($trackings) > 0) {
                return $trackings[0];
            }
        }
        return false;
    }

    /**
     * Get tracking Url
     *
     * @param string $tracking_number
     * @return Mage_Shipping_Model_Tracking_Result
     */
    public function getTracking($tracking_number)
    {
        $collection = Mage::getResourceModel('sales/order_shipment_track_collection')
            ->addFieldToFilter('track_number', $tracking_number)
            ->addAttributeToSelect("order_id")
            ->join('sales/order', 'order_id=`sales/order`.entity_id', array('increment_id' => 'increment_id', 'shipping_description' => 'shipping_description'), null, 'left');
        $orderId = $collection->getFirstItem()->getIncrementId();
        $carrierTitle = $collection->getFirstItem()->getShippingDescription();
        $tracking_result = Mage::getModel('shipping/tracking_result');
        $tracking_status = Mage::getModel('shipping/tracking_result_status');
        $localeExploded = explode('_', Mage::app()->getLocale()->getLocaleCode());
        $tracking_status->setCarrier($this->_code);
        $tracking_status->setCarrierTitle($carrierTitle);
        $tracking_status->setTracking($tracking_number);

        $trackDomainUrl = self::SHIPMENT_TRACK_DOMAIN;
        $tracking_status->addData(
            array(
                'status' => '<a target="_blank" href="' . $trackDomainUrl . 'etr/light/performSearch.do?searchByItemCode=true&oss_language=' . $localeExploded[0] . '&itemCodes=' . $tracking_number . '"><img src="' . Mage::getDesign()->getSkinUrl('images/bpost/bpost_logo_RGB72_M.png') . '" /> <br />' . Mage::helper('bpost_shm')->__('Click here to track your bpost shipments.') . '</a>'
            )
        );
        $tracking_result->append($tracking_status);

        return $tracking_result;
    }

    /**
     * Make tracking available for Bpost shippingmethods.
     *
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * Make shipping labels not available as we provided our own method.
     *
     * @return bool
     */
    public function isShippingLabelsAvailable()
    {
        return false;
    }

    /**
     * Check for available bpost shipping countries
     *
     * @param $request
     * @param $shippingMethodCode
     * @return bool
     */
    public function checkAvailableBpostShipCountries(Mage_Shipping_Model_Rate_Request $request, $shippingMethodCode)
    {
        $speCountriesAllow = (int)$this->getBpostConfigData('sallowspecific', $shippingMethodCode);
        $bpostHelper = Mage::helper("bpost_shm/system_config");
        $countryId = $request->getDestCountryId();

        if ($speCountriesAllow == 1) {
            $allowedCountries = $bpostHelper->getBpostCarriersConfig("specificcountry", "bpost_international");
            $availableCountries = explode(',', $allowedCountries);

            if (!empty($availableCountries) && in_array($countryId, $availableCountries)) {
                return true;
            } else {
                return false;
            }
        }

        //we check on national shipping country Belgium
        if (($shippingMethodCode === "bpost_homedelivery" ||
                $shippingMethodCode === "bpost_parcellocker" ||
                $shippingMethodCode === "bpost_pickuppoint") &&
            $countryId != "BE"
        ) {
            return false;
        }

        return true;
    }

    /**
     * Get rate object for specific shipping method
     *
     * @param $request
     * @param $shippingMethodCode
     * @return array
     */
    public function getRate($request, $shippingMethodCode)
    {
        return Mage::getResourceModel('bpost_shm/tablerates_' . str_replace('bpost_', '', $shippingMethodCode))->getRate($request);
    }
}
