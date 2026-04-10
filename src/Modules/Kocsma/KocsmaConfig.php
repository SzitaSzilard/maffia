<?php
declare(strict_types=1);

namespace Netmafia\Modules\Kocsma;

final class KocsmaConfig
{
    /**
     * Utolsó üzenetek lekérdezési limitje (chat)
     */
    public const RECENT_MESSAGES_LIMIT = 50;
    
    /**
     * Maximum üzenet hossz (karakter)
     * [2026-02-15] Input validációhoz (XSS/spam megelőzés)
     */
    public const MAX_MESSAGE_LENGTH = 500;
}
