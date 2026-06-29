<?php

/*
 *  @since      1.2.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */

use Joomla\Event\Event;
use Joomla\CMS\Factory;

class LSCacheComponentVirtueMart extends LSCacheComponentBase
{

    public function onRegisterEvents()
    {
        $this->dispatcher->addListener("plgVmOnAddToCart", [$this,'plgVmOnAddToCart']);
        $this->dispatcher->addListener("plgVmOnRemoveFromCart", [$this,'plgVmOnRemoveFromCart']);
        $this->dispatcher->addListener("plgVmOnUpdateCart", [$this,'plgVmOnUpdateCart']);
        $this->dispatcher->addListener("plgVmAfterStoreProduct",   [$this,'plgVmAfterStoreProduct']);
        $this->dispatcher->addListener("plgVmOnDeleteProduct",     [$this,'plgVmOnDeleteProduct']);
        $this->dispatcher->addListener("plgVmAfterStoreCategory",  [$this,'plgVmAfterStoreCategory']);
        $this->dispatcher->addListener("plgVmOnDeleteCategory",    [$this,'plgVmOnDeleteCategory']);
        $this->dispatcher->addListener("plgVmAfterVendorStore",       [$this,'plgVmAfterVendorStore']);
        $this->dispatcher->addListener("plgVmConfirmedOrder",         [$this,'plgVmConfirmedOrder']);
        $this->dispatcher->addListener("plgVmOnUpdateOrderShipment",  [$this,'plgVmOnUpdateOrderShipment']);
        $this->dispatcher->addListener("onContentPrepare", [$this,'onContentPrepare']);

        $db = Factory::getDbo();
        $query = $db->createQuery()
                ->select('vendor_currency')
                ->from('#__virtuemart_vendors')
                ->where($db->quoteName('virtuemart_vendor_id') . '=1');
        $db->setQuery($query);
        $vendor_currency = (int) $db->loadResult();

        $app = Factory::getApplication();
        $currency = $app->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', $vendor_currency);

        if ($currency != $vendor_currency) {
            $this->plugin->vary["vm_currency"] = $currency;
        }

        if (isset($this->plugin->pageElements["view"])) {
            $view = $this->plugin->pageElements["view"];
        } else {
            $view = "";
        }

        $user = Factory::getUser();
        if ($user->get('guest')) {
            if (($view == "cart") || ($view == "user")) {
                $this->plugin->pageCachable = false;
            }
        } else if ($view != "category") {
            $this->plugin->pageCachable = false;
        }
    }

    public function plgVmOnAddToCart($cart)
    {
        if (!$this->plugin->lscInstance) {
            return;
        }
        $this->plugin->lscInstance->purgePrivate("com_virtuemart.cart");
        $this->plugin->log();
    }

    public function plgVmOnRemoveFromCart($cart, $prodid=null)
    {
        if (!$this->plugin->lscInstance) {
            return;
        }
        $this->plugin->lscInstance->purgePrivate("com_virtuemart.cart");
        $this->plugin->log();
    }

    public function plgVmOnUpdateCart($cart, $force=null, $html=null)
    {
        if (!$this->plugin->lscInstance) {
            return;
        }
        $this->plugin->lscInstance->purgePrivate("com_virtuemart.cart");
        $this->plugin->log();
    }

    public function plgVmAfterStoreProduct($data, $product_data=null)
    {
        if($data instanceof Event) {
            $product_data = $data->getArgument('1');
            $data = $data->getArgument('0');
        }

        $category_tag = $this->getProductCategoryTags($product_data->virtuemart_product_id);
        $tag = "com_virtuemart, com_virtuemart.product:" . $product_data->virtuemart_product_id . $category_tag;
        $this->plugin->purgeObject->tags[] = $tag;
        if($this->plugin->purgeObject->autoRecache==0){
            $this->plugin->purgeAction();
            return;
        }
        $this->plugin->purgeObject->urls = $this->getProductCategoryUrls($product_data->virtuemart_product_id);
        $this->plugin->purgeObject->urls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $product_data->virtuemart_product_id.'&virtuemart_category_id=0';
        $this->plugin->purgeAction();
        
    }

