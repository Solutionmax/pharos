<?php

namespace App\Notifications;

use App\Services\Branding;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetAccountPassword extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        // Use the configured installation URL, never an untrusted Host header.
        return rtrim(config('app.url'), '/').'/admin/reset-password/'.rawurlencode($this->token)
            .'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password for '.app(Branding::class)->name())
            ->greeting('Reset your password')
            ->line('A password reset was requested for your account.')
            ->action('Choose a new password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes and can only be used once.')
            ->line('If you did not request this, you can ignore this email. Your password has not changed.');
    }
}
