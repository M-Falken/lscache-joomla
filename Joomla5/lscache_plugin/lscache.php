<?php

/**
 *  @since      1.0.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Profiler\Profiler;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Event\PageCache\GetKeyEvent;

/**
 * LiteSpeed Cache Plugin for Joomla running on LiteSpeed Webserver (LSWS).
 *
 * @since  1.0
 */
class plgSystemLSCache extends CMSPlugin {

    const MODULE_ESI = 1;
    const MODULE_PURGEALL = 2;
    const MODULE_PURGETAG = 3;
    const MODULE_EMBED = 4;
    // Un crawl annoncé 'starting'/'running' qui n'a plus écrit depuis ce délai est
    // considéré mort. Marge large : avec le flush temporisé de crawlUrls() et le
    // timeout curl de 30 s, l'écart normal entre deux écritures ne dépasse pas ~60 s.
    const REBUILD_STALE_SECONDS = 180;
    const CATEGORY_CONTEXTS = array('com_categories.category', 'com_banners.category', 'com_contact.category', 'com_content.category', 'com_newsfeeds.category', 'com_users.category',
        'com_categories.categories', 'com_banners.categories', 'com_contact.categories', 'com_content.categories', 'com_newsfeeds.categories', 'com_users.categories');
    const CONTENT_CONTEXTS = array('com_content.article', 'com_content.featured', 'com_content.form', 'com_banner.banner', 'com_contact.contact', 'com_contact.form', 'com_newsfeeds.newsfeed', 'com_content');

    protected $app;
    protected $cacheEnabled;
    protected $esiEnabled;
    protected $esion = false;
    protected $esittl = 0;
    protected $esipublic = true;
    protected $esiModule = null;
    protected $menuItem;
    protected $moduleHelper;
    protected $componentHelper;
    protected $esijs = array();
    public $settings;
    public $lscInstance;
    public $pageElements = array();
    public $pageCachable = false;
    public $vary = array();
    public $cacheTags = array();
    public $purgeObject;

    /**
     * Read LSCache Settings.
     *
     * @since   0.1
     */
    public function __construct(&$subject, $config) {
        parent::__construct($subject, $config);

        $this->settings = ComponentHelper::getParams('com_lscache');
        if ($this->settings->get('cacheEnabled', 3) == 3) {
            $this->saveComponent(true);
            $this->saveHtaccess();
        }

        $this->cacheEnabled = $this->settings->get('cacheEnabled', 1) == 1 ? true : false;
        if (!$this->cacheEnabled) {
            return;
        }
        $this->esiEnabled = $this->settings->get('esiEnabled', 1);

        // Server type
        if (!defined('LITESPEED_SERVER_TYPE')) {
            if (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ADC');
            } elseif (isset($_SERVER['LSWS_EDITION']) && strpos($_SERVER['LSWS_EDITION'], 'Openlitespeed') === 0) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_OLS');
            } elseif (isset($_SERVER['SERVER_SOFTWARE']) && $_SERVER['SERVER_SOFTWARE'] == 'LiteSpeed') {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ENT');
            } else {
                define('LITESPEED_SERVER_TYPE', 'NONE');
            }
        }

        // Checks if caching is allowed via server variable
        if (!empty($_SERVER['HTTP_X_LSCACHE']) || LITESPEED_SERVER_TYPE === 'LITESPEED_SERVER_ADC' || defined('LITESPEED_CLI')) {
            !defined('LITESPEED_ALLOWED') && define('LITESPEED_ALLOWED', true);
        }

        // ESI const defination
        if (!defined('LITESPEED_ESI_SUPPORT')) {
            define('LITESPEED_ESI_SUPPORT', LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ? true : false );
        }

        require_once __DIR__ . '/lscachebase.php';
        require_once __DIR__ . '/lscachecore.php';
        $this->lscInstance = new LiteSpeedCacheCore();

        require_once __DIR__ . '/modules/base.php';
        require_once __DIR__ . '/modules/helper.php';
        $this->moduleHelper = new LSCacheModulesHelper($this);

        if (!$this->app) {
            $this->app = Factory::getApplication();
        }

        require_once __DIR__ . '/components/base.php';
        require_once __DIR__ . '/components/helper.php';
        $this->componentHelper = new LSCacheComponentsHelper($this);

