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

        $configured = (string) $settings->get('consentCookies', 'cookieconsent_status');
        $names      = array_filter(array_map('trim', explode(',', $configured)), 'strlen');

        if (!empty($names)) {
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

        $this->element['description'] = '<div id="lscache-cron-note">' . $description . '</div>' . $this->getCopyScript();

        return parent::getLabel();
    }

    /**
     * Bouton « Copier » a cote de chaque commande complete de la note ci-dessus.
     *
     * Les commandes sont deja composees avec les vraies valeurs de ce site (chemins,
     * binaire PHP) precisement pour etre collees telles quelles dans une crontab - les
     * recopier a la main depuis du texte affiche risque d'en oublier un bout. Injection
     * generique en JS plutot qu'un bouton fige par cle de langue : les blocs
     * conditionnels (consentement, appareil, stock) apparaissent ou disparaissent selon
     * les reglages sans jamais toucher a ce script.
     *
     * Filtre sur la presence de « rebuild.php » : la note contient aussi des <code>
     * ponctuels qui ne sont pas des commandes a copier (un nom d'option, un chemin cite
     * en exemple) - seuls les blocs qui invoquent reellement le script sont concernes.
     */
    protected function getCopyScript()
    {
        $copyLabel   = json_encode(Text::_('COM_LSCACHE_VARY_DIAG_COPY'));
        $copiedLabel = json_encode(Text::_('COM_LSCACHE_VARY_DIAG_COPIED'));

        return '
<script>
(function () {
    var box = document.getElementById("lscache-cron-note");
    if (!box) { return; }
    var codes = box.querySelectorAll("code");
    codes.forEach(function (codeEl) {
        if (codeEl.textContent.indexOf("rebuild.php") === -1) { return; }
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "btn btn-sm btn-outline-secondary ms-1";
        btn.style.verticalAlign = "middle";
        btn.textContent = ' . $copyLabel . ';
        btn.addEventListener("click", function () {
            var text = codeEl.textContent;
            function done() {
                var original = btn.textContent;
                btn.textContent = ' . $copiedLabel . ';
                setTimeout(function () { btn.textContent = original; }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
                return;
            }
            var ta = document.createElement("textarea");
            ta.value = text;
            ta.style.position = "fixed";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand("copy"); done(); } catch (e) {}
            document.body.removeChild(ta);
        });
        codeEl.insertAdjacentElement("afterend", btn);
    });
})();
</script>';
    }
}
