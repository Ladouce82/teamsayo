<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Workspace $workspace, public User $inviter, public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviter->name} invited you to {$this->workspace->name} on TeamSayo")
            ->greeting('Hello,')
            ->line("{$this->inviter->name} has invited you to join **{$this->workspace->name}** on TeamSayo.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires in 14 days. If you were not expecting it, you can ignore this email.');
    }
}