        $this->purgeObject = (object) array('tags' => array(), 'urls' => array(), 'option' => "", 'idField' => "", 'ids' => array(), 'purgeAll' => false, 'recacheAll' => false);
        $this->purgeObject->autoRecache = $this->settings->get('autoRecache', 0);
    }

    /**
     * No cache for backend pages, for page with error messages, for PostBack request, for logged in user, for expired sessions, for exclude pages, etc.
     *
     * @since   1.0.0
     */
    public function onAfterRoute() {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->pageCachable = true;
        $lang = Factory::getLanguage();
        $lang->load('plg_system_lscache', JPATH_ADMINISTRATOR, null, false, true);

        $app = $this->app;

        $this->menuItem = $app->getMenu()->getActive();
        if ($this->menuItem) {
            if ($this->menuItem->type == 'url') {
                $this->pageCachable = false;
                return;
            }
            $this->cacheTags[] = "com_menus:" . $this->menuItem->id;
            if ($this->menuItem->type == 'alias') {
                $menuParams = $this->menuItem->getParams();
                $menuid = $menuParams->get('aliasoptions');
                $this->cacheTags[] = "com_menus:" . $menuid;
            }
            $this->pageElements = $this->menuItem->query;
            if (!empty($app->input->get('option'))) {
                $this->pageElements["option"] = $app->input->get('option');
				$this->pageElements["view"] = $app->input->get('view');
				$this->pageElements["id"] = $app->input->get('id');
            }
        } else {
            $link = Uri::getInstance()->getQuery();
            if (!empty($link)) {
                $this->pageElements = $this->explode2($link, '&', '=');
            } else if (!empty($app->input->get('option'))) {
                $this->pageElements["option"] = $app->input->get('option');
            }
        }
        //$this->debug(__FUNCTION__ . var_export($this->pageElements,true));


        if (isset($this->pageElements["option"])) {
            $option = $this->pageElements["option"];
            $this->componentHelper->registerEvents($option);
        } else {
            $this->pageCachable = false;
            return;
        }

        if ($this->isAdmin()) {
            $this->pageCachable = false;
            $this->purgeAdmin($option);
        } else {
            // Évaluer sans écrire. La clé calculée ici est provisoire : les gestionnaires
            // de consentement peuplent la session pendant le rendu, si bien que la valeur
            // du routage diffère de la valeur définitive. Émettre les deux plaçait deux
            // Set-Cookie _lscache_vary contradictoires dans la même réponse. L'écriture
            // qui fait foi a lieu en fin de requête, dans onAfterRender().
            $this->checkVary("", false);
            if($app->input->get("lscache_formtoken")=="1"){
                $token = Session::getFormToken();
                $app->input->post->set($token,'1');
                // Also cover consumers validating via Session::checkToken('get'|'request')
                $app->input->get->set($token,'1');
                $app->input->request->set($token,'1');
                // Joomla Input sub-inputs are detached copies of the superglobals,
                // so code reading $_REQUEST/$_POST directly or merging them (e.g.
                // VirtueMart's vRequest::setRouterVars()) would not see the values
                // set above. Patch the superglobals as well.
                $_GET[$token] = '1';
                $_POST[$token] = '1';
                $_REQUEST[$token] = '1';
                // VirtueMart system plugins may have snapshotted the request even
                // earlier (vRequest::setRouterVars() runs once and caches a copy);
                // in that case patch the snapshot too, otherwise vmCheckToken()
                // still reads the stale copy and rejects the request.
                if (class_exists('vRequest', false) && !empty(vRequest::$routerSet)) {
                    vRequest::setVar($token, '1');
                }
                // Cached pages also round-trip the token VALUE as a literal
                // 'token' parameter (e.g. mod_vpprime_minicart posts
                // token=<marker>, then its ajax helper calls
                // vRequest::setVar($_POST['token'], 1) as its own way of
                // satisfying vmCheckToken()). That setVar() call can
                // auto-vivify vRequest::$request BEFORE setRouterVars() ever
                // runs, leaving a minimal snapshot that none of the patches
                // above can reach. Rewriting the marker back to the real
                // session token lets that native mechanism work again (and
                // matches vmCheckToken()'s own token=<hash> fallback).
                if ($app->input->post->get('token', '', 'raw') === 'lscache_formtoken') {
                    $app->input->post->set('token', $token);
                }
                if ($app->input->get->get('token', '', 'raw') === 'lscache_formtoken') {
                    $app->input->get->set('token', $token);
                }
                if ($app->input->request->get('token', '', 'raw') === 'lscache_formtoken') {
                    $app->input->request->set('token', $token);
                }
                if (isset($_POST['token']) && $_POST['token'] === 'lscache_formtoken') {
                    $_POST['token'] = $token;
                }
                if (isset($_GET['token']) && $_GET['token'] === 'lscache_formtoken') {
                    $_GET['token'] = $token;
                }
                if (isset($_REQUEST['token']) && $_REQUEST['token'] === 'lscache_formtoken') {
                    $_REQUEST['token'] = $token;
                }
            }
            // Joomla.request() (core.js) reads csrf.token from the script-options
            // block and sends it as the X-CSRF-Token header; on a cached page that
            // value is the neutralized marker. Substitute the current session token
            // so Session::checkToken()'s header path succeeds.
            if($app->input->server->get('HTTP_X_CSRF_TOKEN', '', 'raw') === 'lscache_formtoken'){
                $token = Session::getFormToken();
                $app->input->server->set('HTTP_X_CSRF_TOKEN', $token);
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
            }

        }


        //avoid some application have expired login session serve
        $session = Factory::getSession();
        $user = Factory::getUser();
        if(($session->get('lscacheLogin')!='1') && !$user->get('guest')){
            $this->pageCachable = false;
        }

        //login esi override and esi always on implement
        if($this->settings->get('loginOverrideESI', 0)  && !$user->get('guest')){
            $this->esiEnabled = $this->settings->get('loginOverrideESI');
        }
        if($this->esiEnabled==2){
            $this->esion=true;
        }

        //avoid article edit form been cached
        if (($option=='com_content') && ($app->input->get('view')=='form' )){
            $this->pageCachable = false;
        }

        if ( isset($this->pageElements["view"]) && ($this->pageElements["view"]=='featured')){
            $this->cacheTags[] = $option . ":featured";
        }

     //if post back, purge current page, disabled in case purge search post back
        if ($this->pageCachable && ($app->input->getMethod() != 'GET')) {
            $this->pageCachable = false;
            if ($this->menuItem && isset($this->menuItem->id) && ($this->settings->get('purgePostBack', 0) == 1) ) {
                $purgeTags = "com_menus:" . $this->menuItem->id;
                $this->lscInstance->purgePublic($purgeTags);
                $session->set('lastPostBack', $this->menuItem->id);
                $this->log();
            }
        } else {
            if($this->menuItem && isset($this->menuItem->id) && ($session->get('lastPostBack')==$this->menuItem->id)){
                $this->pageCachable = false;
                $session->clear('lastPostBack');
            }
        }

        if (!$this->pageCachable) {

        } else if (JDEBUG) {
            $this->pageCachable = false;
        } else if (count($app->getMessageQueue())) {
            $this->pageCachable = false;
        } else if ($app->get('offline', '0')){
            $this->pageCachable = false;
        } else if ($this->isExcluded()) {
            $this->pageCachable = false;
        }

        if (!$this->pageCachable && isset($_SERVER['HTTP_USER_AGENT'])) {
            $info = $_SERVER['HTTP_USER_AGENT'];
            if ($info == 'lscache_runner') {
                $app->close();
            }
        }
    }

    public function onAfterCleanModuleList(&$modules){
        if($this->esiModule!=null){
            $modules = array( $this->esiModule ) ;
            $this->pageCachable = false;
        }
    }

    public function onAfterRenderModule($module, $attribs=[]) {

        if(isset($module->esiRending) && $module->esiRending){
            return;
        }

        // Skip ESI substitution on com_ajax module renders. A module with its
        // own AJAX self-refresh (e.g. mod_vme_wishlist, mod_vme_compare) calls
        // JModuleHelper::renderModule() inside its getAjax() helper to return
        // {html: <fragment>}. Without this guard, $module->content would be
        // replaced by an <esi:include> tag and the JSON-consumer JS would coerce
        // the parsed Document to "[object HTMLDocument]" when injecting it.
        if ($this->app->input->get('option') === 'com_ajax') {
            return;
        }

        if(isset($module->output)){
            $module->content = $module->output;
            return;
        }

        if (!$this->pageCachable) {
            return;
        }

        $tag = $this->moduleHelper->getModuleTags($module);
        $cacheType = $this->getModuleCacheType($module);

        $etag = 'com_modules:' . $module->id;
        if (!empty($module->lscache_tag)) {
            $etag .= ',' . $module->lscache_tag;
        }
        if (!empty($tag)) {
            $etag .= ',' . $tag;
        }

        $device = "desktop";
        if ($this->app->client->mobile) {
            $device = 'mobile';
        }

        if ($cacheType == self::MODULE_ESI) {
            if (!$this->esiEnabled) {
                $this->cacheTags[] = $etag;
            } else if (LITESPEED_ESI_SUPPORT) {
                $tag = 'com_modules:' . $module->id;

                if ($module->lscache_ttl == 0) {
                    $module->lscache_type = 0;
                }

                $language = '';
                if ($module->vary_language) {
                    $language = Factory::getLanguage()->getTag();
                }

                $pageUrl = !empty($module->lscache_pageurl) ? $this->getCurrentPageUrl() : '';
                $url = $this->getESIModuleUrl($module->id, $device, $language, $attribs, $pageUrl);
                
                if ($module->lscache_type == 1) {
                    $module->content = '<esi:include src="' . $url . '" cache-control="public,no-vary" cache-tag="' . $tag . '" />';
                } else if ($module->lscache_type == -1) {
                    $tag = 'public:' . $tag . ',' . $tag;
                    $module->content = '<esi:include src="' . $url . '" cache-control="private,no-vary" cache-tag="' . $tag . '" />';
                } else {
                    $module->content = '<esi:include src="' . $url . '" cache-control="no-cache"/>';
                }

                $this->esion = true;
                return;
            } else if (!LITESPEED_ESI_SUPPORT) {
                $language = $module->vary_language ? Factory::getLanguage()->getTag() : '';
                $pageUrl = !empty($module->lscache_pageurl) ? $this->getCurrentPageUrl() : '';
                $url = $this->getESIModuleUrl($module->id, $device, $language, $attribs, $pageUrl);
                $js = '$.ajax({url: "' . $url .'", success: function(result){' . PHP_EOL ;
                $js .= '    $("#lscache_mod' . $module->id . '").replaceWith(result);' . PHP_EOL ;
                $js .= '}});' .PHP_EOL ;

                $this->esijs[] = $js;
                $module->content = '<div id="lscache_mod' .  $module->id  . '"><div>';
                $this->esion = true;
                return;
            }
        } else if ($cacheType == self::MODULE_EMBED) {
            $this->cacheTags[] = $etag;
        } else if (!empty($tag)) {
            $this->cacheTags[] = $tag;
        }
    }

    public function onContentPrepare($context, &$row, &$params, $page = 0) {
        if (!$this->pageCachable) {
            return;
        }
        //$this->debug(__FUNCTION__ . $context . var_export($row,true) );
        $this->pageElements["content"] = $row;

        if (strpos($context, "mod_") === 0) {
            return;
        }

        if (strpos($context, "text") === 0) {
            return;
        }

        if($context == "com_content.featured"){
            $this->pageElements["context"] = $context;
            return;
        }


        // if already have context ignore category context
        if(in_array($context, self::CATEGORY_CONTEXTS) && isset($this->pageElements["context"]) && ($context!=$this->pageElements["context"])){
            return;
        }

        // if it has category context, override it with no-category context
        if(!in_array($context, self::CATEGORY_CONTEXTS) && isset($this->pageElements["context"]) &&  in_array($this->pageElements["context"], self::CATEGORY_CONTEXTS)){
            $this->pageElements["context"] = $context;
            return;
        }

        if(!isset($this->pageElements["context"])){
            $this->pageElements["context"] = $context;
            return;
        }
    }

    public function onBeforeRender() {
        if ($this->settings->get('beforeRender', 0) == 1) {
            $this->onAfterRender();
            define('LSCACHE_RENDERED',true);
        }
    }

    public function onAfterRender() {
        if (!$this->cacheEnabled) {
            if($this->esion){
                header('X-LiteSpeed-Cache-Control:esi=on');
            }
            return;
        }

        if(defined('LSCACHE_RENDERED')){
            return;
        }

        // getVaryKey() first runs in onAfterRoute, before the request has had any
        // chance to mutate the session. Consent managers write their state late -
        // com_gdpr stores the per category choices in the session from its own AJAX
        // tasks - so the cookie set at route time still describes the previous
        // state. Recompute it now that the request is complete, otherwise the next
        // request is looked up under the stale variant and, being a cache hit, it
        // never reaches PHP again: the visitor stays pinned to that variant.
        if (!$this->isAdmin()) {
            $this->checkVary();
        }

        if ($this->purgeObject->recacheAll) {
            $this->purgeObject->recacheAll = false;
            ignore_user_abort(true);
            set_time_limit(0); // unlimited: URL pre-collection may take time on large sites
            $progressFile = $this->getProgressFile();
            // Résidu de l'ancien emplacement (<= 1.5.4) : plus aucun lecteur, on le nettoie.
            @unlink(JPATH_ROOT . '/cache/lscache_rebuild_progress.json');

            $collected = $this->collectCrawlUrls();
            $crawlList = $collected['urls'];

            file_put_contents($progressFile, json_encode([
                'status'  => empty($crawlList) ? 'error' : 'starting',
                'total'   => count($crawlList),
                'current' => 0,
                'success' => 0,
                'started' => time(),
                'updated' => time(),
                'error'   => $collected['error'],
            ]));

            if (!empty($crawlList)) {
                $pfClosure = $progressFile;
                register_shutdown_function(\Closure::bind(function () use ($pfClosure, $crawlList) {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    } elseif (function_exists('litespeed_finish_request')) {
                        litespeed_finish_request();
                    }
                    try {
                        // enforceDuration=false: le rebuild manuel doit tourner jusqu'au bout,
                        // contrairement au recache automatique synchrone après purge (recacheAction).
                        $this->crawlUrls($crawlList, false, true, false, true);
                    } catch (\Throwable $e) {
                        file_put_contents($pfClosure, json_encode([
                            'status'  => 'error',
                            'error'   => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
                            'started' => time(),
                            'updated' => time(),
                        ]));
                    }
                }, $this, \get_class($this)));
            }

            $this->app->redirect('index.php?option=com_lscache');
        }

        if (!$this->pageCachable) {
            return;
        }

        $httpcode = $this->app->getResponse()->getStatusCode();
        if ($httpcode > 201) {
            $this->log("Http Response Code Not Cachable:" . $httpcode);
            return;
        }

        $headers = $this->app->getHeaders();
        if(isset($headers[0]) && isset($headers[0]['name']) && ($headers[0]['name']=='status') && (strpos($headers[0]['value'],'200')===FALSE) && (strpos($headers[0]['value'],'201')===FALSE)){
            return;
        }

        if (isset($this->pageElements["context"])) {
            $context = $this->pageElements["context"];
            if ($context && in_array($context, self::CATEGORY_CONTEXTS)) {
                $context = 'com_categories.category';
            }
        }

        $option = $this->pageElements["option"];
        if (isset($context)) {
            $option = $this->getOption($context);
        }

        if (isset($this->pageElements["id"])) {
            $id = $this->pageElements["id"];
        }

        if (isset($this->pageElements["content"])) {
            $content = $this->pageElements["content"];
            if ($content && isset($content->id) && !in_array($context, self::CATEGORY_CONTEXTS)) {
                $id = $content->id;
            }
        }

        if (!empty($option)){
            $this->cacheTags[] = 'cmp:' . $option;
        }

        if (empty($option) && !empty($this->menuItem)) {
            if ($this->menuItem && !$this->menuItem->home) {
                return;
            }
        } else if (isset($id) && in_array($option, array('com_content', 'com_contact', 'com_banners', 'com_newsfeed', 'com_categories', 'com_users'))) {
            $this->cacheTags[] = $option . ':' . $id;
        } else if ($this->componentHelper->supportComponent($option)) {
            $this->cacheTags[] = $this->componentHelper->getTags($option, $this->pageElements);
        } else if (isset($content) && $content instanceof Table) {
            $tableName = str_replace('#__', "DB", $content->getTableName());
            $tag = $tableName . ':' . implode('-', $content->getPrimaryKey());
            $this->cacheTags[] = $tag;
        } else {
            $this->cacheTags[] = $option;
        }


        $templateName = $this->app->getTemplate();
        $view = isset($this->pageElements["view"]) ? $this->pageElements["view"] : "default";
        $layout = isset($this->pageElements["layout"]) ? $this->pageElements["layout"] : "default";
        $template = "template:" . implode("/", array($templateName, $option, $view, $layout));
        $this->cacheTags[] = $template;

        $cacheTags = implode(',', $this->cacheTags);
        //$this->debug(__FUNCTION__ . $cacheTags . var_export($this->pageElements,true) );

        $cacheTimeout = $this->settings->get('cacheTimeout', 2000) * 60;
        if ($this->menuItem && $this->menuItem->home) {
            $cacheTimeout = $this->settings->get('homePageCacheTimeout', 2000) * 60;
        }

        $content = $this->app->getBody();
        $token = Session::getFormToken();
        $search = '#<input.*?name="'. $token . '".*?>#';
        $replace = '<input type="hidden" name="lscache_formtoken" value="1">';
        $data = preg_replace($search, $replace, $content, -1, $count);
        // Also neutralize the session token wherever it appears as a quoted JSON/JS
        // string, typically the "joomla-script-options" block (core csrf.token and
        // tokens registered by extensions via addScriptOptions). The cached copy
        // would otherwise serve the seeding session's token to every visitor,
        // silently breaking AJAX calls that read it. The token is a session-specific
        // 32-char hash, so this replacement cannot produce false positives.
        $data = str_replace('"' . $token . '"', '"lscache_formtoken"', $data);
        $this->app->setBody($data);

        if ($cacheTimeout == 0) {
            return;
        }

        $this->lscInstance->config(array("public_cache_timeout" => $cacheTimeout, "private_cache_timeout" => $cacheTimeout));
        $this->lscInstance->cachePublic($cacheTags, $this->esion);
        $this->log();

    }

    private function getOption($context) {
        $parts = explode(".", $context);
        return $parts[0];
    }

    public function onContentAfterSave($context, $row, $isNew=false) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeContent($context, $row);
        $this->purgeAction();
    }

    public function onContentAfterDelete($context, $row) {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeContent($context, $row);
        $this->purgeAction();
    }

    public function onUserAfterSave($user, $isNew=false, $success=true, $msg="") {
        if (!$this->cacheEnabled) {
            return;
        }

        if (!$success) {
            return;
        }

        if ($isNew) {
            return;
        }

        $this->purgeContent("com_users.user", $user);
        $this->purgeAction();
    }

    public function onUserAfterLogin($options) {
        if (!$this->cacheEnabled) {
            return;
        }
        $session = Factory::getSession();
        $session->set('lscacheLogin', '1');

        if (!$this->cacheEnabled) {
            return;
        }

        if ($this->isAdmin()) {
            return;
        }
        $this->lscInstance->checkPrivateCookie();
        $this->checkVary();
        if ($this->esiEnabled) {
            $this->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }

    public function onUserAfterLogout($options) {
        if (!$this->cacheEnabled) {
            return;
        }
        $session = Factory::getSession();
        $session->set('lscacheLogin', '0');

        if (!$this->cacheEnabled) {
            return;
        }

        if ($this->isAdmin()) {
            return;
        }
        $this->checkVary();
        if ($this->esiEnabled) {
            $this->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }

    public function onUserLoginFailure($respond) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->lscInstance->purgePrivate('joomla.login');
        $this->log();
    }

    public function onUserBeforeDelete($user, $success=true, $msg="") {
        if (!$this->cacheEnabled) {
            return;
        }

        if (!$success) {
            return;
        }
        $this->purgeContent("com_users.user", $user);
        $this->purgeAction();
    }

    public function purgeContent($context, $row) {
        if ($this->purgeObject->purgeAll) {
            return;
        }

        if(is_array($row) && ($context != "com_users.user")){
            foreach ($row as $rowitem){
                $this->purgeContent($context, $rowitem);
            }
        }

        if(empty($row)){
            return;
        }

        if(is_array($row)) {
            if(empty($row['id'])) { return; }
        } else {
            if(empty($row->id)){ return; }
        }

        $option = $this->getOption($context);
        $purgeTags = '';

        $menu_contexts = array('com_menus.item', 'com_menus.menu');
        if (in_array($context, $menu_contexts)) {
            $this->purgeObject->tags[] = 'com_menus:' . $row->id;;
            $this->purgeObject->urls[] = 'index.php?Itemid=' . $row->id;
            return;
        }

        if (in_array($context, self::CONTENT_CONTEXTS)) {
            $purgeTags =  $option . ':' . $row->id;
            $this->purgeObject->ids[] = $row->id;
            if (($this->settings->get("autoPurgeArticleCategory", 0) == 1) && $row->catid) {
                $purgeTags .= ',com_categories:' . $row->catid;
                $this->purgeObject->ids[] = $row->catid;
                $category = Table::getInstance('Category');
                $category->load($row->catid);
                if($category->parent_id) {
                    $purgeTags .= ',com_categories:' . $category->parent_id;
                    $this->purgeObject->ids[] = $category->parent_id;
                }
            }
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            $this->purgeObject->idField = 'id';
            return;
        }

        if(isset($row->featured) && $row->featured){
            $purgeTags .= ','. $option . ':featured';
        }

        if ($context == "com_users.user") {
            if (!is_array($row)){
                return;
            }
            $purgeTags = 'com_users,com_users:' . $row["id"];
            $purgeContactsTag = $this->getUserContactTag($row["id"]);
            if(!empty($purgeContactsTag)){
                $purgeTags .= ',' . $purgeContactsTag;
            }
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            $this->purgeObject->idField = 'id';
            $this->purgeObject->ids[] = $row->id;
            return;
        }
        if ($this->componentHelper->supportComponent($option)) {
            $purgeTags = $this->componentHelper->onPurgeContent($option, $context, $row);
            return;
        }

        if ($row && $row instanceof Table) {
            $tableName = str_replace('#__', "DB", $row->getTableName());
            $purgeTags = $tableName . ':' . implode('-', $row->getPrimaryKey());
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            return;
        }

        $this->purgeObject->option = $option;
        $this->purgeObject->tags[] = $option;
    }

    public function onContentChangeState($context, $pks, $value=true) {
        if (!$this->cacheEnabled) {
            return;
        }
        $option = $this->getOption($context);

        if ($option == "com_plugins") {
            foreach ($pks as $pk) {
                $row = Table::getInstance('extension');
                $row->load($pk);
                $row->enabled = $value;
                $this->purgeExtension($context, $row);
            }
        } else if ($option == "com_modules") {
            foreach ($pks as $pk) {
                $row = Table::getInstance('module');
                $row->load($pk);
                $row->published = $value;
                $this->purgeExtension($context, $row);
            }
        } else if ($this->isOptionExcluded($option)) {
            return;
        } else if ($option == "com_content") {
            foreach ($pks as $pk) {
                $row = Table::getInstance('content');
                $row->load($pk);
                $row->state = $value;
                $this->purgeContent($context, $row);
            }
        } else if ($option == "com_categories") {
            foreach ($pks as $pk) {
                $row = Table::getInstance('Category');
                $row->load($pk);
                $row->state = $value;
                $this->purgeContent($context, $row);
            }
        } else {
            $row = (object)array('id'=>0);
            foreach ($pks as $pk) {
                $row->id = $pk;
                $this->purgeContent($context, $row);
            }
        }
        $this->purgeAction();
    }

    public function onExtensionBeforeSave($context, $row, $isNew=false) {
        if (!$this->cacheEnabled) {
            return;
        }

        if ($context == "com_modules.module") {
            $menus = $this->getModuleMenuItems($row->id);
            $this->purgeObject->purgeMenu = $menus;
        }
    }

    public function onExtensionAfterSave($context, $row, $isNew = false) {
        if (!$this->cacheEnabled) {
            return;
        }

        if(isset($row->element) && ($row->element=='com_lscache')){
            $newSetting = json_decode($row->params);
            if($this->settings->get('mobileCacheVary') != $newSetting->mobileCacheVary){
                $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_CHECKHTACCESS'), "warning");
            }
        }

        $this->purgeExtension($context, $row);
        $this->purgeAction();
    }

    public function onExtensionBeforeDelete($context, $row) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeExtension($context, $row);
    }

    public function onExtensionAfterDelete($context, $row) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeAction();
    }

    protected function purgeExtension($context, $row) {
        if ($this->purgeObject->purgeAll) {
            return;
        }


        $option = $this->getOption($context);
        if ($option == "com_plugins") {
            if ($this->settings->get("autoPurgePlugin", 0) == 1) {
                $this->purgeObject->purgeAll = true;
            } else if (!empty($row->element) && !empty($row->folder) && ($row->element == "lscache") && ($row->folder == "system")) {
                $this->purgeObject->purgeAll = true;
            }
            return;
        }

        if ($option == "com_languages") {
            if ($this->settings->get("autoPurgeLanguage", 0) == 1) {
                $this->purgeObject->purgeAll = true;
            }
            return;
        }

        if ($context == "com_templates.style") {
            if ($row->home) {
                $this->purgeObject->purgeAll = true;
                return;
            }

            $purgeTags = array();

            $db = Factory::getDbo();

            $query = $db->createQuery()
                    ->select('id')
                    ->from('#__menu')
                    ->where($db->quoteName('template_style_id') . '=' . (int) $row->id);
            $db->setQuery($query);

            $menus = $db->loadObjectList();

            foreach ($menus as $menu) {
                $purgeTags[] = "com_menus:" . $menu->id;
                $this->purgeObject->urls[] = 'index.php?Itemid=' . $menu->id;
            }
            $this->purgeObject->tags[] = implode(',', $purgeTags);
            return;
        }

        if ($option == "com_modules") {
            $cacheType = $this->getModuleCacheType($row);

            if ($cacheType == self::MODULE_PURGEALL) {
                $this->purgeObject->purgeAll = true;
                return;
            }

            $purgeTags = "com_modules:" . $row->id;

            if ($cacheType == self::MODULE_PURGETAG) {
                $menu = $this->getModuleMenuItems($row->id);
                if (!empty($this->purgeObject->purgeMenu)) {
                    $menu = array_merge($menu, $this->purgeObject->purgeMenu);
                }
                $purgeMenu = array_unique($menu, SORT_NUMERIC);
                foreach ($purgeMenu as $menuid) {
                    $this->purgeObject->urls[] = 'index.php?Itemid=' . $menuid;
                    $purgeTags .= ',com_menus:' . $menuid;
                }
            }
            $this->purgeObject->tags[] = $purgeTags;
            return;
        }

        if ($context == "com_lscache.module") {
            $purgeTags = "com_modules:" . $row->moduleid;
            $this->purgeObject->tags[] = $purgeTags;
            return;
        }

        if ($option == "com_config") {
            if ($row->element == "com_lscache") {
                $this->app->setUserState("lscacheOption","debug");
                $settings = json_decode($row->params);
                $cacheEnabled = $settings->cacheEnabled;
                if (!$cacheEnabled) {
                    $this->purgeObject->purgeAll = true;
                }
            } else {
                $this->purgeObject->tags[] = $row->element;
                $this->purgeObject->option = $row->element;
            }
        }
    }


    protected function purgeAdmin($option ){
        $app = $this->app;
        if (empty($option)){
            return;
        }   else if (($option == "com_templates") && isset($this->pageElements["view"]) && ($this->pageElements["view"] == "template")) {
            $task = $app->input->get('task');
            if (!empty($task) && in_array($task, array("template.save", "template.apply", "template.delete"))) {
                $this->purgeTemplate(true);
                $this->purgeAction();
            } else if (!empty($task)) {
                $this->purgeTemplate(false);
                $this->purgeAction();
            }
        }   else if(($option == "com_plugins") && ($app->input->get('jchtask')=="cleancache")){
                $this->purgeObject->purgeAll = true;
                $this->purgeAction();
        }   else if( ($option == "com_cache") && (!empty($task=$app->input->get('task')))){
            if(in_array($task, array("deleteAll"))  ){
                $this->purgeObject->purgeAll = true;
                $this->purgeAction();
            } else if(in_array($task, array("delete"))){
                $cids = $app->input->get('cid');
                foreach($cids as $cid){
                    $this->purgeObject->tags[] = 'cmp:' . $cid;
                }
                $this->purgeAction();
                $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_PURGEINFORMED'), "message");
            }
        }   else if(($option == "com_content") && (!empty($task=$app->input->get('task')))){
            if(in_array($task, array("articles.featured", "articles.unfeatured")) ){
                $this->purgeObject->option = "com_content";
                $this->purgeObject->tags[] = "com_content";
                $this->purgeObject->idField = "view";
                $this->purgeObject->ids[] = "featured";
                $this->purgeAction();
            }
        }   else if(($option == "com_virtuemart") && ($app->input->get('view') == "calc")){
            // VirtueMart calc rules (discounts/tax) never fire a plugin event on save/delete,
            // unlike products/categories — the form posts to plain index.php so the view/task
            // must be read from input directly, pageElements won't have them (no query string).
            $task = $app->input->get('task', '', 'cmd');
            if (in_array($task, array("save", "apply", "remove", "publish", "unpublish"), true) || str_starts_with($task, 'toggle.published')) {
                $instance = $this->componentHelper->getInstance('com_virtuemart');
                if ($instance instanceof LSCacheComponentVirtueMart) {
                    $instance->purgeCalcRule();
                }
            }
        }

    }

    public function getModuleMenuItems($moduleid) {
        $db = Factory::getDbo();
        $query = $db->createQuery()
                ->select('menuid')
                ->from('#__modules_menu')
                ->where($db->quoteName('moduleid') . '=' . (int) $moduleid)
                ->where($db->quoteName('menuid') . ' > 0');

        $db->setQuery($query);
        $menus = $db->loadColumn();
        return $menus;
    }

    protected function explode2($str, $d1, $d2) {
        $result = array();

        $Parts = explode($d1, trim($str));
        foreach ($Parts as $part1) {
            list( $key, $val ) = explode($d2, trim($part1).$d2);
            $result[urldecode($key)] = urldecode($val);
        }

        return $result;
    }

    protected function implode2(array $arr, $d1, $d2) {
        $arr1 = array();

        foreach ($arr as $key => $val) {
            $arr1[] = urlencode($key) . $d2 . urlencode($val);
        }
        return implode($d1, $arr1);
    }

    public function onExtensionAfterInstall($installer, $eid) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeInstallation($eid, true);
        $this->purgeAction();
    }

    public function onExtensionAfterUpdate($installer, $eid) {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeInstallation($eid);
        $this->purgeAction();
    }

    public function onExtensionBeforeUninstall($eid) {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeInstallation($eid);
        $this->purgeAction();
    }

    public function onLSCacheExpired() {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeObject->purgeAll = true;
        $this->purgeAction();
    }

    public function onExtensionAfterUninstall($eid) {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeAction();
    }

    protected function purgeInstallation($eid, $isNew = false) {
        if ($this->purgeObject->purgeAll) {
            return;
        }

        $extension = $this->getExtension($eid);

        if (!$extension) {
            return;
        }

        if ($isNew && in_array($extension->type, array('template', 'module', 'file', 'component'))) {
            return;
        }

        if (in_array($extension->type, array('language', 'plugin'))) {
            if (($extension->element == "lscache") && ($extension->folder == "system") && (!$isNew)) {
                $this->purgeObject->purgeAll = true;
                return;
            }
            $this->purgeExtension("com_" . $extension->type . 's.extension', $extension);
            return;
        }

        if ($extension->type == "component") {
            if (($extension->element == "com_lscache") && (!$isNew)) {
                $this->purgeObject->purgeAll = true;
                return;
            }
            if (!$this->isOptionExcluded($extension->element)) {
                $this->purgeObject->tags[] = $extension->element;
                $this->purgeObject->option = $extension->element;
            }
            return;
        }

        if ($extension->type == "template") {
            $template = $this->getTemplate($extension->element); {
                $this->purgeExtension("com_templates.style", $template);
            }
            return;
        }

        if ($extension->type == "module") {
            $modules = $this->getModules($extension->element);
            foreach ($modules as $module) {
                $this->purgeExtension("com_modules.module", $module);
            }
            return;
        }
    }

    protected function purgeTemplate($purge = false) {

        if ($this->purgeObject->purgeAll) {
            return;
        }

        $extensionID = $this->pageElements["id"];
        $file = $this->pageElements["file"];
        if ($purge && !empty($file) && !empty($extensionID)) {
            $decoded = base64_decode($file, true);
            if ($decoded === false || str_contains($decoded, '..') || str_contains($decoded, "\0")) {
                $purge = false;
            } else {
                $file = $decoded;
                $elements = explode('/', $file);
                if (count($elements) < 3) {
                    $purge = false;
                } else if ($elements[1] !== "html") {
                    $purge = false;
                }
            }
        }

        if ($purge) {
            if (substr($elements[2], 0, 4) == "mod_") {
                $modules = $this->getModules($elements[2]);
                foreach ($modules as $module) {
                    $this->purgeExtension("com_modules.module", $module);
                }
            } else if ((substr($elements[2], 0, 4) == "com_") && (count($elements) == 5)) {
                $extension = $this->getExtension($extensionID);
                $layout = $elements[4];
                $layout = explode(".", $layout)[0];
                $layout = explode("_", $layout)[0];
                $this->purgeObject->tags[] = "template:" . implode("/", array($extension->element, $elements[2], $elements[3], $layout));
                $this->purgeObject->option = $elements[2];
                $this->purgeObject->idField = 'view';
                $this->purgeObject->ids[] = $elements[3];
            } else {
                $purge = false;
            }
        }

        if (!$purge) {
            $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_TEMPLATEPURGEALL'), "message");
        }
    }

    private function getModule($moduleid) {

        $db = Factory::getDbo();
        $query = $db->createQuery()
                ->select('*')
                ->from('#__modules')
                ->where('id=' . $moduleid);
        $db->setQuery($query);
        $modules = $db->loadObjectList();
        if (count($modules) < 1) {
            return FALSE;
        } else {
            return $modules[0];
        }
    }

    public function getModuleCacheType($module) {

        $db = Factory::getDbo();

        if (!empty($module->cache_type)) {
            return $module->cache_type;
        }

        $query1 = $db->createQuery()
                ->select('MIN(menuid)')
                ->from('#__modules_menu')
                ->where($db->quoteName('moduleid') . '=' . (int) $module->id);
        $db->setQuery($query1);
        $pages = (int) $db->loadResult();
        $module->pages = $pages;
        if ($pages === null) {
            $module->cache_type = self::MODULE_EMBED;
        } else if (empty($module->position)) {
            $module->cache_type = self::MODULE_EMBED;
        } else if ($pages <= 0) {
            $module->cache_type = self::MODULE_PURGEALL;
        } else {
            $module->cache_type = self::MODULE_PURGETAG;
        }

        $query = $db->createQuery()
                ->select('*')
                ->from('#__modules_lscache')
                ->where($db->quoteName('moduleid') . '=' . (int) $module->id);
        $db->setQuery($query);
        $rows = $db->loadObjectList();
        if (count($rows) > 0) {
            $module->original_cache_type = $module->cache_type;
            $module->cache_type = self::MODULE_ESI;
            $module->lscache_type = $rows[0]->lscache_type;
            $module->lscache_ttl = $rows[0]->lscache_ttl;
            $module->module_type = $rows[0]->module_type;
            $module->vary_language = $rows[0]->vary_language;
        } else if (($this->settings->get('loginESI', 1) == 1) && ((stripos($module->module, 'login') !== FALSE) || (stripos($module->title, 'login') !== FALSE))) {
            $module->original_cache_type = $module->cache_type;
            $module->cache_type = self::MODULE_ESI;
            $module->lscache_type = -1;
            $module->lscache_ttl = 14;
            $module->lscache_tag = 'joomla.login';
            $module->module_type = 0;
            $module->vary_language = 0;
        }

        return $module->cache_type;
    }

    protected function getModules($element) {

        $db = Factory::getDbo();

        $query = $db->createQuery()
                ->select('*')
                ->from('#__modules')
                ->where($db->quoteName('module') . '=' . $db->quote($element))
                ->where($db->quoteName('published') . '=1');

        $db->setQuery($query);

        $modules = $db->loadObjectList();
        return $modules;
    }

    protected function getTemplate($element) {
        $db = Factory::getDbo();

        $query = $db->createQuery()
                ->select('*')
                ->from('#__template_styles')
                ->where($db->quoteName('template') . '=' . $db->quote($element));

        $db->setQuery($query);

        $template = $db->loadObject();
        return $template;
    }

    protected function getUserContactTag($uid){
        $db = Factory::getDbo();
        $query = $db->createQuery()
            ->select('c.id')
            ->from($db->quoteName('#__contact_details', 'c'))
            ->where('c.published = 1')
            ->where('c.user_id = ' . (int) $uid);
        $db->setQuery($query);
        $contact_ids = $db->loadColumn();

        return implode(',' ,array_map(function($value) { return 'com_contact:' . $value ;}, $contact_ids ));
    }

    protected function getExtension($eid) {
        $db = Factory::getDbo();

        $query = $db->createQuery()
                ->select('*')
                ->from('#__extensions')
                ->where($db->quoteName('extension_id') . '=' . (int) $eid);
        $db->setQuery($query);

        $extension = $db->loadObjectList();

        if (count($extension) > 0) {
            return $extension[0];
        } else {
            return null;
        }
    }

    /**
     * a simple debug function only for development usage
     *
     * @since    0.1
     */
    public function debug($action) {
        $debugFile = "lscache.log";

        date_default_timezone_set("America/New_York");
        list( $usec, $sec ) = explode(' ', microtime());

        if (!defined("LOG_INIT")) {
            define("LOG_INIT", true);
            file_put_contents($debugFile, "\n\n" . date('m/d/y H:i:s') . substr($usec, 1, 4), FILE_APPEND);
        }
        file_put_contents($debugFile, date('m/d/y H:i:s') . substr($usec, 1, 4) . "\t" . $action . "\n", FILE_APPEND);
    }

    /**
     * log if logLevel below settings
     *
     * @since    1.1.0
     */
    public function log($content = null, $logLevel = Log::INFO) {
        if ($content == null) {
            if (!$this->lscInstance) {
                return;
            }

            $content = $this->lscInstance->getLogBuffer();
        }

        //$this->debug($content);

        $logLevelSetting = $this->settings->get('logLevel', -1);
        if ($logLevelSetting < 0) {
            return;
        } else if(($logLevelSetting==Log::DEBUG) && ($this->app->getUserState('lscacheOption',"")=="debug")){

        } else if ($logLevel > $logLevelSetting) {
            return;
        }

        $link = Uri::getInstance();
        if($link) { $content .= '   ' . $link; }

        Log::add($content, $logLevel, 'LiteSpeedCache');
    }

    protected function isOptionExcluded($option) {
        $excludeOptions = $this->settings->get('excludeOptions', array());
        $excludeOptions[] = "com_ajax";
        if ($excludeOptions && $option && in_array($option, (array) $excludeOptions)) {
            return true;
        }
        return false;
    }

    /**
     * Check if the page is excluded from the cache or not.
     *
     * @return   boolean  True if the page is excluded else false
     *
     * @since    0.1
     */
    protected function isExcluded() {
        $option = $this->pageElements["option"];
        if ($option && $this->isOptionExcluded($option)) {
            return true;
        }

        $excludeMenuItems = $this->settings->get('excludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('excludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }

        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = Uri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }

            if ((strpos($exclusion, '/') !== FALSE) && (strpos($exclusion, '\/') === FALSE)) {
                $exclusion = str_replace('/', '\/', $exclusion);
            }

            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }

    protected function isLoginExcluded() {
        $excludeMenuItems = $this->settings->get('loginExcludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems, true)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('loginExcludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }

        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = Uri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }

            if ((strpos($exclusion, '/') !== FALSE) && (strpos($exclusion, '\/') === FALSE)) {
                $exclusion = str_replace('/', '\/', $exclusion);
            }

            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }

    //ESI Render;
    public function onAfterDispatch() {
        $app = $this->app;
        $option = $app->input->get('option');
        if ($option != "com_lscache") {
            return;
        }

        if ($this->isAdmin()) {
            return;
        }

        $ipPass = true;
        $adminIPs = $this->settings->get('adminIPs');
        if (!empty($adminIPs)) {
            $ip = $this->getVisitorIP();
            $serverIP = $_SERVER['SERVER_ADDR'];
            if((strpos($adminIPs, $ip)===FALSE) && ($ip!=="127.0.0.1") && ($ip!==$serverIP)){
                $ipPass = false;
            }
        }

        $cleancache = $app->input->get('cleanCache');
        if($ipPass && (!empty($cleancache))) {
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            if ($cleancache !== $cleanWords) {
                http_response_code(403);
                $app->close();
                return;
            }

            $tags = $app->input->get('tags');
            if (!empty($tags)) {
                $purgeTags = base64_decode($tags);
                $this->lscInstance->purgePublic($purgeTags);
            } else {
                $this->lscInstance->purgeAllPublic();
                echo "<html><body><h2>All LiteSpeed Cache Purged!</h2></body></html>";
            }
            $this->log();
            $app->close();
            return;
        }

        $recache = $app->input->get('recache');
        if ($ipPass && (!empty($recache))) {
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            if ($recache !== $cleanWords) {
                http_response_code(403);
                $app->close();
                return;
            }
            $this->recacheAction(true,true);
            $app->close();
            return;
        }


        if (!$this->esiEnabled) {
            http_response_code(403);
            $app->close();
            return;
        }

        $moduleid = $this->app->input->getInt('moduleid', -1);
        if ($moduleid == -1) {
            http_response_code(403);
            $app->close();
            return;
        }

        if ($moduleid == -2) {
            $this->esiTokenForm();
            $app->close();
            return;
        }

        $module = $this->getModule($moduleid);
        if (!$module) {
            http_response_code(403);
            $app->close();
            return;
        }

        $tag1 = $this->moduleHelper->getModuleTags($module);
        $cacheType = $this->getModuleCacheType($module);
        if ($cacheType != self::MODULE_ESI) {
            http_response_code(403);
            $app->close();
            return;
        }

        $attribs = array();
        if (isset($_GET['attribs'])) {
            $attrib = $_GET['attribs'];
            $attribs = $this->explode2($attrib, ';', ',');
        }

        $requestedMenuid = $app->input->getInt('Itemid', 0);
        $menuid = $app->getMenu()->getDefault()->id;
        $pageContext = array();

        if ($requestedMenuid > 0) {
            $menuid = $requestedMenuid;
            $pageContext = $this->getMenuPageContext($menuid);
            $uri = Uri::getInstance();
            $uri->setPath("");
            $uri->setQuery("");
            $uri->setFragment("");
            $url = Route::_('index.php?Itemid=' . $menuid, false);
            $uri->parse($url);
        } else if (($module->pages > 0) && (isset($_SERVER['HTTP_REFERER']))) {
            $uri = Uri::getInstance();
            $uri->setPath("");
            $uri->setQuery("");
            $uri->setFragment("");
            $uri->parse($_SERVER['HTTP_REFERER']);

            $router = $app->getRouter();
            $uri1 = clone $uri;
            $result = $router->parse($uri1);
            if (is_array($result)) {
                $pageContext = $result;
            }
            if (isset($result['Itemid'])) {
                $menuid = $result['Itemid'];
            }
        } else if (($module->pages > 0) && ($menuItems = $this->getModuleMenuItems($moduleid)) && (!in_array($menuid, $menuItems))) {
            $menuid = $menuItems[0];
            $pageContext = $this->getMenuPageContext($menuid);
            $uri = Uri::getInstance();
            $uri->setPath("");
            $uri->setQuery("");
            $uri->setFragment("");
            $url = Route::_('index.php?Itemid=' . $menuid, FALSE);
            $uri->parse($url);
        } else {
            $pageContext = $this->getMenuPageContext($menuid);
            $root = Uri::root();
            $config = Factory::getConfig();
            $sef_rewrite = $config->get('sef_rewrite');
            if ($sef_rewrite != 1) {
                $root .= 'index.php';
            }

            $uri = Uri::getInstance();
            $uri->setPath("");
            $uri->setQuery("");
            $uri->setFragment("");
            $uri->parse($root);
        }

        $pageContext['Itemid'] = $menuid;
        $this->applyESIPageContext($pageContext);
        $app->input->set('Itemid', $menuid);
        $app->getMenu()->setActive($menuid);

        $lang = Factory::getLanguage();
        $language = $app->input->get('language');
            // Garde defensive : valider le tag avant usage. getESIModuleUrl() construit
            // desormais la query depuis un tableau, la duplication 'fr-FRfr-FR' est donc
            // impossible a produire - mais elle peut subsister dans les URL ESI figees
            // au sein de pages mises en cache avant ce refactor.
        if ($language && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language) && ($language != $lang->getTag())) {
            if (method_exists($lang, 'setLanguage')) {
                $lang->setLanguage($language);
                $lang->load();
            } else {
                $lang->load('', JPATH_SITE, $language, true, false);
            }
        }
        $moduleLanguage = strtolower($module->module);
        $lang->load($moduleLanguage, JPATH_SITE);

        $oldContent = $module->content;
        $module->esiRending = true;
        $content = ModuleHelper::renderModule($module, $attribs);
        if ($content) {
            $tag = "com_modules:" . $module->id;
            if ($tag1 !== "") {
                $tag .= ',' . $tag1;
            }

            if (!empty($module->lscache_tag)) {
                $tag .= ',' . $module->lscache_tag;
            }

            $this->moduleHelper->afterESIRender($module, $content);

            if ($module->lscache_type == 0) {
                header('X-LiteSpeed-Cache-Control: no-cache');
            } else {
                $cacheTimeout = $module->lscache_ttl * 60;
                $this->lscInstance->config(array("public_cache_timeout" => $cacheTimeout, "private_cache_timeout" => $cacheTimeout));
                if ($module->lscache_type == 1) {
                    $this->lscInstance->cachePublic($tag);
                    $this->log();
                } else if ($module->lscache_type == -1) {
                    $this->lscInstance->checkPrivateCookie();
                    $this->lscInstance->cachePrivate($tag, $tag);
                    $this->log();
                }
            }

            if ($module->module_type == 0) {
                echo $content;
                $app->close();
            } else {
                $module->output = $content;
                $module->content = $oldContent;
                $module->position='esi';
                $module->menuid=0;
                $this->esiModule = $module;
                $this->app->setTemplate('esitemplate');
            }
        }
    }

    private function getCurrentMenuItemId() {
        if ($this->menuItem && isset($this->menuItem->id)) {
            return (int) $this->menuItem->id;
        }

        return (int) $this->app->input->getInt('Itemid', 0);
    }

    private function getCurrentPageUrl() {
        $uri = Uri::getInstance();
        $pageUrl = $uri->toString(array('path', 'query'));

        if ($pageUrl === '') {
            return '/';
        }

        return $pageUrl;
    }

    private function getMenuPageContext($menuid) {
        $menuid = (int) $menuid;
        if ($menuid <= 0) {
            return array();
        }

        $menu = $this->app->getMenu()->getItem($menuid);
        if (!$menu) {
            return array('Itemid' => $menuid);
        }

        $context = array();
        if (isset($menu->query) && is_array($menu->query)) {
            $context = $menu->query;
        } else if (isset($menu->query) && is_object($menu->query)) {
            $context = get_object_vars($menu->query);
        }

        $context['Itemid'] = $menuid;
        return $context;
    }

    private function applyESIPageContext(array $pageContext) {
        $reserved = array(
            'moduleid' => true,
            'device' => true,
            'attribs' => true,
            'cleanCache' => true,
            'recache' => true,
        );

        foreach ($pageContext as $key => $value) {
            if ($key === '' || isset($reserved[$key])) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $this->app->input->set($key, $value);
            }
        }
    }

    public function getESIPageUrlParam() {
        return rawurldecode($this->app->input->get('pageurl', '', 'raw'));
    }

    private function getESIModuleUrl($moduleid, $device, $language = '', array $attribs = array(), $pageUrl = '') {
        $params = array(
            'option' => 'com_lscache',
            'moduleid' => (int) $moduleid,
            'device' => $device,
        );

        $menuid = $this->getCurrentMenuItemId();
        if ($menuid > 0) {
            $params['Itemid'] = $menuid;
        }

        if ($language !== '') {
            $params['language'] = $language;
        }

        if ($pageUrl !== '') {
            $params['pageurl'] = rawurlencode($pageUrl);
        }

        return 'index.php?' . $this->implode2($params, '&', '=') . $this->getModuleAttribs($attribs);
    }

    private function getModuleAttribs(array $attribs) {
        if ($attribs && count($attribs) > 0) {
            $attrib = $this->implode2($attribs, ';', ',');
            $result = '&attribs=' . $attrib;
            return $result;
        }
        return '';
    }

    /**
     * Le visiteur porte-t-il une décision de consentement enregistrée ?
     *
     * Les noms de cookies viennent de la configuration plutôt que du code : ce plugin
     * n'a pas à connaître le gestionnaire de consentement installé. Champ vide = on ne
     * sait pas distinguer un visiteur par défaut d'un visiteur décidé, on fait donc
     * varier dans tous les cas. L'exactitude du contenu prime sur le taux de hit.
     */
    private function hasConsentDecision() {
        $configured = (string) $this->settings->get('consentCookies', 'cookieconsent_status');
        $names      = array_filter(array_map('trim', explode(',', $configured)), 'strlen');

        // Aucun nom exploitable - champ vide, blancs, virgules seules : on ne sait pas
        // distinguer un visiteur par défaut d'un visiteur décidé, on fait donc varier dans
        // tous les cas. L'exactitude du contenu prime sur le taux de hit.
        if (empty($names)) {
            return true;
        }

        foreach ($names as $name) {
            if (!empty($_COOKIE[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     *  Collect the cache key parts published by Joomla page cache plugins.
     *
     *  plg_system_cache dispatches onPageCacheGetKey so extensions can declare
     *  what makes their output differ between visitors. Honouring the same
     *  event here lets those extensions vary the LiteSpeed cache too, the GDPR
     *  consent state (com_gdpr, "auto manage caching" set to Advanced) being
     *  the typical case: without it a single cached copy is shared by visitors
     *  who accepted and refused cookies alike.
     */
    private function getPageCacheVary() {
        // Coupure explicite, pour un site qui a VERIFIE que sa sortie HTML ne depend pas
        // de l'etat de consentement. Le vary fragmente alors le cache en copies identiques :
        // chaque variante doit etre rechauffee separement alors qu'aucune ne differe. La
        // condition est dans la description du reglage, et elle est serieuse - un gestionnaire
        // de consentement qui se mettrait a filtrer le HTML cote serveur rendrait ce reglage
        // dangereux du jour au lendemain.
        if (!$this->settings->get('pagecacheVary', 1)) {
            return '';
        }

        if (!class_exists('Joomla\\CMS\\Event\\PageCache\\GetKeyEvent')) {
            return '';
        }

        // Un visiteur qui n'a rien décidé voit la sortie par défaut du site, identique
        // pour tous : une seule copie partagée est exacte. Faire varier sur cet état
        // exilait dans un compartiment jamais pré-chauffé toute première visite, le
        // crawler du rebuild et chaque audit PageSpeed — qui arrivent tous sans cookie
        // et ne font qu'une seule vue, donc un miss garanti sur une page pourtant
        // identique à la copie partagée. Seul un visiteur ayant réellement tranché
        // obtient sa propre variante.
        if (!$this->hasConsentDecision()) {
            return '';
        }

        try {
            $dispatcher = $this->getDispatcher();
            PluginHelper::importPlugin('pagecache', null, true, $dispatcher);
            $parts = $dispatcher->dispatch('onPageCacheGetKey', new GetKeyEvent('onPageCacheGetKey'))
                                ->getArgument('result', array());
        } catch (\Throwable $e) {
            // A third party listener must never be able to break page delivery.
            return '';
        }

        if (empty($parts)) {
            return '';
        }

        return substr(md5(serialize($parts)), 0, 12);
    }

    private function getVaryKey() {
        //$lang = Factory::getLanguage();
        //. $lang->getDefault();
        // . $lang->getTag();

        if ($this->app->client->mobile && ($this->settings->get('mobileCacheVary', 0) == 1)) {
            $this->vary['device'] = 'mobile';
        } else if (isset($this->vary['device'])) {
            unset($this->vary['device']);
        }

        $user = Factory::getUser();
        if (!$user->get('guest')) {
            $this->lscInstance->checkPrivateCookie();
            $loginCachable = $this->settings->get('loginCachable', 0) == 1 ? true : false;
            if ($loginCachable) {
                if ($this->settings->get('loginCacheVary', 0) == 2) {
                    $groups = $user->get('groups');
                    if(count($groups)>1){
                        $this->pageCachable = false;
                        $this->vary['login'] = 'true';
                    } else if(count($groups)==1){
                        $this->vary['login'] = reset($groups);
                    }
                } else if ($this->settings->get('loginCacheVary', 0) == 1) {
                    $this->vary['login'] = 'true';
                } else if (!empty($this->settings->get('loginExcludeMenus'))) {
                    $this->vary['login'] = 'true';
                } else if (!empty($this->settings->get('loginExcludeURLs'))) {
                    $this->vary['login'] = 'true';
                } else if (isset($this->vary['login'])) {
                    unset($this->vary['login']);
                }

                if ($this->isLoginExcluded()) {
                    $this->pageCachable = false;
                }
            } else {
                $this->vary['login'] = 'true';
                $this->pageCachable = false;
            }
        } else if (isset($this->vary['login'])) {
            unset($this->vary['login']);
        }

        $pageCacheVary = $this->getPageCacheVary();
        if ($pageCacheVary !== '') {
            $this->vary['pagecache'] = $pageCacheVary;
        } else if (isset($this->vary['pagecache'])) {
            unset($this->vary['pagecache']);
        }

        if (count($this->vary)) {
            ksort($this->vary);
            $varyKey = $this->implode2($this->vary, ',', ':');
            return $varyKey;
        } else {
            return '';
        }
    }

    /**
     *
     *  set or delete cache vary cookie, if cookie need no change return true;
     *
     * @since   0.1
     */
    /**
     * Compare la clé de variance du visiteur à celle que porte son cookie.
     *
     * $writeCookie permet d'évaluer sans écrire. getVaryKey() a des effets de bord
     * dont le pipeline dépend (remplissage de $this->vary, pageCachable, cookie
     * privé), il faut donc toujours l'appeler tôt — mais une seule écriture, la
     * dernière, doit atteindre le navigateur : voir onAfterRoute().
     */
    private function checkVary($value = "", $writeCookie = true) {

        if ($value == "") {
            $value = $this->getVaryKey();
        }

        $inputCookie = $this->app->input->cookie;

        if ($value == "") {
            if (isset($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE])) {
                if ($writeCookie) {
                    $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, null, time() - 1, '/');
                }
                return false;
            }
            return true;
        }

        if (!isset($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE])) {
            if ($writeCookie) {
                $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0, '/');
            }
            return false;
        }

        if ($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE] != $value) {
            if ($writeCookie) {
                $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0, '/');
            }
            return false;
        }

        return true;
    }

    /**
     * Emplacement du fichier de suivi du rebuild manuel.
     *
     * Volontairement dans le tmp_path configuré de Joomla et non dans /cache : le dossier
     * cache est vidé régulièrement (com_cache, tâches planifiées, scripts de maintenance)
     * et emportait l'état du rebuild en cours avec lui.
     */
    private function getProgressFile() {
        $tmp = (string) $this->app->get('tmp_path');
        if (($tmp === '') || (!is_dir($tmp)) || (!is_writable($tmp))) {
            $tmp = JPATH_ROOT . '/tmp';
        }
        return rtrim($tmp, '/\\') . '/lscache_rebuild_progress.json';
    }

    public function onAjaxLscache() {
        if (!$this->isAdmin()) {
            return ['status' => 'idle'];
        }
        $progressFile = $this->getProgressFile();

        if ($this->app->getInput()->getInt('dismiss', 0) === 1) {
            @unlink($progressFile);
            return ['status' => 'idle'];
        }

        if (!file_exists($progressFile)) {
            return ['status' => 'idle'];
        }
        $json = @json_decode(@file_get_contents($progressFile), true);
        if (!is_array($json)) {
            return ['status' => 'idle'];
        }
        // Un état terminal (completed/error) reste affiché indéfiniment jusqu'à ce que
        // l'admin le consulte (dismiss) ou qu'un nouveau rebuild écrase le fichier : un
        // rebuild peut durer plusieurs heures et personne ne regarde l'écran au moment
        // précis où il se termine.
        //
        // Un crawl encore annoncé actif mais qui n'écrit plus est un processus mort : il
        // tourne détaché après litespeed_finish_request() et peut être tué à tout moment
        // par le watchdog LSAPI/PHP-FPM, sans jamais pouvoir écrire son état final. On le
        // signale ('stalled') au lieu de laisser la barre tourner dans le vide.
        $lastUpdate = $json['updated'] ?? ($json['started'] ?? null);
        if ((!in_array($json['status'] ?? '', ['completed', 'error'], true))
            && ($lastUpdate !== null)
            && ((time() - $lastUpdate) > self::REBUILD_STALE_SECONDS)) {
            $json['status']  = 'stalled';
            $json['stalled'] = time() - $lastUpdate;
        }
        return $json;
    }

    /**
     * Collecte et pré-route les URLs à réchauffer.
     *
     * Le pré-routage a lieu ici, tant que Joomla est complètement initialisé :
     * getSiteMap() et Route::link() ont besoin du routeur ET de la session, dont le
     * handler de shutdown ne dispose plus (en-têtes déjà envoyés).
     *
     * @return  array  ['urls' => string[], 'error' => string|null]
     */
    private function collectCrawlUrls() {
        try {
            $menus   = $this->getSiteMap();
            $rawList = array_column($menus, 'path');

            // Joomla5 a reçu de l'amont un champ « recacheComponents » multiple, alors que le
            // code lit historiquement « recacheComponent » au singulier. Sans repli le réglage
            // reste sans effet, et le formulaire affiche « Please Select » puisque la valeur
            // enregistrée vit sous l'ancienne clé : un simple enregistrement de la config
            // suffirait alors à retirer silencieusement les URLs du composant du crawl.
            // On teste donc le vide, pas seulement l'absence de clé.
            $components = $this->settings->get('recacheComponents', null);
            if (empty($components)) {
                $components = $this->settings->get('recacheComponent', false);
            }

            $menuCount = count($rawList);
            $resolved  = array();
            foreach ((array) $components as $component) {
                if (empty($component)) {
                    continue;
                }
                $comUrls = $this->componentHelper->getComMap($component);
                $resolved[$component] = count($comUrls);
                $rawList = array_merge($comUrls, $rawList);
            }
        } catch (\Throwable $e) {
            return array(
                'urls'       => array(),
                'error'      => Text::sprintf('COM_LSCACHE_ERR_URL_COLLECTION', $e->getMessage())
                                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
                'menuCount'  => 0,
                'compCount'  => 0,
                'components' => array(),
            );
        }

        $crawlList    = array();
        $failedRoute  = 0;
        $failedBucket = 0;
        $firstFailure = null;
        foreach ($rawList as $path) {
            try {
                // xhtml=false → pas d'encodage des & en &amp; (crucial pour curl)
                $routed = Route::link('site', $path, false);
                if (strpos($routed, '/component') === 0) {
                    $routed = '/' . $path;
                }
                // Quand sef_rewrite est actif, la regle du routeur qui retire « index.php/ »
                // du chemin n'est pas toujours attachee hors requete web. Ce prefixe est alors
                // de trop : la page est servie sur l'URL propre, et rechauffer /index.php/x ne
                // met pas /x en cache. En requete web le routeur l'a deja retire, sans effet.
                if ($this->app->get('sef_rewrite')) {
                    $routed = preg_replace('#^(/?)index\.php/#', '$1', $routed);
                }
                if ((strpos($routed, '[') !== false) && (strpos($routed, ']') !== false)) {
                    $pos = strpos($routed, '?');
                    if ($pos === false) {
                        $failedBucket++;
                        continue;
                    }
                    $routed = substr($routed, 0, $pos);
                }
                $crawlList[] = $routed;
            } catch (\Throwable $e) {
                // Ne plus avaler l'echec en silence : c'est ici que des milliers d'URLs
                // peuvent disparaitre sans que rien ne le signale.
                $failedRoute++;
                if ($firstFailure === null) {
                    $firstFailure = get_class($e) . ' : ' . $e->getMessage()
                                  . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']'
                                  . ' pour ' . $path;
                }
                continue;
            }
        }

        return array(
            'urls'       => $crawlList,
            'error'      => empty($crawlList) ? Text::_('COM_LSCACHE_ERR_NO_URLS') : null,
            'menuCount'    => $menuCount,
            'compCount'    => count($rawList) - $menuCount,
            'components'   => $resolved,
            'failedRoute'  => $failedRoute,
            'failedBucket' => $failedBucket,
            'firstFailure' => $firstFailure,
        );
    }

    /**
     * Un crawl est-il déjà en cours ? Retourne son âge en secondes, sinon null.
     *
     * Sans ce garde-fou, un cron qui repasse pendant qu'une reconstruction tourne en
     * lancerait une seconde en parallèle et les deux se disputeraient le fichier de suivi.
     * Un crawl qui n'écrit plus depuis le seuil d'inactivité est considéré mort : il ne
     * doit pas bloquer indéfiniment les relances.
     */
    private function runningCrawlAge() {
        $file = $this->getProgressFile();
        if (!file_exists($file)) {
            return null;
        }

        $json = @json_decode(@file_get_contents($file), true);
        if ((!is_array($json)) || (!in_array($json['status'] ?? '', array('starting', 'running'), true))) {
            return null;
        }

        $last = $json['updated'] ?? ($json['started'] ?? null);
        if ($last === null) {
            return null;
        }

        $age = time() - $last;
        return ($age > self::REBUILD_STALE_SECONDS) ? null : $age;
    }

    /**
     * Taux de couverture reel du cache, mesure par echantillonnage.
     *
     * LiteSpeed n'expose aucune API interrogeable depuis PHP : le seul signal disponible
     * est l'en-tete X-LiteSpeed-Cache de chaque reponse. On echantillonne donc la liste a
     * pas regulier - et non au hasard, pour que deux mesures soient comparables - et on
     * compte les hit. Le decoupage en tranches est le point important : si le cache evince
     * faute de place, les URLs du debut de liste seront froides et celles de la fin
     * chaudes, ce qu'un taux global masquerait.
     *
     * Les requetes n'utilisent pas newCrawlHandle() : elles ne doivent RIEN rechauffer,
     * sinon la mesure fausserait ce qu'elle observe.
     *
     * @return  array
     */
    private function checkCacheCoverage($urls, $sample = 100) {
        $count = count($urls);
        if ($count < 1) {
            return array('sampled' => 0, 'hit' => 0, 'miss' => 0, 'bands' => array());
        }

        $sample = max(1, min((int) $sample, $count));
        $step   = $count / $sample;
        $root   = Uri::getInstance()->toString(array('scheme', 'host', 'port'));

        $bandCount = min(5, $sample);
        $bands     = array_fill(0, $bandCount, array('hit' => 0, 'miss' => 0, 'from' => 0, 'to' => 0));
        $hit = 0;
        $miss = 0;

        for ($i = 0; $i < $sample; $i++) {
            $index = (int) floor($i * $step);
            $url   = $urls[$index];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->absoluteCrawlUrl($root, $url));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; lscache_probe)');
            $response = curl_exec($ch);

            $isHit = (is_string($response)
                && preg_match('/^x-litespeed-cache:\s*hit/mi', $response) === 1);

            $band = min($bandCount - 1, (int) floor($i / max(1, $sample / $bandCount)));
            if ($isHit) {
                $hit++;
                $bands[$band]['hit']++;
            } else {
                $miss++;
                $bands[$band]['miss']++;
            }
            $bands[$band]['from'] = $bands[$band]['from'] ?: $index + 1;
            $bands[$band]['to']   = $index + 1;
        }

        return array('sampled' => $sample, 'total' => $count, 'hit' => $hit, 'miss' => $miss, 'bands' => $bands);
    }

    /**
     * Point d'entrée du crawl en ligne de commande — voir cli/rebuild.php.
     *
     * Le rebuild lancé depuis l'admin tourne dans un processus détaché après
     * litespeed_finish_request(), à la merci du watchdog LSAPI/PHP-FPM. En CLI il n'y a
     * ni watchdog ni limite de temps : c'est la voie fiable dès que la reconstruction
     * complète dépasse quelques minutes.
     *
     * Écrit dans le même fichier de suivi que le bouton admin, donc la carte de
     * progression affiche un rebuild CLI sans rien avoir à changer.
     */
    public function onLSCacheRebuildCli($limit = 0, $dryRun = false, $check = 0, $cookieHeader = '') {
        if (PHP_SAPI !== 'cli') {
            return array('status' => 'error', 'error' => 'onLSCacheRebuildCli is CLI only');
        }

        if (!$this->cacheEnabled) {
            return array('status' => 'error', 'error' => Text::_('COM_LSCACHE_PLUGIN_TURNONFIRST'));
        }

        if (!function_exists('curl_version')) {
            return array('status' => 'error', 'error' => Text::_('COM_LSCACHE_PLUGIN_CURLNOTSUPPORT'));
        }

        $age = $this->runningCrawlAge();
        if ($age !== null) {
            return array('status' => 'busy', 'error' => Text::sprintf('COM_LSCACHE_ERR_CRAWL_BUSY', $age));
        }

        $collected = $this->collectCrawlUrls();
        $crawlList = $collected['urls'];

        $limit = (int) $limit;
        if (($limit > 0) && (count($crawlList) > $limit)) {
            $crawlList = array_slice($crawlList, 0, $limit);
        }

        if ($check > 0) {
            $stats = $this->checkCacheCoverage($crawlList, $check);
            $stats['status'] = 'coverage';
            return $stats;
        }

        if ($dryRun) {
            return array(
                'status'     => 'dry-run',
                'total'      => count($crawlList),
                'urls'       => $crawlList,
                'error'      => $collected['error'],
                'menuCount'  => $collected['menuCount'],
                'compCount'  => $collected['compCount'],
                'components'   => $collected['components'],
                'sef'          => (int) $this->app->get('sef', 0),
                'sefRewrite'   => (int) $this->app->get('sef_rewrite', 0),
                'failedRoute'  => $collected['failedRoute'] ?? 0,
                'failedBucket' => $collected['failedBucket'] ?? 0,
                'firstFailure' => $collected['firstFailure'] ?? null,
            );
        }

        if (empty($crawlList)) {
            return array('status' => 'error', 'total' => 0, 'error' => $collected['error']);
        }

        // enforceDuration=false : comme le rebuild manuel, un run CLI va jusqu'au bout.
        // Un plantage doit laisser un etat terminal : sinon le fichier reste sur « running »
        // et le verrou anti-cumul bloque les relances jusqu'au seuil d'inactivite.
        try {
            $this->crawlUrls($crawlList, false, true, false, true, $cookieHeader);
        } catch (\Throwable $e) {
            file_put_contents($this->getProgressFile(), json_encode(array(
                'status'  => 'error',
                'total'   => count($crawlList),
                'current' => 0,
                'success' => 0,
                'error'   => $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
                'started' => time(),
                'updated' => time(),
            )));
            throw $e;
        }

        $json = @json_decode(@file_get_contents($this->getProgressFile()), true);
        $json = is_array($json) ? $json : array('status' => 'completed', 'total' => count($crawlList));

        // Remonter les reglages reellement lus : sans cela, impossible de savoir depuis la
        // ligne de commande si un changement dans l'admin a bien ete pris en compte.
        $json['concurrency'] = (int) $this->settings->get('crawlConcurrency', 5);
        $json['delay']       = (int) $this->settings->get('crawlDelay', 0);
        $json['cookie']      = $cookieHeader;

        return $json;
    }

    public function onLSCacheRebuildAll() {
        if (!$this->isAdmin()) {
            return;
        }

        if (!$this->cacheEnabled) {
            $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_TURNONFIRST'));
            return;
        }

        if (!function_exists('curl_version')) {
            $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_CURLNOTSUPPORT'));
            return;
        }

        $this->purgeObject->recacheAll = true;
        return;
    }

    private function getSiteMap($option = "") {
        $app  = Factory::getContainer()->get(SiteApplication::class);
        $appmenus = $app->getMenu();
        $menus = $appmenus->getMenu();
        $curlMenus = array();
        if (!empty($menus) && is_array($menus)) {
            foreach ($menus as $menu) {
                // access > 1 = non-public (Registered/Special/...) → crawler anonyme = 403.
                // Et seul le type « component » rend une page qui lui soit propre : « url »
                // pointe ailleurs et son lien absolu se retrouvait concaténé au domaine du
                // site (double domaine), tandis que « separator », « heading », « container »
                // et « alias » n'ont pas de lien exploitable et retombaient sur l'accueil.
                if (($menu->type === 'component') && ((int)$menu->access <= 1)) {
                    $menu->path = $menu->link . '&Itemid=' . $menu->id;
                    if(!empty($menu->link)){
                        if($menu->language!="*"){
                            $menu->path = $menu->path . '&lang=' . $menu->language;
                        }
                    }
                    $curlMenus[]=$menu;
                }
            }
        }
        return $curlMenus;
    }

    /**
     * Résout l'URL à crawler pour une entrée de la liste.
     *
     * @return  string|null  null si l'entrée doit être ignorée (routage impossible).
     */
    private function resolveCrawlUrl($url, $preRouted) {
        if ($preRouted) {
            return $url;
        }

        try {
            $curlurl = Route::link("site", $url);
        } catch (\Throwable $ex) {
            $this->log($ex->getMessage());
            return null;
        }

        if (strpos($curlurl, '/component') === 0) {
            $curlurl = '/' . $url;
        }

        if ((strpos($curlurl, '[') !== false) && (strpos($curlurl, ']') !== false)) {
            $pos = strpos($curlurl, '?');
            if ($pos === false) {
                return null;
            }
            $curlurl = substr($curlurl, 0, $pos);
        }

        return $curlurl;
    }

    /**
     * Prépare un handle curl pour le pré-chauffage d'une page.
     */
    private function newCrawlHandle($absoluteUrl, $root, $cookieHeader = '') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $absoluteUrl);
        if ($cookieHeader !== '') {
            // Fait porter au crawler le cookie de consentement d'une variante reelle
            // (voir --cookie du CLI). Le serveur calcule alors la cle de variance comme
            // pour un vrai visiteur, et LiteSpeed range la reponse sous cette cle - sans
            // que le crawler ait besoin de connaitre ou deviner le cookie _lscache_vary.
            curl_setopt($ch, CURLOPT_COOKIE, $cookieHeader);
        }
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
        // Sans timeout explicite curl attend indéfiniment : une seule page front qui
        // pend bloquait tout le crawl, progression figée et aucun état d'erreur écrit.
        // Une page qui met plus de 30 s à se rendre n'a pas sa place en pré-chauffage.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // Browser-like UA keeps WAFs and security plugins happy while the
        // "lscache_runner" suffix stays identifiable in server logs.
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; lscache_runner)');
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_REFERER, $root . '/');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ' . $this->crawlLanguageTag(),
            'X-LSCACHE: 1',
        ));
        return $ch;
    }

    /**
     * Compose l'URL absolue à demander.
     *
     * Une entrée peut déjà être absolue (élément de menu de type « url »). La préfixer
     * produisait « https://site.frhttps://site.fr/... ». On la laisse telle quelle.
     */
    private function absoluteCrawlUrl($root, $url) {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $root . $url;
    }

    /**
     * Tag de langue à annoncer au crawl.
     *
     * getLanguage() rend null tant que l'application n'a pas ete initialisee - le cas hors
     * requete web. On retombe alors sur la langue configuree du site plutot que de fataliser.
     */
    private function crawlLanguageTag() {
        $language = $this->app->getLanguage();
        if ($language !== null) {
            return $language->getTag();
        }
        return (string) $this->app->get('language', 'en-GB');
    }

    private function crawlUrls($urls, $output = true, $preRouted = false, $enforceDuration = true, $trackProgress = false, $cookieHeader = '') {
        $prevTimeLimit = (int) ini_get('max_execution_time');
        set_time_limit(0);

        $cli = false;
        if (php_sapi_name() == 'cli') {
            $cli = true;
        }

        $count = count($urls);
        if ($count < 1) {
            return "";
        }

        $acceptCode = array(200, 201);
        $begin = microtime();
        $success = 0;
        $current = 0;
        $root = Uri::getInstance()->toString(array('scheme', 'host', 'port'));
        $recacheDuration = $this->settings->get('recacheDuration', 30) * 1000000;

        // Pages demandées en parallèle. Le crawl passe l'essentiel de son temps à
        // attendre le serveur, pas à travailler : les lancer par lots divise la durée
        // d'autant. Borné à 20 pour ne pas saturer le pool PHP du site que l'on crawle.
        $concurrency = (int) $this->settings->get('crawlConcurrency', 5);
        if ($concurrency < 1) {
            $concurrency = 1;
        } else if ($concurrency > 20) {
            $concurrency = 20;
        }

        // Pause entre deux lots, en millisecondes. Zéro par défaut : on crawle son
        // propre site. L'ancienne version dormait systématiquement aussi longtemps que
        // la requête avait duré, ce qui doublait la durée totale sans bénéficiaire.
        $crawlDelay = (int) $this->settings->get('crawlDelay', 0);
        if ($crawlDelay < 0) {
            $crawlDelay = 0;
        } else if ($crawlDelay > 10000) {
            $crawlDelay = 10000;
        }

        $break = false;
        $breakReason     = null;
        // Seul le rebuild manuel alimente le fichier de suivi. Le recache automatique
        // déclenché par une purge passe par la même méthode : sans ce garde-fou il
        // écrasait la progression du rebuild en cours et remettait la barre à zéro.
        $progressFile    = $trackProgress ? $this->getProgressFile() : null;
        $progressStarted = time();
        $lastFlush       = $progressStarted;
        if ($trackProgress) {
            file_put_contents($progressFile, json_encode([
                'status'  => 'running',
                'total'   => $count,
                'current' => 0,
                'success' => 0,
                'started' => $progressStarted,
                'updated' => $progressStarted,
            ]));
        }
        if ($output) {
            echo '<h3>Rebuild LiteSpeed Cache may take several minutes</h3><br/>';
            if (ob_get_contents()){
                ob_flush();
            }
            flush();
        }

        // Fenetre glissante : on garde en permanence $concurrency requetes en vol et on
        // relance des qu'une se termine. La version par lots attendait le membre le plus
        // lent de chaque groupe avant d'en lancer un nouveau : melangez une page en cache
        // (0,13 s) et une page a generer (~1,4 s) et le lot entier coutait 1,4 s, soit
        // 0,28 s/page quelle que soit la proportion de pages deja chaudes. C'est ce qui
        // rendait une reconstruction aussi lente sur un cache tiede que sur un cache vide.
        $queue    = array_values($urls);
        $next     = 0;
        $inFlight = array();
        $mh       = curl_multi_init();

        while (true) {
            // Remplir la fenetre. Une entree non routable ne consomme pas de place.
            while ((!$break) && ($next < $count) && (count($inFlight) < $concurrency)) {
                $path = $queue[$next];
                $next++;

                $curlurl = $this->resolveCrawlUrl($path, $preRouted);
                if ($curlurl === null) {
                    // Comptee comme traitee, sinon la barre ne peut pas atteindre 100 %.
                    $current++;
                    continue;
                }

                if (($crawlDelay > 0) && ($current > 0)) {
                    usleep($crawlDelay * 1000);
                }

                $ch = $this->newCrawlHandle($this->absoluteCrawlUrl($root, $curlurl), $root, $cookieHeader);
                curl_multi_add_handle($mh, $ch);
                $inFlight[spl_object_id($ch)] = $curlurl;
            }

            if (empty($inFlight)) {
                break;
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            if ($active && ($mrc === CURLM_OK)) {
                if (curl_multi_select($mh, 1.0) === -1) {
                    usleep(1000);
                }
            }

            // Recolter tout ce qui vient de se terminer, et liberer autant de places.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $id  = spl_object_id($ch);
                $url = isset($inFlight[$id]) ? $inFlight[$id] : '';
                unset($inFlight[$id]);

                $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);

                $current++;
                $this->log($root . $url);

                if (in_array($httpcode, $acceptCode)) {
                    $success++;
                } else if ($httpcode == 428) {
                    $this->log('httpcode:' . $httpcode);
                    $breakReason = Text::_('COM_LSCACHE_ERR_CRAWLER_DISABLED');
                    $break = true;
                } else {
                    $this->log('httpcode:' . $httpcode);
                }

                if ($output) {
                    $line = $current . '/' . $count . ' ' . $root . $url . ' : ' . $httpcode;
                    echo $cli ? ($line . PHP_EOL) : ($line . '<br/>' . PHP_EOL);
                }
            }

            if ($output) {
                if (ob_get_contents()){
                    ob_flush();
                }
                flush();
            }

            // Throttle a 3 secondes : sur un site lent la barre restait affichee a 0.
            if ($trackProgress && ((($current >= $count) || $break) || ((time() - $lastFlush) >= 3))) {
                $lastFlush = time();
                file_put_contents($progressFile, json_encode([
                    'status'  => 'running',
                    'total'   => $count,
                    'current' => $current,
                    'success' => $success,
                    'started' => $progressStarted,
                    'updated' => $lastFlush,
                ]));
            }

            if ((!$break) && $enforceDuration && ($this->microtimeMinus($begin, microtime()) > $recacheDuration)) {
                $breakReason = Text::sprintf('COM_LSCACHE_ERR_DURATION_LIMIT', $current, $count);
                $break = true;
            }
        }

        curl_multi_close($mh);

        if($output && (!$break)){
            echo '100%';
            if (ob_get_contents()){
                ob_flush();
            }
            flush();
        }

        if ($trackProgress) {
            file_put_contents($progressFile, json_encode([
                'status'   => $break ? 'error' : 'completed',
                'total'    => $count,
                'current'  => $current,
                'success'  => $success,
                'started'  => $progressStarted,
                'updated'  => time(),
                'finished' => time(),
                'error'    => $breakReason,
            ]));
        }
        $totalTime = round($this->microtimeMinus($begin, microtime()) / 1000000);
        if ($count == $current) {
            $msg = str_replace('%d', $totalTime, Text::_('COM_LSCACHE_PLUGIN_PAGERECACHED'));
        } else {
            $msg = str_replace('%d', $totalTime, Text::_('COM_LSCACHE_PLUGIN_PAGERECACHOVERTIME'));
        }
        if ($prevTimeLimit > 0) {
            set_time_limit($prevTimeLimit);
        }
        return $msg;
    }

    private function microtimeMinus($start, $end) {
        list($s_usec, $s_sec) = explode(" ", $start);
        list($e_usec, $e_sec) = explode(" ", $end);
        $diff = ((int) $e_sec - (int) $s_sec) * 1000000 + ((float) $e_usec - (float) $s_usec) * 1000000;
        return $diff;
    }

    public function purgeAction() {
        if ((!$this->purgeObject->purgeAll) && (count($this->purgeObject->tags) < 1)) {
            return;
        }

        $httpcode = 0;
        if ((!$this->purgeObject->purgeAll) && ($this->purgeObject->autoRecache > 0)) {
            $root = Uri::root();
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            $url = $root . "index.php?option=com_lscache&cleanCache=" . $cleanWords;
            if ($this->purgeObject->purgeAll) {
                $this->purgeObject->recacheAll = false;
            } else if (count($this->purgeObject->tags) > 0) {
                $purgeTags = implode(',', $this->purgeObject->tags);
                $tags = base64_encode($purgeTags);
                $url .= "&tags=" . $tags;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $buffer = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        if (!empty($httpcode) && in_array($httpcode, array(200, 201))) {
            usleep(100000);
            if ($this->purgeObject->autoRecache == 2) {
                $this->purgeObject->recacheAll = true;
            }
            $this->recacheAction($this->purgeObject->recacheAll);
            $this->purgeObject->recacheAll = false;

        } else if ($this->purgeObject->purgeAll) {
            $this->lscInstance->purgeAllPublic();
            $this->log();
            if ($this->isAdmin()) {
                $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_NEEDMANUALRECACHE'), "message");
            }

        } else if (count($this->purgeObject->tags) > 0) {
            $serveStale = $this->settings->get('serveStale', 1);
            $purgeTags = implode(',', $this->purgeObject->tags);
            $this->lscInstance->purgePublic($purgeTags,$serveStale);
            $this->log();
        }

        if ($this->isAdmin()) {
            $this->app->enqueueMessage(Text::_('COM_LSCACHE_PLUGIN_PURGEINFORMED'), "message");
        }

    }

    private function recacheAction($recacheAll = true, $showProgress=false) {
        if ($recacheAll) {
            $menus = $this->getSiteMap();
            $urls = array_map(function($menu) {
                return $menu->path;
            }, $menus);
            $recacheComponent = $this->settings->get('recacheComponent', false);
            if ($recacheComponent) {
                $compUrls = $this->componentHelper->getComMap($recacheComponent);
                $urls = array_merge($compUrls,$urls);
            }
        } else if ($this->purgeObject->autoRecache > 0) {
            $urls = $this->purgeObject->urls;
            if (!empty($this->purgeObject->option)) {
                $menus = $this->getSiteMap($this->purgeObject->option);
                foreach ($menus as $menu) {
                    if (empty($this->purgeObject->idField)) {
                        //$urls[] = $menu->path;
                    } else if (count($this->purgeObject->ids) > 0) {
                        $query = $this->explode2($menu->link, '&', '=');
                        if (!isset($query[$this->purgeObject->idField])) {
                            $urls[] = $menu->path;
                        } else if (in_array($query[$this->purgeObject->idField], $this->purgeObject->ids)) {
                            $urls[] = $menu->path;
                        }
                    } else {
                        $query = $this->explode2($menu->link, '&', '=');
                        if (!isset($query[$this->purgeObject->idField])) {
                            $urls[] = $menu->path;
                        }
                    }
                }
            }
        }

        $msg = $this->crawlUrls($urls, $showProgress);

        if ((!$this->isAdmin()) && (!empty($msg))) {
            $this->app->enqueueMessage($msg, "message");
        }
    }

    private function saveComponent($firstRun = false) {
        if ($firstRun) {
            $this->settings->set('excludeOptions', array('com_users'));
            $this->settings->set('cacheEnabled', "1");
        }

        if ($this->settings->get('cleanCache', 'purgeAllCache') == "purgeAllCache") {
            $this->settings->set('cleanCache', md5((String) rand()));
        }

        $componentid = ComponentHelper::getComponent('com_lscache')->id;
        $table = Table::getInstance('extension');
        $table->load($componentid);
        $table->bind(array('params' => $this->settings->toString()));
        $table->store();
    }

    private function saveHtaccess() {
        $htaccess = JPATH_ROOT . '/.htaccess';

        $directives = '### LITESPEED_CACHE_START - Do not remove this line' . PHP_EOL;
        $directives .= '<IfModule LiteSpeed>' . PHP_EOL;
        $directives .= 'CacheLookup on' . PHP_EOL;
        $directives .= '## Uncomment the following directives if you has a separate mobile view' . PHP_EOL;
        $directives .= '##RewriteEngine On' . PHP_EOL;
        $directives .= '##RewriteCond %{HTTP_USER_AGENT} Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi [NC] ' . PHP_EOL;
        $directives .= '##RewriteRule .* - [E=Cache-Control:vary=ismobile]' . PHP_EOL;
        $directives .= '## Uncomment the following directives to enable login remember me' . PHP_EOL;
        $directives .= '##RewriteCond %{HTTP_COOKIE} ^.*joomla_remember_me.*$' . PHP_EOL;
        $directives .= '##RewriteCond %{HTTP_COOKIE} !^.*_lscache_vary.*$' . PHP_EOL;
        $directives .= '##RewriteRule .* - [E=cache-control:no-cache]' . PHP_EOL;
        $directives .= '</IfModule>' . PHP_EOL;
        $directives .= '### LITESPEED_CACHE_END';

        $pattern = '@### LITESPEED_CACHE_START - Do not remove this line.*?### LITESPEED_CACHE_END@s';

        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            $newContent = preg_replace($pattern, $directives, $content, -1, $count);

            if ($count <= 0) {
                $newContent = preg_replace('@\<IfModule\ LiteSpeed\>.*?\<\/IfModule\>@s', '', $content);
                $newContent = preg_replace('@CacheLookup\ on@s', '', $newContent);
                file_put_contents($htaccess, $newContent . PHP_EOL . $directives . PHP_EOL);
            } else if ($count > 0) {
                file_put_contents($htaccess, $newContent);
            }
        } else {
            file_put_contents($htaccess, $directives);
        }
    }


    protected function getVisitorIP() {
        // Use only REMOTE_ADDR for security-sensitive IP checks (admin whitelist).
        // HTTP_CLIENT_IP and HTTP_X_FORWARDED_FOR are client-controlled and can
        // be spoofed to bypass IP restrictions.
        $jinput = Factory::getApplication()->input;
        $ip = $jinput->server->getString('REMOTE_ADDR', '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    protected function esiTokenForm(){
        $this->lscInstance->checkPrivateCookie();
        $this->lscInstance->cachePrivate('token','token');
        echo HTMLHelper::_( 'form.token' );
    }

    protected function esiTokenBlock(){
        $block = '<esi:include src="index.php?option=com_lscache&moduleid=-2" cache-control="private,no-vary" cache-tag="token" />' . PHP_EOL;
        return $block;
    }

    protected function isAdmin(){
        return $this->app->isClient('administrator') ;
    }

}
