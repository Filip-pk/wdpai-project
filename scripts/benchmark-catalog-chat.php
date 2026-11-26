<?php

declare(strict_types=1);

use App\Database;
use App\Repository\RecommendationChatRepository;
use App\Service\OllamaClient;
use App\Service\RecommendationChatService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $catalogue = new RecommendationChatRepository((new Database())->connect());
    $service = new RecommendationChatService($catalogue, OllamaClient::fromEnvironment());
    $message = 'Chcę harem, ponad 20 odcinków, w stylu podobnym do No Game No Life.';
    $cases = [
        [$message, []],
        [$message, []],
        [$message, []],
        ['Jednak bez haremu, bardziej zależy mi na grach i strategii.', ['minEpisodes' => 21,
            'requireTags' => ['Harem'], 'references' => ['No Game No Life']]],
        ['Krótka, zakończona seria TV z inteligentnym głównym bohaterem.', []],
    ];
    foreach ($cases as [$prompt, $previous]) {
        $result = $service->interpret($prompt, $previous);
        echo $prompt . PHP_EOL;
        printf("%d ms (ładowanie %d ms). %s\n", $result['elapsedMs'], $result['loadMs'],
            json_encode([$result['action'], $result['criteria']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Pomiar przerwany (' . $exception::class . '). Sprawdź bazę i Ollamę.' . PHP_EOL);
    exit(1);
}
