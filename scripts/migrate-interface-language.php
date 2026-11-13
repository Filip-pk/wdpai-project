<?php

declare(strict_types=1);

use App\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$database = (new Database())->connect();
$exists = $database->query(
    "SELECT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'language')"
)->fetchColumn();

if (!$exists) {
    $database->exec(file_get_contents(dirname(__DIR__) . '/database/22-interface-language.sql'));
}
echo "Interface language migration is ready.\n";
