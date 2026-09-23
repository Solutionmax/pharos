<?php

namespace App\Notifications\Concerns;

use App\Services\MailConfig;
use App\Services\MailTemplates;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Account mails (invitation, password reset) in the same frame as every other
 * mail from this install: brand name, logo, accent and footer. Without it
 * Laravel's own layout is used, which signs with APP_NAME instead of the brand.
 */
trait BrandedAccountMail
{
    /** The greeting, lines and action stay on the message; only the frame is ours. */
    protected function branded(MailMessage $message): MailMessage
    {
        $sender = app(MailConfig::class)->sender();
        if ($sender['address'] !== '') {
            $message->from($sender['address'], $sender['name']);
        }
        if ($sender['reply_to'] !== '') {
            $message->replyTo($sender['reply_to']);
        }

        return $message->view(['html' => 'mail.account', 'text' => 'mail.text.account'], MailTemplates::frame());
    }
}
