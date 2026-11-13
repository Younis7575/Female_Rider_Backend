<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development SMS driver: writes messages to the application log instead of sending. */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        Log::channel(config('logging.default'))->info('[SMS:log] to '.$phone.': '.$message);

        return true;
    }
}
