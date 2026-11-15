<?php

declare(strict_types=1);

use App\Service\AnimeClueTitleSearch;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach (['nar', 'NAR', '  Nár  '] as $query) {
    $matches = AnimeClueTitleSearch::suggestions($query);
    $assert(in_array('Naruto', array_column($matches, 'title'), true), 'Fragment powinien podpowiadać Naruto.');
}
$matches = AnimeClueTitleSearch::suggestions('katachi');
$assert(in_array(['title' => 'A Silent Voice', 'matchedTitle' => 'Koe no Katachi'], $matches, true),
    'Fragment aliasu powinien wskazać właściwy tytuł.');
$matches = AnimeClueTitleSearch::suggestions('ksiezniczka');
$assert(in_array('Princess Mononoke', array_column($matches, 'title'), true), 'Polski alias wymaga normalizacji znaków.');
$assert(AnimeClueTitleSearch::suggestions('naruto')[0]['title'] === 'Naruto', 'Dokładny tytuł powinien być pierwszy.');
echo '[OK] Fragmenty tytułów, aliasy, wielkość liter i znaki diakrytyczne.' . PHP_EOL;

foreach (['', 'n', '!', 'a!', [], null, true, 5, str_repeat('a', 201), "nar\nuto", "\xFF", 'not-a-title-xyz123'] as $query) {
    $assert(AnimeClueTitleSearch::suggestions($query) === [], 'Nieprawidłowa lub niedopasowana fraza powinna dawać pustą listę.');
}
$matches = AnimeClueTitleSearch::suggestions('no');
$assert(count($matches) === 10, 'Odpowiedź ma zawierać najwyżej dziesięć tytułów.');
$assert(count(array_unique(array_column($matches, 'title'))) === count($matches), 'Tytuły nie mogą być powielone przez aliasy.');
foreach ($matches as $match) {
    $assert(array_keys($match) === ['title', 'matchedTitle'], 'Wyszukiwarka nie może zwracać identyfikatorów ani wskazówek.');
}
$assert($matches === AnimeClueTitleSearch::suggestions('no'), 'Lista ma mieć stałą kolejność niezależną od rundy.');
echo '[OK] Walidacja, limit, brak duplikatów i odpowiedź zawierająca wyłącznie tytuły.' . PHP_EOL;
