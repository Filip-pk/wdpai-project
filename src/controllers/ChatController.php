<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Data\DemoData;

final class ChatController extends AppController
{
    public function index(): void
    {
        $animes = DemoData::animes();

        $this->render('chat-assistant', [
            'pageTitle' => Translator::current()->translate('message.page.ai_assistant'),
            'user' => DemoData::user(),
            'animeId' => 3,
            'anime' => $animes[3],
        ]);
    }

    public function show(int $id): void
    {
        $personas = DemoData::personas();

        if ($id < 1 || !isset($personas[$id])) {
            http_response_code(404);

            $this->render('not-found', [
                'pageTitle' => Translator::current()->translate('message.page.persona_missing'),
            ]);

            return;
        }

        $this->render('chat-persona', [
            'pageTitle' => Translator::current()->translate('message.page.chat_prefix') . $personas[$id]['name'] . ' — AniVibe',
            'persona' => $personas[$id],
            'isNaruto' => $id === 1,
        ]);
    }
}