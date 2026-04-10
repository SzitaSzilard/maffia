<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth;

final class AuthConfig
{
    public const USERNAME_MIN_LENGTH = 2;
    public const USERNAME_MAX_LENGTH = 20;
    public const PASSWORD_MIN_LENGTH = 6;
    
    public const MAX_REGISTER_ATTEMPTS = 3;
    public const LOCKOUT_MINUTES = 60;
}
