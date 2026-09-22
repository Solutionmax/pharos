<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;

class TokenAccess
{
    public static function allows(ApiToken $token, int $pageId, bool $write = false): bool
    {
        if (! in_array($token->scope, ['read', 'write'], true) || ($write && $token->scope !== 'write')) {
            return false;
        }
        if ((int) $token->status_page_id !== $pageId) {
            return false;
        }
        if ($token->user_id === null) {
            return $pageId === StatusPage::default()->id;
        }

        $owner = User::find($token->user_id);

        return $write ? ($owner?->canEditPage($pageId) ?? false) : ($owner?->canAccessPage($pageId) ?? false);
    }
}
