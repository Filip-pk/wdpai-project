<?php
require __DIR__ . '/partials/header.html.php';

$hasConversation = $conversation !== null || $isComposing;
$conversationId = $conversation['id'] ?? null;
$recipientId = $isComposing ? $recipient['id'] : ($conversation['recipientId'] ?? null);
$recipientName = $isComposing ? $recipient['name'] : ($conversation['recipientName'] ?? '');
$recipientAvatarUrl = $isComposing ? $recipient['avatar_url'] : ($conversation['recipientAvatarUrl'] ?? null);
$lastVisibleMessageId = $messagePage['entries'] === [] ? null : max(array_column($messagePage['entries'], 'id'));
$messageDate = static fn (string $value): DateTimeImmutable =>
    (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Warsaw'));
?>

<div class="messages-page<?= $isComposing ? ' is-composing' : '' ?>">
    <header class="messages-page-heading social-hero">
        <div class="messages-heading-copy">
            <p class="social-kicker">Społeczność · prywatne rozmowy</p>
            <h1 class="social-title"><?= $isComposing ? 'Nowa wiadomość' : 'Wiadomości' ?></h1>
            <p class="social-description">Rozmawiaj z innymi fanami anime. Dostęp do rozmowy mają tylko jej uczestnicy.</p>
        </div>
        <span class="messages-privacy">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
            Tylko dla uczestników
        </span>
    </header>

    <nav class="social-tabs" aria-label="Sekcje społeczności">
        <a href="/messages" aria-current="page">Wiadomości</a>
        <a href="/notifications/comments">Powiadomienia</a>
        <a href="/messages/blocked">Zablokowani użytkownicy</a>
    </nav>

    <?php if ($sent): ?>
        <p class="messages-notice" data-kind="success" role="status">Wiadomość została wysłana.</p>
    <?php endif; ?>
    <?php if (($_GET['reportSaved'] ?? null) === '1'): ?>
        <p class="messages-notice" data-kind="success" role="status">Zgłoszenie zostało przekazane do moderacji.</p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div id="message-errors" class="messages-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($hasConversation): ?>
        <a class="messages-back" href="/messages">← Wszystkie rozmowy</a>
    <?php endif; ?>

    <div class="messages-layout<?= $hasConversation ? ' has-conversation' : '' ?>">
        <aside class="messages-inbox" aria-labelledby="conversations-title" data-conversations-total="<?= $escape($conversationList['total']) ?>" data-conversations-page="<?= $escape($conversationList['page']) ?>" data-conversations-total-pages="<?= $escape($conversationList['totalPages']) ?>">
            <header class="messages-inbox-heading">
                <h2 id="conversations-title">Twoje rozmowy</h2>
                <span class="social-pill" aria-label="<?= $escape('Liczba rozmów: ' . $conversationList['total']) ?>"><?= $escape($conversationList['total']) ?></span>
            </header>

            <?php if ($conversationList['entries'] === []): ?>
                <div class="messages-empty social-empty">
                    <span class="social-empty-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8Z"/><path d="M7 9h9M7 13h6"/></svg>
                    </span>
                    <p>Nie masz jeszcze żadnej rozmowy.</p>
                    <p>Otwórz profil użytkownika i wybierz „Napisz wiadomość”.</p>
                    <a href="/ranking">Poznaj społeczność AniVibe <span aria-hidden="true">→</span></a>
                </div>
            <?php else: ?>
                <ul class="conversation-list" aria-label="Lista Twoich rozmów">
                    <?php foreach ($conversationList['entries'] as $listedConversation): ?>
                        <li>
                            <a class="conversation-link" href="/messages/<?= $escape($listedConversation['id']) ?>" data-nav-conversation-id="<?= $escape($listedConversation['id']) ?>"<?= $conversationId === $listedConversation['id'] ? ' aria-current="page"' : '' ?>>
                                <span class="messages-avatar" aria-hidden="true">
                                    <?php if ($listedConversation['recipientAvatarUrl'] !== null): ?>
                                        <img src="<?= $escape($listedConversation['recipientAvatarUrl']) ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        ✦
                                    <?php endif; ?>
                                </span>
                                <span class="conversation-copy">
                                    <span class="conversation-title-row">
                                        <span class="conversation-name"><?= $escape($listedConversation['recipientName']) ?></span>
                                        <span class="conversation-unread" data-conversation-unread="<?= $escape($listedConversation['id']) ?>" aria-label="<?= $escape('Nieprzeczytane wiadomości: ' . $listedConversation['unreadCount']) ?>"<?= $listedConversation['unreadCount'] === 0 ? ' hidden' : '' ?>><?= $escape($listedConversation['unreadCount']) ?></span>
                                    </span>
                                    <span class="conversation-preview"><?php if ($listedConversation['lastMessageSenderId'] === $accountId): ?>Ty: <?php endif; ?><?= $escape($listedConversation['lastMessagePreview'] ?? 'Rozpocznij rozmowę') ?></span>
                                    <?php if ($listedConversation['lastMessageAt'] !== null): ?>
                                        <?php $lastMessageDate = $messageDate($listedConversation['lastMessageAt']); ?>
                                        <time datetime="<?= $escape($lastMessageDate->format(DATE_ATOM)) ?>"><?= $escape($lastMessageDate->format('d.m.Y H:i')) ?></time>
                                    <?php endif; ?>
                                </span>
                                <span class="conversation-chevron" aria-hidden="true">›</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($conversationList['totalPages'] > 1): ?>
                <nav class="messages-pagination" aria-label="Strony listy rozmów">
                    <?php if ($conversationList['page'] > 1): ?>
                        <a href="<?= $escape($conversationList['previousUrl']) ?>" rel="prev">← Poprzednia</a>
                    <?php endif; ?>
                    <span>Strona <?= $escape($conversationList['page']) ?> z <?= $escape($conversationList['totalPages']) ?></span>
                    <?php if ($conversationList['page'] < $conversationList['totalPages']): ?>
                        <a href="<?= $escape($conversationList['nextUrl']) ?>" rel="next">Następna →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </aside>

        <?php if ($hasConversation): ?>
            <section class="messages-thread" aria-labelledby="conversation-title"<?php if ($conversationId !== null): ?> data-conversation-id="<?= $escape($conversationId) ?>"<?php endif; ?> data-conversation-recipient-id="<?= $escape($recipientId) ?>">
                <header class="messages-thread-heading">
                    <a class="messages-recipient" href="/users/<?= $escape($recipientId) ?>" aria-label="<?= $escape('Profil rozmówcy: ' . $recipientName) ?>">
                        <span class="messages-avatar" aria-hidden="true">
                            <?php if ($recipientAvatarUrl !== null): ?>
                                <img src="<?= $escape($recipientAvatarUrl) ?>" alt="" width="40" height="40" decoding="async">
                            <?php else: ?>
                                ✦
                            <?php endif; ?>
                        </span>
                        <div class="messages-recipient-copy">
                            <span class="messages-context"><?= $isComposing ? 'Nowa rozmowa' : 'Rozmowa prywatna' ?></span>
                            <h2 id="conversation-title"><?= $escape($recipientName) ?></h2>
                        </div>
                    </a>
                    <div class="messages-thread-actions">
                        <?php if (!$isComposing): ?>
                            <a class="messages-refresh" href="/messages/<?= $escape($conversationId) ?>#message-history">Odśwież rozmowę <span aria-hidden="true">↻</span></a>
                        <?php endif; ?>
                        <form action="/users/<?= $escape($recipientId) ?>/<?= $isBlockedByMe ? 'unblock' : 'block' ?>" method="post">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <?php if ($conversationId !== null): ?><input type="hidden" name="conversationId" value="<?= $escape($conversationId) ?>"><?php endif; ?>
                            <button class="messages-secondary-button" type="submit"><?= $isBlockedByMe ? 'Odblokuj użytkownika' : 'Zablokuj użytkownika' ?></button>
                        </form>
                    </div>
                </header>

                <?php if (!$canSend): ?>
                    <p class="messages-notice" role="status">Wysyłanie wiadomości do tego konta jest niedostępne.</p>
                <?php endif; ?>

                <section id="message-history" class="message-history" aria-labelledby="message-history-title">
                    <h3 id="message-history-title">Historia rozmowy</h3>
                    <?php if ($messagePage['entries'] === []): ?>
                        <div class="messages-empty messages-thread-empty social-empty">
                            <span class="social-empty-icon" aria-hidden="true">✦</span>
                            <p>Wyślij pierwszą wiadomość i rozpocznij rozmowę.</p>
                        </div>
                    <?php endif; ?>
                    <ol class="message-list" aria-label="Wiadomości w kolejności od najstarszej" data-message-page="<?= $escape($messagePage['page']) ?>" data-message-total="<?= $escape($messagePage['total']) ?>" data-message-total-pages="<?= $escape($messagePage['totalPages']) ?>">
                        <?php foreach ($messagePage['entries'] as $message): ?>
                            <?php
                            $isOwnMessage = $message['senderId'] === $accountId;
                            $createdDate = $messageDate($message['createdAt']);
                            ?>
                            <li class="message-row<?= $isOwnMessage ? ' message-row-own' : '' ?>">
                                <article id="message-<?= $escape($message['id']) ?>" class="message-bubble<?= $isOwnMessage ? ' message-bubble-own' : '' ?>" data-message-id="<?= $escape($message['id']) ?>" data-message-sender-id="<?= $escape($message['senderId']) ?>">
                                    <header class="message-meta">
                                        <span><?= $isOwnMessage ? 'Ty' : $escape($recipientName) ?></span>
                                        <time datetime="<?= $escape($createdDate->format(DATE_ATOM)) ?>"><?= $escape($createdDate->format('d.m.Y H:i')) ?></time>
                                    </header>
                                    <?php if ($message['isRemoved']): ?>
                                        <p class="message-removed">Wiadomość ukryta przez moderację.</p>
                                    <?php else: ?>
                                        <p class="message-body"><?= $escape($message['body']) ?></p>
                                        <?php if (!$isOwnMessage): ?>
                                            <a class="message-report" href="/reports/message/<?= $escape($message['id']) ?>?page=<?= $escape($messagePage['page']) ?>" aria-label="Zgłoś wiadomość">Zgłoś</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($conversationId !== null && $lastVisibleMessageId !== null): ?>
                        <form class="message-read-form" data-mark-conversation-read action="/messages/<?= $escape($conversationId) ?>/read" method="post">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="lastMessageId" value="<?= $escape($lastVisibleMessageId) ?>">
                            <input type="hidden" name="page" value="<?= $escape($messagePage['page']) ?>">
                            <button class="messages-secondary-button" type="submit">Oznacz jako przeczytane</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($messagePage['totalPages'] > 1): ?>
                        <nav class="messages-pagination" aria-label="Strony historii rozmowy">
                            <?php if ($messagePage['page'] > 1): ?>
                                <a href="<?= $escape($messagePage['previousUrl']) ?>" rel="prev">← Starsze wiadomości</a>
                            <?php endif; ?>
                            <span>Strona <?= $escape($messagePage['page']) ?> z <?= $escape($messagePage['totalPages']) ?></span>
                            <?php if ($messagePage['page'] < $messagePage['totalPages']): ?>
                                <a href="<?= $escape($messagePage['nextUrl']) ?>" rel="next">Nowsze wiadomości →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>

                <?php if ($canSend): ?>
                    <form id="message-composer" class="message-form" action="<?= $isComposing ? '/messages/new/' . $escape($recipientId) : '/messages/' . $escape($conversationId) ?>" method="post" aria-labelledby="message-form-title">
                        <h3 id="message-form-title"><?= $isComposing ? 'Rozpocznij rozmowę' : 'Napisz wiadomość' ?></h3>
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <label for="message-body">Treść wiadomości</label>
                        <textarea id="message-body" name="body" rows="4" maxlength="4000" aria-describedby="message-help<?= $errors !== [] ? ' message-errors' : '' ?>"<?= $errors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($body) ?></textarea>
                        <p id="message-help">Zwykły tekst, do 4000 znaków. Zgłoszoną wiadomość może sprawdzić moderator.</p>
                        <div class="message-form-actions">
                            <button type="submit">Wyślij wiadomość <span aria-hidden="true">↗</span></button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="messages-welcome" aria-labelledby="messages-welcome-title">
                <span class="messages-welcome-symbol" aria-hidden="true">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8Z"/><path d="M7 9h9M7 13h6"/></svg>
                </span>
                <p class="messages-welcome-kicker">Rozmowy zaczynają się od wspólnej pasji</p>
                <h2 id="messages-welcome-title">Twój anime krąg</h2>
                <p>Wybierz rozmowę z listy albo otwórz profil innego użytkownika, aby napisać pierwszą wiadomość.</p>
                <a href="/ranking">Przejdź do społeczności <span aria-hidden="true">→</span></a>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
