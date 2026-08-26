<?php

/*
 *  Displays the CLI rebuild command with this install's real paths resolved,
 *  rather than generic placeholders the admin would have to work out.
 *
 *  @copyright  Copyright (c) 2026 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */

defined('JPATH_BASE') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;

FormHelper::loadFieldClass('note');

/**
 * Renders the CLI command and cron example with this installation's actual
 * paths substituted in.
 */
class JFormFieldNoteCron extends NoteField
{
    protected $type = 'NoteCron';

    /**
     * Absolute path to the command line rebuild script.
     */
    protected function getCliPath()
    {
        return JPATH_PLUGINS . '/system/lscache/cli/rebuild.php';
    }

    /**
     * A generic PHP binary path first: this is an example meant to be adapted,
     * and on hosts that expose it as a dispatcher - CloudLinux among them - it
     * follows the account's currently selected PHP version, so it survives a
     * version upgrade where a pinned path like /opt/alt/php84/... would not.
     * The exact binary serving pages is shown separately, for reference.
     */
    protected function getPhpBinary()
    {
        if (@is_file('/usr/bin/php')) {
            return '/usr/bin/php';
        }

        foreach (array(PHP_BINDIR . '/php', '/usr/local/bin/php') as $candidate) {
            if (@is_file($candidate)) {
                return $candidate;
            }
        }

        return '/usr/bin/php';
    }

    /**
     * The PHP binary actually serving pages.
     *
     * PHP_BINDIR is the web SAPI's directory, not necessarily the command
     * line's: the two often match but nothing guarantees it. Shown as a hint,
     * not a value to copy blindly.
     */
    protected function getDetectedPhpBinary()
    {
        return PHP_BINDIR . '/php';
    }

    /**
     * The --url option to add, or an empty string.
     *
     * There is no HTTP request on the command line, so the script has nothing
     * to derive the domain from. It falls back to $live_site, but that field is
     * often empty - composing the option here avoids the admin discovering the
     * failure on the first cron run.
     */
    protected function getUrlOption()
    {
        $liveSite = trim((string) Factory::getApplication()->get('live_site', ''));
        if ($liveSite !== '') {
            return '';
        }

        return ' --url=' . rtrim(Uri::root(), '/');
    }

    protected function getLabel()
    {
        $description = Text::_((string) $this->element['description']);
        $description = str_replace('{clipath}', $this->getCliPath(), $description);
        $description = str_replace('{phpbin}', $this->getPhpBinary(), $description);
        $description = str_replace('{urlopt}', $this->getUrlOption(), $description);
        $description = str_replace('{phpver}', PHP_VERSION, $description);
        $description = str_replace('{phpdetected}', $this->getDetectedPhpBinary(), $description);

        $this->element['description'] = $description;

        return parent::getLabel();
    }
}
