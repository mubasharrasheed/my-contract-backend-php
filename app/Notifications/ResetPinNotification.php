<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPinNotification extends Notification
{
    use Queueable;

    public $pin;

    public function __construct(string $pin)
    {
        $this->pin = $pin;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Your password reset PIN')
                    ->line('You requested a password reset. Use the following 6-digit PIN to reset your password:')
                    ->line('PIN: ' . $this->pin)
                    ->line('This PIN will expire shortly. If you did not request a password reset, please ignore this message.');
    }
}
