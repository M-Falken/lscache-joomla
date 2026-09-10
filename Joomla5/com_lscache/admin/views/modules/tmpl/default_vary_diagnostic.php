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
<div class="card mb-3" id="lscache-vary-diagnostic">
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

        <ul class="list-unstyled mb-3">
        <?php foreach ($diag['dimensions'] as $dimension) : ?>
            <li class="mb-1">
                <span class="d-inline-block" style="min-width:11rem;">
                    <?php echo Text::_('COM_LSCACHE_VARY_DIAG_DIM_' . strtoupper($dimension['key'])); ?>
                </span>
                <?php if ($dimension['active']) : ?>
                    <span class="badge bg-info"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_STATE_ACTIVE'); ?></span>
                <?php else : ?>
                    <span class="badge bg-secondary"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_STATE_INACTIVE'); ?></span>
                <?php endif; ?>

                <?php if (!empty($dimension['fedBy'])) : ?>
                    <span class="text-muted ms-2">
                        <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_FED_BY', implode(', ', $dimension['fedBy'])); ?>
                    </span>
                <?php endif; ?>

                <?php if ($dimension['note'] !== '') : ?>
                    <span class="text-muted ms-2"><?php echo Text::_($dimension['note']); ?></span>
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
            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-all;"><?php
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
        <?php endif; ?>

        <?php if ($coverage['errors'] > 0) : ?>
            <p class="text-warning mt-2 mb-0">
                <?php echo Text::sprintf('COM_LSCACHE_VARY_DIAG_COVERAGE_ERRORS', (int) $coverage['errors']); ?>
            </p>
        <?php endif; ?>

        <hr>

        <?php $purges = $diag['purges']; ?>
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
                <li>
                    <span class="d-inline-block" style="min-width:9rem;">
                        <?php echo HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $p['time']), 'd/m H:i'); ?>
                    </span>
                    <span class="badge bg-<?php echo $p['origin'] === 'site' ? 'warning' : 'secondary'; ?>">
                        <?php echo Text::_('COM_LSCACHE_VARY_DIAG_ORIGIN_' . strtoupper($p['origin'])); ?>
                    </span>
                    <span class="text-muted ms-2"><?php echo htmlspecialchars($p['detail'], ENT_QUOTES, 'UTF-8'); ?></span>
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
                    <?php if ($purges['rebuild'] !== null) : ?>
                        <?php echo ' ' . Text::sprintf('COM_LSCACHE_VARY_DIAG_PURGES_COST',
                            (int) round($purges['rebuild'] / 60)); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <hr>

        <?php if ($diag['doubleCache']) : ?>
            <p class="text-danger mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_DOUBLECACHE_WARN'); ?></p>
        <?php else : ?>
            <p class="text-success mb-0"><?php echo Text::_('COM_LSCACHE_VARY_DIAG_DOUBLECACHE_OK'); ?></p>
        <?php endif; ?>

    </div>
</div>
