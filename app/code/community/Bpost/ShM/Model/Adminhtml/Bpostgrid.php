<?php
/**
 * Created by PHPro
 *
 * @package      Bpost
 * @subpackage   ShM
 * @author       PHPro (info@phpro.be)
 */
class Bpost_ShM_Model_Adminhtml_Bpostgrid extends Varien_Event_Observer{

    const MEDIA_LABEL_PATH = "/bpost/orderlabels/";
    protected $_transaction = null;
    protected $_addReturnLabels = false;
    protected $_returnBarcodeArray = array();
    protected $_barcodeArray = array();


    /**
     * Generates and completes an order, reference: generateAndCompleteAction.
     * @param $order
     * @return bool
     */
    public function generateAndCompleteOrder($orderId)
    {
        $order = Mage::getModel('sales/order')->load($orderId);
        $shipmentCollection = $order->getShipmentsCollection();
        $collectionCount = $shipmentCollection->count();
        $shipment = false;

        $retryAutomatedShipment = false;
        if($collectionCount == 1 && $shipmentCollection->getFirstItem()->getBpostShipmentAutomated() == 1){
            $retryAutomatedShipment = true;
        }

        if ($collectionCount > 0 && !$order->getBpostLabelExists() && !$retryAutomatedShipment) {
            return $this->_processAvailableShipments($order);

        } elseif (!$order->getBpostLabelExists()) {

            if($retryAutomatedShipment){
                $shipment = $shipmentCollection->getFirstItem();
            }
            return $this->_createBpostShipment($order, $shipment);
        } else {
            $message = Mage::helper('bpost_shm')->__("The order with id %s is not ready to be shipped or has already been shipped.", $order->getIncrementId());
            Mage::getSingleton('core/session')->addNotice($message);
            return false;
        }
    }


    /**
     * @param $order
     * @return bool
     */
    protected function _processAvailableShipments(&$order)
    {
        $configHelper = Mage::helper('bpost_shm/system_config');
        $bpostHelper =  Mage::helper('bpost_shm');

        $this->_addReturnLabels = (bool)$configHelper->getBpostShippingConfig("automatic_retour_labels", $order->getStoreId());
        $locale = strtolower($bpostHelper->getLocaleByOrder($order, true));

        $trackCollection = Mage::getResourceModel('sales/order_shipment_track_collection')
            ->addFieldToFilter('order_id', $order->getId())
            ->addFieldToFilter('carrier_code', 'bpostshm');


        if ($trackCollection->count() > 0) {
            $counter = 0;

            $shipmentsArray = array();
            foreach ($trackCollection as $tracker) {
                if (!array_key_exists($tracker->getParentId(), $shipmentsArray)) {
                    $shipmentsArray[$tracker->getParentId()] = Mage::getResourceModel('sales/order_shipment_collection')->addFieldToFilter('entity_id', $tracker->getParentId())->setPageSize(1)->getFirstItem();
                }

                //first we create a bpost order
                Mage::log("creating order");
                $this->_createBpostOrder($order);
                $counter++;
            }

            $pdfBaseName = $this->_generateLabelAndReturnLabelIfEnabled($order);

            if (!$pdfBaseName) {
                $message = Mage::helper('bpost_shm')->__("Something went wrong while processing order #%s, please check your error logs.", $order->getIncrementId());
                Mage::getSingleton('core/session')->addError($message);
                return false;
            }

            $tmpCounter = 0;
            foreach ($trackCollection as $tracker) {
                $shipment = $shipmentsArray[$tracker->getParentId()];
                $label = $this->_parseLabelData($order, $tmpCounter);
                $barcode = $label['barcode'];

                try {
                    if (array_key_exists('returnBarcode', $label)) {
                        $shipment->setBpostReturnBarcode($label['returnBarcode']);
                    }

                    $shipment->setBpostLabelPath($pdfBaseName);
                    $shipment->setBpostTrackingUrl('<a target="_blank" href="' . Bpost_ShM_Model_Shipping_Carrier_BpostShM::SHIPMENT_TRACK_DOMAIN."etr/light/performSearch.do?searchByItemCode=true&oss_language=" . $locale . "&itemCodes=" . $order->getIncrementId() . '">' . Mage::helper('bpost_shm')->__('Track this shipment') . '</a>');
                    $tracker->setData("number", $barcode);

                    //add data to transaction
                    $this->_getTransaction()
                        ->addObject($shipment)
                        ->addObject($tracker);

                } catch (Exception $e) {
                    Mage::helper('bpost_shm')->log($e->getMessage(), Zend_Log::ERR);
                    continue;
                }

                $tmpCounter++;
            }

            $order->addStatusHistoryComment(Mage::helper('bpost_shm')->__('Shipped with bpost generateLabelAndComplete'), true);

            if($this->_addReturnLabels){
                $order->setBpostReturnLabelExists(true);
            }

            $order->setBpostLabelExists(true);
            $this->_getTransaction()->addObject($order);

            return $counter;
        } else {
            $message = Mage::helper('bpost_shm')->__("The order with id %s only has non-bpost shipments.", $order->getIncrementId());
            Mage::getSingleton('core/session')->addNotice($message);
            return false;
        }
    }


