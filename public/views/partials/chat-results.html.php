<section class="ai-panel ai-chat-results" aria-labelledby="assistant-results-title">
    <header class="ai-chat-section-head">
        <h2 id="assistant-results-title" tabindex="-1"><?= $escape($t('chat.results')) ?></h2>
        <?php if ($chatState['hasMore'] ?? false): ?>
            <form action="/chat" method="post" class="ai-chat-buttons" data-chat-form>
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="intent" value="more">
                <input type="hidden" name="requestId" value="<?= $escape($chatRequestId) ?>">
                <button type="button" class="ai-chat-button" data-chat-results-cancel hidden><?= $escape($t('chat.cancel')) ?></button>
                <button type="submit" class="ai-chat-button"><?= $escape($t('chat.more')) ?> <span aria-hidden="true">→</span></button>
            </form>
        <?php endif; ?>
    </header>
    <p class="ai-chat-feedback" role="status" data-chat-results-progress></p>
    <?php if ($cards === []): ?>
        <p class="ai-chat-hint"><?= $escape($t($turns === [] ? 'chat.results_waiting' : 'chat.results_empty')) ?></p>
    <?php else: ?>
        <ul class="ai-chat-result-grid">
            <?php foreach ($cards as $animeId => $card): ?>
                <li class="ai-chat-result">
                    <?php $anime = $card['anime']; ?>
                    <article class="anime-card" data-anime-id="<?= $escape($animeId) ?>" data-title="<?= $escape($anime['title']) ?>" data-score="<?= $escape($anime['score'] ?? '') ?>" data-episodes="<?= $escape($anime['episodes'] ?? '') ?>" data-year="<?= $escape($anime['year'] ?? '') ?>">
                        <div class="ai-chat-result-main">
                            <div class="anime-cover ai-chat-cover" aria-hidden="true">
                                <span class="anime-cover-placeholder"><span>✦</span></span>
                                <?php if (!empty($anime['coverUrl'])): ?>
                                    <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="96" height="144" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                <?php endif; ?>
                            </div>
                            <div class="ai-chat-result-copy">
                                <h3><a href="/anime/<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></a></h3>
                                <dl class="ai-chat-result-facts">
                                    <div>
                                        <dt><?= $escape($t('view.catalog.episodes')) ?></dt>
                                        <dd><?= $escape(($anime['episodes'] ?? 0) > 0 ? $anime['episodes'] : $t('view.catalog.unknown_plural')) ?></dd>
                                    </div>
                                    <?php if (isset($anime['score'])): ?>
                                        <div>
                                            <dt><?= $escape($t('view.catalog.source_score')) ?></dt>
                                            <dd><?= $escape(number_format($anime['score'], 2, ',', '') . ' / 10') ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (isset($anime['year'])): ?>
                                        <div>
                                            <dt><?= $escape($t('view.catalog.premiere')) ?></dt>
                                            <dd><?= $escape($anime['year']) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($anime['type']) && $anime['type'] !== 'UNKNOWN'): ?>
                                        <div>
                                            <dt><?= $escape($t('view.catalog.format')) ?></dt>
                                            <dd><?= $escape($anime['type']) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                                <ul class="anime-genres" aria-label="<?= $escape($t('view.catalog.genres')) ?>">
                                    <?php foreach ($anime['genres'] as $genre): ?>
                                        <li><?= $escape($translateText($genre)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php if ($card['quote'] !== null): ?>
                            <p class="ai-chat-reason">
                                <span><?= $escape($t('chat.source_quote')) ?></span>
                                <q lang="<?= $escape($anime['descriptionLanguage']) ?>"><?= $escape($card['quote']) ?></q>
                            </p>
                        <?php elseif (!empty($criteria['themes'])): ?>
                            <p class="ai-chat-reason"><?= $escape($t('chat.uncertain')) ?></p>
                        <?php elseif ($card['sharedTags'] !== []): ?>
                            <p class="ai-chat-reason"><?= $escape($t('chat.shared_tags', [
                                'value' => implode(', ', array_map($translateText, $card['sharedTags'])),
                            ])) ?></p>
                        <?php else: ?>
                            <p class="ai-chat-reason"><?= $escape($t($card['similarDescription'] ? 'chat.shared_words' : 'chat.catalog_match')) ?></p>
                        <?php endif; ?>
                        <details class="ai-chat-card-description">
                            <summary><?= $escape($t('view.catalog.short_description')) ?></summary>
                            <p<?= $anime['description'] !== '' ? ' lang="' . $escape($anime['descriptionLanguage']) . '"' : '' ?>><?= $escape($anime['description'] === '' ? $t('view.catalog.no_description') : $anime['description']) ?></p>
                            <a href="/anime/<?= $escape($animeId) ?>#anime-actions-title"><?= $escape($t('view.catalog.details_watchlist')) ?> <span aria-hidden="true">→</span></a>
                        </details>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
