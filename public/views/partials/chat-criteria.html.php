<?php
$labels = [
    'minEpisodes' => 'chat.min_episodes', 'maxEpisodes' => 'chat.max_episodes',
    'minYear' => 'chat.min_year', 'maxYear' => 'chat.max_year',
    'minScore' => 'chat.min_score', 'maxScore' => 'chat.max_score', 'format' => 'chat.format',
];
$labelsShown = isset($criteria['ranking']) ? [$t('chat.ranking_top')] : [];
foreach ($labels as $key => $label) {
    if (isset($criteria[$key])) {
        $labelsShown[] = $t($label, ['value' => $criteria[$key]]);
    }
}
foreach (['status', 'length'] as $key) {
    if (isset($criteria[$key])) {
        $labelsShown[] = $t('chat.' . $criteria[$key]);
    }
}
foreach (['requireTags' => 'chat.required_tag', 'excludeTags' => 'chat.excluded_tag'] as $key => $label) {
    foreach ($criteria[$key] ?? [] as $tag) {
        $labelsShown[] = $t($label, ['value' => $translateText(\App\Repository\AnimeRepository::genreLabel($tag))]);
    }
}
$references = array_column($chatState['references'] ?? [], 'title');
foreach ($references ?: ($criteria['references'] ?? []) as $reference) {
    $labelsShown[] = $t('chat.reference', ['value' => $reference]);
}
if (!empty($criteria['themes'])) {
    $labelsShown[] = $t('chat.themes', ['value' => implode(', ', $criteria['themes'])]);
}
foreach (['motifs' => 'chat.motifs', 'excludeMotifs' => 'chat.exclude_motifs'] as $key => $label) {
    if (!empty($criteria[$key])) {
        $motifLabels = array_map(static fn (string $motif): string => \App\Model\RecommendationChatMotifs::label($motif, $t),
            $criteria[$key]);
        $labelsShown[] = $t($label, ['value' => implode(', ', $motifLabels)]);
    }
}
?>
<section class="ai-panel ai-criteria-panel" aria-labelledby="assistant-criteria-title">
    <h2 id="assistant-criteria-title"><?= $escape($t('chat.criteria')) ?></h2>
    <?php if ($labelsShown === []): ?>
        <p class="ai-chat-hint"><?= $escape($t($turns === [] ? 'chat.no_criteria' : 'chat.no_recognized_criteria')) ?></p>
    <?php else: ?>
        <ul class="ai-criteria-list">
            <?php foreach ($labelsShown as $label): ?>
                <li><?= $escape($label) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="ai-chat-hint"><?= $escape($t('chat.edit_hint')) ?></p>
    <?php endif; ?>
</section>
