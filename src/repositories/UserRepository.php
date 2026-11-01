<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{id: int, name: string, email: string, password_hash: string, interests: string, theme: string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, password_hash, interests, theme
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
        ];
    }

    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, email, interests, theme, created_at FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        $user['id'] = (int) $user['id'];

        return $user;
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
