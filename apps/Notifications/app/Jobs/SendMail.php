<?php

declare(strict_types=1);

namespace Apps\Notifications\Jobs;

use Apps\Notifications\Mail\NotificationMail;
use Apps\Notifications\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

final class SendMail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Notification $notification) {}

    public function handle(): void
    {
        $recipient = $this->notification->recipient;

        if ($recipient !== null) {
            Mail::to($recipient->email)->send(new NotificationMail($this->notification));
        }
    }
}
