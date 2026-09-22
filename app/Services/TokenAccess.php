<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;

class TokenAccess
{
    public static function allows(ApiToken $token, int $pageId): bool
    {
        if ((int) $token->status_page_id !== $pageId) {
            return false;
        }
        if ($token->user_id === null) {
            return $pageId === StatusPage::default()->id;
        }

        return User::find($token->user_id)?->canAccessPage($pageId) ?? false;
    }
}
