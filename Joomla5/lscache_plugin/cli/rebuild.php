<?php
/**
 * LiteSpeed Cache - command line cache rebuild.
 *
 * The admin "Rebuild All Cache" button crawls in a process detached after
 * litespeed_finish_request(). That process has no protection: neither
 * set_time_limit(0) nor ignore_user_abort() shield it from the LSAPI watchdog or
 * a PHP-FPM request_terminate_timeout. On a catalogue whose full rebuild takes
 * more than a few minutes, it can be killed halfway through with nothing to
 * show for it.
 *
 * On the command line neither limit exists. This script writes to the same
 * progress file the admin button uses, so the LSCache panel's progress card
 * follows a CLI-driven rebuild with no change on its side.
 *
 * Usage:
 *   php plugins/system/lscache/cli/rebuild.php [options]
 *
 *   --url=https://example.com  Public site address. Required if $live_site is
 *                              empty in configuration.php: there is no HTTP
 *                              request on the command line, so nothing tells
 *                              Joomla the domain to build crawled URLs against.
 *   --dry-run                  Collect and print the first 20 URLs without
 *                              warming anything.
 *   --list                     Like --dry-run but prints the full list, one URL
 *                              per line, with no extra text - pipe it to grep.
 *   --check[=N]                Warm nothing: sample N URLs (default 100) and
 *                              report how many are already in the page cache,
 *                              broken down by band across the list.
 *   --cookie=NAME=VALUE        Make the crawl carry this cookie (repeatable).
 *                              Useful for any extension whose output - and
 *                              cache key - varies by cookie: a plain crawl runs
 *                              cookieless and only ever warms the bucket for
 *                              visitors with no such cookie, never the one a
 *                              real visitor lands in once they have one. For
 *                              example, with a GDPR consent plugin:
 *                              --cookie=cookieconsent_status=allow
 *   --limit=N                  Only process the first N URLs (smoke test).
 *   --quiet                    Print errors only. Use this in cron.
 *   --help                     Show this help.
 *
 * Exit codes: 0 success, 1 error, 2 a rebuild is already running.
 *
 * @package    LiteSpeed.Cache
 * @copyright  Copyright (c) 2026 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license    https://opensource.org/licenses/GPL-3.0
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script must be run from the command line.');
}

$options = getopt('', array('url::', 'dry-run', 'list', 'check::', 'limit::', 'cookie::', 'quiet', 'help'));

if (isset($options['help'])) {
    $doc = file_get_contents(__FILE__);
    echo substr($doc, strpos($doc, ' * Usage:'), strpos($doc, ' * @package') - strpos($doc, ' * Usage:'));
    exit(0);
}

$quiet    = isset($options['quiet']);
$listOnly = isset($options['list']);
$dryRun   = isset($options['dry-run']) || $listOnly;
if ($listOnly) {
    $quiet = true; // the list only, meant to be piped into grep
}
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$check = array_key_exists('check', $options)
    ? (($options['check'] === false || $options['check'] === '') ? 100 : (int) $options['check'])
    : 0;

// --cookie is repeatable: getopt() then returns an array, a single occurrence
// returns a string. Normalise, then keep only syntactically valid NAME=VALUE
// pairs - a malformed one should not abort the whole run, just be dropped.
$cookiePairs = array();
if (isset($options['cookie'])) {
    foreach ((array) $options['cookie'] as $pair) {
        if (strpos((string) $pair, '=') !== false) {
            $cookiePairs[] = trim((string) $pair);
        }
    }
}
$cookieHeader = implode('; ', $cookiePairs);

function lsc_out($message, $isError = false)
{
    global $quiet;
    if ($isError) {
        fwrite(STDERR, $message . PHP_EOL);
    } else if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

// Survive the terminal closing. Closing an SSH window sends SIGHUP to the
// foreground process, which kills it - this has happened mid rebuild in
// practice. Under cron there is no controlling terminal and the signal never
// arrives, but a manually started run has to survive a disconnect.
if (function_exists('pcntl_signal') && defined('SIGHUP')) {
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
    pcntl_signal(SIGHUP, SIG_IGN);
}

define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 4));

if (!is_file(JPATH_BASE . '/includes/defines.php')) {
    lsc_out('Joomla not found from ' . JPATH_BASE . ' - move this script or fix its location.', true);
    exit(1);
}

// Determine the public address BEFORE bootstrapping Joomla: the router and
// Uri::getInstance() read $_SERVER, which does not exist on the command line.
// Without this the crawled URLs would be built against an empty host and the
// crawl would run into the void.
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
    lsc_out('Site address unknown: $live_site is empty in configuration.php.', true);
    lsc_out('Run again with --url=https://your-domain.com', true);
    exit(1);
}

$parts = parse_url($siteUrl);
if (empty($parts['host'])) {
    lsc_out('Invalid address: ' . $siteUrl, true);
    exit(1);
}

$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
$path   = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

$_SERVER['HTTP_HOST']       = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$_SERVER['SERVER_NAME']     = $parts['host'];
$_SERVER['SERVER_PORT']     = isset($parts['port']) ? (string) $parts['port'] : ($scheme === 'https' ? '443' : '80');
$_SERVER['SCRIPT_NAME']     = $path . '/index.php';
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']     = $path . '/index.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'lscache_cli_rebuild';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
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

    // includes/app.php registers these aliases just before instantiating the
    // application. Without them the container cannot resolve SessionInterface,
    // which SiteApplication needs, and bootstrapping fails. This points at
    // 'session.cli' rather than 'session.web.site': that service is backed by
    // RuntimeStorage, in memory, so nothing tries to emit a cookie or a header
    // in the middle of a command line run.
    $container->alias('session', 'session.cli')
        ->alias('JSession', 'session.cli')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

    $app = $container->get(SiteApplication::class);
    Factory::$application = $app;

    // SiteApplication::execute() builds the extension namespace map right
    // after its system variable check. execute() is never called here - it
    // would render a page - so this step has to happen manually, or no
    // extension class is autoloadable and plugin service providers fail with
    // "Class ... not found".
    $app->createExtensionNamespaceMap();

    // Same reason: initialiseApp() builds the language object and registers it
    // with Factory. Without it getLanguage() returns null and the first call to
    // ->getTag() is fatal.
    $lang = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)
        ->createLanguage($app->get('language', 'en-GB'), (bool) $app->get('debug_lang', false));
    $app->loadLanguage($lang);
    Factory::$language = $app->getLanguage();

    // Plugin and component strings live in the admin language files. Without
    // this, messages come out as raw keys (COM_LSCACHE_ERR_...).
    $lang->load('com_lscache', JPATH_ADMINISTRATOR);
    $lang->load('plg_system_lscache', JPATH_ADMINISTRATOR);
} catch (\Throwable $e) {
    lsc_out('Could not bootstrap Joomla: ' . $e->getMessage(), true);
    lsc_out('  ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), true);
    exit(1);
}

lsc_out('Site      : ' . $siteUrl);
lsc_out('Starting  : ' . date('Y-m-d H:i:s'));
if (!$dryRun && $limit === 0 && !function_exists('pcntl_signal')) {
    lsc_out('Note      : pcntl is unavailable, this process will not survive the terminal');
    lsc_out('            closing. Run it with nohup ... & or under screen/tmux.');
}

try {
    // plg_behaviour_compat loads legacy class aliases (JFactory, JPlugin...) IN
    // ITS CONSTRUCTOR, precisely so they exist as early as possible. A web
    // request imports the behaviour group while the application boots; here it
    // has to be done manually, or any extension still relying on those aliases
    // fails to route with "Class JFactory not found".
    PluginHelper::importPlugin('behaviour');
    PluginHelper::importPlugin('system', 'lscache');
    $results = $app->triggerEvent('onLSCacheRebuildCli', array($limit, $dryRun, $check, $cookieHeader));
} catch (\Throwable $e) {
    lsc_out('Rebuild failed: ' . $e->getMessage(), true);
    lsc_out('  ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), true);
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
    lsc_out('The LSCache system plugin did not respond - is it enabled?', true);
    exit(1);
}

if (isset($result['concurrency'])) {
    lsc_out('Settings  : ' . (int) $result['concurrency'] . ' page(s) in parallel, '
          . (int) $result['delay'] . ' ms delay between requests');
}
if (!empty($result['cookie'])) {
    lsc_out('Variant   : ' . $result['cookie']);
}

if ($result['status'] === 'coverage') {
    $pct = $result['sampled'] ? round(100 * $result['hit'] / $result['sampled']) : 0;
    lsc_out(sprintf('Coverage  : %d/%d sampled URL(s) already cached (%d%%) out of %d total',
        $result['hit'], $result['sampled'], $pct, $result['total']));
    lsc_out('');
    lsc_out('  list band                   cached    cold      rate');
    foreach ($result['bands'] as $b) {
        $n = $b['hit'] + $b['miss'];
        lsc_out(sprintf('  %6d - %-6d %10d %9d   %3d%%',
            $b['from'], $b['to'], $b['hit'], $b['miss'], $n ? round(100 * $b['hit'] / $n) : 0));
    }
    lsc_out('');
    lsc_out('A rate rising from the start to the end of the list suggests the cache is');
    lsc_out('evicting for want of room. A uniformly low rate suggests pages are not being');
    lsc_out('cached at all.');
    exit(0);
}

switch ($result['status']) {
    case 'dry-run':
        if ($listOnly) {
            foreach ($result['urls'] as $url) {
                fwrite(STDOUT, $siteUrl . $url . PHP_EOL);
            }
            exit(empty($result['urls']) ? 1 : 0);
        }
        lsc_out('Dry run   : ' . (int) $result['total'] . ' URL(s) would be warmed.');
        foreach (array_slice($result['urls'], 0, 20) as $url) {
            lsc_out('  ' . $siteUrl . $url);
        }
        if ((int) $result['total'] > 20) {
            lsc_out('  ... and ' . ((int) $result['total'] - 20) . ' more.');
        }
        exit(empty($result['urls']) ? 1 : 0);

    case 'busy':
        lsc_out('A rebuild is already running: ' . $result['error'], true);
        exit(2);

    case 'completed':
        $seconds = (isset($result['finished'], $result['started']))
            ? (int) $result['finished'] - (int) $result['started']
            : 0;
        lsc_out(sprintf(
            'Done      : %d/%d page(s) cached in %d min %02d s.',
            (int) $result['success'],
            (int) $result['total'],
            intdiv($seconds, 60),
            $seconds % 60
        ));
        exit(0);

    default:
        lsc_out('Error: ' . (isset($result['error']) ? $result['error'] : 'unknown'), true);
        if (isset($result['current'], $result['total'])) {
            lsc_out('Stopped at ' . (int) $result['current'] . '/' . (int) $result['total'] . ' page(s).', true);
        }
        exit(1);
}