    public function plgVmOnDeleteProduct($id, $ok=true)
    {
        if($id instanceof Event) {
            return $this->plgVmOnDeleteProduct($id->getArgument('0'),$id->getArgument('1'));
        }

        if (!$ok) {
            return;
        }
        $category_tag = $this->getProductCategoryTags($id);
        $tag = "com_virtuemart, com_virtuemart.product:" . $id . $category_tag;
        $this->plugin->purgeObject->tags[] = $tag;
        if($this->plugin->purgeObject->autoRecache==0){
            $this->plugin->purgeAction();
            return;
        }
        $this->plugin->purgeObject->urls = $this->getProductCategoryUrls($id);
        $this->plugin->purgeObject->urls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $id.'&virtuemart_category_id=0';
        $this->plugin->purgeAction();
    }

    public function plgVmAfterStoreCategory($data, $table=null)
    {
        if ($data instanceof Event) {
            $table = $data->getArgument('1');
            $data  = $data->getArgument('0');
        }

        $cid      = is_array($data) ? (int)($data['virtuemart_category_id'] ?? 0) : 0;
        $parentId = is_array($data) ? (int)($data['category_parent_id']    ?? 0) : 0;

        if (!$cid && is_object($table)) {
            $cid      = (int)($table->virtuemart_category_id ?? 0);
            $parentId = isset($table->category_parent_id) ? (int)$table->category_parent_id : 0;
        }

        if (!$cid) {
            return;
        }

        $tag = "com_virtuemart, com_virtuemart.category:" . $cid;
        if ($parentId > 0) {
            $tag .= ", com_virtuemart.category:" . $parentId;
        }
        $this->plugin->purgeObject->tags[] = $tag;

        if ($this->plugin->purgeObject->autoRecache == 0) {
            $this->plugin->purgeAction();
            return;
        }

        $this->plugin->purgeObject->urls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id=' . $cid;
        if ($parentId > 0) {
            $this->plugin->purgeObject->urls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id=' . $parentId;
        }
        $this->plugin->purgeAction();
    }

    public function plgVmOnDeleteCategory($data)
    {
        if ($data instanceof Event) {
            $data = $data->getArgument('0');
        }

        $cid = is_array($data) ? (int)($data[0] ?? 0) : (int)$data;
        if (!$cid) {
            return;
        }

        $this->plugin->purgeObject->tags[] = "com_virtuemart, com_virtuemart.category:" . $cid;
        $this->plugin->purgeAction();
    }

    public function plgVmAfterVendorStore($data)
    {
        $tag = "com_virtuemart, com_virtuemart.vendor:" . $data->virtuemart_vendor_id;
        $this->plugin->purgeObject->tags[] = $tag;
        $this->plugin->purgeAction();
    }

    public function plgVmConfirmedOrder($cart, $orderDetails = null)
    {
        if($cart instanceof Event) {
            $cart = $cart->getArgument('0');
        }

        $tag = "com_virtuemart";
        $productids = array();
        $productUrls = array();
        
        foreach ($cart->products as $product) {
            $productids[] = $product->virtuemart_product_id;
            $productUrls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $product->virtuemart_product_id .'&virtuemart_category_id=0';
        }
        
        if(empty($productids)){
            return;
        }
        
        $category_tag = $this->getProductCategoryTags($productids);
        $tag .= $category_tag;
        $this->plugin->purgeObject->tags[] = $tag;
        if($this->plugin->purgeObject->autoRecache==0){
            $this->plugin->purgeAction();
            return;
        }
        $urls = $this->getProductCategoryUrls($productids);
        $this->plugin->purgeObject->urls = array_merge($urls, $productUrls);
        $this->plugin->purgeAction();
    }

    public function plgVmOnUpdateOrderShipment($order, $old_order_status=null, $inputOrder=null)
    {
        if ($order instanceof Event) {
            $old_order_status = $order->getArgument('1');
            $order            = $order->getArgument('0');
        }

        if (!is_object($order) || empty($order->virtuemart_order_id)) {
            return;
        }

        $new_status = $order->order_status ?? null;
        if (!$new_status || $new_status === $old_order_status) {
            return;
        }

        if (!$this->orderStatusAffectsStock($new_status, $old_order_status)) {
            return;
        }

        $this->purgeOrderProductCache((int)$order->virtuemart_order_id);
    }

