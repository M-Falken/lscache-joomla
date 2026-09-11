<?php

/*
 *  Note affichant la commande de reconstruction en ligne de commande, chemins resolus.
 *
 *  @author     Grégory Roussel <siriusocteam@gmail.com>
 *  @copyright  2026 Grégory Roussel. All rights reserved.
 *  @license    http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *  @link       https://github.com/M-Falken
 */

defined('JPATH_BASE') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\NoteField;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;

FormHelper::loadFieldClass('note');

/**
 * Affiche la commande CLI et l'exemple de cron avec les chemins reels de cette
 * installation, plutot que des chemins generiques que l'admin devrait adapter.
 */
class JFormFieldNoteCron extends NoteField
{
    protected $type = 'NoteCron';

    /**
     * Chemin absolu du script de reconstruction en ligne de commande.
     */
    protected function getCliPath()
    {
        return JPATH_PLUGINS . '/system/lscache/cli/rebuild.php';
    }

    /**
     * Binaire PHP le plus probable pour la ligne de commande.
     *
     * PHP_BINARY pointe ici sur le binaire qui sert les pages (lsphp, php-fpm), pas sur
     * celui du shell : on ne peut donc que proposer une piste, d'ou l'avertissement dans
     * le texte. PHP_BINDIR est le meilleur indice disponible.
     */
    protected function getPhpBinary()
    {
        // Chemin generique en premier. C'est un exemple destine a etre adapte, et sur les
        // hebergements qui l'exposent - CloudLinux notamment - il suit la version PHP
        // choisie pour le compte, donc il survit a une migration de version, contrairement
        // a un chemin fige comme /opt/alt/php84/... Le binaire precis est donne dans le
        // texte a titre indicatif.
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
     * Binaire PHP qui sert reellement les pages.
     *
     * PHP_BINDIR est le repertoire du SAPI web, pas celui de la ligne de commande : les
     * deux coincident souvent mais rien ne le garantit. On l'affiche comme indication,
     * pas comme valeur a copier aveuglement.
     */
    protected function getDetectedPhpBinary()
    {
        return PHP_BINDIR . '/php';
    }

    /**
     * Option --url a ajouter, ou chaine vide.
     *
     * Sans requete HTTP, la ligne de commande n'a rien dont deduire le domaine. Le script
     * se rabat sur $live_site, mais ce champ est souvent vide : on compose alors l'option
     * ici plutot que de laisser l'admin decouvrir l'echec au premier passage du cron.
     */
    protected function getUrlOption()
    {
        $liveSite = trim((string) Factory::getApplication()->get('live_site', ''));
        if ($liveSite !== '') {
            return '';
        }

        return ' --url=' . rtrim(Uri::root(), '/');
    }

    /**
     * Premier nom de cookie configure pour distinguer un visiteur decide, ou la valeur
     * par defaut si le reglage est vide - jamais une chaine vide, sinon l'exemple de
     * commande --cookie=... serait invalide.
     */
    protected function getConsentCookieName()
    {
        $settings   = ComponentHelper::getParams('com_lscache');
        $configured = (string) $settings->get('consentCookies', '');
        $names      = array_filter(array_map('trim', explode(',', $configured)), 'strlen');

        return $names ? reset($names) : 'cookieconsent_status';
    }

    /**
     * Blocs d'explication qui ne concernent que les dimensions de vary REELLEMENT actives.
     *
     * Enseigner la chaine a trois passes a un site dont le vary consentement est coupe
     * revient a lui faire tripler son crawl pour rien - et c'est du bruit qui masque
     * l'unique commande dont il a besoin. Chaque bloc suit donc son reglage.
     */
    protected function getConditionalBlocks()
    {
        $settings = ComponentHelper::getParams('com_lscache');
        $blocs    = array();

        if ($settings->get('pagecacheVary', 1)) {
            $blocs[] = Text::_('COM_LSCACHE_FIELD_CRON_NOTE_CONSENT');
        }

        if ($settings->get('mobileCacheVary', 0) == 1) {
            $blocs[] = Text::_('COM_LSCACHE_FIELD_CRON_NOTE_DEVICE');
        }

        // --purge-changed repose sur l'integration VirtueMart : sans elle, la tache
        // echouerait a chaque passage du cron.
        if (ComponentHelper::isEnabled('com_virtuemart')) {
            $blocs[] = Text::_('COM_LSCACHE_FIELD_CRON_NOTE_PURGECHANGED');
        }

        return implode('', $blocs);
    }

    protected function getLabel()
    {
        $description = Text::_((string) $this->element['description']);
        $description = str_replace('{conditionnels}', $this->getConditionalBlocks(), $description);
        $description = str_replace('{clipath}', $this->getCliPath(), $description);
        $description = str_replace('{phpbin}', $this->getPhpBinary(), $description);
        $description = str_replace('{urlopt}', $this->getUrlOption(), $description);
        $description = str_replace('{phpver}', PHP_VERSION, $description);
        $description = str_replace('{phpdetected}', $this->getDetectedPhpBinary(), $description);
        $description = str_replace('{consentcookie}', $this->getConsentCookieName(), $description);

        $this->element['description'] = $description;

        return parent::getLabel();
    }
}
