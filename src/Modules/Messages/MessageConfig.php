<?php
declare(strict_types=1);

namespace Netmafia\Modules\Messages;

final class MessageConfig
{
    public const SUBJECT_MAX_LENGTH = 255;
    public const BODY_MAX_LENGTH = 5000;
    
    /**
     * Spam védelem: minimum idő két üzenet között másodpercben
     */
    public const SPAM_COOLDOWN_SECONDS = 15;
    
    /**
     * Bejövő és kimenő üzenetek maximális lekérdezési limitje / oldal
     */
    public const INBOX_LIMIT = 100;
    public const DEFAULT_PAGE_SIZE = 50;
}
