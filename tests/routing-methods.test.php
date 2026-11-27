<?php

declare(strict_types=1);

use App\Attribute\AllowedMethods;
use App\Controller\AppController;
use App\Controller\AuthController;
use App\Routing;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class AllowedMethodsTestController extends AppController
{
    public static array $calls = [];

    #[AllowedMethods('POST')]
    public function onlyPost(): void
    {
        self::$calls[] = 'onlyPost';
    }

    public function anything(): void
    {
        self::$calls[] = 'anything';
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$routing = new Routing();
// A route registered for GET by mistake is still blocked by the action's attribute.
$routing->get('/guarded', AllowedMethodsTestController::class, 'onlyPost');
$routing->post('/guarded', AllowedMethodsTestController::class, 'onlyPost');
$routing->get('/open/{id}', AllowedMethodsTestController::class, 'anything');

$assert(!$routing->dispatch('GET', '/guarded'), 'The attribute must block a GET route.');
$assert(AllowedMethodsTestController::$calls === [], 'A blocked action must not run.');
$assert($routing->allowedMethods('/guarded') === ['POST'], 'Allow must list only accepted methods.');
$assert($routing->dispatch('POST', '/guarded'), 'POST must reach the action.');
$assert(AllowedMethodsTestController::$calls === ['onlyPost'], 'The allowed action must run once.');
$assert($routing->allowedMethods('/open/7?source=test') === ['GET'], 'Query strings must not change the result.');
$assert(!$routing->dispatch('POST', '/open/7'), 'An unregistered method must not dispatch.');
$assert($routing->allowedMethods('/missing') === [], 'An unknown address must give 404, not 405.');
echo "[OK] #[AllowedMethods] blocks other methods and allowedMethods() feeds the Allow header\n";

$attributeOf = static function (string $action): array {
    $attributes = (new ReflectionMethod(AuthController::class, $action))->getAttributes(AllowedMethods::class);
    return $attributes === [] ? [] : $attributes[0]->newInstance()->methods;
};
$assert($attributeOf('login') === ['GET', 'POST'], 'Login accepts only GET and POST.');
$assert($attributeOf('register') === ['GET', 'POST'], 'Registration accepts only GET and POST.');
$assert($attributeOf('logout') === ['POST'], 'Logout accepts only POST.');
echo "[OK] Login, registration and logout declare their HTTP methods\n";
