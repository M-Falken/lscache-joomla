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

    /**
     * VirtueMart never fires a plugin event when a calculation rule (discount/tax,
     * #__virtuemart_calcs) is saved or deleted from the admin "Calculation Rules" view —
     * only a vmcalculation-scoped event unrelated to caching. Called from
     * LiteSpeedCacheCore::purgeAdmin() for option=com_virtuemart&view=calc.
     */
    public function purgeCalcRule()
    {
        $app = Factory::getApplication();

        $calcIds = array();
        $singleId = (int) $app->input->get('virtuemart_calc_id', 0, 'int');
        if ($singleId > 0) {
            $calcIds[] = $singleId;
        }
        foreach ($app->input->get('cid', array(), 'array') as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $calcIds[] = $cid;
            }
        }
        $calcIds = array_unique($calcIds);
        if (empty($calcIds)) {
            return;
        }

        $db = Factory::getDbo();
        $idsList = implode(',', $calcIds);

        // Products directly assigned this rule (the "Prix final" discount selector on the product form)
        $query = $db->createQuery()
                ->select('DISTINCT ' . $db->quoteName('virtuemart_product_id'))
                ->from($db->quoteName('#__virtuemart_product_prices'))
                ->where($db->quoteName('product_discount_id') . ' IN (' . $idsList . ')');
        $db->setQuery($query);
        $productIds = $db->loadColumn();

        // Categories the rule is scoped to — every product in these categories is affected too
        $query = $db->createQuery()
                ->select($db->quoteName('virtuemart_category_id'))
                ->from($db->quoteName('#__virtuemart_calc_categories'))
                ->where($db->quoteName('virtuemart_calc_id') . ' IN (' . $idsList . ')');
        $db->setQuery($query);
        $categoryIds = $db->loadColumn();

        if (!empty($categoryIds)) {
            $query = $db->createQuery()
                    ->select('DISTINCT ' . $db->quoteName('virtuemart_product_id'))
                    ->from($db->quoteName('#__virtuemart_product_categories'))
                    ->where($db->quoteName('virtuemart_category_id') . ' IN (' . implode(',', array_map('intval', $categoryIds)) . ')');
            $db->setQuery($query);
            $productIds = array_merge($productIds, $db->loadColumn());
        }
        $productIds = array_unique(array_map('intval', $productIds));

        if (empty($productIds)) {
            // Scoped only by manufacturer/shopper group/country/state (not resolvable cheaply
            // to specific product pages), or not linked to any product yet — purge broadly.
            $this->plugin->purgeObject->tags[] = "com_virtuemart";
            $this->plugin->purgeAction();
            return;
        }

        $tag = "com_virtuemart" . $this->getProductCategoryTags($productIds);
        foreach ($productIds as $pid) {
            $tag .= ", com_virtuemart.product:" . $pid;
        }
        $this->plugin->purgeObject->tags[] = $tag;

        if ($this->plugin->purgeObject->autoRecache == 0) {
            $this->plugin->purgeAction();
            return;
        }

        $urls = $this->getProductCategoryUrls($productIds);
        foreach ($productIds as $pid) {
            $urls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $pid . '&virtuemart_category_id=0';
        }
        $this->plugin->purgeObject->urls = $urls;
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
    
    /**
     * Itemid utilisable pour une categorie : le sien, sinon celui du plus proche ancetre.
     *
     * @param   int    $cid          Categorie VirtueMart.
     * @param   array  $catItemid    Carte categorie -> Itemid, issue des elements de menu.
     * @param   array  $parents      Carte enfant -> parent de l'arbre des categories.
     * @param   array  $cache        Memoisation, passee par reference : sur un catalogue
     *                               profond la meme branche est remontee des centaines de
     *                               fois, une par produit de la sous-categorie.
     *
     * @return  int    0 si aucun ancetre ne porte de menu.
     */
    private function categoryItemid($cid, array $catItemid, array $parents, array &$cache)
    {
        if (isset($cache[$cid])) {
            return $cache[$cid];
        }

        $chain = array();
        $cur   = $cid;
        $found = 0;

        // La condition d'arret sur $chain est une garde anti-cycle : une arborescence
        // VirtueMart incoherente (categorie sa propre ancetre) ferait sinon tourner la
        // boucle indefiniment pendant la collecte des URLs.
        while ($cur && !isset($chain[$cur])) {
            if (isset($catItemid[$cur])) {
                $found = $catItemid[$cur];
                break;
            }
            $chain[$cur] = true;
            $cur = isset($parents[$cur]) ? $parents[$cur] : 0;
        }

        // Toute la branche remontee partage le meme resultat.
        foreach (array_keys($chain) as $step) {
            $cache[$step] = $found;
        }
        $cache[$cid] = $found;

        return $found;
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

        // Arbre des categories, pour heriter de l'Itemid du plus proche ancetre.
        //
        // Exiger un menu sur LA categorie elle-meme ecartait 330 categories sur 416 et
        // 568 produits sur 2780 (mesure MGF, 10/09/2026) : un produit range dans une
        // sous-categorie sans menu n'etait prechauffe par aucun chemin, et payait donc
        // une generation a froid a chaque premiere visite, dans chaque compartiment de
        // vary. Or VirtueMart sert tres bien une sous-categorie sous l'Itemid d'un
        // ancetre - c'est ce que fait un visiteur qui descend depuis le menu, et un
        // appel direct le confirme (HTTP 200). La garde etait donc trop stricte.
        $parents = array();
        try {
            $query = $db->createQuery()
                    ->select($db->quoteName(array('category_child_id', 'category_parent_id')))
                    ->from('#__virtuemart_category_categories');
            $db->setQuery($query);
            foreach ($db->loadObjectList() as $edge) {
                $parents[(int) $edge->category_child_id] = (int) $edge->category_parent_id;
            }
        } catch (\Throwable) {
            // Arbre indisponible : categoryItemid() retombe alors sur le comportement
            // strict d'avant, sans jamais produire d'URL invalide.
        }

        $itemidCache = array();

        $query = $db->createQuery()
                ->select('virtuemart_category_id')
                ->from('#__virtuemart_categories');
        try {
            $db->setQuery($query);
            $cateids = $db->loadColumn();
            foreach($cateids as $cateid){
                $cid    = (int) $cateid;
                $itemid = $this->categoryItemid($cid, $catItemid, $parents, $itemidCache);
                if (!$itemid) {
                    continue; // aucun ancetre avec menu : rien a quoi rattacher l'URL
                }
                $comUrls[] = 'index.php?option=com_virtuemart&view=category&virtuemart_category_id=' . $cid . '&Itemid=' . $itemid;
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
                if (!$cid) {
                    continue;
                }
                $itemid = $this->categoryItemid($cid, $catItemid, $parents, $itemidCache);
                if (!$itemid) {
                    continue; // aucun ancetre avec menu : rien a quoi rattacher l'URL
                }
                $comUrls[] = 'index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=' . $pid . '&virtuemart_category_id=' . $cid . '&Itemid=' . $itemid;
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
