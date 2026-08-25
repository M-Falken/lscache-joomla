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
        $candidates = array(
            PHP_BINDIR . '/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
        );

        foreach ($candidates as $candidate) {
            if (@is_file($candidate)) {
                return $candidate;
            }
        }

        return '/usr/bin/php';
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

    protected function getLabel()
    {
        $description = Text::_((string) $this->element['description']);
        $description = str_replace('{clipath}', $this->getCliPath(), $description);
        $description = str_replace('{phpbin}', $this->getPhpBinary(), $description);
        $description = str_replace('{urlopt}', $this->getUrlOption(), $description);
        $description = str_replace('{phpver}', PHP_VERSION, $description);

        $this->element['description'] = $description;

        return parent::getLabel();
    }
}
