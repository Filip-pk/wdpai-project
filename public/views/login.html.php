<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page auth-page-login" aria-labelledby="login-title">
    <a href="/" class="auth-back">
        <span aria-hidden="true">←</span>
        <?= $escape($t('view.interface.back_to_home')) ?>
    </a>

    <div class="auth-card">
        <aside class="auth-showcase" aria-labelledby="showcase-title">
            <img
                class="auth-image"
                src="<?= $assetUrl('/img/neo-tokyo-guide.jpg') ?>"
                alt=""
                fetchpriority="high"
                decoding="async"
            >

            <div class="auth-showcase-content">
                <p class="auth-kicker">
                    <span aria-hidden="true">🌸</span>
                    AniVibe Neural Core
                </p>

                <h2 id="showcase-title"><?= $escape($t('view.interface.connect_to_the_cyberworld')) ?></h2>

                <p class="auth-showcase-description">
                    <?= $escape($t('view.interface.your_anime_your_stories_a_new_dimension_of_discovery')) ?>
                </p>
            </div>
        </aside>

        <div class="auth-panel">
            <header class="auth-heading">
                <p class="auth-kicker"><span class="auth-status-dot" aria-hidden="true"></span> <?= $escape($t('view.interface.your_adventure_continues')) ?></p>

                <h1 id="login-title">
                    <?= $escape($t('view.interface.welcome_back')) ?>
                    <span aria-hidden="true">👋</span>
                </h1>

                <p>
                    <?= $escape($t('view.interface.sign_in_to_chat_with_characters_and_discover_anime_with_ai')) ?>
                </p>
            </header>

            <p id="login-info" class="auth-info">
                <?= $escape($t('view.interface.sign_in_with_your_account_email_and_password')) ?>
            </p>

            <?php if ($registered ?? false): ?>
                <p class="auth-notice" role="status">
                    <?= $escape($t('view.interface.your_account_has_been_created_you_can_now_sign_in')) ?>
                </p>
            <?php endif; ?>

            <?php if (($mailStatus ?? null) === 'sent'): ?>
                <p class="auth-notice" role="status" data-mail-status="sent">
                    <?= $escape($t('view.interface.we_sent_a_welcome_email_to_the_address_you_provided')) ?>
                </p>
            <?php elseif (($mailStatus ?? null) === 'failed'): ?>
                <p class="auth-notice auth-notice--warning" role="status" data-mail-status="failed">
                    <?= $escape($t('view.interface.the_welcome_email_could_not_be_sent_your_account_is_active_and_you_can_sign_in')) ?>
                </p>
            <?php endif; ?>

            <?php if (($errors ?? []) !== []): ?>
                <div class="auth-notice" role="alert">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= $escape($translateText($error)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form
                action="/login"
                method="post"
                class="auth-form"
                aria-describedby="login-info"
            >
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">

                <fieldset class="auth-fields">
                    <legend class="auth-visually-hidden">
                        <?= $escape($t('view.interface.sign_in_details')) ?>
                    </legend>

                    <div class="auth-field">
                        <label for="login-email">
                            <?= $escape($t('view.interface.email')) ?>
                        </label>

                        <input
                            id="login-email"
                            name="email"
                            type="email"
                            autocomplete="username"
                            maxlength="255"
                            value="<?= $escape($email ?? '') ?>"
                            placeholder="<?= $escape($t('view.interface.e_g_otaku_example_com')) ?>"
                            required
                        >
                    </div>

                    <div class="auth-field">
                        <label for="login-password">
                            <?= $escape($t('view.interface.password')) ?>
                        </label>

                        <input
                            id="login-password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="<?= $escape($t('view.interface.enter_your_password')) ?>"
                            required
                        >
                    </div>

                    <div class="auth-password-help">
                        <button
                            type="button"
                            class="auth-text-button"
                            disabled
                        >
                            <?= $escape($t('view.interface.forgot_your_password')) ?>
                        </button>
                    </div>

                    <button
                        type="submit"
                        class="auth-submit"
                    >
                        <span aria-hidden="true">→</span>
                        <?= $escape($t('view.interface.sign_in')) ?>
                    </button>
                </fieldset>
            </form>

            <div class="auth-register">
                <p><?= $escape($t('view.interface.don_t_have_an_account_yet')) ?></p>

                <a
                    href="/register"
                    class="auth-text-button"
                >
                    <?= $escape($t('view.interface.sign_up')) ?>
                </a>
            </div>

            <a href="/dashboard" class="auth-demo-link">
                <?= $escape($t('view.interface.view_the_demo_dashboard')) ?>
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
