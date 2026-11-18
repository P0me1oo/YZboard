<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\TelegramBot\UserEntryService;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        return $this->success(app(UserEntryService::class)->botInfo());
    }

}