    private function orderStatusAffectsStock($newStatus, $oldStatus)
    {
        $db = Factory::getDbo();
        $query = $db->createQuery()
            ->select($db->quoteName(['order_status_code', 'order_stock_handle']))
            ->from($db->quoteName('#__virtuemart_orderstates'))
            ->where($db->quoteName('order_status_code') . ' IN ('
                . $db->quote($newStatus) . ',' . $db->quote($oldStatus) . ')');
        $db->setQuery($query);
        $rows = $db->loadObjectList('order_status_code');

        $newHandle = isset($rows[$newStatus]) ? $rows[$newStatus]->order_stock_handle : 'A';
        $oldHandle = isset($rows[$oldStatus]) ? $rows[$oldStatus]->order_stock_handle : 'A';

        return $newHandle !== $oldHandle;
    }

    private function purgeOrderProductCache($orderId)
    {
        $db = Factory::getDbo();
        $query = $db->createQuery()
            ->select($db->quoteName('virtuemart_product_id'))
            ->from($db->quoteName('#__virtuemart_order_items'))
            ->where($db->quoteName('virtuemart_order_id') . ' = ' . $orderId);
        $db->setQuery($query);
        $productIds = $db->loadColumn();

        if (empty($productIds)) {
            return;
        }

        $productIds = array_map('intval', array_unique($productIds));

        $tag = "com_virtuemart";
        $productUrls = [];
        foreach ($productIds as $pid) {
            $tag .= ", com_virtuemart.product:" . $pid;
            $productUrls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $pid . '&virtuemart_category_id=0';
        }
        $tag .= $this->getProductCategoryTags($productIds);

        $this->plugin->purgeObject->tags[] = $tag;

        if ($this->plugin->purgeObject->autoRecache == 0) {
            $this->plugin->purgeAction();
            return;
        }

        $categoryUrls = $this->getProductCategoryUrls($productIds);
        $this->plugin->purgeObject->urls = array_merge($categoryUrls, $productUrls);
        $this->plugin->purgeAction();
    }

    public function onPurgeContent($context, $row)
    {
        if ($context == "com_virtuemart.product") {
            $this->plgVmOnDeleteProduct($row->virtuemart_product_id,true);
            
        } else if ($context == "com_virtuemart.category") {
            $tag = "com_virtuemart, com_virtuemart.category:" . $row->virtuemart_category_id;
            $this->plugin->purgeObject->tags[] = $tag;
            $this->plugin->purgeObject->urls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id='.$row->virtuemart_category_id;
        } else if ($context == "com_virtuemart.vendor") {
            $this->plgVmAfterVendorStore($row);
        } else {
            $this->plugin->purgeObject->tags[] = "com_virtuemart";
        }
    }

    public function getTags($option, $pageElements)
    {
        if (isset($pageElements["context"])) {
            $context = $pageElements["context"];
        } else {
            $context = $option;
        }

        if ($context == "com_virtuemart.productdetails") {
            return 'com_virtuemart.product:' . $pageElements['content']->virtuemart_product_id;
        } else if ($context == "com_virtuemart.category") {
            if (isset($pageElements["content"]) && !empty($pageElements["content"]->virtuemart_category_id)) {
                return 'com_virtuemart.category:' . $pageElements["content"]->virtuemart_category_id;
            }
            return $option;
        } else {
            return $option;
        }
    }

    private function getProductCategories($productid)
    {
        $db = Factory::getDbo();
        $query = $db->createQuery()
                ->select($db->quoteName(array('virtuemart_category_id', 'virtuemart_product_id')))
                ->from('#__virtuemart_product_categories');
        if (is_array($productid)) {
            $products = implode(',', array_map('intval', $productid));
            $query->where($db->quoteName('virtuemart_product_id') . ' IN (' . $products . ')');
        } else {
            $query->where($db->quoteName('virtuemart_product_id') . '=' . (int) $productid);
        }
        $db->setQuery($query);
        $result = $db->loadObjectList();
        return $result;
    }
    
    private function getProductCategoryTags($productid)
    {
        $categories = $this->getProductCategories($productid);
        $tags = "";
        if (count($categories)) {
            foreach ($categories as $category) {
                $tags .= ",com_virtuemart.category:" . $category->virtuemart_category_id;
            }
        }
        return $tags;
    }

