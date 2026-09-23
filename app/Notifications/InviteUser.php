<?php

namespace App\Notifications;

use App\Services\Branding;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You have an account, choose your password." Same shape as the password
 * reset mail, with a link built from the configured installation URL.
 */
class InviteUser extends Notification
{
    public function __construct(public string $token, public string $invitedBy) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function url(mixed $notifiable): string
    {
        // Never an untrusted Host header: always the installation URL.
        return rtrim((string) config('app.url'), '/').'/admin/welcome/'.rawurlencode($this->token)
            .'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = app(Branding::class)->name();
        $days = intdiv((int) config('auth.passwords.invitations.expire'), 60 * 24);

        return (new MailMessage)
            ->subject('You are invited to '.$name)
            ->greeting('Welcome, '.$notifiable->name)
            ->line($this->invitedBy.' created an account for you on '.$name.'.')
            ->action('Choose your password', $this->url($notifiable))
            ->line('This link works for '.$days.' '.($days === 1 ? 'day' : 'days').' and only once. After that, ask for a new invitation or use "Forgot password" on the sign in screen.')
            ->line('If you did not expect this, you can ignore this email.');
    }
}
