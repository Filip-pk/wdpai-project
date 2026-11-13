<?php
$privacyFeedback = $_SESSION['privacy_feedback'] ?? [];
unset($_SESSION['privacy_feedback']);
?>
<section class="settings-card" id="privacy" aria-labelledby="privacy-title">
    <h2 id="privacy-title"><?= $escape($t('view.interface.privacy')) ?></h2>
    <p><?= $escape($t('view.interface.choose_what_appears_on_your_public_profile_and_who_can_message_you')) ?></p>
    <?php if (isset($privacyFeedback['success'])): ?>
        <p class="settings-notice" role="status"><?= $escape($translateText($privacyFeedback['success'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($privacyFeedback['errors'])): ?>
        <div class="settings-notice settings-error" role="alert">
            <?php foreach ($privacyFeedback['errors'] as $privacyError): ?>
                <p><?= $escape($translateText($privacyError)) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form action="/settings/privacy" method="post" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <div class="settings-field">
            <label for="message-policy"><?= $escape($t('view.interface.who_can_message_you')) ?></label>
            <select id="message-policy" name="messagePolicy" aria-describedby="message-policy-help">
                <?php foreach (['everyone' => $t('view.interface.everyone'), 'existing' => $t('view.interface.existing_conversations_only'), 'nobody' => $t('view.interface.nobody')] as $privacyValue => $privacyLabel): ?>
                    <option value="<?= $escape($privacyValue) ?>"<?= $privacySettings['messagePolicy'] === $privacyValue ? ' selected' : '' ?>><?= $escape($privacyLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <p id="message-policy-help" class="settings-help"><?= $escape($t('view.interface.existing_conversations_only_allows_ongoing_conversations_to_continue_nobody_blocks_all_new')) ?></p>
        </div>

        <div class="settings-field">
            <label for="public-ratings"><?= $escape($t('view.interface.ratings_on_your_public_profile')) ?></label>
            <select id="public-ratings" name="publicRatings">
                <option value="1"<?= $privacySettings['publicRatings'] ? ' selected' : '' ?>><?= $escape($t('view.interface.visible')) ?></option>
                <option value="0"<?= !$privacySettings['publicRatings'] ? ' selected' : '' ?>><?= $escape($t('view.interface.hidden')) ?></option>
            </select>
            <p class="settings-help"><?= $escape($t('view.interface.ratings_are_hidden_by_default_you_can_show_them_on_your_public_watchlist_cards_the_communi')) ?></p>
        </div>

        <div class="settings-field">
            <label for="public-statistics"><?= $escape($t('view.interface.statistics_and_level_on_your_public_profile')) ?></label>
            <select id="public-statistics" name="publicStatistics">
                <option value="1"<?= $privacySettings['publicStatistics'] ? ' selected' : '' ?>><?= $escape($t('view.interface.visible')) ?></option>
                <option value="0"<?= !$privacySettings['publicStatistics'] ? ' selected' : '' ?>><?= $escape($t('view.interface.hidden')) ?></option>
            </select>
            <p class="settings-help"><?= $escape($t('view.interface.hides_viewing_statistics_and_exp_on_your_profile_your_watchlist_and_the_user_ranking_remai')) ?></p>
        </div>
        <button class="settings-save" type="submit"><?= $escape($t('view.interface.save_privacy_settings')) ?></button>
    </form>
    <p><a href="/messages/blocked"><?= $escape($t('view.interface.manage_blocked_users')) ?></a></p>
</section>
