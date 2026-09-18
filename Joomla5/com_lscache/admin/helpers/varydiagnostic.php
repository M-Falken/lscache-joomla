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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;

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

    // Purges automatiques espacees de moins de 15 min : un seul evenement pour la moyenne.
    // Un meme declencheur (nettoyage JSpeed relance sur deux pages visitees coup sur coup)
    // en produit souvent plusieurs en rafale.
    const PURGE_GROUP_SECONDS = 900;

    /**
     * Purges de produits affichées : autant de lignes que la liste des purges globales,
     * qu'elle côtoie.
     */
    const TARGETED_SHOWN = 6;

    /**
     * Produits nommés par ligne ; les suivants sont comptés, et listés au survol.
     */
    const TARGETED_NAMED = 3;

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
            'targeted'    => self::targetedPurges(),
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
        $names = self::consentCookieNames($params);

        $dimension = array(
            'key'     => 'consent',
            'active'  => false,
            'buckets' => self::CONSENT_BUCKETS,
            'fedBy'   => array(),
            'cookie'  => empty($names) ? '' : reset($names),
            'counted' => true,
            'note'    => '',
        );

        if (empty($names)) {
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

    /**
     * Liste vide = réglage désactivé, il n'y a pas de bascule séparée ni de valeur active
     * par défaut sur une installation neuve - voir hasConsentDecision() dans lscache.php
     * pour pourquoi le champ XML n'a plus de defaut non vide.
     */
    private static function consentCookieNames($params)
    {
        $configured = (string) $params->get('consentCookies', '');

        return array_filter(array_map('trim', explode(',', $configured)), 'strlen');
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
     * Duree lisible : secondes sous la minute, minutes et secondes au-dela.
     *
     * L'arrondi a la minute affichait « une reconstruction de 0 minutes » sur tout site
     * dont la reconstruction dure moins de 30 s (Emaging : 275 pages).
     */
    public static function formatDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 60) {
            return $seconds . ' s';
        }

        $rest = $seconds % 60;

        return intdiv($seconds, 60) . ' min' . ($rest ? sprintf(' %02d s', $rest) : '');
    }

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
        $siteDates = array();
        $entries   = array();
        $siteExtra = array();
        $siteCount = 0;

        foreach ($histo as $e) {
            if ((!is_array($e)) || empty($e['purged'])) {
                continue;
            }

            $dates[] = (int) $e['purged'];
            $client  = (string) ($e['client'] ?? '');
            $detail  = trim(((string) ($e['option'] ?? '')) . ' ' . ((string) ($e['task'] ?? '')));

            if ($detail === '') {
                $detail = (string) ($e['uri'] ?? '');
            }

            $entry = array(
                'time'   => (int) $e['purged'],
                'origin' => in_array($client, array('site', 'administrator', 'cli'), true) ? $client : 'cli',
                'detail' => $detail,
            );

            if ($client === 'site') {
                $siteCount++;
                $siteDates[] = (int) $e['purged'];
            }

            // Les cinq plus recentes, puis jusqu'a trois purges automatiques plus anciennes :
            // sans elles, une serie de purges manuelles pousse hors de la liste celles que
            // l'alerte denonce (Emaging, 14/09/2026 : alerte sur 4 purges depuis une page
            // publique, liste ne montrant que des purges de l'administration).
            if (count($entries) < 5) {
                $entries[] = $entry;
            } else if (($client === 'site') && (count($siteExtra) < 3)) {
                $siteExtra[] = $entry;
            }
        }

        $entries = array_merge($entries, $siteExtra);

        if (empty($dates)) {
            return $vide;
        }

        rsort($dates);
        $seuil   = time() - 86400;
        $last24h = count(array_filter($dates, function ($d) use ($seuil) { return $d > $seuil; }));

        // Moyenne sur les seules purges AUTOMATIQUES, declenchees depuis une page publique.
        // Une purge de l'administration est une decision humaine ponctuelle : melangees au
        // calcul, huit purges manuelles en une demi-heure faisaient annoncer « une purge
        // toutes les 3,4 h » sur Emaging (14/09/2026), ou JSpeed ne purgeait qu'une fois
        // par jour. Des purges automatiques rapprochees comptent pour un seul evenement.
        //
        // Ecarts reellement observes, et non fenetre fixe : l'historique est plafonne a
        // 20 entrees. En dessous de quatre evenements rien n'est affiche - deux points
        // proches donnent un chiffre juste et depourvu de sens.
        rsort($siteDates);
        $events = array();

        foreach ($siteDates as $d) {
            if (empty($events) || ((end($events) - $d) > self::PURGE_GROUP_SECONDS)) {
                $events[] = $d;
            }
        }

        $interval = (count($events) >= self::PURGE_MIN_SAMPLE)
            ? (int) round(($events[0] - $events[count($events) - 1]) / (count($events) - 1))
            : null;

        // Cout d'une purge : la duree d'une reconstruction a froid, soit la plus longue des
        // passes terminees de l'historique. La derniere passe ne convient pas : sur un cache
        // deja chaud elle n'enchaine que des hits (Emaging, 14/09/2026 : passe « Cookies
        // refuses » de 1 s, annoncee comme le cout d'une purge qui oblige pourtant a tout
        // regenerer).
        $rebuild = null;
        $hist    = self::readHistory();

        if (is_array($hist)) {
            foreach ($hist as $e) {
                if (is_array($e) && (($e['status'] ?? '') === 'completed')
                    && !empty($e['finished']) && !empty($e['started'])) {
                    $rebuild = max((int) $rebuild, (int) $e['finished'] - (int) $e['started']);
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
     * Produits purgés récemment, écrits par plgSystemLSCache::recordTargetedPurge().
     *
     * Répond à la question la plus fréquente d'un marchand : « j'ai changé le prix et le
     * client voit l'ancien ». La ligne dit si la purge a eu lieu, quand et d'où elle vient.
     * Les noms sont lus à l'affichage et non à la purge : l'enregistrement d'un produit ne
     * paie aucune requête de plus, et un produit renommé depuis apparaît sous son nom actuel.
     */
    private static function targetedPurges()
    {
        $result = array(
            // Colonne affichée dès que VirtueMart est là, même vide : son absence laisserait
            // croire que les purges de produits ne sont pas suivies.
            'available' => ComponentHelper::isEnabled('com_virtuemart'),
            'entries'   => array(),
            'last24h'   => 0,
        );

        $file  = self::tmpFile('lscache_targeted_purges.json');
        $histo = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($histo)) {
            return $result;
        }

        $seuil = time() - 86400;
        $ids   = array();

        foreach ($histo as $e) {
            if ((!is_array($e)) || empty($e['time']) || (!is_array($e['products'] ?? null))) {
                continue;
            }

            $vm = array_values(array_filter(array_map('intval',
                (array) ($e['products']['com_virtuemart'] ?? array()))));

            if (empty($vm)) {
                continue;
            }

            if ((int) $e['time'] > $seuil) {
                $result['last24h']++;
            }

            if (count($result['entries']) >= self::TARGETED_SHOWN) {
                continue;
            }

            $client = (string) ($e['client'] ?? '');
            $detail = array_filter(array((string) ($e['option'] ?? ''), (string) ($e['view'] ?? ''),
                                         (string) ($e['task'] ?? '')));

            $result['entries'][] = array(
                'time'   => (int) $e['time'],
                'origin' => in_array($client, array('site', 'administrator', 'cli'), true) ? $client : 'cli',
                'source' => (string) ($e['source'] ?? ''),
                'detail' => implode(' ', $detail),
                'ids'    => $vm,
                // Le plugin ne garde qu'une partie des identifiants d'une purge massive.
                'total'  => max(count($vm), (int) ($e['total'] ?? 0)),
            );
            $ids = array_merge($ids, $vm);
        }

        $names = self::vmProductNames($ids);

        foreach ($result['entries'] as &$entry) {
            $labels = array();
            foreach ($entry['ids'] as $id) {
                $labels[] = $names[$id] ?? Text::sprintf('COM_LSCACHE_VARY_DIAG_TARGETED_UNKNOWN', $id);
            }

            // Une déclinaison porte le nom de son parent, purgé avec elle : un seul libellé.
            $entry['names'] = array_values(array_unique($labels));
            $entry['more']  = max(0, $entry['total'] - count($entry['ids']))
                            + max(0, count($entry['names']) - self::TARGETED_NAMED);
            unset($entry['ids']);
        }
        unset($entry);

        return $result;
    }

    /**
     * Noms des produits VirtueMart, lus dans ses tables de textes par langue
     * (#__virtuemart_products_fr_fr...) : la langue par défaut du site d'abord, puis les
     * autres pour un produit qui n'y serait pas traduit.
     *
     * @return  array  [id => nom] ; un produit supprimé depuis n'y figure pas.
     */
    private static function vmProductNames(array $ids)
    {
        $ids   = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $names = array();

        if (empty($ids)) {
            return $names;
        }

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $prefix = $db->getPrefix() . 'virtuemart_products_';
            $site   = strtolower(str_replace('-', '_',
                (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB')));

            $tables = array();

            foreach ($db->getTableList() as $table) {
                $lang = substr((string) $table, strlen($prefix));

                if ((strpos((string) $table, $prefix) === 0) && preg_match('/^[a-z]{2,3}_[a-z]{2}$/', $lang)) {
                    $tables[$lang] = $table;
                }
            }

            if (isset($tables[$site])) {
                $tables = array($site => $tables[$site]) + $tables;
            }

            foreach ($tables as $table) {
                $missing = array_values(array_diff($ids, array_keys($names)));

                if (empty($missing)) {
                    break;
                }

                $query = $db->createQuery()
                    ->select($db->quoteName(array('virtuemart_product_id', 'product_name')))
                    ->from($db->quoteName($table))
                    ->whereIn($db->quoteName('virtuemart_product_id'), $missing);

                foreach ((array) $db->setQuery($query)->loadObjectList() as $row) {
                    if (trim((string) $row->product_name) !== '') {
                        $names[(int) $row->virtuemart_product_id] = (string) $row->product_name;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Sans noms, chaque produit s'affiche sous son numéro ; la cause, elle, reste
            // consignée.
            Log::add($e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
                Log::WARNING, 'LiteSpeedCache');
        }

        return $names;
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
