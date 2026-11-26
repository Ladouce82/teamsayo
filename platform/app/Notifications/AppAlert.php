<?php

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Workspace $workspace, public string $text)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plain = trim(preg_replace('/^[^\pL\pN“"]+/u', '', $this->text));

        return (new MailMessage)
            ->subject('TeamSayo · ' . mb_strimwidth($plain, 0, 80, '…'))
            ->line($this->text)
            ->action('Open TeamSayo', route('app', $this->workspace))
            ->line('You can change which notifications are emailed in TeamSayo → Settings → General.');
    }
}
