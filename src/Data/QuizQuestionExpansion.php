<?php

declare(strict_types=1);

namespace App\Data;

/** Language-neutral facts and authored clues; answer positions are shared by every locale. */
final class QuizQuestionExpansion
{
    private const LOCALES = ['pl', 'en', 'de', 'fr', 'es', 'ru'];

    /** @return list<array> */
    public static function all(): array
    {
        $questions = [];
        $studios = ['Bones', 'Madhouse', 'Kyoto Animation', 'MAPPA', 'WIT STUDIO', 'ufotable', 'Production I.G', 'A-1 Pictures'];
        foreach (self::productions() as $index => [$key, $title, $studio, $year, $source]) {
            $questions[] = self::fact(61 + $index, $key, 'medium', 'studio', $title, $studio, $studios, $source);
            $questions[] = self::fact(91 + $index, $key, 'hard', 'year', $title, (string) $year,
                [(string) ($year - 2), (string) ($year - 1), (string) ($year + 1)], $source);
        }
        $directors = self::directors();
        $directorNames = array_values(array_unique(array_column($directors, 2)));
        foreach ($directors as $index => [$key, $title, $director, $source]) {
            $questions[] = self::fact(121 + $index, $key, 'hard', 'director', $title, $director, $directorNames, $source);
        }
        $authors = self::authors();
        $authorNames = array_column($authors, 2);
        foreach ($authors as $index => [$key, $title, $author, $source]) {
            $questions[] = self::fact(141 + $index, $key, 'medium', 'author', $title, $author, $authorNames, $source);
        }
        $characters = self::characters();
        $titles = array_column($characters, 1);
        foreach ($characters as $index => [$key, $title, $character, $source]) {
            $questions[] = self::fact(161 + $index, $key, $index < 20 ? 'easy' : 'medium', 'character',
                $title, $title, $titles, $source, $character);
        }
        foreach (self::descriptions() as $index => [$key, $title, $difficulty, $source, $clues]) {
            $id = 191 + $index;
            [$options, $correctOption] = self::options($title, array_column(self::descriptions(), 1), $id);
            $localized = [];
            foreach (self::LOCALES as $localeIndex => $locale) {
                $localized[$locale] = [
                    'question' => strtr(self::templates()[$locale]['description'][0], ['{clue}' => $clues[$localeIndex]]),
                    'options' => $options,
                    'explanation' => strtr(self::templates()[$locale]['description'][1], ['{title}' => $title]),
                ];
            }
            $questions[] = self::record($id, $key, $difficulty, 'description', $correctOption, $source, $localized);
        }
        usort($questions, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $questions;
    }

    private static function fact(
        int $id,
        string $animeKey,
        string $difficulty,
        string $kind,
        string $title,
        string $answer,
        array $alternatives,
        string $source,
        string $character = '',
    ): array {
        [$options, $correctOption] = self::options($answer, $alternatives, $id);
        $localized = [];
        foreach (self::LOCALES as $locale) {
            [$prompt, $explanation] = self::templates()[$locale][$kind];
            $parameters = ['{title}' => $title, '{answer}' => $answer, '{character}' => $character];
            $localized[$locale] = [
                'question' => strtr($prompt, $parameters),
                'options' => $options,
                'explanation' => strtr($explanation, $parameters),
            ];
        }

        return self::record($id, $animeKey, $difficulty, $kind === 'character' ? 'character' : 'trivia',
            $correctOption, $source, $localized);
    }

    private static function options(string $answer, array $alternatives, int $id): array
    {
        $decoys = array_values(array_filter(array_unique($alternatives), static fn (string $value): bool => $value !== $answer));
        // Rotate the distractor pool as well as the correct position: author/studio questions
        // should not repeat the same three names throughout the whole bank.
        $offset = $id % count($decoys);
        $decoys = [...array_slice($decoys, $offset), ...array_slice($decoys, 0, $offset)];
        $options = array_slice($decoys, 0, 3);
        $correctOption = $id % 4;
        array_splice($options, $correctOption, 0, [$answer]);

        return [$options, $correctOption];
    }

    private static function record(int $id, string $animeKey, string $difficulty, string $type, int $correctOption, string $source, array $localized): array
    {
        $polish = $localized['pl'];
        unset($localized['pl']);

        return [
            'id' => $id, ...$polish, 'correctOption' => $correctOption, 'sourceUrl' => $source,
            'animeKey' => $animeKey, 'difficulty' => $difficulty, 'type' => $type,
            'translations' => $localized,
        ];
    }

    /** Templates deliberately translate all prose; proper names and numeric years are invariant. */
    private static function templates(): array
    {
        return [
            'pl' => [
                'studio' => ['Które studio przygotowało animację pierwszego sezonu telewizyjnego „{title}”?', 'Animację pierwszego sezonu „{title}” przygotowało studio {answer}.'],
                'year' => ['W którym roku rozpoczęła się japońska emisja pierwszego sezonu telewizyjnego „{title}”?', 'Pierwszy sezon telewizyjny „{title}” rozpoczął emisję w Japonii w roku {answer}.'],
                'director' => ['Kto reżyserował pierwszy sezon telewizyjny „{title}”?', 'Reżyseria pierwszego sezonu „{title}”: {answer}.'],
                'author' => ['Kto stworzył mangę „{title}”, na podstawie której powstało anime?', 'Autorstwo mangi „{title}”: {answer}.'],
                'character' => ['Z którego anime pochodzi postać {character}?', 'Postać {character} występuje w anime „{title}”.'],
                'description' => ['Rozpoznaj anime po opisie: {clue}', 'Opis przedstawia założenia historii anime „{title}”.'],
            ],
            'en' => [
                'studio' => ['Which studio animated the first television season of “{title}”?', 'The first season of “{title}” was animated by {answer}.'],
                'year' => ['In which year did the first television season of “{title}” begin airing in Japan?', 'The first television season of “{title}” began airing in Japan in {answer}.'],
                'director' => ['Who directed the first television season of “{title}”?', 'The director of the first season of “{title}” was {answer}.'],
                'author' => ['Who created the manga “{title}” on which the anime is based?', 'The manga “{title}” was created by {answer}.'],
                'character' => ['Which anime features the character {character}?', '{character} is a character in “{title}”.'],
                'description' => ['Identify the anime from this description: {clue}', 'This description outlines the premise of “{title}”.'],
            ],
            'de' => [
                'studio' => ['Welches Studio animierte die erste Fernsehstaffel von „{title}“?', 'Die erste Staffel von „{title}“ wurde von {answer} animiert.'],
                'year' => ['In welchem Jahr begann die japanische Ausstrahlung der ersten Fernsehstaffel von „{title}“?', 'Die erste Fernsehstaffel von „{title}“ startete in Japan im Jahr {answer}.'],
                'director' => ['Wer führte bei der ersten Fernsehstaffel von „{title}“ Regie?', 'Regie bei der ersten Staffel von „{title}“: {answer}.'],
                'author' => ['Wer schuf den Manga „{title}“, auf dem der Anime basiert?', 'Der Manga „{title}“ stammt von {answer}.'],
                'character' => ['Aus welchem Anime stammt die Figur {character}?', 'Die Figur {character} tritt in „{title}“ auf.'],
                'description' => ['Erkenne den Anime anhand der Beschreibung: {clue}', 'Die Beschreibung gibt die Ausgangslage von „{title}“ wieder.'],
            ],
            'fr' => [
                'studio' => ['Quel studio a animé la première saison télévisée de « {title} » ?', 'La première saison de « {title} » a été animée par {answer}.'],
                'year' => ['En quelle année la première saison télévisée de « {title} » a-t-elle commencé au Japon ?', 'La première saison télévisée de « {title} » a commencé au Japon en {answer}.'],
                'director' => ['Qui a réalisé la première saison télévisée de « {title} » ?', 'Réalisation de la première saison de « {title} » : {answer}.'],
                'author' => ['Qui a créé le manga « {title} » dont est adapté cet anime ?', 'Le manga « {title} » a été créé par {answer}.'],
                'character' => ['Dans quel anime apparaît le personnage {character} ?', 'Le personnage {character} apparaît dans « {title} ».'],
                'description' => ['Reconnaissez cet anime grâce à sa description : {clue}', 'Cette description présente le point de départ de « {title} ».'],
            ],
            'es' => [
                'studio' => ['¿Qué estudio animó la primera temporada televisiva de «{title}»?', 'La primera temporada de «{title}» fue animada por {answer}.'],
                'year' => ['¿En qué año empezó a emitirse en Japón la primera temporada televisiva de «{title}»?', 'La primera temporada televisiva de «{title}» empezó a emitirse en Japón en {answer}.'],
                'director' => ['¿Quién dirigió la primera temporada televisiva de «{title}»?', 'La dirección de la primera temporada de «{title}» estuvo a cargo de {answer}.'],
                'author' => ['¿Quién creó el manga «{title}» en el que se basa el anime?', 'El manga «{title}» fue creado por {answer}.'],
                'character' => ['¿En qué anime aparece el personaje {character}?', 'El personaje {character} aparece en «{title}».'],
                'description' => ['Identifica el anime a partir de esta descripción: {clue}', 'Esta descripción presenta la premisa de «{title}».'],
            ],
            'ru' => [
                'studio' => ['Какая студия создала анимацию первого телевизионного сезона «{title}»?', 'Анимацию первого сезона «{title}» создала студия {answer}.'],
                'year' => ['В каком году в Японии начался показ первого телевизионного сезона «{title}»?', 'Первый телевизионный сезон «{title}» начали показывать в Японии в {answer} году.'],
                'director' => ['Кто был режиссёром первого телевизионного сезона «{title}»?', 'Режиссёр первого сезона «{title}»: {answer}.'],
                'author' => ['Кто создал мангу «{title}», по которой снято это аниме?', 'Автор манги «{title}»: {answer}.'],
                'character' => ['В каком аниме появляется персонаж {character}?', 'Персонаж {character} появляется в аниме «{title}».'],
                'description' => ['Узнайте аниме по описанию: {clue}', 'Описание передаёт завязку аниме «{title}».'],
            ],
        ];
    }

    /** First TV seasons only: no sequel dates, film dates or local dub premieres. */
    private static function productions(): array
    {
        return [
            ['fullmetal-alchemist', 'Fullmetal Alchemist: Brotherhood', 'Bones', 2009, 'https://www.bones.co.jp/work/fullmetal-alchemist/'],
            ['my-hero-academia', 'My Hero Academia', 'Bones', 2016, 'https://www.bones.co.jp/work/heroaca1/'],
            ['mob-psycho-100', 'Mob Psycho 100', 'Bones', 2016, 'https://www.bones.co.jp/work/mobpsycho/'],
            ['soul-eater', 'Soul Eater', 'Bones', 2008, 'https://www.bones.co.jp/work/soul-eater/'],
            ['noragami', 'Noragami', 'Bones', 2014, 'https://www.bones.co.jp/work/noragami/'],
            ['eureka-seven', 'Eureka Seven', 'Bones', 2005, 'https://www.bones.co.jp/work/eureka-seven/'],
            ['bungo-stray-dogs', 'Bungo Stray Dogs', 'Bones', 2016, 'https://www.bones.co.jp/work/bungo1_2/'],
            ['ouran-high-school-host-club', 'Ouran High School Host Club', 'Bones', 2006, 'https://www.bones.co.jp/work/ouran-high-school/'],
            ['sk8-the-infinity', 'SK8 the Infinity', 'Bones', 2021, 'https://www.bones.co.jp/work/sk%E2%88%9E/'],
            ['carole-and-tuesday', 'Carole & Tuesday', 'Bones', 2019, 'https://www.bones.co.jp/work/carotue/'],
            ['k-on', 'K-ON!', 'Kyoto Animation', 2009, 'https://www.kyotoanimation.co.jp/en/works/k-on/'],
            ['hyouka', 'Hyouka', 'Kyoto Animation', 2012, 'https://www.kyotoanimation.co.jp/en/works/hyouka/'],
            ['violet-evergarden', 'Violet Evergarden', 'Kyoto Animation', 2018, 'https://www.kyotoanimation.co.jp/en/works/violet/'],
            ['sound-euphonium', 'Sound! Euphonium', 'Kyoto Animation', 2015, 'https://www.kyotoanimation.co.jp/en/works/eupho/'],
            ['tamako-market', 'Tamako Market', 'Kyoto Animation', 2013, 'https://www.kyotoanimation.co.jp/en/works/tamako/'],
            ['haruhi-suzumiya', 'The Melancholy of Haruhi Suzumiya', 'Kyoto Animation', 2006, 'https://www.kyotoanimation.co.jp/en/works/haruhi/'],
            ['lucky-star', 'Lucky Star', 'Kyoto Animation', 2007, 'https://www.kyotoanimation.co.jp/en/works/luckystar/'],
            ['clannad', 'Clannad', 'Kyoto Animation', 2007, 'https://www.kyotoanimation.co.jp/en/works/clannad/'],
            ['dragon-maid', 'Miss Kobayashi’s Dragon Maid', 'Kyoto Animation', 2017, 'https://www.kyotoanimation.co.jp/en/works/maidragon/'],
            ['nichijou', 'Nichijou', 'Kyoto Animation', 2011, 'https://www.kyotoanimation.co.jp/en/works/nichijou/'],
            ['death-note', 'Death Note', 'Madhouse', 2006, 'https://madhouse.co.jp/works/tv_deathnote/'],
            ['black-lagoon', 'Black Lagoon', 'Madhouse', 2006, 'https://madhouse.co.jp/english/works/'],
            ['one-punch-man', 'One-Punch Man', 'Madhouse', 2015, 'https://madhouse.co.jp/works/tv_onepunchman/'],
            ['overlord', 'Overlord', 'Madhouse', 2015, 'https://madhouse.co.jp/works/tv_overlord/'],
            ['no-game-no-life', 'No Game No Life', 'Madhouse', 2014, 'https://madhouse.co.jp/english/works/'],
            ['death-parade', 'Death Parade', 'Madhouse', 2015, 'https://madhouse.co.jp/works/tv_deathparade/'],
            ['parasyte', 'Parasyte -the maxim-', 'Madhouse', 2014, 'https://madhouse.co.jp/works/tv_kiseiju/'],
            ['chihayafuru', 'Chihayafuru', 'Madhouse', 2011, 'https://www.ntv.co.jp/english/pc/2011/08/chihayafuru.html'],
            ['monster', 'Monster', 'Madhouse', 2004, 'https://www.ntv.co.jp/monster/'],
            ['frieren', 'Frieren: Beyond Journey’s End', 'Madhouse', 2023, 'https://madhouse.co.jp/works/tv_frieren-anime/'],
        ];
    }

    private static function directors(): array
    {
        return [
            ['fullmetal-alchemist', 'Fullmetal Alchemist: Brotherhood', 'Yasuhiro Irie', 'https://www.bones.co.jp/work/fullmetal-alchemist/'],
            ['my-hero-academia', 'My Hero Academia', 'Kenji Nagasaki', 'https://www.bones.co.jp/work/heroaca1/'],
            ['mob-psycho-100', 'Mob Psycho 100', 'Yuzuru Tachikawa', 'https://www.bones.co.jp/work/mobpsycho/'],
            ['soul-eater', 'Soul Eater', 'Takuya Igarashi', 'https://www.bones.co.jp/work/soul-eater/'],
            ['noragami', 'Noragami', 'Kotaro Tamura', 'https://www.bones.co.jp/work/noragami/'],
            ['eureka-seven', 'Eureka Seven', 'Tomoki Kyoda', 'https://www.bones.co.jp/work/eureka-seven/'],
            ['bungo-stray-dogs', 'Bungo Stray Dogs', 'Takuya Igarashi', 'https://www.bones.co.jp/work/bungo1_2/'],
            ['ouran-high-school-host-club', 'Ouran High School Host Club', 'Takuya Igarashi', 'https://www.bones.co.jp/work/ouran-high-school/'],
            ['sk8-the-infinity', 'SK8 the Infinity', 'Hiroko Utsumi', 'https://www.bones.co.jp/work/sk%E2%88%9E/'],
            ['blast-of-tempest', 'Blast of Tempest', 'Masahiro Ando', 'https://www.bones.co.jp/work/blast-of-tempest/'],
            ['un-go', 'UN-GO', 'Seiji Mizushima', 'https://www.bones.co.jp/work/un-go/'],
            ['gosick', 'Gosick', 'Hitoshi Nanba', 'https://www.bones.co.jp/work/gosick/'],
            ['no-6', 'No. 6', 'Kenji Nagasaki', 'https://www.bones.co.jp/work/no6/'],
            ['darker-than-black', 'Darker than Black', 'Tensai Okamura', 'https://www.bones.co.jp/work/dtb/'],
            ['wolfs-rain', 'Wolf’s Rain', 'Tensai Okamura', 'https://www.bones.co.jp/work/wolfs-rain/'],
            ['rahxephon', 'RahXephon', 'Yutaka Izubuchi', 'https://www.bones.co.jp/work/rahxephon/'],
            ['concrete-revolutio', 'Concrete Revolutio', 'Seiji Mizushima', 'https://www.bones.co.jp/work/concre/'],
            ['snow-white-with-red-hair', 'Snow White with the Red Hair', 'Masahiro Ando', 'https://www.bones.co.jp/work/akagami/'],
            ['chaika', 'Chaika -The Coffin Princess-', 'Soichi Masui', 'https://www.bones.co.jp/work/chaika/'],
            ['death-note', 'Death Note', 'Tetsuro Araki', 'https://madhouse.co.jp/works/tv_deathnote/'],
        ];
    }

    private static function authors(): array
    {
        return [
            ['spy-family', 'SPY × FAMILY', 'Tatsuya Endo', 'https://www.viz.com/spy-x-family'],
            ['demon-slayer', 'Demon Slayer: Kimetsu no Yaiba', 'Koyoharu Gotouge', 'https://www.viz.com/demon-slayer-kimetsu-no-yaiba'],
            ['haikyu', 'Haikyu!!', 'Haruichi Furudate', 'https://www.viz.com/haikyu'],
            ['fullmetal-alchemist', 'Fullmetal Alchemist', 'Hiromu Arakawa', 'https://www.viz.com/fullmetal-alchemist'],
            ['bleach', 'Bleach', 'Tite Kubo', 'https://www.viz.com/bleach'],
            ['black-clover', 'Black Clover', 'Yuki Tabata', 'https://www.viz.com/black-clover'],
            ['tokyo-ghoul', 'Tokyo Ghoul', 'Sui Ishida', 'https://www.viz.com/tokyo-ghoul'],
            ['blue-exorcist', 'Blue Exorcist', 'Kazue Kato', 'https://www.viz.com/blue-exorcist'],
            ['assassination-classroom', 'Assassination Classroom', 'Yusei Matsui', 'https://www.viz.com/assassination-classroom'],
            ['dandadan', 'Dandadan', 'Yukinobu Tatsu', 'https://www.viz.com/dandadan'],
            ['kaiju-no-8', 'Kaiju No. 8', 'Naoya Matsumoto', 'https://www.viz.com/kaiju-no-8'],
            ['undead-unluck', 'Undead Unluck', 'Yoshifumi Tozuka', 'https://www.viz.com/undead-unluck'],
            ['mashle', 'Mashle: Magic and Muscles', 'Hajime Komoto', 'https://www.viz.com/mashle-magic-and-muscles'],
            ['sakamoto-days', 'Sakamoto Days', 'Yuto Suzuki', 'https://www.viz.com/sakamoto-days'],
            ['komi-cant-communicate', 'Komi Can’t Communicate', 'Tomohito Oda', 'https://www.viz.com/komi-can-t-communicate'],
            ['call-of-the-night', 'Call of the Night', 'Kotoyama', 'https://www.viz.com/call-of-the-night'],
            ['nana', 'Nana', 'Ai Yazawa', 'https://www.viz.com/nana'],
            ['soul-eater', 'Soul Eater', 'Atsushi Ohkubo', 'https://www.bones.co.jp/work/soul-eater/'],
            ['noragami', 'Noragami', 'Adachitoka', 'https://www.bones.co.jp/work/noragami/'],
            ['ouran-high-school-host-club', 'Ouran High School Host Club', 'Bisco Hatori', 'https://www.viz.com/ouran-high-school-host-club'],
        ];
    }

    private static function characters(): array
    {
        return [
            ['naruto', 'Naruto', 'Kakashi Hatake', 'https://naruto-official.com/en/news/01_1903'],
            ['one-piece', 'One Piece', 'Roronoa Zoro', 'https://one-piece.com/character/zoro/index.html'],
            ['death-note', 'Death Note', 'L', 'https://madhouse.co.jp/works/tv_deathnote/'],
            ['spy-family', 'SPY × FAMILY', 'Loid Forger', 'https://spy-family.net/tvseries/'],
            ['demon-slayer', 'Demon Slayer: Kimetsu no Yaiba', 'Zenitsu Agatsuma', 'https://demonslayer-anime.com/eda/character/?id=03'],
            ['my-hero-academia', 'My Hero Academia', 'Katsuki Bakugo', 'https://www.bones.co.jp/work/heroaca1/'],
            ['haikyu', 'Haikyu!!', 'Tobio Kageyama', 'https://www.viz.com/haikyu'],
            ['dr-stone', 'Dr. STONE', 'Kohaku', 'https://dr-stone.jp/character/'],
            ['fullmetal-alchemist', 'Fullmetal Alchemist: Brotherhood', 'Roy Mustang', 'https://www.bones.co.jp/work/fullmetal-alchemist/'],
            ['jujutsu-kaisen', 'Jujutsu Kaisen', 'Satoru Gojo', 'https://jujutsukaisen.jp/character/shibuyajihen.php'],
            ['dragon-ball', 'Dragon Ball Z', 'Vegeta', 'https://en.dragon-ball-official.com/news/01_4219.html'],
            ['sailor-moon', 'Sailor Moon', 'Ami Mizuno', 'https://sailormoon-official.com/stage/news/superlive_1207.php'],
            ['hunter-x-hunter', 'Hunter × Hunter', 'Killua Zoldyck', 'https://madhouse.co.jp/works/tv_hunter/'],
            ['one-punch-man', 'One-Punch Man', 'Genos', 'https://madhouse.co.jp/works/tv_onepunchman/'],
            ['chainsaw-man', 'Chainsaw Man', 'Power', 'https://chainsawman.dog/tvseries/character/'],
            ['attack-on-titan', 'Attack on Titan', 'Levi Ackerman', 'https://shingeki.tv/season1/character/'],
            ['bleach', 'Bleach', 'Ichigo Kurosaki', 'https://www.viz.com/bleach'],
            ['black-clover', 'Black Clover', 'Asta', 'https://www.viz.com/black-clover'],
            ['tokyo-ghoul', 'Tokyo Ghoul', 'Ken Kaneki', 'https://www.viz.com/tokyo-ghoul'],
            ['blue-exorcist', 'Blue Exorcist', 'Rin Okumura', 'https://www.viz.com/blue-exorcist'],
            ['mob-psycho-100', 'Mob Psycho 100', 'Shigeo Kageyama', 'https://www.bones.co.jp/work/mobpsycho/'],
            ['noragami', 'Noragami', 'Yato', 'https://www.bones.co.jp/work/noragami/'],
            ['soul-eater', 'Soul Eater', 'Maka Albarn', 'https://www.bones.co.jp/work/soul-eater/'],
            ['bungo-stray-dogs', 'Bungo Stray Dogs', 'Osamu Dazai', 'https://www.bones.co.jp/work/bungo1_2/'],
            ['sk8-the-infinity', 'SK8 the Infinity', 'Langa Hasegawa', 'https://www.bones.co.jp/work/sk%E2%88%9E/'],
            ['eureka-seven', 'Eureka Seven', 'Renton Thurston', 'https://www.bones.co.jp/work/eureka-seven/'],
            ['ouran-high-school-host-club', 'Ouran High School Host Club', 'Haruhi Fujioka', 'https://www.bones.co.jp/work/ouran-high-school/'],
            ['hyouka', 'Hyouka', 'Eru Chitanda', 'https://www.kyotoanimation.co.jp/en/works/hyouka/'],
            ['k-on', 'K-ON!', 'Mio Akiyama', 'https://www.kyotoanimation.co.jp/en/works/k-on/'],
            ['tamako-market', 'Tamako Market', 'Tamako Kitashirakawa', 'https://www.kyotoanimation.co.jp/en/works/tamako/'],
        ];
    }

    /** Clues are original short summaries of the premise, in pl/en/de/fr/es/ru order. */
    private static function descriptions(): array
    {
        return [
            ['attack-on-titan', 'Attack on Titan', 'easy', 'https://www.witstudio.co.jp/works/shingeki', [
                'Ludzie chronią się za ogromnymi murami przed pożerającymi ich gigantami. Młodzi żołnierze chcą poznać świat poza fortyfikacjami.',
                'Humanity shelters behind enormous walls from man-eating giants. Young soldiers want to discover the world beyond the fortifications.',
                'Die Menschheit schützt sich hinter riesigen Mauern vor menschenfressenden Giganten. Junge Soldaten wollen die Welt dahinter entdecken.',
                'Les humains se protègent derrière de hautes murailles contre des géants dévoreurs. De jeunes soldats veulent découvrir le monde extérieur.',
                'La humanidad se refugia tras enormes murallas frente a gigantes devoradores. Unos jóvenes soldados quieren descubrir el mundo exterior.',
                'Люди укрываются за огромными стенами от великанов-людоедов. Молодые солдаты хотят узнать, что находится за укреплениями.',
            ]],
            ['death-note', 'Death Note', 'easy', 'https://www.viz.com/death-note', [
                'Wzorowy uczeń znajduje notes, który może zabijać ludzi po wpisaniu ich nazwisk. Tajemniczy detektyw próbuje go powstrzymać.',
                'A model student finds a notebook that can kill people whose names are written in it. A mysterious detective tries to stop him.',
                'Ein Musterschüler findet ein Heft, das Menschen töten kann, deren Namen darin stehen. Ein geheimnisvoller Detektiv will ihn stoppen.',
                'Un élève brillant trouve un carnet capable de tuer les personnes dont il écrit le nom. Un mystérieux détective tente de l’arrêter.',
                'Un estudiante ejemplar encuentra un cuaderno que mata a quienes tienen su nombre escrito en él. Un misterioso detective intenta detenerlo.',
                'Образцовый ученик находит тетрадь, убивающую людей, чьи имена в неё записаны. Таинственный детектив пытается его остановить.',
            ]],
            ['spy-family', 'SPY × FAMILY', 'easy', 'https://www.viz.com/spy-x-family', [
                'Szpieg zakłada rodzinę na potrzeby misji. Nie wie, że jego żona jest zabójczynią, a adoptowana córka czyta w myślach.',
                'A spy starts a family for a mission. He does not know his wife is an assassin and his adopted daughter can read minds.',
                'Ein Spion gründet für einen Auftrag eine Familie. Er weiß nicht, dass seine Frau eine Auftragsmörderin ist und seine Adoptivtochter Gedanken liest.',
                'Un espion fonde une famille pour une mission. Il ignore que sa femme est une tueuse et que sa fille adoptive lit les pensées.',
                'Un espía forma una familia para una misión. No sabe que su esposa es una asesina y su hija adoptiva puede leer la mente.',
                'Шпион создаёт семью ради задания. Он не знает, что его жена — наёмная убийца, а приёмная дочь умеет читать мысли.',
            ]],
            ['one-punch-man', 'One-Punch Man', 'easy', 'https://www.viz.com/one-punch-man', [
                'Łysy bohater pokonuje przeciwników jednym ciosem. Zamiast cieszyć się siłą, nudzi się i szuka godnego rywala.',
                'A bald hero defeats opponents with a single punch. Rather than enjoying his strength, he is bored and seeks a worthy rival.',
                'Ein glatzköpfiger Held besiegt seine Gegner mit einem einzigen Schlag. Statt seine Stärke zu genießen, langweilt er sich und sucht einen würdigen Rivalen.',
                'Un héros chauve bat ses adversaires d’un seul coup. Sa puissance l’ennuie et il cherche un rival à sa hauteur.',
                'Un héroe calvo derrota a sus rivales de un solo golpe. Su fuerza le aburre y busca un oponente digno.',
                'Лысый герой побеждает противников одним ударом. Его сила ему наскучила, и он ищет достойного соперника.',
            ]],
            ['hyouka', 'Hyouka', 'medium', 'https://www.kyotoanimation.co.jp/en/works/hyouka/', [
                'Uczeń oszczędzający energię trafia do klubu literatury klasycznej. Ciekawość koleżanki wciąga go w rozwiązywanie szkolnych zagadek.',
                'An energy-saving student joins a classic literature club. A classmate’s curiosity draws him into solving everyday school mysteries.',
                'Ein energiesparender Schüler tritt einem Literaturclub bei. Die Neugier einer Mitschülerin zieht ihn in die Lösung alltäglicher Schulrätsel hinein.',
                'Un lycéen économe de ses efforts rejoint un club de littérature classique. La curiosité d’une camarade l’entraîne dans des énigmes scolaires.',
                'Un estudiante que ahorra esfuerzos entra en un club de literatura clásica. La curiosidad de una compañera lo lleva a resolver misterios escolares.',
                'Школьник, привыкший беречь силы, вступает в клуб классической литературы. Любопытная одноклассница вовлекает его в расследование школьных загадок.',
            ]],
            ['dr-stone', 'Dr. STONE', 'medium', 'https://www.viz.com/dr-stone', [
                'Po tysiącach lat skamienienia ludzie budzą się w świecie bez współczesnej technologii. Młody naukowiec postanawia odbudować cywilizację.',
                'After thousands of years turned to stone, people awaken in a world without modern technology. A young scientist decides to rebuild civilization.',
                'Nach Jahrtausenden der Versteinerung erwachen Menschen in einer Welt ohne moderne Technik. Ein junger Wissenschaftler will die Zivilisation neu aufbauen.',
                'Après des millénaires de pétrification, des humains se réveillent dans un monde sans technologie moderne. Un jeune scientifique veut reconstruire la civilisation.',
                'Tras miles de años petrificadas, las personas despiertan en un mundo sin tecnología moderna. Un joven científico decide reconstruir la civilización.',
                'После тысячелетий окаменения люди просыпаются в мире без современных технологий. Молодой учёный решает восстановить цивилизацию.',
            ]],
            ['fullmetal-alchemist', 'Fullmetal Alchemist: Brotherhood', 'medium', 'https://www.bones.co.jp/work/fullmetal-alchemist/', [
                'Dwaj bracia płacą straszną cenę za zakazaną alchemię. Wyruszają po legendarny kamień, aby odzyskać utracone ciała.',
                'Two brothers pay a terrible price for forbidden alchemy. They seek a legendary stone to restore their lost bodies.',
                'Zwei Brüder zahlen einen furchtbaren Preis für verbotene Alchemie. Sie suchen einen legendären Stein, um ihre verlorenen Körper zurückzuerlangen.',
                'Deux frères paient un terrible prix pour une alchimie interdite. Ils cherchent une pierre légendaire afin de retrouver leurs corps.',
                'Dos hermanos pagan un terrible precio por la alquimia prohibida. Buscan una piedra legendaria para recuperar sus cuerpos.',
                'Два брата дорого платят за запретную алхимию. Они ищут легендарный камень, чтобы вернуть утраченные тела.',
            ]],
            ['chihayafuru', 'Chihayafuru', 'medium', 'https://www.ntv.co.jp/english/pc/2011/08/chihayafuru.html', [
                'Dziewczyna obdarzona świetnym słuchem zakłada szkolny klub karuty. Karty z klasyczną poezją stają się polem sportowej rywalizacji.',
                'A girl with exceptional hearing starts a school karuta club. Cards featuring classical poetry become the arena for fierce competition.',
                'Ein Mädchen mit außergewöhnlichem Gehör gründet einen Karuta-Schulclub. Karten mit klassischer Dichtung werden zum Schauplatz sportlichen Wettkampfs.',
                'Une jeune fille à l’ouïe exceptionnelle fonde un club scolaire de karuta. Des cartes de poésie classique deviennent un terrain de compétition.',
                'Una chica con un oído excepcional funda un club escolar de karuta. Las cartas de poesía clásica se convierten en un campo de competición.',
                'Девушка с отличным слухом создаёт школьный клуб каруты. Карты с классическими стихами становятся основой напряжённых соревнований.',
            ]],
            ['eureka-seven', 'Eureka Seven', 'hard', 'https://www.bones.co.jp/work/eureka-seven/', [
                'Chłopak marzący o podniebnym surfingu spotyka tajemniczą dziewczynę i maszynę Nirvash. Dołącza do załogi Gekkostate.',
                'A boy dreaming of sky surfing meets a mysterious girl and the machine Nirvash. He joins the crew of Gekkostate.',
                'Ein Junge träumt vom Surfen am Himmel und trifft ein geheimnisvolles Mädchen mit der Maschine Nirvash. Er schließt sich Gekkostate an.',
                'Un garçon rêve de surfer dans le ciel et rencontre une mystérieuse fille avec la machine Nirvash. Il rejoint l’équipage de Gekkostate.',
                'Un chico sueña con surfear por el cielo y conoce a una misteriosa chica con la máquina Nirvash. Se une a la tripulación de Gekkostate.',
                'Мальчик мечтает о сёрфинге в небе и встречает загадочную девушку с машиной Nirvash. Он присоединяется к команде Gekkostate.',
            ]],
            ['noragami', 'Noragami', 'hard', 'https://www.bones.co.jp/work/noragami/', [
                'Mało znany bóg bez własnej świątyni oferuje pomoc za pięć jenów. Próbuje zdobyć wyznawców i poprawić swój los.',
                'A little-known god without a shrine offers help for five yen. He tries to gain followers and improve his fortunes.',
                'Ein kaum bekannter Gott ohne eigenen Schrein bietet Hilfe für fünf Yen an. Er versucht, Anhänger zu gewinnen und sein Schicksal zu verbessern.',
                'Un dieu peu connu sans sanctuaire propose son aide pour cinq yens. Il tente de gagner des fidèles et d’améliorer son sort.',
                'Un dios poco conocido y sin santuario ofrece ayuda por cinco yenes. Intenta ganar seguidores y mejorar su suerte.',
                'Малоизвестный бог без собственного храма предлагает помощь за пять иен. Он пытается обрести последователей и изменить свою судьбу.',
            ]],
        ];
    }
}
