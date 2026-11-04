<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MessageService;
use Closure;

final class MessageController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private MessageRepository|Closure $messages,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $this->renderMessages($this->currentUser($this->users()));
    }

    public function compose(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $recipient = $id > 0 && $id !== $user['id'] ? $this->users()->findPublicById($id) : null;

        if ($recipient === null) {
            $this->notFound();
            return;
        }

        if (!$this->isPost()) {
            $conversation = $this->messages()->findBetweenUsers($user['id'], $id);
            if ($conversation !== null) {
                $this->redirect('/messages/' . $conversation['id']);
            }
            $this->renderMessages($user, recipient: $recipient);
            return;
        }

        $body = MessageService::normalize($this->postString('body'));
        $errors = $this->validateMessage($body);
        if ($errors !== []) {
            $this->renderMessages($user, recipient: $recipient, errors: $errors, body: $body);
            return;
        }

        $conversationId = $this->messages()->startWithMessageForUser($user['id'], $id, $body);
        if ($conversationId === null) {
            $this->notFound();
            return;
        }

        $this->redirect('/messages/' . $conversationId . '?sent=1#message-history');
    }

    public function show(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $conversation = $id > 0 ? $this->messages()->findForUser($user['id'], $id) : null;

        if ($conversation === null) {
            $this->notFound();
            return;
        }

        if ($this->isPost()) {
            $body = MessageService::normalize($this->postString('body'));
            $errors = $this->validateMessage($body);
            if ($errors !== []) {
                $this->renderMessages($user, $conversation, errors: $errors, body: $body);
                return;
            }

            $messageId = $this->messages()->sendForUser($user['id'], $id, $body);
            if ($messageId === null) {
                $this->notFound();
                return;
            }
            $this->redirect('/messages/' . $id . '?sent=1#message-' . $messageId);
        }

        $this->renderMessages($user, $conversation);
    }

    /** @return list<string> */
    private function validateMessage(string $body): array
    {
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            return ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.'];
        }

        $errors = MessageService::validate($body);
        if ($errors !== []) {
            http_response_code(422);
        }

        return $errors;
    }

    private function renderMessages(
        array $user,
        ?array $conversation = null,
        ?array $recipient = null,
        array $errors = [],
        string $body = '',
    ): void {
        $listPage = $this->page($_GET['conversationsPage'] ?? '1');
        $conversationList = $this->messages()->findPageForUser($user['id'], $listPage);
        $messagePage = ['entries' => [], 'page' => 1, 'total' => 0, 'totalPages' => 1];

        if ($conversation !== null) {
            $requestedPage = !$this->isPost() && isset($_GET['page']) ? $this->page($_GET['page']) : null;
            $messagePage = $this->messages()->findMessagesPageForUser($user['id'], $conversation['id'], $requestedPage);
            if ($messagePage === null) {
                $this->notFound();
                return;
            }
            $messagePage += [
                'previousUrl' => '/messages/' . $conversation['id'] . '?page=' . ($messagePage['page'] - 1) . '#message-history',
                'nextUrl' => '/messages/' . $conversation['id'] . '?page=' . ($messagePage['page'] + 1) . '#message-history',
            ];
        }

        if ($recipient !== null) {
            $conversation = [
                'id' => null,
                'recipientId' => $recipient['id'],
                'recipientName' => $recipient['name'],
                'recipientAvatarUrl' => $recipient['avatar_url'],
                'createdAt' => '',
            ];
        }

        $this->render('messages', [
            'pageTitle' => ($conversation === null ? 'Wiadomości' : 'Rozmowa: ' . $conversation['recipientName']) . ' — AniVibe',
            'conversationList' => $conversationList + [
                'previousUrl' => '/messages?conversationsPage=' . ($conversationList['page'] - 1),
                'nextUrl' => '/messages?conversationsPage=' . ($conversationList['page'] + 1),
            ],
            'conversation' => $conversation,
            'recipient' => $recipient,
            'isComposing' => $recipient !== null,
            'messagePage' => $messagePage,
            'errors' => $errors,
            'body' => $body,
            'sent' => !$this->isPost() && ($_GET['sent'] ?? null) === '1',
        ]);
    }

    private function page(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false ? 1 : $page;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('not-found', ['pageTitle' => 'Nie znaleziono rozmowy lub użytkownika — AniVibe']);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }

        return $this->users;
    }

    private function messages(): MessageRepository
    {
        if ($this->messages instanceof Closure) {
            $this->messages = ($this->messages)();
        }

        return $this->messages;
    }
}
