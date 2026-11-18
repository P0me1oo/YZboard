<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Client\AppController;
use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // App
            $router->get('/app/getConfig', [AppController::class, 'getConfig'])->middleware('throttle:60,1');
            $router->get('/app/getVersion', [AppController::class, 'getVersion'])->middleware('throttle:60,1');
        });
    }
}
