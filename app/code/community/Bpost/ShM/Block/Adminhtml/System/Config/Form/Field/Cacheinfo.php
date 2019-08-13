<?php

/**
 * Class Bpost_ShM_Block_Adminhtml_System_Config_Form_Field_Clickcollect
 */
class Bpost_ShM_Block_Adminhtml_System_Config_Form_Field_Cacheinfo extends Mage_Adminhtml_Block_System_Config_Form_Field
{

    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $text = Mage::helper('bpost_shm')->__('Please note that the Click & Collect points are updated once a day.') . '<br />';
        $text .= Mage::helper('bpost_shm')->__('If you have added or removed Click & Collect points in Shipping Manager, be aware that it will take up to 24h to be operational in the bpost Magento plugin.') . '<br />';
        $text .= Mage::helper('bpost_shm')->__('If the points don’t appear after 24h, make sure to have cleared the Magento cache.');
        $html = '<tr><td colspan="4" class="value fulltext"><div id="' . $element->getHtmlId() . '" style="color:red;">' . $text . '</div></td></tr>';

        return $html;
    }
}