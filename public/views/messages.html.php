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
            <p class="social-kicker"><?= $escape($t('view.interface.community_private_conversations')) ?></p>
            <h1 class="social-title"><?= $escape($isComposing ? $t('view.interface.new_message') : $t('view.interface.messages')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.chat_with_fellow_anime_fans_only_participants_can_access_a_conversation')) ?></p>
        </div>
        <span class="messages-privacy">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
            <?= $escape($t('view.interface.participants_only')) ?>
        </span>
    </header>

    <nav class="social-tabs" aria-label="<?= $escape($t('view.interface.community_sections')) ?>">
        <a href="/messages" aria-current="page"><?= $escape($t('view.interface.messages')) ?></a>
        <a href="/notifications/comments"><?= $escape($t('view.interface.notifications')) ?></a>
        <a href="/messages/blocked"><?= $escape($t('view.interface.blocked_users')) ?></a>
    </nav>

    <?php if ($sent): ?>
        <p class="messages-notice" data-kind="success" role="status"><?= $escape($t('view.interface.your_message_has_been_sent')) ?></p>
    <?php endif; ?>
    <?php if (($_GET['reportSaved'] ?? null) === '1'): ?>
        <p class="messages-notice" data-kind="success" role="status"><?= $escape($t('view.interface.your_report_has_been_sent_to_moderation')) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div id="message-errors" class="messages-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($hasConversation): ?>
        <a class="messages-back" href="/messages"><?= $escape($t('view.interface.all_conversations')) ?></a>
    <?php endif; ?>

    <div class="messages-layout<?= $hasConversation ? ' has-conversation' : '' ?>">
        <aside class="messages-inbox" aria-labelledby="conversations-title" data-conversations-total="<?= $escape($conversationList['total']) ?>" data-conversations-page="<?= $escape($conversationList['page']) ?>" data-conversations-total-pages="<?= $escape($conversationList['totalPages']) ?>">
            <header class="messages-inbox-heading">
                <h2 id="conversations-title"><?= $escape($t('view.interface.your_conversations')) ?></h2>
                <span class="social-pill" aria-label="<?= $escape($t('view.interface.conversation_count') . ' ' . $conversationList['total']) ?>"><?= $escape($conversationList['total']) ?></span>
            </header>

            <?php if ($conversationList['entries'] === []): ?>
                <div class="messages-empty social-empty">
                    <span class="social-empty-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8Z"/><path d="M7 9h9M7 13h6"/></svg>
                    </span>
                    <p><?= $escape($t('view.interface.you_have_no_conversations_yet')) ?></p>
                    <p><?= $escape($t('view.interface.open_a_user_profile_and_choose_write_a_message')) ?></p>
                    <a href="/ranking"><?= $escape($t('view.interface.meet_the_anivibe_community')) ?> <span aria-hidden="true">→</span></a>
                </div>
            <?php else: ?>
                <ul class="conversation-list" aria-label="<?= $escape($t('view.interface.your_conversation_list')) ?>">
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
                                        <span class="conversation-unread" data-conversation-unread="<?= $escape($listedConversation['id']) ?>" aria-label="<?= $escape($t('view.interface.unread_messages') . ' ' . $listedConversation['unreadCount']) ?>"<?= $listedConversation['unreadCount'] === 0 ? ' hidden' : '' ?>><?= $escape($listedConversation['unreadCount']) ?></span>
                                    </span>
                                    <span class="conversation-preview"><?php if ($listedConversation['lastMessageSenderId'] === $accountId): ?><?= $escape($t('view.interface.you')) ?> <?php endif; ?><?= $escape($listedConversation['lastMessagePreview'] ?? $t('view.interface.start_a_conversation')) ?></span>
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
                <nav class="messages-pagination" aria-label="<?= $escape($t('view.interface.conversation_list_pages')) ?>">
                    <?php if ($conversationList['page'] > 1): ?>
                        <a href="<?= $escape($conversationList['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous_d3daaf')) ?></a>
                    <?php endif; ?>
                    <span><?= $escape($t('view.social.page_of', ['page' => $conversationList['page'], 'total' => $conversationList['totalPages']])) ?></span>
                    <?php if ($conversationList['page'] < $conversationList['totalPages']): ?>
                        <a href="<?= $escape($conversationList['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next_6ff546')) ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </aside>

        <?php if ($hasConversation): ?>
            <section class="messages-thread" aria-labelledby="conversation-title"<?php if ($conversationId !== null): ?> data-conversation-id="<?= $escape($conversationId) ?>"<?php endif; ?> data-conversation-recipient-id="<?= $escape($recipientId) ?>">
                <header class="messages-thread-heading">
                    <a class="messages-recipient" href="/users/<?= $escape($recipientId) ?>" aria-label="<?= $escape($t('view.interface.participant_profile') . ' ' . $recipientName) ?>">
                        <span class="messages-avatar" aria-hidden="true">
                            <?php if ($recipientAvatarUrl !== null): ?>
                                <img src="<?= $escape($recipientAvatarUrl) ?>" alt="" width="40" height="40" decoding="async">
                            <?php else: ?>
                                ✦
                            <?php endif; ?>
                        </span>
                        <div class="messages-recipient-copy">
                            <span class="messages-context"><?= $escape($isComposing ? $t('view.interface.new_conversation') : $t('view.interface.private_conversation')) ?></span>
                            <h2 id="conversation-title"><?= $escape($recipientName) ?></h2>
                        </div>
                    </a>
                    <div class="messages-thread-actions">
                        <?php if (!$isComposing): ?>
                            <a class="messages-refresh" href="/messages/<?= $escape($conversationId) ?>#message-history"><?= $escape($t('view.interface.refresh_conversation')) ?> <span aria-hidden="true">↻</span></a>
                        <?php endif; ?>
                        <form action="/users/<?= $escape($recipientId) ?>/<?= $isBlockedByMe ? 'unblock' : 'block' ?>" method="post">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <?php if ($conversationId !== null): ?><input type="hidden" name="conversationId" value="<?= $escape($conversationId) ?>"><?php endif; ?>
                            <button class="messages-secondary-button" type="submit"><?= $escape($isBlockedByMe ? $t('view.interface.unblock_user') : $t('view.interface.block_user')) ?></button>
                        </form>
                    </div>
                </header>

                <?php if (!$canSend): ?>
                    <p class="messages-notice" role="status"><?= $escape($t('view.interface.messaging_this_account_is_unavailable')) ?></p>
                <?php endif; ?>

                <section id="message-history" class="message-history" aria-labelledby="message-history-title">
                    <h3 id="message-history-title"><?= $escape($t('view.interface.conversation_history')) ?></h3>
                    <?php if ($messagePage['entries'] === []): ?>
                        <div class="messages-empty messages-thread-empty social-empty">
                            <span class="social-empty-icon" aria-hidden="true">✦</span>
                            <p><?= $escape($t('view.interface.send_the_first_message_and_start_a_conversation')) ?></p>
                        </div>
                    <?php endif; ?>
                    <ol class="message-list" aria-label="<?= $escape($t('view.interface.messages_oldest_first')) ?>" data-message-page="<?= $escape($messagePage['page']) ?>" data-message-total="<?= $escape($messagePage['total']) ?>" data-message-total-pages="<?= $escape($messagePage['totalPages']) ?>">
                        <?php foreach ($messagePage['entries'] as $message): ?>
                            <?php
                            $isOwnMessage = $message['senderId'] === $accountId;
                            $createdDate = $messageDate($message['createdAt']);
                            ?>
                            <li class="message-row<?= $isOwnMessage ? ' message-row-own' : '' ?>">
                                <article id="message-<?= $escape($message['id']) ?>" class="message-bubble<?= $isOwnMessage ? ' message-bubble-own' : '' ?>" data-message-id="<?= $escape($message['id']) ?>" data-message-sender-id="<?= $escape($message['senderId']) ?>">
                                    <header class="message-meta">
                                        <span><?= $escape($isOwnMessage ? $t('view.interface.you_ce679e') : $recipientName) ?></span>
                                        <time datetime="<?= $escape($createdDate->format(DATE_ATOM)) ?>"><?= $escape($createdDate->format('d.m.Y H:i')) ?></time>
                                    </header>
                                    <?php if ($message['isRemoved']): ?>
                                        <p class="message-removed"><?= $escape($t('view.interface.message_hidden_by_moderation')) ?></p>
                                    <?php else: ?>
                                        <p class="message-body"><?= $escape($message['body']) ?></p>
                                        <?php if (!$isOwnMessage): ?>
                                            <a class="message-report" href="/reports/message/<?= $escape($message['id']) ?>?page=<?= $escape($messagePage['page']) ?>" aria-label="<?= $escape($t('view.interface.report_message')) ?>"><?= $escape($t('view.interface.report')) ?></a>
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
                            <button class="messages-secondary-button" type="submit"><?= $escape($t('view.interface.mark_as_read')) ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($messagePage['totalPages'] > 1): ?>
                        <nav class="messages-pagination" aria-label="<?= $escape($t('view.interface.conversation_history_pages')) ?>">
                            <?php if ($messagePage['page'] > 1): ?>
                                <a href="<?= $escape($messagePage['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.older_messages')) ?></a>
                            <?php endif; ?>
                            <span><?= $escape($t('view.social.page_of', ['page' => $messagePage['page'], 'total' => $messagePage['totalPages']])) ?></span>
                            <?php if ($messagePage['page'] < $messagePage['totalPages']): ?>
                                <a href="<?= $escape($messagePage['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.newer_messages')) ?></a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>

                <?php if ($canSend): ?>
                    <form id="message-composer" class="message-form" action="<?= $isComposing ? '/messages/new/' . $escape($recipientId) : '/messages/' . $escape($conversationId) ?>" method="post" aria-labelledby="message-form-title">
                        <h3 id="message-form-title"><?= $escape($isComposing ? $t('view.interface.start_a_conversation') : $t('view.interface.write_a_message')) ?></h3>
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <label for="message-body"><?= $escape($t('view.interface.message_text')) ?></label>
                        <textarea id="message-body" name="body" rows="4" maxlength="4000" aria-describedby="message-help<?= $errors !== [] ? ' message-errors' : '' ?>"<?= $errors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($body) ?></textarea>
                        <p id="message-help"><?= $escape($t('view.interface.plain_text_up_to_4_000_characters_a_reported_message_may_be_reviewed_by_a_moderator')) ?></p>
                        <div class="message-form-actions">
                            <button type="submit"><?= $escape($t('view.interface.send_message')) ?> <span aria-hidden="true">↗</span></button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="messages-welcome" aria-labelledby="messages-welcome-title">
                <span class="messages-welcome-symbol" aria-hidden="true">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11a8 8 0 0 1-8 8H5l-3 3V11a8 8 0 0 1 8-8h3a8 8 0 0 1 8 8Z"/><path d="M7 9h9M7 13h6"/></svg>
                </span>
                <p class="messages-welcome-kicker"><?= $escape($t('view.interface.conversations_start_with_a_shared_passion')) ?></p>
                <h2 id="messages-welcome-title"><?= $escape($t('view.interface.your_anime_circle')) ?></h2>
                <p><?= $escape($t('view.interface.choose_a_conversation_or_open_another_user_s_profile_to_send_your_first_message')) ?></p>
                <a href="/ranking"><?= $escape($t('view.interface.go_to_the_community')) ?> <span aria-hidden="true">→</span></a>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
