<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum;

class ForumConfig
{
    public const POSTS_PER_PAGE = 15;
    public const TOPIC_TITLE_MIN = 3;
    public const TOPIC_TITLE_MAX = 200;
    public const POST_CONTENT_MIN = 2;
    public const POST_CONTENT_MAX = 10000;
}
