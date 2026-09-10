<?php
declare(strict_types=1);

/**
 * Core function of communicating with LSWS Server for LSCache operations
 * The Core class works at site level, its operation will only affect a site in the server.
 *
 * @since      1.0.0
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 * @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */
class LiteSpeedCacheCore extends LiteSpeedCacheBase
{

    protected $site_only_tag = "";

    /**
     *
     *  set the specified tag for this site
     *
     * @since   1.0.0
     */
    public function __construct($tag = '')
    {
        if(!isset($tag) || ($tag=='')){
            $this->site_only_tag = substr(md5(__DIR__),0,4);
        }
        else{
            $this->site_only_tag = $tag;
        }
    }

    /**
     *
     * put tag into Array in the format for this site only.
     *
     * @since   1.0.0
     */
    protected function tagsForSite(Array &$tagArray, $rawTags, $prefix = "")
    {
        if (!isset($rawTags)) {
            return;
        }

        if ($rawTags == "") {
            return;
        }

        if(is_array($rawTags)){
            $tags = $rawTags;
        } else {
            $tags = explode(",", $rawTags);
        }
        
        foreach ($tags as $tag) {
            if(trim($tag)==""){
                continue;
            }
                        
            $tagStr = $prefix . $this->site_only_tag . trim($tag);
            if(!in_array($tagStr, $tagArray, false)){
                $tagArray[] = $tagStr;
            }
        }
    }

    /**
     *
     *  purge all public cache of this site
     *
     * @since   1.0.0
     */
    public function purgeAllPublic()
    {
        $LSheader = self::CACHE_PURGE . 'public,' . $this->site_only_tag;
        $this->liteSpeedHeader($LSheader);
        $this->recordPurgeAll();
    }

