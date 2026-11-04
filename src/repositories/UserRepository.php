<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{id: int, name: string, email: string, password_hash: string, interests: string, theme: string, avatar_url: ?string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, password_hash, interests, theme, avatar_version
             FROM users
             WHERE LOWER(email) = LOWER(:email)'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'password_hash' => (string) $user['password_hash'],
            'interests' => (string) $user['interests'],
            'theme' => (string) $user['theme'],
            'avatar_url' => $this->avatarUrl($user),
        ];
    }

    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, interests, theme, created_at, avatar_version FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        $user['id'] = (int) $user['id'];
        $user['avatar_url'] = $this->avatarUrl($user);

        return $user;
    }

    /** @return array{id: int, name: string, interests: string, created_at: string, avatar_url: ?string}|null */
    public function findPublicById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT id, name, interests, created_at, avatar_version FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'interests' => (string) $user['interests'],
            'created_at' => (string) $user['created_at'],
            'avatar_url' => $this->avatarUrl($user),
        ];
    }

    public function updateAvatar(int $userId, string $contents, string $version): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET avatar_data = :contents, avatar_version = :version WHERE id = :id'
        );
        $statement->bindValue('contents', $contents, PDO::PARAM_LOB);
        $statement->bindValue('version', $version);
        $statement->bindValue('id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function findAvatar(int $userId, string $version): ?string
    {
        $statement = $this->database->prepare(
            'SELECT avatar_data FROM users WHERE id = :id AND avatar_version = :version'
        );
        $statement->execute(['id' => $userId, 'version' => $version]);
        $contents = $statement->fetchColumn();

        if (is_resource($contents)) {
            $contents = stream_get_contents($contents);
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private function avatarUrl(array $user): ?string
    {
        return is_string($user['avatar_version'])
            && preg_match('/\A[a-f0-9]{32}\z/', $user['avatar_version']) === 1
            ? '/avatars/' . (int) $user['id'] . '?v=' . $user['avatar_version']
            : null;
    }

    public function updateProfile(int $userId, string $name, string $email, string $interests, string $theme): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET name = :name, email = :email, interests = :interests, theme = :theme
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'interests' => $interests,
            'theme' => $theme,
        ]);
    }

    public function updateTheme(int $userId, string $theme): void
    {
        $statement = $this->database->prepare('UPDATE users SET theme = :theme WHERE id = :id');
        $statement->execute(['id' => $userId, 'theme' => $theme]);
    }

    public function create(string $name, string $email, string $passwordHash): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO users (name, email, password_hash)
             VALUES (:name, :email, :passwordHash)
             RETURNING id'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'passwordHash' => $passwordHash,
        ]);

        return (int) $statement->fetchColumn();
    }
}
