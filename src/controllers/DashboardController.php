<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;
use App\Repository\UserRepository;
use Closure;

final class DashboardController extends AppController
{
    public function __construct(private UserRepository|Closure $users)
    {
    }

    public function index(): void
    {
        $animes = DemoData::animes();
        $user = null;

        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            if ($this->users instanceof Closure) {
                $this->users = ($this->users)();
            }
            $user = $this->currentUser($this->users);
        }

        $this->render('dashboard', [
            'pageTitle' => 'Twój pulpit — AniVibe',
            'user' => $user,
            'personas' => DemoData::personas(),
            'recommendations' => [
                3 => $animes[3],
                5 => $animes[5],
            ],
        ]);
    }
}