    /**
     * @param $order
     * @return bool
     */
    protected function _createBpostShipment(&$order, $shipment = false)
    {
        $configHelper = Mage::helper('bpost_shm/system_config');
        $bpostHelper =  Mage::helper('bpost_shm');

        $this->_addReturnLabels = (bool)$configHelper->getBpostShippingConfig("automatic_retour_labels", $order->getStoreId());
        $locale = strtolower($bpostHelper->getLocaleByOrder($order, true));

        if(!$shipment){
            $shipment = $order->prepareShipment();
            $shipment->register()->setBpostShipmentAutomated(true)->save();
        }

        $weight = Mage::helper('bpost_shm')->calculateTotalShippingWeight($shipment);
        $shipment->setTotalWeight($weight);

        //first create the bpost order
        $this->_createBpostOrder($order);

        //then create label for order
        $pdfBaseName = $this->_generateLabelAndReturnLabelIfEnabled($order);
        $label = $this->_parseLabelData($order);
        $barcode = $label['barcode'];

        if (!$pdfBaseName) {
            $message = Mage::helper('bpost_shm')->__("Something went wrong while processing order #%s, please check your error logs.", $order->getIncrementId());
            Mage::getSingleton('core/session')->addError($message);
            return false;
        } else {
            if (array_key_exists('returnBarcode', $label)) {
                $shipment->setBpostReturnBarcode($label['returnBarcode']);
            }
            $shipment->setBpostLabelPath($pdfBaseName);
            $shipment->setBpostTrackingUrl('<a target="_blank" href="' . Bpost_ShM_Model_Shipping_Carrier_BpostShM::SHIPMENT_TRACK_DOMAIN."etr/light/performSearch.do?searchByItemCode=true&oss_language=" . $locale . "&itemCodes=" . $order->getIncrementId() . '">' . Mage::helper('bpost_shm')->__('Track this shipment') . '</a>');
            $order->setIsInProcess(true);
            $order->addStatusHistoryComment(Mage::helper('bpost_shm')->__('Shipped with bpost generateLabelAndComplete'), true);
            $order->setBpostLabelExists(true);

            if($this->_addReturnLabels){
                $order->setBpostReturnLabelExists(true);
            }

            $tracker = Mage::getModel('sales/order_shipment_track')
                ->setShipment($shipment)
                ->setData('title', 'bpost')
                ->setData('number', $barcode)
                ->setData('carrier_code', "bpostshm")
                ->setData('order_id', $shipment->getData('order_id'));

            try {
                //save all objects in 1 transaction
                //add data to the current transaction
                $this->_getTransaction()
                    ->addObject($shipment)
                    ->addObject($order)
                    ->addObject($tracker);
            } catch (Exception $e) {
                Mage::helper('bpost_shm')->log($e->getMessage(), Zend_Log::ERR);
            }

            return 1;
        }
    }


