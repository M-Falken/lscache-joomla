<?php
defined('_JEXEC') or die;

/**
 * @author    Grégory Roussel <siriusocteam@gmail.com>
 * @copyright 2026 Grégory Roussel. All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   1.5.9
 * @link      https://github.com/M-Falken
 *
 *  com_vminventory (https://extensions.joomla.org/extension/vm-inventory/)
 *  is a third-party admin component that modifies VirtueMart products via
 *  direct SQL UPDATEs. When its controller dispatches plgVmAfterStoreProduct
 *  to notify LSCache, the normal LSCache bootstrap path does not register the
 *  com_virtuemart listeners (because the active $option is com_vminventory,
 *  not com_virtuemart). This handler compensates by force-loading the VM
 *  component handler and delegating its listener registration, so VM events
 *  fired from com_vminventory are received as if they came from com_virtuemart
 *  itself.
 */

class LSCacheComponentVmInventory extends LSCacheComponentBase
{
    public function onRegisterEvents()
    {
        $vmFile = __DIR__ . '/com_virtuemart.php';
        if (!file_exists($vmFile)) {
            return;
        }
        if (!class_exists('LSCacheComponentVirtueMart')) {
            require_once $vmFile;
        }
        $vmHandler = new LSCacheComponentVirtueMart($this->dispatcher, array());
        $vmHandler->init($this->dispatcher, $this->plugin);
        $vmHandler->onRegisterEvents();
    }
}
