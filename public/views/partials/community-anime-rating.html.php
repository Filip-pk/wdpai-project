<?php
$communityRating = $communityRatings[$animeId] ?? ['average' => null, 'count' => 0];
$communityAverage = $communityRating['average'];
$communityRatingCount = (int) $communityRating['count'];
$communityRatingIsSingular = $locale === 'ru'
    ? $communityRatingCount % 10 === 1 && $communityRatingCount % 100 !== 11
    : $communityRatingCount === 1;
$communityRatingLabel = $communityRatingIsSingular ? $t('view.catalog.rating_singular')
    : ($communityRatingCount % 10 >= 2 && $communityRatingCount % 10 <= 4
        && ($communityRatingCount % 100 < 12 || $communityRatingCount % 100 > 14) ? $t('view.catalog.rating_few') : $t('view.catalog.rating_many'));
?>
<div class="community-anime-rating" data-community-anime-id="<?= $escape($animeId) ?>" data-community-rating-average="<?= $communityAverage !== null ? $escape(number_format($communityAverage, 2, '.', '')) : '' ?>" data-community-rating-count="<?= $escape($communityRatingCount) ?>">
    <dt><?= $escape($t('view.catalog.community_score')) ?></dt>
    <dd>
        <span class="community-anime-rating-average"><?= $communityAverage !== null ? $escape(number_format($communityAverage, 2, ',', '')) . ' / 10' : $escape($t('view.catalog.no_ratings')) ?></span>
        <span class="community-anime-rating-count"><?= $escape($communityRatingCount) ?> <?= $escape($communityRatingLabel) ?></span>
    </dd>
</div>