    /**
     * @param $labelData
     * function parses label data and returns it
     */
    protected function _parseLabelData($order, $barcodeIndex = 0){
        $bpostHelper = Mage::helper('bpost_shm');

        $returnData = array();
        $locale = $bpostHelper->getLocaleByOrder($order, true);

        $returnData["locale"] = $locale;

        if(!isset($this->_barcodeArray[$barcodeIndex])){
            $returnData["barcode"] = "no-barcode";
        }else{
            $returnData["barcode"] = $this->_barcodeArray[$barcodeIndex];
        }

        //check if the barcode index isset
        //if so, a return barcode is available
        if(isset($this->_returnBarcodeArray[$barcodeIndex])){
            $returnData["returnBarcode"] = $this->_returnBarcodeArray[$barcodeIndex];
        }

        return $returnData;
    }


    /**
     * function generates bpost API order
     * @param $order
     * @throws Mage_Core_Exception
     */
    protected function _createBpostOrder(&$order){
        $bpostHelper = Mage::helper("bpost_shm");

        $webserviceModel = Mage::getModel("bpost_shm/api", true);
        $response = $webserviceModel->createOrder($order);

        if(!$response){
            Mage::throwException($bpostHelper->__("Error while creating a bpost order for Magento order #%s. Please check your API log.", $order->getIncrementId()));
        }

        if(!$order->getBpostReference()){
            $order->setBpostReference($order->getIncrementId());
        }
    }



    /**
     * Generates a shipment label and saves it on the harddisk.
     *
     * @param $order
     * @param $shipment
     * @return mixed
     */
    protected function _generateLabelAndReturnLabelIfEnabled(&$order){
        $bpostHelper = Mage::helper("bpost_shm");
        $pdfName = null;

        $webserviceModel = Mage::getModel("bpost_shm/api", true);
        $responseLabelWebserviceCallback = $webserviceModel->createLabelByOrder($order, $this->_addReturnLabels);

        if ($responseLabelWebserviceCallback) {
            $parsedResponse = $bpostHelper->parseLabelApiResponse($responseLabelWebserviceCallback, $order);

            if(empty($parsedResponse) || !isset($parsedResponse["pdfString"])){
                $message = $bpostHelper->__("No label response received for Magento order #%s.", $order->getIncrementId());
                $bpostHelper->log($message, Zend_Log::ERR);
                return false;
            }

            $this->_barcodeArray = $parsedResponse["barcodeString"];

            if(array_key_exists("returnBarcodeString", $parsedResponse)) {
                $this->_returnBarcodeArray = $parsedResponse["returnBarcodeString"];
            }

            //loop array and save pdf files
            $loopNr = 0;
            $pdfName = "";
            foreach($parsedResponse["pdfString"] as $pdfString){
                if($loopNr){
                    $pdfName .= ":";
                }

                $pdfName .= $bpostHelper->generatePdfAndSave($pdfString, "orderlabels", $order->getIncrementId());
                $pdfName .= ".pdf";

                if($this->_addReturnLabels){
                    $order->setBpostReturnLabelExists(true);
                }

                $loopNr++;
            }

            return $pdfName;
        }

        return false;
    }


    /**
     * function returns the current transaction
     */
    protected function _getTransaction(){
        if(is_null($this->_transaction)){
            $this->_transaction = Mage::getModel('core/resource_transaction');
        }

        return $this->_transaction;
    }


    /**
     * function will save the current transaction
     */
    public function saveTransaction(){
        //make sure we have an object first before saving it
        if(is_object($this->_transaction)){
            $this->_transaction->save();
        }
    }


