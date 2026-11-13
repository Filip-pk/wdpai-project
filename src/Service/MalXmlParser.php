<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use XMLReader;

final class MalXmlParser
{
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    public const MAX_XML_BYTES = 50 * 1024 * 1024;
    public const MAX_ENTRIES = 40000;

    /** @return array{entries: list<array>, issues: list<array>, total: int} */
    public function parse(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.choose_file'));
        }
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $xml = @gzdecode($bytes, self::MAX_XML_BYTES + 1);
            if ($xml === false || strlen($xml) > self::MAX_XML_BYTES) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.gzip_limit'));
            }
        } else {
            $xml = $bytes;
        }
        if ($xml === '' || str_contains($xml, "\0") || preg_match('//u', $xml) !== 1
            || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.xml_utf8'));
        }

        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        try {
            if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_anime_export'));
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $entries = [];
            $issues = [];
            $seen = [];
            $total = 0;
            $nodes = 0;
            $rootFound = false;
            $inAnime = false;
            $fields = [];
            $invalidFields = false;
            $capturing = null;
            $oversized = [];
            while ($reader->read()) {
                if (++$nodes > 10000000 || $reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new InvalidArgumentException(Translator::current()->translate('message.mal.xml_elements'));
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    if ($rootFound || $reader->localName !== 'myanimelist') {
                        throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_export'));
                    }
                    $rootFound = true;
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 1) {
                    if ($reader->localName === 'manga') {
                        throw new InvalidArgumentException(Translator::current()->translate('message.mal.no_manga'));
                    }
                    if ($reader->localName === 'anime') {
                        if (++$total > self::MAX_ENTRIES) {
                            throw new InvalidArgumentException(Translator::current()->translate('message.mal.max_entries'));
                        }
                        $inAnime = true;
                        $fields = [];
                        $invalidFields = false;
                        $capturing = null;
                        $oversized = [];
                        if ($reader->isEmptyElement) {
                            $this->appendRow($fields, $invalidFields, $entries, $issues, $seen);
                            $inAnime = false;
                        }
                    }
                } elseif ($inAnime && $reader->nodeType === XMLReader::ELEMENT && $reader->depth === 2) {
                    $name = $reader->localName;
                    $capturing = null;
                    if (in_array($name, ['series_animedb_id', 'series_title', 'my_status', 'my_watched_episodes', 'my_score'], true)) {
                        $invalidFields = $invalidFields || array_key_exists($name, $fields);
                        $fields[$name] = '';
                        $capturing = $reader->isEmptyElement ? null : $name;
                    }
                } elseif ($inAnime && $reader->nodeType === XMLReader::ELEMENT && $reader->depth > 2 && $capturing !== null) {
                    $invalidFields = true;
                } elseif ($inAnime && $reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 2) {
                    $capturing = null;
                } elseif ($inAnime && $reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 1) {
                    $this->appendRow($fields, $invalidFields, $entries, $issues, $seen);
                    $inAnime = false;
                    $capturing = null;
                } elseif ($inAnime && $capturing !== null && !isset($oversized[$capturing]) && in_array($reader->nodeType, [
                    XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE,
                ], true)) {
                    $text = $fields[$capturing] . $reader->value;
                    if ($capturing === 'series_title') {
                        preg_match('/\A.{0,512}/us', $text, $prefix);
                        $fields[$capturing] = $prefix[0] ?? '';
                    } elseif (strlen($text) > 128) {
                        $fields[$capturing] = 'invalid';
                        $oversized[$capturing] = true;
                    } else {
                        $fields[$capturing] = $text;
                    }
                }
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new InvalidArgumentException(Translator::current()->translate('message.mal.corrupt_xml'));
                }
            }
            if (!$rootFound || $inAnime) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_anime_export'));
            }
            return ['entries' => $entries, 'issues' => $issues, 'total' => $total];
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function appendRow(array $fields, bool $invalidFields, array &$entries, array &$issues, array &$seen): void
    {
        $fields = array_map('trim', $fields);
        $malId = $this->integer($fields['series_animedb_id'] ?? null, 1, 2147483647);
        $title = ($fields['series_title'] ?? '') === '' ? 'MAL #' . ($malId ?? '?') : $fields['series_title'];
        $status = $this->status($fields['my_status'] ?? '');
        $watched = $this->integer($fields['my_watched_episodes'] ?? null, 0, 2147483647);
        $score = $this->integer($fields['my_score'] ?? '0', 0, 10);
        $reason = match (true) {
            $invalidFields => 'Powtórzone pola lub niedozwolone elementy wewnątrz pól XML.',
            $malId === null => 'Nieprawidłowy lub brakujący identyfikator MAL.',
            $status === null => 'Nieprawidłowy lub brakujący status oglądania.',
            $watched === null => 'Nieprawidłowa lub brakująca liczba obejrzanych odcinków.',
            $score === null => 'Ocena musi być liczbą całkowitą od 0 do 10.',
            isset($seen[$malId]) => 'Powtórzony identyfikator MAL; zachowano pierwszy poprawny wpis.',
            default => null,
        };
        if ($reason !== null) {
            $issues[] = ['malId' => $malId, 'title' => $title, 'reason' => $reason];
            return;
        }
        $seen[$malId] = true;
        $entries[] = ['malId' => $malId, 'title' => $title, 'status' => $status, 'watched' => $watched, 'rating' => $score === 0 ? null : $score];
    }

    private function integer(?string $value, int $minimum, int $maximum): ?int
    {
        if ($value === null || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return null;
        }
        $value = ltrim($value, '0');
        $value = $value === '' ? '0' : $value;
        $limit = (string) $maximum;
        if (strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            return null;
        }
        $number = (int) $value;
        return $number >= $minimum ? $number : null;
    }

    private function status(string $value): ?string
    {
        $value = strtolower((string) preg_replace('/[\s_-]+/u', '', $value));
        return match ($value) {
            '1', 'watching' => 'watching',
            '2', 'completed' => 'completed',
            '3', 'onhold' => 'on_hold',
            '4', 'dropped' => 'dropped',
            '6', 'plantowatch', 'planned' => 'planned',
            default => null,
        };
    }
}
