<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private readonly string $username;
    private readonly string $password;
    private readonly string $host;
    private readonly int $port;
    private readonly string $database;

    public function __construct()
    {
        $configuration = require dirname(__DIR__) . '/config.php';

        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (!is_string($configuration[$key]) || $configuration[$key] === '') {
                throw new RuntimeException('Nieprawidłowa konfiguracja połączenia z bazą danych.');
            }
        }

        $port = filter_var($configuration['port'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if (
            $port === false
            || preg_match('/[;\x00-\x1F\x7F]/', $configuration['host']) === 1
            || preg_match('/[;\x00-\x1F\x7F]/', $configuration['database']) === 1
        ) {
            throw new RuntimeException('Nieprawidłowa konfiguracja połączenia z bazą danych.');
        }

        $this->username = $configuration['username'];
        $this->password = $configuration['password'];
        $this->host = $configuration['host'];
        $this->port = $port;
        $this->database = $configuration['database'];
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5;sslmode=prefer',
            $this->host,
            $this->port,
            $this->database,
        );

        try {
            return new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Nie udało się połączyć z bazą danych.',
                0,
                $exception,
            );
        }
    }
}
