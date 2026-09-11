<?php
/**
 * Diagnostic des dimensions de vary et de la couverture du préchauffage.
 *
 * Le cache LiteSpeed se fragmente en compartiments selon des dimensions de vary, mais le
 * crawl de préchauffage ne remplit que ceux des passes qu'on lui demande. Quand les deux
 * divergent le symptôme est muet : le rebuild annonce « terminé, N pages en cache »,
 * l'administrateur constate des hits depuis son propre navigateur, et pourtant une part
 * des visiteurs paie une génération à froid à chaque visite.
 *
 * Tout se calcule ici, jamais dans le gabarit.
 *
 * @author    Grégory Roussel <siriusocteam@gmail.com>
 * @copyright 2026 Grégory Roussel. All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   1.5.27
 * @link      https://github.com/M-Falken
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;

class LSCacheVaryDiagnostic
{
    /**
     * Nombre de compartiments d'une dimension de consentement active : le compartiment
     * partagé des visiteurs qui n'ont rien décidé, plus les deux décisions possibles.
     */
    const CONSENT_BUCKETS = 3;

    /**
     * Nombre minimal de purges avant d'oser afficher un intervalle moyen.
     */
    const PURGE_MIN_SAMPLE = 4;

    /**
     * Assemble l'état complet à afficher.
     *
     * @return  array
     */
    public static function collect()
    {
        $params = ComponentHelper::getParams('com_lscache');

        $consent = self::probeConsent($params);
        $device  = self::probeDevice($params);
        $login   = self::probeLogin($params);

        // Seules les dimensions qui fragmentent le cache d'un VISITEUR ANONYME entrent
        // dans le produit, parce que ce sont les seules que le crawl peut préchauffer.
        // La dimension connexion est affichée pour être exhaustive mais reste hors du
        // compte : voir probeLogin().
        $buckets = 1;
        foreach (array($consent, $device) as $dimension) {
            if ($dimension['active']) {
                $buckets *= $dimension['buckets'];
            }
        }

        return array(
            'dimensions'  => array($consent, $device, $login),
            'buckets'     => $buckets,
            'coverage'    => self::coverage($params, $consent, $device),
            'doubleCache' => PluginHelper::isEnabled('system', 'cache'),
            'purges'      => self::purges($params),
            'cliPath'     => JPATH_PLUGINS . '/system/lscache/cli/rebuild.php',
            'phpBinary'   => self::phpBinary(),
        );
    }

    /**
     * Binaire PHP a proposer dans les commandes.
     *
     * Meme logique que le champ « Reconstruction planifiee » des reglages : les deux
     * endroits affichent des commandes a copier, ils ne doivent pas en proposer deux
     * versions differentes.
     */
    private static function phpBinary()
    {
        foreach (array('/usr/bin/php', PHP_BINDIR . '/php', '/usr/local/bin/php') as $candidate) {
            if (@is_file($candidate)) {
                return $candidate;
            }
        }

        return '/usr/bin/php';
    }

    /**
     * Dimension consentement.
     *
     * On ne dispatche PAS onPageCacheGetKey ici, on se contente de constater qu'un
     * écouteur y est abonné. Deux raisons.
     *
     * D'abord parce que dispatcher exécuterait du code tiers pendant le rendu de l'admin.
     * Ensuite et surtout parce que le résultat serait faux : le contributeur type est
     * com_gdpr, qui lit le cookie de consentement via $app->getInput()->cookie, or
     * Joomla\CMS\Input\Cookie copie $_COOKIE par VALEUR à sa construction et l'objet est
     * mémoïsé pour la requête. Simuler un consentement dans $_COOKIE au moment du
     * dispatch n'atteindrait donc jamais l'écouteur, les deux sondes rendraient la même
     * clé, et on conclurait « dimension inerte » sur un site où elle fragmente
     * réellement le cache — exactement l'erreur que cet encadré doit rendre impossible.
     *
     * La présence d'un écouteur est donc traitée comme « fragmente », ce qui est le sens
     * prudent : sur-préchauffer coûte du temps de crawl, sous-préchauffer coûte une
     * génération à froid à des visiteurs réels.
     */
    private static function probeConsent($params)
    {
        $dimension = array(
            'key'     => 'consent',
            'active'  => false,
            'buckets' => self::CONSENT_BUCKETS,
            'fedBy'   => array(),
            'cookie'  => self::consentCookieName($params),
            'counted' => true,
            'note'    => '',
        );

        if (!$params->get('pagecacheVary', 1)) {
            $dimension['note'] = 'COM_LSCACHE_VARY_DIAG_NOTE_CONSENT_OFF';

            return $dimension;
        }

        $listeners = self::pageCacheListeners();

        if (empty($listeners)) {
            // Le reglage est actif mais personne ne repond : distinguer ce cas de la
            // coupure manuelle evite de chercher un compartiment qui n'existe pas.
            $dimension['note'] = 'COM_LSCACHE_VARY_DIAG_NOTE_CONSENT_NOLISTENER';

            return $dimension;
        }

        $dimension['active'] = true;
        $dimension['fedBy']  = $listeners;
        $dimension['note']   = 'COM_LSCACHE_VARY_DIAG_NOTE_CONSENT_ON';

        return $dimension;
    }

    /**
     * Extensions abonnées à onPageCacheGetKey, sous un nom affichable.
     *
     * Ne jamais déduire cet état d'une liste de plugins du groupe « pagecache » : le
     * dispatch atteint tout abonné du dispatcher de l'application, quel que soit son
     * groupe. Le contributeur type est un plugin SYSTÈME (com_gdpr), et sur un site qui
     * fragmente réellement son cache le dossier plugins/pagecache/ peut ne pas exister.
     */
    private static function pageCacheListeners()
    {
        $names = array();

        try {
            $dispatcher = Factory::getApplication()->getDispatcher();

            // Les plugins système sont déjà abonnés ; on importe le groupe pagecache pour
            // ne pas manquer un contributeur qui, lui, respecte la convention de nommage.
            PluginHelper::importPlugin('pagecache', null, true, $dispatcher);

            foreach ($dispatcher->getListeners('onPageCacheGetKey') as $listener) {
                $owner = null;

                if (is_array($listener) && isset($listener[0]) && is_object($listener[0])) {
                    // Plugin implémentant SubscriberInterface : l'écouteur est [objet, methode].
                    $owner = $listener[0];
                } else if ($listener instanceof \Closure) {
                    // Plugin historique : CMSPlugin::registerListeners() enveloppe l'appel
                    // dans une fermeture dont $this est le plugin.
                    $reflection = new \ReflectionFunction($listener);
                    $owner      = $reflection->getClosureThis();
                }

                $label = ($owner !== null) ? self::extensionLabel(get_class($owner)) : null;

                if (($label !== null) && (!in_array($label, $names, true))) {
                    $names[] = $label;
                }
            }
        } catch (\Throwable $e) {
            // Un écouteur tiers ne doit jamais pouvoir casser l'affichage de l'admin.
            return array();
        }

        return $names;
    }

    /**
     * « PlgSystemGdpr » devient « System - Gdpr ». Un libellé approximatif vaut mieux que
     * pas de libellé : l'information « actif » prime sur l'attribution exacte.
     */
    private static function extensionLabel($class)
    {
        $short = substr($class, strrpos($class, '\\') === false ? 0 : strrpos($class, '\\') + 1);

        if (preg_match('/^Plg([A-Z][a-z0-9]*)(.+)$/', $short, $m)) {
            return $m[1] . ' - ' . $m[2];
        }

        return $short;
    }

    private static function consentCookieName($params)
    {
        $configured = (string) $params->get('consentCookies', 'cookieconsent_status');
        $names      = array_filter(array_map('trim', explode(',', $configured)), 'strlen');

        return empty($names) ? 'cookieconsent_status' : reset($names);
    }

    /**
     * Dimension appareil : lecture directe du réglage, aucune ambiguïté possible.
     */
    private static function probeDevice($params)
    {
        $active = ($params->get('mobileCacheVary', 0) == 1);

        return array(
            'key'     => 'device',
            'active'  => $active,
            'buckets' => 2,
            'fedBy'   => array(),
            'counted' => true,
            'note'    => $active
                ? 'COM_LSCACHE_VARY_DIAG_NOTE_DEVICE_ON'
                : 'COM_LSCACHE_VARY_DIAG_NOTE_DEVICE_OFF',
        );
    }

    /**
     * Dimension connexion : affichée, mais volontairement HORS du produit.
     *
     * Deux raisons de ne pas la compter. Avec loginCachable à 0 les visiteurs connectés
     * ne sont pas rangés dans un compartiment, ils ne sont pas cachés du tout — compter 2
     * décrirait un compartiment qui n'existe pas. Et avec loginCacheVary à 2 la clé vaut
     * l'identifiant du groupe utilisateur : la cardinalité est le nombre de groupes, pas
     * 2. Dans les deux cas le crawl ne peut rien y faire, il visite en anonyme. La faire
     * entrer dans le produit ne produirait que des « INSUFFISANT » impossibles à corriger.
     */
    private static function probeLogin($params)
    {
        $cachable = ($params->get('loginCachable', 0) == 1);
        $segments = ($params->get('loginCacheVary', 0) != 0);

        if (!$cachable) {
            $note = 'COM_LSCACHE_VARY_DIAG_NOTE_LOGIN_UNCACHED';
        } else if ($segments) {
            $note = 'COM_LSCACHE_VARY_DIAG_NOTE_LOGIN_SEGMENTED';
        } else {
            $note = 'COM_LSCACHE_VARY_DIAG_NOTE_LOGIN_SHARED';
        }

        return array(
            'key'     => 'login',
            'active'  => $cachable && $segments,
            'buckets' => 0,
            'fedBy'   => array(),
            'counted' => false,
            'note'    => $note,
        );
    }

    /**
     * Couverture du préchauffage, mesurée sur l'historique réel des reconstructions.
     */
    private static function coverage($params, $consent, $device)
    {
        $expected = self::expectedBuckets($consent, $device);
        $history  = self::readHistory();

        if ($history === null) {
            return array('state' => 'none', 'warmed' => 0, 'expected' => count($expected),
                         'missing' => array(), 'errors' => 0, 'passes' => array(),
                         'historyLatest' => 0, 'purgeSeen' => (int) self::readLastPurge());
        }

        // Une passe plus ancienne que la durée de vie du cache ne compte pas : son travail
        // a expiré, les pages qu'elle a chauffées ont été évincées depuis.
        $ttl    = (int) $params->get('cacheTimeout', 2000) * 60;
        $cutoff = ($ttl > 0) ? (time() - $ttl) : 0;

        // Une purge globale invalide instantanément TOUTES les passes antérieures, quel
        // que soit leur âge. Sans cette borne, l'encadré continuait d'annoncer « toutes
        // les variantes sont préchauffées » juste après un purge-tout, sur un cache vide.
        $lastPurge = self::readLastPurge();

        if (($lastPurge !== null) && ($lastPurge > $cutoff)) {
            $cutoff = $lastPurge;
        }

        $passes = array();
        $errors = 0;
        $stale  = 0;
        $purged = 0;
        $latest = 0;

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $latest = max($latest, (int) ($entry['finished'] ?? ($entry['updated'] ?? 0)));

            if (($entry['status'] ?? '') === 'error') {
                // Une passe interrompue n'a pas chauffé ce qu'elle annonce.
                $errors++;
                continue;
            }

            if (($entry['status'] ?? '') !== 'completed') {
                continue;
            }

            // On juge une passe sur son DEBUT, pas sur sa fin. Une purge survenue pendant
            // qu'elle tournait annule tout ce qu'elle avait chauffe avant : comparer la
            // fin la declarerait valide alors qu'une part du site est redevenue froide.
            // Cas MGF du 10/09/2026 : passe de 16:45:44 a 17:15:12, purge a 16:53:35 -
            // huit minutes de travail perdues, et une couverture annoncee complete.
            $finished = (int) ($entry['finished'] ?? 0);
            $debut    = (int) ($entry['started'] ?? $finished);

            if ($debut <= $cutoff) {
                // Distinguer les deux causes : « trop vieux » se corrige en planifiant le
                // cron plus souvent, « purgé depuis » se corrige en relançant une passe.
                if (($lastPurge !== null) && ($debut <= $lastPurge)) {
                    $purged++;
                } else {
                    $stale++;
                }

                continue;
            }

            $signature = self::bucketSignature((string) ($entry['cookie'] ?? ''), (string) ($entry['agent'] ?? ''));

            if (!isset($passes[$signature])) {
                $passes[$signature] = array(
                    'label'    => (string) ($entry['label'] ?? ''),
                    'cookie'   => (string) ($entry['cookie'] ?? ''),
                    'agent'    => (string) ($entry['agent'] ?? ''),
                    'finished' => $finished,
                );
            }
        }

        $missing = array();

        foreach ($expected as $signature => $bucket) {
            if (!isset($passes[$signature])) {
                $missing[] = $bucket;
            }
        }

        if (empty($passes)) {
            // Rien de valide. Trois causes distinctes, trois messages : le cache a été
            // purgé depuis, les passes ont expiré, ou rien n'a jamais tourné.
            if ($purged > 0) {
                $state = 'purged';
            } else if ($stale > 0) {
                $state = 'stale';
            } else {
                $state = 'none';
            }
        } else {
            $state = empty($missing) ? 'ok' : 'short';
        }

        return array(
            'state'    => $state,
            'warmed'   => count($passes),
            'expected' => count($expected),
            'missing'  => $missing,
            'errors'   => $errors,
            'passes'   => array_values($passes),
            // Servent au gabarit a detecter que l'encadre est perime : la liste des
            // reconstructions se rafraichit en AJAX, cet encadre non. On expose des
            // HORODATAGES et non un compteur : l'historique est plafonne a 20 entrees,
            // donc sa taille cesse d'augmenter des le plafond atteint et un compteur
            // n'y verrait plus jamais rien changer.
            'historyLatest' => $latest,
            'purgeSeen'     => (int) $lastPurge,
        );
    }

    /**
     * Liste des compartiments qu'un crawl devrait remplir, chacun avec les options CLI qui
     * le remplissent. Produire la liste plutôt qu'un simple nombre est ce qui permet
     * d'afficher la commande exacte qui manque, au lieu d'un « 1/3 » que l'administrateur
     * doit interpréter seul.
     */
    private static function expectedBuckets($consent, $device)
    {
        $consentVariants = array(
            array('cookie' => '', 'labelKey' => 'COM_LSCACHE_VARY_DIAG_LABEL_DEFAULT'),
        );

        if ($consent['active']) {
            // allow/deny sont les valeurs du gestionnaire de consentement le plus répandu.
            // Un autre gestionnaire peut en employer d'autres : la commande proposée reste
            // alors un point de départ à ajuster, ce que dit la chaîne de langue.
            $decisions = array(
                'allow' => 'COM_LSCACHE_VARY_DIAG_LABEL_ALLOW',
                'deny'  => 'COM_LSCACHE_VARY_DIAG_LABEL_DENY',
            );

            foreach ($decisions as $decision => $labelKey) {
                $consentVariants[] = array(
                    'cookie'   => $consent['cookie'] . '=' . $decision,
                    'labelKey' => $labelKey,
                );
            }
        }

        $deviceVariants = array(false);

        if ($device['active']) {
            $deviceVariants[] = true;
        }

        $expected = array();

        foreach ($consentVariants as $consentVariant) {
            foreach ($deviceVariants as $mobile) {
                $bucket = array(
                    'cookie'   => $consentVariant['cookie'],
                    'mobile'   => $mobile,
                    'labelKey' => $consentVariant['labelKey'],
                );

                $expected[self::bucketSignature($consentVariant['cookie'], $mobile ? 'mobile' : '')] = $bucket;
            }
        }

        return $expected;
    }

    /**
     * Identité d'un compartiment : le cookie porté, et la classe d'appareil de l'agent.
     *
     * L'agent est réduit à mobile/bureau selon la règle de Joomla lui-même
     * (WebClient::detectPlatform), parce que c'est cette classification-là, et pas la
     * chaîne exacte, qui décide du compartiment où LiteSpeed range la réponse.
     */
    private static function bucketSignature($cookie, $agent)
    {
        return trim($cookie) . '|' . (self::isMobileAgent($agent) ? 'mobile' : 'desktop');
    }

    private static function isMobileAgent($agent)
    {
        if ($agent === '') {
            return false;
        }

        return (bool) preg_match('/iPhone|iPad|iPod|Android|Windows Phone|Windows CE|BlackBerry|^mobile$/i', $agent);
    }

    /**
     * Horodatage de la dernière purge globale, écrit par LiteSpeedCacheCore::purgeAllPublic().
     *
     * @return  int|null  null si aucune purge n'a été enregistrée.
     */
    /**
     * Emplacement d'un fichier d'etat du plugin : le tmp_path configure, et non /cache,
     * ce dernier etant vide regulierement.
     */
    private static function tmpFile($nom)
    {
        $tmp = (string) Factory::getApplication()->get('tmp_path');

        if (($tmp === '') || (!is_dir($tmp))) {
            $tmp = JPATH_ROOT . '/tmp';
        }

        return rtrim($tmp, '/\\') . '/' . $nom;
    }

    private static function readLastPurge()
    {
        $file = self::tmpFile('lscache_last_purge.json');

        if (!is_readable($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if ((!is_array($data)) || empty($data['purged'])) {
            return null;
        }

        return (int) $data['purged'];
    }

    /**
     * Historique des purges globales, ecrit par LiteSpeedCacheCore::purgeAllPublic().
     *
     * Une purge isolee se diagnostique, une purge recurrente se surveille. Sur MGF le
     * 10/09/2026, le ramasse-miettes de plg_system_jspeed vidait tout le cache depuis une
     * requete front anonyme a intervalle regulier : invisible sans cet affichage, et
     * indiscernable d'un cache qui « ne prend pas ».
     *
     * L'intervalle moyen compare a la duree d'une reconstruction est le chiffre qui
     * compte : en dessous, le prechauffage ne rattrape jamais son retard.
     */
    private static function purges($params)
    {
        $vide = array('entries' => array(), 'last24h' => 0, 'interval' => null,
                      'rebuild' => null, 'ttl' => 0, 'siteCount' => 0, 'alert' => false);

        $file = self::tmpFile('lscache_purge_history.json');

        if (!is_readable($file)) {
            return $vide;
        }

        $histo = json_decode((string) file_get_contents($file), true);

        if ((!is_array($histo)) || empty($histo)) {
            return $vide;
        }

        $dates     = array();
        $entries   = array();
        $siteCount = 0;

        foreach ($histo as $e) {
            if ((!is_array($e)) || empty($e['purged'])) {
                continue;
            }

            $dates[] = (int) $e['purged'];

            if (($e['client'] ?? '') === 'site') {
                $siteCount++;
            }

            if (count($entries) < 5) {
                $client = (string) ($e['client'] ?? '');
                $detail = trim(((string) ($e['option'] ?? '')) . ' ' . ((string) ($e['task'] ?? '')));

                if ($detail === '') {
                    $detail = (string) ($e['uri'] ?? '');
                }

                $entries[] = array(
                    'time'   => (int) $e['purged'],
                    'origin' => in_array($client, array('site', 'administrator', 'cli'), true) ? $client : 'cli',
                    'detail' => $detail,
                );
            }
        }

        if (empty($dates)) {
            return $vide;
        }

        rsort($dates);
        $seuil   = time() - 86400;
        $last24h = count(array_filter($dates, function ($d) use ($seuil) { return $d > $seuil; }));

        // Moyenne sur les ecarts reellement observes, et non sur une fenetre fixe :
        // l'historique est plafonne a 20 entrees et peut couvrir quelques heures comme
        // plusieurs jours selon le rythme des purges.
        //
        // En dessous de quatre points elle n'est pas affichee : deux purges manuelles
        // espacees de trois minutes donnaient « une purge toutes les 0,1 h », un chiffre
        // juste et depourvu de sens, qui desinforme plus qu'il n'informe.
        $interval = (count($dates) >= self::PURGE_MIN_SAMPLE)
            ? (int) round(($dates[0] - $dates[count($dates) - 1]) / (count($dates) - 1))
            : null;

        // Duree de la derniere reconstruction menee a son terme.
        $rebuild = null;
        $hist    = self::readHistory();

        if (is_array($hist)) {
            foreach ($hist as $e) {
                if (is_array($e) && (($e['status'] ?? '') === 'completed')
                    && !empty($e['finished']) && !empty($e['started'])) {
                    $rebuild = (int) $e['finished'] - (int) $e['started'];
                    break;
                }
            }
        }

        return array(
            'entries'  => $entries,
            'last24h'  => $last24h,
            'interval' => $interval,
            'rebuild'  => $rebuild,
            'ttl'      => (int) $params->get('cacheTimeout', 2000) * 60,
            'siteCount' => $siteCount,
            // Le signal qui compte n'est pas la frequence mais l'ORIGINE. Une purge
            // declenchee depuis une page publique n'est jamais une decision humaine :
            // c'est une extension tierce qui vide le cache a l'insu de l'administration.
            // Une seule suffit a la signaler, meme si le rythme parait supportable.
            'alert'    => ($siteCount > 0),
        );
    }

    /**
     * Historique écrit par le plugin. Même emplacement que getHistoryFile() : tmp_path et
     * non /cache, ce dernier étant vidé régulièrement.
     *
     * @return  array|null  null si aucun historique exploitable n'existe.
     */
    private static function readHistory()
    {
        $file = self::tmpFile('lscache_rebuild_history.json');

        if (!is_readable($file)) {
            return null;
        }

        $history = json_decode((string) file_get_contents($file), true);

        if ((!is_array($history)) || empty($history)) {
            return null;
        }

        return $history;
    }
}
