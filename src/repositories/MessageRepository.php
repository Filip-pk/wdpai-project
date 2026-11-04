<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\MessageService;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class MessageRepository
{
    private const SELECT_CONVERSATIONS = '
        SELECT conversations.id, conversations.created_at,
               recipient.id AS recipient_id, recipient.name AS recipient_name, recipient.avatar_version
        FROM private_conversations AS conversations
        INNER JOIN users AS recipient
            ON recipient.id = CASE WHEN conversations.first_user_id = :viewer_id
                THEN conversations.second_user_id ELSE conversations.first_user_id END';

    private int $savepointCounter = 0;

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{entries: list<array>, page: int, total: int, totalPages: int} */
    public function findPageForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $statement = $this->database->prepare(
            'SELECT count(*) FROM private_conversations
             WHERE first_user_id = :first_participant OR second_user_id = :second_participant'
        );
        $statement->execute(['first_participant' => $userId, 'second_participant' => $userId]);
        $total = (int) $statement->fetchColumn();
        [$page, $perPage, $totalPages] = $this->pagination($total, $page, $perPage);

        $statement = $this->database->prepare(
            'SELECT conversations.id, recipient.id AS recipient_id, recipient.name AS recipient_name,
                    recipient.avatar_version, LEFT(latest.body, 160) AS last_message_preview,
                    latest.sender_id AS last_message_sender_id, latest.created_at AS last_message_at
             FROM private_conversations AS conversations
             INNER JOIN users AS recipient
                ON recipient.id = CASE WHEN conversations.first_user_id = :viewer_id
                    THEN conversations.second_user_id ELSE conversations.first_user_id END
             LEFT JOIN LATERAL (
                 SELECT id, body, sender_id, created_at FROM private_messages
                 WHERE conversation_id = conversations.id ORDER BY id DESC LIMIT 1
             ) AS latest ON TRUE
             WHERE conversations.first_user_id = :first_participant
                OR conversations.second_user_id = :second_participant
             ORDER BY latest.id DESC NULLS LAST, conversations.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('viewer_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('first_participant', $userId, PDO::PARAM_INT);
        $statement->bindValue('second_participant', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'recipientId' => (int) $row['recipient_id'],
                'recipientName' => (string) $row['recipient_name'],
                'recipientAvatarUrl' => $this->avatarUrl($row),
                'lastMessagePreview' => $row['last_message_preview'] === null ? null : (string) $row['last_message_preview'],
                'lastMessageSenderId' => $row['last_message_sender_id'] === null ? null : (int) $row['last_message_sender_id'],
                'lastMessageAt' => $row['last_message_at'] === null ? null : (string) $row['last_message_at'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /** @return array{id: int, recipientId: int, recipientName: string, recipientAvatarUrl: ?string, createdAt: string}|null */
    public function findForUser(int $userId, int $conversationId): ?array
    {
        $statement = $this->database->prepare(
            self::SELECT_CONVERSATIONS . '
             WHERE conversations.id = :conversation_id
               AND (conversations.first_user_id = :first_participant
                    OR conversations.second_user_id = :second_participant)'
        );
        $statement->execute([
            'viewer_id' => $userId, 'conversation_id' => $conversationId,
            'first_participant' => $userId, 'second_participant' => $userId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapConversation($row);
    }

    public function findBetweenUsers(int $userId, int $recipientId): ?array
    {
        if ($userId <= 0 || $recipientId <= 0 || $userId === $recipientId) {
            return null;
        }
        $statement = $this->database->prepare(
            self::SELECT_CONVERSATIONS . '
             WHERE conversations.first_user_id = :first_user_id AND conversations.second_user_id = :second_user_id'
        );
        $statement->execute([
            'viewer_id' => $userId, 'first_user_id' => min($userId, $recipientId),
            'second_user_id' => max($userId, $recipientId),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapConversation($row);
    }

    public function startWithMessageForUser(int $userId, int $recipientId, string $body): ?int
    {
        $body = MessageService::normalize($body);
        $this->validateBody($body);
        if ($userId <= 0 || $recipientId <= 0 || $userId === $recipientId) {
            return null;
        }

        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'private_message_start_' . ++$this->savepointCounter;
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        } else {
            $this->database->exec('SAVEPOINT ' . $savepoint);
        }
        $completed = false;

        try {
            $statement = $this->database->prepare(
                'INSERT INTO private_conversations (first_user_id, second_user_id)
                 SELECT first_user.id, second_user.id FROM users AS first_user
                 CROSS JOIN users AS second_user
                 WHERE first_user.id = :first_user_id AND second_user.id = :second_user_id
                 ON CONFLICT (first_user_id, second_user_id)
                 DO UPDATE SET first_user_id = EXCLUDED.first_user_id RETURNING id'
            );
            $statement->execute([
                'first_user_id' => min($userId, $recipientId), 'second_user_id' => max($userId, $recipientId),
            ]);
            $conversationId = $statement->fetchColumn();
            if ($conversationId === false) {
                return null;
            }
            if ($this->sendForUser($userId, (int) $conversationId, $body) === null) {
                throw new RuntimeException('Nie udało się zapisać wiadomości w rozmowie uczestnika.');
            }

            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            $completed = true;

            return (int) $conversationId;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23503') {
                return null;
            }
            throw $exception;
        } finally {
            if (!$completed && $this->database->inTransaction()) {
                if ($ownsTransaction) {
                    $this->database->rollBack();
                } else {
                    $this->database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
        }
    }

    public function sendForUser(int $userId, int $conversationId, string $body): ?int
    {
        $body = MessageService::normalize($body);
        $this->validateBody($body);
        $statement = $this->database->prepare(
            'INSERT INTO private_messages (conversation_id, sender_id, body)
             SELECT id, :sender_id, :body FROM private_conversations
             WHERE id = :conversation_id
               AND (first_user_id = :first_participant OR second_user_id = :second_participant)
             RETURNING id'
        );
        $statement->execute([
            'sender_id' => $userId, 'body' => $body, 'conversation_id' => $conversationId,
            'first_participant' => $userId, 'second_participant' => $userId,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array{entries: list<array{id: int, senderId: int, body: string, createdAt: string}>, page: int, total: int, totalPages: int}|null */
    public function findMessagesPageForUser(int $userId, int $conversationId, ?int $page = null, int $perPage = 30): ?array
    {
        if ($this->findForUser($userId, $conversationId) === null) {
            return null;
        }
        $statement = $this->database->prepare(
            'SELECT count(*) FROM private_messages AS messages
             INNER JOIN private_conversations AS conversations ON conversations.id = messages.conversation_id
             WHERE conversations.id = :conversation_id
               AND (conversations.first_user_id = :first_participant OR conversations.second_user_id = :second_participant)'
        );
        $statement->execute([
            'conversation_id' => $conversationId, 'first_participant' => $userId, 'second_participant' => $userId,
        ]);
        $total = (int) $statement->fetchColumn();
        [$page, $perPage, $totalPages] = $this->pagination($total, $page, $perPage);

        $statement = $this->database->prepare(
            'SELECT messages.id, messages.sender_id, messages.body, messages.created_at FROM private_messages AS messages
             INNER JOIN private_conversations AS conversations ON conversations.id = messages.conversation_id
             WHERE conversations.id = :conversation_id
               AND (conversations.first_user_id = :first_participant OR conversations.second_user_id = :second_participant)
             ORDER BY messages.id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('conversation_id', $conversationId, PDO::PARAM_INT);
        $statement->bindValue('first_participant', $userId, PDO::PARAM_INT);
        $statement->bindValue('second_participant', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'senderId' => (int) $row['sender_id'],
                'body' => (string) $row['body'], 'createdAt' => (string) $row['created_at'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page, 'total' => $total, 'totalPages' => $totalPages,
        ];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function pagination(int $total, ?int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [max(1, min($page ?? $totalPages, $totalPages)), $perPage, $totalPages];
    }

    private function validateBody(string $body): void
    {
        $errors = MessageService::validate($body);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    private function mapConversation(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'recipientId' => (int) $row['recipient_id'],
            'recipientName' => (string) $row['recipient_name'], 'recipientAvatarUrl' => $this->avatarUrl($row),
            'createdAt' => (string) $row['created_at'],
        ];
    }

    private function avatarUrl(array $row): ?string
    {
        $version = $row['avatar_version'];

        return is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
            ? '/avatars/' . (int) $row['recipient_id'] . '?v=' . $version : null;
    }
}
