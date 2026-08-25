<?php
/**
 * LiteSpeed Cache - reconstruction du cache en ligne de commande.
 *
 * Le bouton « Reconstruire tout le cache » de l'admin lance le crawl dans un processus
 * détaché après litespeed_finish_request(). Ce processus n'a aucune protection : ni
 * set_time_limit(0) ni ignore_user_abort() ne le mettent à l'abri du watchdog LSAPI ou
 * de request_terminate_timeout en PHP-FPM. Sur un site dont la reconstruction complète
 * dépasse quelques minutes, il peut être tué en cours de route.
 *
 * En ligne de commande il n'y a ni watchdog ni limite de temps. Ce script écrit dans le
 * même fichier de suivi que le bouton admin, donc la barre de progression du panneau
 * LSCache affiche un rebuild CLI sans aucune adaptation.
 *
 * Usage :
 *   php plugins/system/lscache/cli/rebuild.php [options]
 *
 *   --url=https://exemple.fr  Adresse publique du site. Obligatoire si $live_site est
 *                             vide dans configuration.php : en CLI il n'y a pas de
 *                             requête HTTP, donc rien dont Joomla puisse déduire le
 *                             domaine pour construire les URLs à crawler.
 *   --dry-run                 Collecte et affiche les URLs sans rien crawler.
 *   --limit=N                 Ne traite que les N premières URLs (test de fumée).
 *   --quiet                   N'affiche que les erreurs. À utiliser en cron.
 *   --help                    Affiche cette aide.
 *
 * Codes de retour : 0 succès, 1 erreur, 2 reconstruction déjà en cours.
 *
 * @author    Grégory Roussel <siriusocteam@gmail.com>
 * @copyright 2026 Grégory Roussel. All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   1.5.9
 * @link      https://github.com/M-Falken
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script must be run from the command line.');
}

$options = getopt('', array('url::', 'dry-run', 'limit::', 'quiet', 'help'));

if (isset($options['help'])) {
    $doc = file_get_contents(__FILE__);
    echo substr($doc, strpos($doc, ' * Usage :'), strpos($doc, ' * @author') - strpos($doc, ' * Usage :'));
    exit(0);
}

$quiet  = isset($options['quiet']);
$dryRun = isset($options['dry-run']);
$limit  = isset($options['limit']) ? (int) $options['limit'] : 0;

function lsc_out($message, $isError = false)
{
    global $quiet;
    if ($isError) {
        fwrite(STDERR, $message . PHP_EOL);
    } else if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 4));

if (!is_file(JPATH_BASE . '/includes/defines.php')) {
    lsc_out('Joomla introuvable depuis ' . JPATH_BASE . ' - deplacez ce script ou corrigez son emplacement.', true);
    exit(1);
}

// Determiner l'adresse publique AVANT d'amorcer Joomla : le routeur et Uri::getInstance()
// lisent $_SERVER, qui n'existe pas en CLI. Sans cela les URLs crawlees seraient baties
// sur un hote vide et le crawl taperait dans le vide.
$siteUrl = isset($options['url']) ? trim((string) $options['url']) : '';

if ($siteUrl === '') {
    require_once JPATH_BASE . '/configuration.php';
    if (class_exists('JConfig')) {
        $jconfig = new JConfig();
        $siteUrl = isset($jconfig->live_site) ? trim((string) $jconfig->live_site) : '';
    }
}

$siteUrl = rtrim($siteUrl, '/');

if ($siteUrl === '') {
    lsc_out('Adresse du site inconnue : $live_site est vide dans configuration.php.', true);
    lsc_out('Relancez avec --url=https://votre-domaine.fr', true);
    exit(1);
}

$parts = parse_url($siteUrl);
if (empty($parts['host'])) {
    lsc_out('Adresse invalide : ' . $siteUrl, true);
    exit(1);
}

$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
$path   = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

$_SERVER['HTTP_HOST']      = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$_SERVER['SERVER_NAME']    = $parts['host'];
$_SERVER['SERVER_PORT']    = isset($parts['port']) ? (string) $parts['port'] : ($scheme === 'https' ? '443' : '80');
$_SERVER['SCRIPT_NAME']    = $path . '/index.php';
$_SERVER['PHP_SELF']       = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']    = $path . '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'lscache_cli_rebuild';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
if ($scheme === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;

try {
    $container = Factory::getContainer();

    // includes/app.php enregistre ces alias avant d'instancier l'application : sans eux le
    // conteneur ne sait pas resoudre SessionInterface, dont SiteApplication a besoin, et
    // l'amorcage echoue. On pointe sur « session.cli » et non « session.web.site » : ce
    // service utilise RuntimeStorage, en memoire, sans cookie ni en-tete - ce qu'une
    // session web tenterait d'emettre au beau milieu d'un script en ligne de commande.
    $container->alias('session', 'session.cli')
        ->alias('JSession', 'session.cli')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

    $app = $container->get(SiteApplication::class);
    Factory::$application = $app;
} catch (\Throwable $e) {
    lsc_out('Amorcage de Joomla impossible : ' . $e->getMessage(), true);
    lsc_out('  ' . get_class($e) . ' dans ' . basename($e->getFile()) . ':' . $e->getLine(), true);
    exit(1);
}

lsc_out('Site       : ' . $siteUrl);
lsc_out('Demarrage  : ' . date('Y-m-d H:i:s'));

try {
    PluginHelper::importPlugin('system', 'lscache');
    $results = $app->triggerEvent('onLSCacheRebuildCli', array($limit, $dryRun));
} catch (\Throwable $e) {
    lsc_out('Echec de la reconstruction : ' . $e->getMessage(), true);
    lsc_out('  ' . get_class($e) . ' dans ' . basename($e->getFile()) . ':' . $e->getLine(), true);
    exit(1);
}

$result = null;
foreach ((array) $results as $candidate) {
    if (is_array($candidate) && isset($candidate['status'])) {
        $result = $candidate;
        break;
    }
}

if ($result === null) {
    lsc_out('Le plugin systeme LSCache n\'a pas repondu - est-il active ?', true);
    exit(1);
}

switch ($result['status']) {
    case 'dry-run':
        lsc_out('Simulation : ' . (int) $result['total'] . ' URL(s) seraient crawlees.');
        lsc_out('  elements de menu : ' . (int) $result['menuCount']);
        lsc_out('  URLs composants  : ' . (int) $result['compCount']);
        if (empty($result['components'])) {
            lsc_out('  composants       : AUCUN - verifiez « Remettre en cache les URL generees'
                  . ' par le composant » dans les reglages LSCache, puis enregistrez.');
        } else {
            foreach ($result['components'] as $name => $n) {
                lsc_out('  composant ' . $name . ' : ' . (int) $n . ' URL(s)');
            }
        }
        lsc_out('  sef=' . (int) $result['sef'] . ' sef_rewrite=' . (int) $result['sefRewrite']
              . ((int) $result['sefRewrite'] === 0 ? '  <-- index.php restera dans les URLs' : ''));
        $perdues = (int) $result['failedRoute'] + (int) $result['failedBucket'];
        if ($perdues > 0) {
            lsc_out('  ECARTEES au routage : ' . $perdues
                  . ' (exception : ' . (int) $result['failedRoute']
                  . ', crochets : ' . (int) $result['failedBucket'] . ')', true);
            if (!empty($result['firstFailure'])) {
                lsc_out('  premiere cause : ' . $result['firstFailure'], true);
            }
        }
        foreach (array_slice($result['urls'], 0, 20) as $url) {
            lsc_out('  ' . $siteUrl . $url);
        }
        if ((int) $result['total'] > 20) {
            lsc_out('  ... et ' . ((int) $result['total'] - 20) . ' autres.');
        }
        exit(empty($result['urls']) ? 1 : 0);

    case 'busy':
        lsc_out('Reconstruction deja en cours : ' . $result['error'], true);
        exit(2);

    case 'completed':
        $seconds = (isset($result['finished'], $result['started']))
            ? (int) $result['finished'] - (int) $result['started']
            : 0;
        lsc_out(sprintf(
            'Termine : %d/%d page(s) en cache en %d min %02d s.',
            (int) $result['success'],
            (int) $result['total'],
            intdiv($seconds, 60),
            $seconds % 60
        ));
        exit(0);

    default:
        lsc_out('Erreur : ' . (isset($result['error']) ? $result['error'] : 'inconnue'), true);
        if (isset($result['current'], $result['total'])) {
            lsc_out('Interrompu a ' . (int) $result['current'] . '/' . (int) $result['total'] . ' page(s).', true);
        }
        exit(1);
}
