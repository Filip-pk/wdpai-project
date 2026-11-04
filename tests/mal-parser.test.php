<?php

declare(strict_types=1);

use App\Service\MalXmlParser;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$parser = new MalXmlParser();
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$row = static fn (string $id = '1535', string $status = 'Watching', string $watched = '3', string $score = '8', string $title = 'Death Note'): string =>
    '<anime><series_animedb_id>' . $id . '</series_animedb_id><series_title><![CDATA[' . $title . ']]></series_title>'
    . '<my_status>' . $status . '</my_status><my_watched_episodes>' . $watched . '</my_watched_episodes>'
    . '<my_score>' . $score . '</my_score></anime>';
$xml = static fn (string $rows): string => '<?xml version="1.0" encoding="UTF-8"?><myanimelist>' . $rows . '</myanimelist>';
$reject = static function (string $contents) use ($parser, $assert): void {
    $rejected = false;
    try {
        $parser->parse($contents);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'Nieprawidłowy plik musi zostać odrzucony.');
};
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
    }
};

$run('XML odczytuje własne dane i CDATA bez kopiowania opisów ani danych konta', static function () use ($parser, $row, $xml, $assert): void {
    $parsed = $parser->parse($xml('<myinfo><user_id>999</user_id><user_name>Inne konto</user_name></myinfo>'
        . $row(title: 'Żółć <script>alert(1)</script> & anime')));
    $assert($parsed['total'] === 1 && $parsed['issues'] === []);
    $assert($parsed['entries'] === [[
        'malId' => 1535, 'title' => 'Żółć <script>alert(1)</script> & anime',
        'status' => 'watching', 'watched' => 3, 'rating' => 8,
    ]]);
});
$run('XML.GZ oraz UTF-8 BOM dają te same wpisy co XML', static function () use ($parser, $row, $xml, $assert): void {
    $source = $xml($row());
    $expected = $parser->parse($source);
    $assert($parser->parse(gzencode($source)) === $expected);
    $assert($parser->parse("\xEF\xBB\xBF" . $source) === $expected);
});
$run('Przestrzeń nazw nie zmienia dopasowania pól listy', static function () use ($parser, $assert): void {
    $parsed = $parser->parse('<m:myanimelist xmlns:m="urn:mal"><m:anime><m:series_animedb_id>1</m:series_animedb_id>'
        . '<m:series_title>Anime</m:series_title><m:my_status>Watching</m:my_status>'
        . '<m:my_watched_episodes>1</m:my_watched_episodes><m:my_score>0</m:my_score></m:anime></m:myanimelist>');
    $assert($parsed['entries'][0]['malId'] === 1 && $parsed['entries'][0]['rating'] === null);
});
$run('Pięć statusów MAL i ich numery zachowuje znaczenie, a zero oznacza brak oceny', static function () use ($parser, $row, $xml, $assert): void {
    $statuses = [
        'Watching' => 'watching', 'Completed' => 'completed', 'On-Hold' => 'on_hold',
        'Dropped' => 'dropped', 'Plan to Watch' => 'planned',
        '1' => 'watching', '2' => 'completed', '3' => 'on_hold', '4' => 'dropped', '6' => 'planned',
    ];
    $source = '';
    $id = 1;
    foreach ($statuses as $status => $expected) {
        $source .= $row((string) $id++, (string) $status, '0', '0');
    }
    $parsed = $parser->parse($xml($source));
    $assert(array_column($parsed['entries'], 'status') === array_values($statuses));
    $assert(array_unique(array_column($parsed['entries'], 'rating')) === [null]);
});
$run('Błędne wpisy i duplikaty trafiają do raportu, a pierwszy poprawny wpis wygrywa', static function () use ($parser, $row, $xml, $assert): void {
    $parsed = $parser->parse($xml($row('1535', watched: '-1') . $row('1535', watched: '2') . $row('1535', watched: '9')
        . $row('0') . $row('3', status: 'Unknown') . $row('4', score: '11')
        . $row('5', watched: '1.5') . $row('999999999999999999999999')));
    $assert($parsed['total'] === 8 && count($parsed['entries']) === 1 && count($parsed['issues']) === 7);
    $assert($parsed['entries'][0]['watched'] === 2);
});
$run('Brakujące pola i powtórzone kluczowe pola nie zerują cudzego postępu', static function () use ($parser, $xml, $row, $assert): void {
    $parsed = $parser->parse($xml('<anime><series_animedb_id>1</series_animedb_id><my_status>Watching</my_status></anime>'
        . str_replace('</anime>', '<my_status>Completed</my_status></anime>', $row('2'))));
    $assert($parsed['entries'] === [] && count($parsed['issues']) === 2);
});
$run('Manga, błędny korzeń i uszkodzony XML są odrzucane', static function () use ($reject): void {
    foreach (['<myanimelist><manga/></myanimelist>', '<other/>', '<myanimelist><anime>', 'not xml', ''] as $source) {
        $reject($source);
    }
});
$run('Błąd XML po poprawnym anime odrzuca całą listę zamiast zwracać częściowy import', static function () use ($reject, $row): void {
    $reject('<myanimelist>' . $row() . '<anime><series_animedb_id>2</myanimelist>');
    $reject('<myanimelist>' . $row() . '</myanimelist><another-root/>');
});
$run('DTD, encje, XXE oraz kodowanie omijające kontrolę UTF-8 są odrzucane', static function () use ($reject, $row, $xml): void {
    foreach ([
        '<!DOCTYPE myanimelist SYSTEM "http://127.0.0.1:1/no-network"><myanimelist/>',
        '<!DOCTYPE myanimelist [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><myanimelist/>',
        '<!DOCTYPE myanimelist [<!ENTITY a "xxxxxxxxxx">]><myanimelist/>',
        "\xFF\xFE<\0m\0y\0a\0n\0i\0m\0e\0l\0i\0s\0t\0/\0>\0",
        str_replace('Death Note', "\xFF", $xml($row())),
    ] as $source) {
        $reject($source);
    }
});
$run('Limit przesyłania oraz limit rozpakowania blokują duże pliki i bomby GZIP', static function () use ($reject): void {
    $reject(str_repeat('x', MalXmlParser::MAX_UPLOAD_BYTES + 1));
    $reject(gzencode(str_repeat(' ', MalXmlParser::MAX_XML_BYTES + 1)));
    $reject(gzencode(''));
    $reject("\x1f\x8bbroken gzip");
});
$run('Limit wpisów dotyczy również zduplikowanych rekordów', static function () use ($reject): void {
    $entry = '<anime><series_animedb_id>1</series_animedb_id><my_status>1</my_status><my_watched_episodes>0</my_watched_episodes></anime>';
    $reject('<myanimelist>' . str_repeat($entry, MalXmlParser::MAX_ENTRIES + 1) . '</myanimelist>');
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
