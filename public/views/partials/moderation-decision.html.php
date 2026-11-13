<form class="community-form moderation-decision" method="post" action="<?= $escape($action) ?>" aria-label="<?= $escape($label) ?>">
    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="requestKey" value="<?= $escape(bin2hex(random_bytes(16))) ?>">
    <?php foreach ($extra as $name => $value): ?>
        <input type="hidden" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>">
    <?php endforeach; ?>
    <div class="community-field">
        <label for="<?= $escape($formId) ?>-reason"><?= $escape($label) ?> — <?= $escape($t('moderation.reason')) ?></label>
        <textarea id="<?= $escape($formId) ?>-reason" name="reason" rows="2" maxlength="1000" required></textarea>
    </div>
    <?php if ($withDuration): ?>
        <div class="community-field">
            <label for="<?= $escape($formId) ?>-duration"><?= $escape($t('moderation.duration')) ?></label>
            <select id="<?= $escape($formId) ?>-duration" name="duration" required>
                <?php foreach ([3600 => 'hour', 86400 => 'day', 259200 => 'three_days', 604800 => 'week', 2592000 => 'month'] as $seconds => $durationKey): ?>
                    <option value="<?= $escape($seconds) ?>"><?= $escape($t('moderation.duration.' . $durationKey)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <button type="submit"><?= $escape($label) ?></button>
</form>
