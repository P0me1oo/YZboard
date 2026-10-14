<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramBot\UserEntryService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        return $this->success(app(UserEntryService::class)->botInfo());
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user()->id)->first();
    }
}
