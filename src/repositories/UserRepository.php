<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{id: int, name: string, email: string, password_hash: string, interests: string, theme: string, language: string, avatar_url: ?string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, password_hash, interests, theme, language, avatar_version
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
            'language' => (string) $user['language'],
            'avatar_url' => $this->avatarUrl($user),
        ];
    }

    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, interests, theme, language, created_at, avatar_version FROM users WHERE id = :id'
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

    /** @return array{id: int, name: string, interests: string, created_at: string, avatar_url: ?string, isAdmin: bool}|null */
    public function findPublicById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $statement = $this->database->prepare(
            "SELECT id, name, interests, created_at, avatar_version,
                COALESCE((to_jsonb(u)->>'is_admin')::boolean, false) AS is_admin FROM users u WHERE id = :id"
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'interests' => (string) $user['interests'],
            'created_at' => (string) $user['created_at'],
            'avatar_url' => $this->avatarUrl($user),
            'isAdmin' => DatabaseValue::toBool($user['is_admin']),
        ];
    }

    /**
     * Points the account at a new avatar file. The version is the public cache key in avatar URLs;
     * the path stays private. A photo saved in the database by older versions is dropped with it.
     */
    public function updateAvatar(int $userId, string $path, string $version): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET avatar_path = :path, avatar_version = :version, avatar_data = NULL WHERE id = :id'
        );
        $statement->execute(['path' => $path, 'version' => $version, 'id' => $userId]);
    }

    public function findAvatarPath(int $userId, string $version): ?string
    {
        $statement = $this->database->prepare(
            'SELECT avatar_path FROM users WHERE id = :id AND avatar_version = :version'
        );
        $statement->execute(['id' => $userId, 'version' => $version]);
        $path = $statement->fetchColumn();

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function avatarUrl(array $user): ?string
    {
        return is_string($user['avatar_version'])
            && preg_match('/\A[a-f0-9]{32}\z/', $user['avatar_version']) === 1
            ? '/avatars/' . (int) $user['id'] . '?v=' . $user['avatar_version']
            : null;
    }

    public function updateProfile(int $userId, string $name, string $interests, string $theme): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET name = :name, interests = :interests, theme = :theme
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'name' => $name,
            'interests' => $interests,
            'theme' => $theme,
        ]);
    }

    public function updateTheme(int $userId, string $theme): void
    {
        $statement = $this->database->prepare('UPDATE users SET theme = :theme WHERE id = :id');
        $statement->execute(['id' => $userId, 'theme' => $theme]);
    }

    public function create(string $name, string $email, string $passwordHash, string $language = 'pl'): int
    {
        if (!\App\Service\Translator::isSupported($language)) {
            throw new \InvalidArgumentException('Unsupported language.');
        }
        $statement = $this->database->prepare(
            'INSERT INTO users (name, email, password_hash, language)
             VALUES (:name, :email, :passwordHash, :language)
             RETURNING id'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'passwordHash' => $passwordHash,
            'language' => $language,
        ]);

        return (int) $statement->fetchColumn();
    }
}