    /**
     * Processes the undownloadable labels. (set mark and zip)
     *
     * @param $orderIds
     * @return bool|string
     */
    public function processUndownloadedLabels($orderIds)
    {
        $file = new Varien_Io_File();
        $labelPdfArray = array();
        $i = 0;
        foreach ($orderIds as $orderId) {
            $order = Mage::getModel('sales/order')->load($orderId);
            $exported = false;

            if (!$order->getBpostLabelExported()) {
                $shippingCollection = Mage::getResourceModel('sales/order_shipment_collection')
                ->setOrderFilter($order)
                ->load();

                if (count($shippingCollection)) {
                    foreach ($shippingCollection as $shipment) {
                        if($shipment->getBpostLabelPath() != "" && !$exported){
                            $labelPaths = explode(":", $shipment->getBpostLabelPath());
                            foreach($labelPaths as $labelPath){
                                $filePath = Mage::getBaseDir('media') . self::MEDIA_LABEL_PATH . $labelPath;
                                if($file->fileExists($filePath)){
                                    $labelPdfArray[] = $filePath;
                                    $exported = true;
                                }
                            }
                        }
                    }

                    if ($exported) {
                        $shippingCollection->setDataToAll("bpost_label_exported", true);
                        $order->setBpostLabelExported(true);
                    }

                    $shippingCollection->save();
                    $order->save();
                }
            } else {
                $i++;
            }
        }

        if (!count($labelPdfArray)) {
            return false;
        }
        if ($i > 0) {
            $message = Mage::helper('bpost_shm')->__('%s orders already had downloaded labels.', $i);
            Mage::getSingleton('core/session')->addNotice($message);
        }

        $generated_name = date("Y_m_d_H_i_s") . "_undownloaded.zip";

        $file = new Varien_Io_File();
        $file->checkAndCreateFolder(Mage::getBaseDir('media') . self::MEDIA_LABEL_PATH."zips/");

        return $this->_zipLabelPdfArray($labelPdfArray, $generated_name, true, true);
    }


    /**
     * Zips the labels.
     *
     * @param array $files
     * @param string $generated_name
     * @param bool $overwrite
     * @return bool|string
     */
    protected function _zipLabelPdfArray($files = array(), $generated_name = '', $overwrite = false, $mergePdfFiles = false)
    {
        $destination = Mage::getBaseDir('media') . self::MEDIA_LABEL_PATH."zips/" . $generated_name;
        $varienFile = new Varien_Io_File();
        $bpostHelper = Mage::helper("bpost_shm");


        if ($varienFile->fileExists($destination) && !$overwrite) {
            return false;
        }

        $valid_files = array();
        $pdfMerged = new Zend_Pdf();

        if (is_array($files)) {
            foreach ($files as $file) {
                if ($varienFile->fileExists($file)) {
                    $valid_files[] = $file;

                    if ($mergePdfFiles) {
                        $tmpPdf = Zend_Pdf::load($file);
                        foreach ($tmpPdf->pages as $page) {
                            $clonedPage = clone $page;
                            $pdfMerged->pages[] = $clonedPage;
                        }
                    }
                }
            }

            //save new pdf file is necessary
            if($mergePdfFiles){
                $mergedPdfName = $bpostHelper->generatePdfAndSave($pdfMerged->render(), "orderlabels", "merged");
                $mergedPdfName = $mergedPdfName.".pdf";

                if($mergedPdfName){
                    $valid_files = array($mergedPdfName);
                }
            }

            $validFilesCount = count($valid_files);
            if ($validFilesCount && $validFilesCount > 1) {
                $zip = new ZipArchive();

                if ($zip->open($destination, $overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
                    return false;
                }

                foreach ($valid_files as $file) {
                    $fileName = $bpostHelper->getFileNameByPath($file);
                    $zip->addFile($file, $fileName);
                }

                $zip->close();
                return $generated_name;
            } elseif($validFilesCount) {
                //we return the pdf path instead of creating a zip file
                $pdfName = $valid_files[0];
                return $pdfName;
            }

            return false;
        }
    }
}