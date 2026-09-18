<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API token that expires after a period without use instead of at a fixed
 * date: every save recomputes "expires_at" from the last use, and Sanctum
 * saves the token on every authenticated request.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected static function booted(): void
    {
        static::saving(function (PersonalAccessToken $token): void {
            $lastActivity = $token->last_used_at ?? $token->created_at ?? now();

            $token->expires_at = $lastActivity->addMinutes((int) config('sanctum.inactivity_expiration'));
        });
    }
}
