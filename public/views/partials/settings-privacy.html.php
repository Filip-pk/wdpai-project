<?php
$privacyFeedback = $_SESSION['privacy_feedback'] ?? [];
unset($_SESSION['privacy_feedback']);
?>
<section class="settings-card" id="privacy" aria-labelledby="privacy-title">
    <h2 id="privacy-title">Prywatność</h2>
    <p>Zdecyduj, co pokazujesz na publicznym profilu i kto może do Ciebie pisać.</p>
    <?php if (isset($privacyFeedback['success'])): ?>
        <p class="settings-notice" role="status"><?= $escape($privacyFeedback['success']) ?></p>
    <?php endif; ?>
    <?php if (!empty($privacyFeedback['errors'])): ?>
        <div class="settings-notice settings-error" role="alert">
            <?php foreach ($privacyFeedback['errors'] as $privacyError): ?>
                <p><?= $escape($privacyError) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form action="/settings/privacy" method="post" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <div class="settings-field">
            <label for="message-policy">Kto może wysyłać do Ciebie wiadomości?</label>
            <select id="message-policy" name="messagePolicy" aria-describedby="message-policy-help">
                <?php foreach (['everyone' => 'Wszyscy', 'existing' => 'Tylko istniejące rozmowy', 'nobody' => 'Nikt'] as $privacyValue => $privacyLabel): ?>
                    <option value="<?= $escape($privacyValue) ?>"<?= $privacySettings['messagePolicy'] === $privacyValue ? ' selected' : '' ?>><?= $escape($privacyLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <p id="message-policy-help" class="settings-help">„Tylko istniejące rozmowy” pozwala kontynuować wcześniej rozpoczęte rozmowy. „Nikt” wyłącza wszystkie nowe wiadomości przychodzące. Historia rozmów pozostaje dostępna.</p>
        </div>

        <div class="settings-field">
            <label for="public-ratings">Oceny na publicznym profilu</label>
            <select id="public-ratings" name="publicRatings">
                <option value="1"<?= $privacySettings['publicRatings'] ? ' selected' : '' ?>>Widoczne</option>
                <option value="0"<?= !$privacySettings['publicRatings'] ? ' selected' : '' ?>>Ukryte</option>
            </select>
            <p class="settings-help">Oceny pozostają domyślnie ukryte. Możesz udostępnić je na kartach publicznej watchlisty. Zbiorcza ocena społeczności pozostaje anonimowa.</p>
        </div>

        <div class="settings-field">
            <label for="public-statistics">Statystyki i poziom na publicznym profilu</label>
            <select id="public-statistics" name="publicStatistics">
                <option value="1"<?= $privacySettings['publicStatistics'] ? ' selected' : '' ?>>Widoczne</option>
                <option value="0"<?= !$privacySettings['publicStatistics'] ? ' selected' : '' ?>>Ukryte</option>
            </select>
            <p class="settings-help">Ukrywa podsumowanie oglądania i EXP na profilu. Watchlista i ranking użytkowników nadal są publiczne.</p>
        </div>
        <button class="settings-save" type="submit">Zapisz prywatność</button>
    </form>
    <p><a href="/messages/blocked">Zarządzaj zablokowanymi użytkownikami →</a></p>
</section>