    /**
     * Horodate la derniere purge globale.
     *
     * L'encadre de diagnostic de l'admin juge la couverture du prechauffage sur
     * l'historique des reconstructions, et ne pouvait jusqu'ici constater qu'une chose :
     * l'age des passes. Une purge vide pourtant le cache instantanement, ce qui invalide
     * toutes les passes anterieures quel que soit leur age - l'encadre continuait donc
     * d'annoncer « toutes les variantes sont prechauffees » sur un cache entierement vide.
     *
     * C'est ici, et non chez les appelants, parce que c'est le point de passage unique de
     * toute purge globale : bouton de l'admin, purge declenchee par une modification de
     * contenu, ou requete de purge externe.
     *
     * Silencieux par construction : le suivi d'un diagnostic ne doit jamais faire echouer
     * une purge.
     *
     * @since   1.5.28
     */
    protected function recordPurgeAll(): void
    {
        try {
            $tmp = '';

            if (class_exists('\Joomla\CMS\Factory')) {
                $tmp = (string) \Joomla\CMS\Factory::getApplication()->get('tmp_path');
            }

            if (($tmp === '') || (!is_dir($tmp)) || (!is_writable($tmp))) {
                $tmp = (defined('JPATH_ROOT') ? JPATH_ROOT : __DIR__) . '/tmp';
            }

            if (!is_dir($tmp)) {
                return;
            }

            // Consigner l'ORIGINE, pas seulement l'heure : une purge globale survenue
            // en plein crawl annule le travail des passes precedentes, et « quelque
            // chose a purge a 15:58 » ne permet pas de la corriger. Le cas s'est produit
            // sur MGF le 10/09/2026, au milieu d'une chaine de trois passes.
            $contexte = array('purged' => time());

            if (class_exists('\\Joomla\\CMS\\Factory')) {
                $app = \Joomla\CMS\Factory::getApplication();

                if ($app->isClient('administrator')) {
                    $contexte['client'] = 'administrator';
                } else if ($app->isClient('site')) {
                    $contexte['client'] = 'site';
                } else {
                    $contexte['client'] = 'cli';
                }

                $input = $app->getInput();
                $contexte['option'] = (string) $input->getCmd('option', '');
                $contexte['task']   = (string) $input->getCmd('task', '');
                $contexte['view']   = (string) $input->getCmd('view', '');
                $contexte['uri']    = isset($_SERVER['REQUEST_URI'])
                    ? substr((string) $_SERVER['REQUEST_URI'], 0, 200) : '';
                $contexte['agent']  = isset($_SERVER['HTTP_USER_AGENT'])
                    ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 120) : '';
            }

            @file_put_contents(
                rtrim($tmp, '/\\') . '/lscache_last_purge.json',
                json_encode($contexte)
            );
        } catch (\Throwable $e) {
            // Ignore : une purge reussie prime sur son horodatage.
        }
    }

    /**
     *
     *  purge all private cache of this session
     *
     * @since   0.1
     */
    public function purgeAllPrivate()
    {
        $LSheader = self::CACHE_PURGE . 'private,' . $this->site_only_tag;
        $this->liteSpeedHeader($LSheader);
    }

    /**
     *
     * Cache this page for public use if not cached before
     *
     * @since   1.0.1
     */
    public function cachePublic($publicTags, $esi=false)
    {
        if (!isset($publicTags) || ($publicTags == null)) {
            return;
        }

        $LSheader = self::PUBLIC_CACHE_CONTROL . $this->public_cache_timeout;
        if($esi){
            $LSheader .= ',esi=on';
        }        
        $this->liteSpeedHeader($LSheader);

        $siteTags = Array();
        $siteTags[] = $this->site_only_tag;
        $this->tagsForSite($siteTags, $publicTags);

        $LSheader = $this->tagCommand( self::CACHE_TAG ,  $siteTags);
        $this->liteSpeedHeader($LSheader);
    }

    /**
     *
     * Cache this page for private session if not cached before
     *
     * @since   0.1
     */
    public function cachePrivate($publicTags, $privateTags = "", $esi=false)
    {
        if ( !isset($privateTags) || ($privateTags == "") ) {
            if ( !isset($publicTags) || ($publicTags == "")) {
                return;
            }
        }

        $LSheader = self::PRIVATE_CACHE_CONTROL . $this->private_cache_timeout;
        if($esi){
            $LSheader .= ',esi=on';
        }
        $this->liteSpeedHeader($LSheader);

        $siteTags = Array();
        $this->tagsForSite($siteTags, $publicTags, "public:".$this->site_only_tag);
        if($publicTags!=""){
            $siteTags[] = "public:" . $this->site_only_tag;
        }

        $this->tagsForSite($siteTags, $privateTags);
        $siteTags[] = $this->site_only_tag;
        
        $LSheader = $this->tagCommand( self::CACHE_TAG ,  $siteTags);
        $this->liteSpeedHeader($LSheader);
    }

    public function getSiteOnlyTag(){
        return $this->site_only_tag;
    }

    /**
     *
     *  purge public cache with specified tags for this site.
     *
     * @since   1.0.0
     */
    public function purgePublic($publicTags, $serveStale=FALSE)
    {
        if ((!isset($publicTags)) || ($publicTags == "")) {
            return;
        }
        
        $siteTags = Array();
        $this->tagsForSite($siteTags, $publicTags);
        if($serveStale){
            $LSheader = $this->tagCommand(self::CACHE_PURGE . 'public,stale,' ,  $siteTags) ;            
        } else {
            $LSheader = $this->tagCommand(self::CACHE_PURGE . 'public,' ,  $siteTags) ;
        }
        $this->liteSpeedHeader($LSheader);
    }


    /**
     *
     *  purge private cache with specified tags for this site.
     *
     * @since   1.0.0
     */
    public function purgePrivate($privateTags)
    {
        if ((!isset($privateTags)) || ($privateTags == "")) {
            return;
        }

        $siteTags = Array();
        $this->tagsForSite($siteTags, $privateTags);
        $LSheader = $this->tagCommand( self::CACHE_PURGE . 'private,' ,  $siteTags);
        $this->liteSpeedHeader($LSheader);
    }    
    
}
