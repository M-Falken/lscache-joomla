<?php
/**
 * Encadré de diagnostic : dimensions de vary, couverture du préchauffage, double cache.
 *
 * Ce gabarit affiche, il ne calcule pas : tout vient de LSCacheVaryDiagnostic::collect().
 *
 * @author    Grégory Roussel <siriusocteam@gmail.com>
 * @copyright 2026 Grégory Roussel. All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   1.5.27
 * @link      https://github.com/M-Falken
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$diag     = $this->varyDiagnostic;
$coverage = $diag['coverage'];

$stateClass = array('ok' => 'success', 'short' => 'danger', 'stale' => 'warning',
                    'purged' => 'warning', 'none' => 'secondary');
$badge      = $stateClass[$coverage['state']] ?? 'secondary';
?>
<div class="card mb-3" id="lscache-vary-diagnostic" style="flex:1 1 380px;">
    <div class="card-header">
        <strong><?php echo Text::_('COM_LSCACHE_VARY_DIAG_TITLE'); ?></strong>
    </div>
    <div class="card-body">

        <?php // Ce sur quoi cet encadre a statue. La liste des reconstructions se
              // rafraichit en AJAX, cet encadre non : si une passe se termine ou si une
              // purge survient apres le rendu, les deux blocs se contredisent a l'ecran. ?>
        <script>
            window._lscDiagLatest = <?php echo (int) ($coverage['historyLatest'] ?? 0); ?>;
            window._lscDiagPurge  = <?php echo (int) ($coverage['purgeSeen'] ?? 0); ?>;
        </script>

        <div class="alert alert-info py-2" id="lscache-vary-stale" hidden>
            <?php echo Text::_('COM_LSCACHE_VARY_DIAG_OUTDATED'); ?>
            <button type="button" class="btn btn-sm btn-primary ms-2" id="lscache-vary-reload">
                <?php echo Text::_('COM_LSCACHE_VARY_DIAG_RELOAD'); ?>
            </button>
        </div>

        <?php // Sur une colonne etroite, un badge + une note un peu longue debordaient
              // et la ligne coupee reprenait au ras de la marge gauche de la carte, sous
              // le badge, au lieu de rester alignee sous le debut de la note. Chaque ligne
              // est desormais une rangee flex a 2 zones : le libelle+badge ne retrecit
              // jamais (flex-shrink-0), la note absorbe seule le manque de place et
              // s'enroule dans sa propre colonne (min-width:0 autorise ce retrecissement,
              // sinon un flex-item garde sa largeur de contenu et deborde). ?>
        <ul class="list-unstyled mb-3">
        <?php foreach ($diag['dimensions'] as $dimension) : ?>
            <li class="mb-1 d-flex align-items-start">
                <span class="d-inline-flex align-items-center flex-shrink-0" style="gap:.5rem;">
                    <span class="d-inline-block" style="min-width:7rem;">
                        <?php echo Text::_('COM_LSCACHE_VARY_DIAG_DIM_' . strtoupper($dimension['key'])); ?>
                    </span>
                    <?php if ($dimension['active']) : ?>
                        <span class="badge bg-info"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_STATE_ACTIVE'); ?></span>
                    <?php else : ?>
                        <span class="badge bg-secondary"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_STATE_INACTIVE'); ?></span>
                    <?php endif; ?>
                </span>

                <?php if (!empty($dimension['fedBy']) || $dimension['note'] !== '') : ?>
                    <span class="text-muted ms-2" style="min-width:0;">
                        <?php if (!empty($dimension['fedBy'])) : ?>
                            <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_FED_BY', implode(', ', $dimension['fedBy'])); ?>
                        <?php endif; ?>
                        <?php if ($dimension['note'] !== '') : ?>
                            <?php echo Text::_($dimension['note']); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>

        <p class="mb-3"><?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_BUCKETS', (int) $diag['buckets']); ?></p>

        <?php if ($diag['dimensions'][1]['active']) : ?>
            <div class="alert alert-warning py-2">
                <?php echo Text::_('COM_LSCACHE_VARY_DIAG_DEVICE_WARN'); ?>
            </div>
        <?php endif; ?>

        <hr>

        <p class="mb-2">
            <strong><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_TITLE'); ?></strong>
            <span class="badge bg-<?php echo $badge; ?> ms-2">
                <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_COVERAGE_COUNT',
                    (int) $coverage['warmed'], (int) $coverage['expected']); ?>
            </span>
        </p>

        <?php if ($coverage['state'] === 'ok') : ?>
            <p class="text-success mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_OK'); ?></p>
        <?php elseif ($coverage['state'] === 'none') : ?>
            <p class="text-muted mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_NONE'); ?></p>
        <?php elseif ($coverage['state'] === 'purged') : ?>
            <p class="text-warning mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_PURGED'); ?></p>
        <?php elseif ($coverage['state'] === 'stale') : ?>
            <p class="text-warning mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_STALE'); ?></p>
        <?php else : ?>
            <p class="mb-2"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_SHORT'); ?></p>
            <p class="mb-2"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_COVERAGE_HINT'); ?></p>
            <div class="position-relative">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="lscache-vary-copy" style="position:absolute;top:4px;right:4px;">
                    <?php echo Text::_('COM_LSCACHE_VARY_DIAG_COPY'); ?>
                </button>
                <pre class="mb-0" id="lscache-vary-commands" style="white-space:pre-wrap;word-break:break-all;padding-top:2.25rem;"><?php
                foreach ($coverage['missing'] as $bucket) {
                    $cmd = $diag['phpBinary'] . ' ' . $diag['cliPath'];

                    if ($bucket['cookie'] !== '') {
                        $cmd .= ' --cookie=' . $bucket['cookie'];
                    }

                    if ($bucket['mobile']) {
                        $cmd .= ' --user-agent=mobile';
                    }

                    $label = Text::_($bucket['labelKey']);

                    if ($bucket['mobile']) {
                        $label .= ' ' . Text::_('COM_LSCACHE_VARY_DIAG_LABEL_MOBILE');
                    }

                    // L'intitule est cite entre apostrophes dans un shell : une apostrophe
                    // dans une traduction couperait la commande en deux.
                    $cmd .= " --label='" . str_replace("'", '', $label) . "' --quiet";

                    echo htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') . "\n";
                }
                ?></pre>
            </div>
            <script>
            (function () {
                // Les commandes sont deja generees avec les vraies valeurs de ce site
                // (chemins, binaire PHP) : les recopier a la main depuis un <pre> risque
                // d'en oublier une ligne ou d'attraper un retour a la ligne en trop.
                var btn = document.getElementById('lscache-vary-copy');
                var pre = document.getElementById('lscache-vary-commands');
                if (!btn || !pre) { return; }

                function confirmCopied() {
                    var original = btn.textContent;
                    btn.textContent = <?php echo json_encode(Text::_('COM_LSCACHE_VARY_DIAG_COPIED')); ?>;
                    setTimeout(function () { btn.textContent = original; }, 2000);
                }

                btn.addEventListener('click', function () {
                    var text = pre.textContent;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(confirmCopied);
                        return;
                    }
                    // Repli pour un contexte sans l'API Clipboard (ancien navigateur,
                    // page non servie en HTTPS).
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); confirmCopied(); } catch (e) {}
                    document.body.removeChild(ta);
                });
            })();
            </script>
        <?php endif; ?>

        <?php if ($coverage['errors'] > 0) : ?>
            <p class="text-warning mt-2 mb-0">
                <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_COVERAGE_ERRORS', (int) $coverage['errors']); ?>
            </p>
        <?php endif; ?>

        <hr>

        <?php $purges   = $diag['purges']; ?>
        <?php $targeted = $diag['targeted']; ?>
        <?php // Colonnes selon la largeur de l'encadre, et non de l'ecran : il partage deja
              // sa ligne avec l'historique des reconstructions. ?>
        <div class="d-flex flex-wrap" style="gap:1rem 2rem;">
            <div style="flex:1 1 300px; min-width:0;">
                <p class="mb-2">
                    <strong><?php echo Text::_('COM_LSCACHE_VARY_DIAG_PURGES_TITLE'); ?></strong>
                    <?php if ($purges['last24h'] > 0) : ?>
                        <span class="badge bg-<?php echo $purges['alert'] ? 'danger' : 'secondary'; ?> ms-2">
                            <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_COUNT', (int) $purges['last24h']); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <?php if (empty($purges['entries'])) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_PURGES_NONE'); ?></p>
                <?php else : ?>
                    <ul class="list-unstyled small mb-2">
                    <?php foreach ($purges['entries'] as $p) : ?>
                        <li class="mb-1 d-flex align-items-start">
                            <span class="d-inline-flex align-items-center flex-shrink-0" style="gap:.5rem;">
                                <span class="d-inline-block" style="min-width:4rem;">
                                    <?php echo HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $p['time']), 'd/m H:i'); ?>
                                </span>
                                <span class="badge bg-<?php echo $p['origin'] === 'site' ? 'warning' : 'secondary'; ?>">
                                    <?php echo Text::_('COM_LSCACHE_VARY_DIAG_ORIGIN_' . strtoupper($p['origin'])); ?>
                                </span>
                            </span>
                            <span class="text-muted ms-2" style="min-width:0;"><?php echo htmlspecialchars($p['detail'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>

                    <?php if ($purges['interval'] !== null) : ?>
                        <p class="text-muted mb-1">
                            <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_INTERVAL',
                                round($purges['interval'] / 3600, 1), round($purges['ttl'] / 3600, 1)); ?>
                        </p>
                    <?php endif; ?>

                    <?php // Hors du test sur l'intervalle : une seule purge suffit a alerter. ?>
                    <?php if ($purges['alert']) : ?>
                        <p class="text-danger mb-0">
                            <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_ALERT', (int) $purges['siteCount']); ?>
                            <?php if (!empty($purges['rebuild'])) : ?>
                                <?php echo ' ' . Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_COST',
                                    LSCacheVaryDiagnostic::formatDuration($purges['rebuild'])); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($targeted['available'] || !empty($targeted['entries'])) : ?>
            <div style="flex:1 1 300px; min-width:0;">
                <p class="mb-2">
                    <strong><?php echo Text::_('COM_LSCACHE_VARY_DIAG_TARGETED_TITLE'); ?></strong>
                    <?php if ($targeted['last24h'] > 0) : ?>
                        <span class="badge bg-secondary ms-2">
                            <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_COUNT', (int) $targeted['last24h']); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <?php if (empty($targeted['entries'])) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_TARGETED_NONE'); ?></p>
                <?php else : ?>
                    <ul class="list-unstyled small mb-2">
                    <?php foreach ($targeted['entries'] as $p) : ?>
                        <?php $source = in_array($p['source'], array('order', 'stock'), true)
                            ? Text::_('COM_LSCACHE_VARY_DIAG_TARGETED_SOURCE_' . strtoupper($p['source'])) : $p['detail']; ?>
                        <li class="mb-1 d-flex align-items-start">
                            <span class="d-inline-flex align-items-center flex-shrink-0" style="gap:.5rem;">
                                <span class="d-inline-block" style="min-width:4rem;">
                                    <?php echo HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $p['time']), 'd/m H:i'); ?>
                                </span>
                                <?php // Une purge venue d'une page publique est ici une commande : rien d'alarmant. ?>
                                <span class="badge bg-<?php echo $p['origin'] === 'site' ? 'info' : 'secondary'; ?>">
                                    <?php echo Text::_('COM_LSCACHE_VARY_DIAG_ORIGIN_' . strtoupper($p['origin'])); ?>
                                </span>
                            </span>
                            <span class="ms-2" style="min-width:0;">
                                <span title="<?php echo htmlspecialchars(implode(', ', $p['names']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(implode(', ', array_slice($p['names'], 0, LSCacheVaryDiagnostic::TARGETED_NAMED)), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($p['more'] > 0) : ?>
                                        <?php echo Text::plural('COM_LSCACHE_VARY_DIAG_TARGETED_MORE', (int) $p['more']); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="d-block text-muted"><?php echo htmlspecialchars($source, ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>

                    <p class="text-muted mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_TARGETED_NOTE'); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <hr>

        <?php if ($diag['doubleCache']) : ?>
            <p class="text-danger mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_DOUBLECACHE_WARN'); ?></p>
        <?php else : ?>
            <p class="text-success mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_DOUBLECACHE_OK'); ?></p>
        <?php endif; ?>

    </div>
</div>
