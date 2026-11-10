<?php
$communityRating = $communityRatings[$animeId] ?? ['average' => null, 'count' => 0];
$communityAverage = $communityRating['average'];
$communityRatingCount = (int) $communityRating['count'];
$communityRatingLabel = $communityRatingCount === 1 ? 'ocena'
    : ($communityRatingCount % 10 >= 2 && $communityRatingCount % 10 <= 4
        && ($communityRatingCount % 100 < 12 || $communityRatingCount % 100 > 14) ? 'oceny' : 'ocen');
?>
<div class="community-anime-rating" data-community-anime-id="<?= $escape($animeId) ?>" data-community-rating-average="<?= $communityAverage !== null ? $escape(number_format($communityAverage, 2, '.', '')) : '' ?>" data-community-rating-count="<?= $escape($communityRatingCount) ?>">
    <dt>Ocena AniVibe</dt>
    <dd>
        <span class="community-anime-rating-average"><?= $communityAverage !== null ? $escape(number_format($communityAverage, 2, ',', '')) . ' / 10' : 'Brak ocen' ?></span>
        <span class="community-anime-rating-count"><?= $escape($communityRatingCount) ?> <?= $escape($communityRatingLabel) ?></span>
    </dd>
</div>