    private function getProductCategoryUrls($productid)
    {
        $categories = $this->getProductCategories($productid);
        $urls = array();
        $catids = array();
        if (count($categories)) {
            foreach ($categories as $category) {
                $urls[] =  'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $category->virtuemart_product_id.'&virtuemart_category_id='.$category->virtuemart_category_id;
                if(!in_array($category->virtuemart_category_id, $catids)){
                    $urls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id='.$category->virtuemart_category_id;
                    $catids[] = $category->virtuemart_category_id;
                }
            }
        }
        return $urls;
    }
    
    public function getComMap()
    {
        $comUrls =  array();
        $comUrls[] = 'index.php?option=com_virtuemart';

        $db = Factory::getDbo();

        // Map category_id → Itemid from published frontend menu items bound to VM
        // categories. Without an explicit Itemid, Route::link picks an arbitrary VM
        // Itemid and VirtueMart returns 403 when the SEF path doesn't match the
        // requested virtuemart_category_id.
        $catItemid = array();
        try {
            $query = $db->createQuery()
                    ->select($db->quoteName(array('id', 'link')))
                    ->from('#__menu')
                    ->where($db->quoteName('client_id') . ' = 0')
                    ->where($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%option=com_virtuemart%view=category%virtuemart_category_id=%'));
            $db->setQuery($query);
            foreach ($db->loadObjectList() as $menu) {
                $qs = '';
                $pos = strpos($menu->link, '?');
                if ($pos !== false) {
                    $qs = substr($menu->link, $pos + 1);
                }
                $args = array();
                parse_str($qs, $args);
                if (!empty($args['virtuemart_category_id'])) {
                    $cid = (int) $args['virtuemart_category_id'];
                    if (!isset($catItemid[$cid])) {
                        $catItemid[$cid] = (int) $menu->id;
                    }
                }
            }
        } catch (\Throwable) {
            // continue with empty map — categories without menu will be skipped
        }

        $query = $db->createQuery()
                ->select('virtuemart_category_id')
                ->from('#__virtuemart_categories');
        try {
            $db->setQuery($query);
            $cateids = $db->loadColumn();
            foreach($cateids as $cateid){
                $cid = (int) $cateid;
                if (!isset($catItemid[$cid])) {
                    continue; // no menu → VM would 403 on SEF mismatch
                }
                $comUrls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id=' . $cid . '&Itemid=' . $catItemid[$cid];
            }
        } catch (\Throwable) {
            return array();
        }

        $query = $db->createQuery()
                ->select($db->quoteName(array('virtuemart_category_id', 'virtuemart_product_id')))
                ->from('#__virtuemart_product_categories');
        try {
            $db->setQuery($query);
            $products = $db->loadObjectList();
            foreach($products as $product){
                $cid = (int) $product->virtuemart_category_id;
                $pid = (int) $product->virtuemart_product_id;
                if (!$cid || !isset($catItemid[$cid])) {
                    continue; // no usable Itemid → skip to avoid 403
                }
                $comUrls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $pid . '&virtuemart_category_id=' . $cid . '&Itemid=' . $catItemid[$cid];
            }
        } catch (\Throwable) {
            return array();
        }

        return $comUrls;
    }
    
    
    public function onContentPrepare($context, $row=null, $params=null, $page = 0) {
        if($context instanceof Event) {
            return $this->onContentPrepare($context->getArgument(0),$context->getArgument(1),$context->getArgument(2),$context->getArgument(3));
        }

        if (!class_exists('VmModel')) {
            return;
        }

        $productModel = VmModel::getModel('Product');
        $total = $productModel->getTotal();
        $limitstart = $productModel->_limitStart;
        $limit = $productModel->_limit;
        $app = Factory::getApplication();
        $start = $app->input->get('start', $limitstart);
        $pagination = $productModel->_pagination;
        
        $pageCurrent = "pages.current";
        if(($start>0) && ($pagination->$pageCurrent==1)){
            $this->plugin->pageCachable = false;
            return;
        }
        
        if($limitstart>$total){
            $this->plugin->pageCachable = false;
            return;
        }
        
        if($pagination instanceof VmPagination){
            if($limitstart!=$pagination->limitstart){
                $this->plugin->pageCachable = false;
                return;
            }
            if($limit!=$pagination->limit){
                $this->plugin->pageCachable = false;
                return;
            }
        }
    }

   
}
