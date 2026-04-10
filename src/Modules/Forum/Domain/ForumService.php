<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Forum\ForumConfig;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class ForumService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    private function ensureIsAdmin(int $userId): void
    {
        $isAdmin = (bool)$this->db->fetchOne("SELECT is_admin FROM users WHERE id = ?", [$userId]);
        if (!$isAdmin) {
            throw new GameException("Nincs jogosultságod ehhez a művelethez!");
        }
    }

    /**
     * Kategóriák listája statisztikákkal
     */
    public function getCategories(): array
    {
        $categories = $this->db->fetchAllAssociative("
            SELECT 
                fc.id, fc.name, fc.description, fc.is_closed, fc.is_single_topic, fc.sort_order,
                COUNT(DISTINCT ft.id) as topic_count,
                COALESCE(SUM(ft.post_count), 0) as post_count
            FROM forum_categories fc
            LEFT JOIN forum_topics ft ON ft.category_id = fc.id
            GROUP BY fc.id
            ORDER BY fc.sort_order ASC, fc.name ASC
        ");

        if (empty($categories)) {
            return [];
        }

        $catIds = array_column($categories, 'id');
        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        
        $latestPosts = $this->db->fetchAllAssociative("
            SELECT fp.created_at, u.username, ft.category_id
            FROM forum_posts fp
            JOIN forum_topics ft ON fp.topic_id = ft.id
            JOIN users u ON fp.user_id = u.id
            WHERE fp.id IN (
                SELECT MAX(fp2.id) 
                FROM forum_posts fp2
                JOIN forum_topics ft2 ON fp2.topic_id = ft2.id
                WHERE ft2.category_id IN ($placeholders)
                GROUP BY ft2.category_id
            )
        ", $catIds);

        $latestByCat = [];
        foreach ($latestPosts as $lp) {
            $latestByCat[$lp['category_id']] = $lp;
        }

        foreach ($categories as &$cat) {
            $catId = $cat['id'];
            $cat['last_post_at'] = $latestByCat[$catId]['created_at'] ?? null;
            $cat['last_post_username'] = $latestByCat[$catId]['username'] ?? null;
        }

        return $categories;
    }

    /**
     * Egy kategória adatai
     */
    public function getCategory(int $id): ?array
    {
        $cat = $this->db->fetchAssociative(
            "SELECT id, name, description, is_closed, is_single_topic FROM forum_categories WHERE id = ?",
            [$id]
        );
        return $cat ?: null;
    }

    /**
     * Topikok egy kategóriában
     */
    public function getTopicsByCategory(int $categoryId): array
    {
        return $this->db->fetchAllAssociative("
            SELECT 
                ft.id, ft.title, ft.is_pinned, ft.is_locked, ft.post_count,
                ft.created_at,
                u.username as author_name,
                ft.last_post_at,
                u2.username as last_post_username
            FROM forum_topics ft
            JOIN users u ON ft.user_id = u.id
            LEFT JOIN users u2 ON ft.last_post_user_id = u2.id
            WHERE ft.category_id = ?
            ORDER BY ft.is_pinned DESC, ft.last_post_at DESC
        ", [$categoryId]);
    }

    /**
     * Egy topic adatai
     */
    public function getTopic(int $id): ?array
    {
        $topic = $this->db->fetchAssociative("
            SELECT ft.id, ft.category_id, ft.title, ft.is_pinned, ft.is_locked, ft.post_count,
                   ft.created_at, u.username as author_name,
                   fc.name as category_name, fc.is_closed as category_is_closed
            FROM forum_topics ft
            JOIN users u ON ft.user_id = u.id
            JOIN forum_categories fc ON ft.category_id = fc.id
            WHERE ft.id = ?
        ", [$id]);
        return $topic ?: null;
    }

    /**
     * Hozzászólások lapozva
     */
    public function getPostsByTopic(int $topicId, int $page): array
    {
        $offset = ($page - 1) * ForumConfig::POSTS_PER_PAGE;
        $qb = $this->db->createQueryBuilder();
        $qb->select('fp.id', 'fp.content', 'fp.created_at', 'u.id as user_id', 'u.username', 'u.is_admin', 'u.xp')
           ->from('forum_posts', 'fp')
           ->join('fp', 'users', 'u', 'fp.user_id = u.id')
           ->where('fp.topic_id = :topicId')
           ->setParameter('topicId', $topicId)
           ->orderBy('fp.created_at', 'DESC')
           ->setFirstResult($offset)
           ->setMaxResults(ForumConfig::POSTS_PER_PAGE);
        
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Topic poszt szám (lapozáshoz)
     */
    public function getPostCount(int $topicId): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM forum_posts WHERE topic_id = ?",
            [$topicId]
        );
    }

    /**
     * Kategória létrehozás (admin)
     */
    public function createCategory(string $name, string $description, bool $isClosed, bool $isSingleTopic, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $name = htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(trim($description), ENT_QUOTES, 'UTF-8');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new InvalidInputException('A kategória neve 2-100 karakter között legyen!');
        }

        $__fetchResult = $this->db->fetchOne("SELECT COALESCE(MAX(sort_order), 0) FROM forum_categories");
        if ($__fetchResult === false) {
            throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
        }
        $maxSort = (int) $__fetchResult;

        $this->db->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');

            $this->db->insert('forum_categories', [
                'name' => $name,
                'description' => $description,
                'is_closed' => $isClosed ? 1 : 0,
                'is_single_topic' => $isSingleTopic ? 1 : 0,
                'sort_order' => $maxSort + 1,
                'created_at' => $now,
            ]);
            $categoryId = (int)$this->db->lastInsertId();

            // Single-topic: automatikus topic + üres első poszt
            if ($isSingleTopic) {
                $this->db->insert('forum_topics', [
                    'category_id' => $categoryId,
                    'user_id' => $adminUserId,
                    'title' => $name,
                    'post_count' => 0,
                    'last_post_at' => $now,
                    'last_post_user_id' => $adminUserId,
                    'created_at' => $now,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Új topic nyitás (tranzakciós — topic + első poszt)
     */
    public function createTopic(int $categoryId, int $userId, string $title, string $content): int
    {
        $title = htmlspecialchars(trim($title), ENT_QUOTES, 'UTF-8');
        $content = htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8');

        if (mb_strlen($title) < ForumConfig::TOPIC_TITLE_MIN || mb_strlen($title) > ForumConfig::TOPIC_TITLE_MAX) {
            throw new InvalidInputException('A topic címe ' . ForumConfig::TOPIC_TITLE_MIN . '-' . ForumConfig::TOPIC_TITLE_MAX . ' karakter között legyen!');
        }
        if (mb_strlen($content) < ForumConfig::POST_CONTENT_MIN || mb_strlen($content) > ForumConfig::POST_CONTENT_MAX) {
            throw new InvalidInputException('A hozzászólás ' . ForumConfig::POST_CONTENT_MIN . '-' . ForumConfig::POST_CONTENT_MAX . ' karakter között legyen!');
        }

        $category = $this->getCategory($categoryId);
        if (!$category) {
            throw new GameException('A kategória nem létezik!');
        }

        $this->db->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');

            $this->db->insert('forum_topics', [
                'category_id' => $categoryId,
                'user_id' => $userId,
                'title' => $title,
                'post_count' => 1,
                'last_post_at' => $now,
                'last_post_user_id' => $userId,
                'created_at' => $now,
            ]);
            $topicId = (int)$this->db->lastInsertId();

            $this->db->insert('forum_posts', [
                'topic_id' => $topicId,
                'user_id' => $userId,
                'content' => $content,
                'created_at' => $now,
            ]);

            $this->db->commit();
            return $topicId;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Új hozzászólás (tranzakciós — poszt + topic stat frissítés)
     * §2.6: FOR UPDATE a topic soron — race condition elleni védelem
     */
    public function createPost(int $topicId, int $userId, string $content): void
    {
        $content = htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($content) < ForumConfig::POST_CONTENT_MIN || mb_strlen($content) > ForumConfig::POST_CONTENT_MAX) {
            throw new InvalidInputException('A hozzászólás ' . ForumConfig::POST_CONTENT_MIN . '-' . ForumConfig::POST_CONTENT_MAX . ' karakter között legyen!');
        }

        $this->db->beginTransaction();
        try {
            // FOR UPDATE: lockolja a topic sort, amíg a tranzakció tart
            $topic = $this->db->fetchAssociative(
                "SELECT ft.id, ft.is_locked, fc.is_closed as category_is_closed
                 FROM forum_topics ft
                 JOIN forum_categories fc ON ft.category_id = fc.id
                 WHERE ft.id = ? FOR UPDATE",
                [$topicId]
            );
            if (!$topic) {
                throw new GameException('A topic nem létezik!');
            }
            if (!empty($topic['is_locked'])) {
                throw new GameException('Ez a topic le van zárva!');
            }

            $now = gmdate('Y-m-d H:i:s');

            $this->db->insert('forum_posts', [
                'topic_id' => $topicId,
                'user_id' => $userId,
                'content' => $content,
                'created_at' => $now,
            ]);

            $this->db->executeStatement(
                "UPDATE forum_topics SET post_count = post_count + 1, last_post_at = ?, last_post_user_id = ? WHERE id = ?",
                [$now, $userId, $topicId]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Globális statisztikák (alul megjelenő összesítő)
     */
    public function getTotalStats(): array
    {
        $__fetchResult = $this->db->fetchOne("SELECT COUNT(*) FROM forum_topics");
        if ($__fetchResult === false) {
            throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
        }
        $topics = (int) $__fetchResult;
        $__fetchResult = $this->db->fetchOne("SELECT COUNT(*) FROM forum_posts");
        if ($__fetchResult === false) {
            throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
        }
        $posts = (int) $__fetchResult;
        return ['total_topics' => $topics, 'total_posts' => $posts];
    }

    /**
     * Single-topic kategória topic ID-ja
     */
    public function getSingleTopicId(int $categoryId): ?int
    {
        $topicId = $this->db->fetchOne(
            "SELECT id FROM forum_topics WHERE category_id = ? LIMIT 1",
            [$categoryId]
        );
        return $topicId !== false ? (int)$topicId : null;
    }

    // =========================================================================
    // Admin CRUD
    // =========================================================================

    /**
     * Kategória szerkesztése (admin)
     */
    public function updateCategory(int $id, string $name, string $description, bool $isClosed, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $name = htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(trim($description), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new InvalidInputException('A kategória neve 2-100 karakter között legyen!');
        }
        $this->db->executeStatement(
            "UPDATE forum_categories SET name = ?, description = ?, is_closed = ? WHERE id = ?",
            [$name, $description, $isClosed ? 1 : 0, $id]
        );
    }

    /**
     * Kategória törlése (admin) — cascading: topikok és posztok is törlődnek
     */
    public function deleteCategory(int $id, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $this->db->beginTransaction();
        try {
            // Posztok törlése a kategória összes topikjából
            $this->db->executeStatement(
                "DELETE fp FROM forum_posts fp JOIN forum_topics ft ON fp.topic_id = ft.id WHERE ft.category_id = ?",
                [$id]
            );
            // Topikok törlése
            $this->db->executeStatement("DELETE FROM forum_topics WHERE category_id = ?", [$id]);
            // Kategória törlése
            $this->db->executeStatement("DELETE FROM forum_categories WHERE id = ?", [$id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Topic szerkesztése (admin)
     */
    public function updateTopic(int $id, string $title, bool $isLocked, bool $isPinned, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $title = htmlspecialchars(trim($title), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($title) < ForumConfig::TOPIC_TITLE_MIN || mb_strlen($title) > ForumConfig::TOPIC_TITLE_MAX) {
            throw new InvalidInputException('A topic címe ' . ForumConfig::TOPIC_TITLE_MIN . '-' . ForumConfig::TOPIC_TITLE_MAX . ' karakter között legyen!');
        }
        $this->db->executeStatement(
            "UPDATE forum_topics SET title = ?, is_locked = ?, is_pinned = ? WHERE id = ?",
            [$title, $isLocked ? 1 : 0, $isPinned ? 1 : 0, $id]
        );
    }

    /**
     * Topic törlése (admin) — posztokkal együtt
     */
    public function deleteTopic(int $id, int $adminUserId): int
    {
        $this->ensureIsAdmin($adminUserId);
        $topic = $this->getTopic($id);
        if (!$topic) {
            throw new GameException('A topic nem létezik!');
        }

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("DELETE FROM forum_posts WHERE topic_id = ?", [$id]);
            $this->db->executeStatement("DELETE FROM forum_topics WHERE id = ?", [$id]);
            $this->db->commit();
            return (int)$topic['category_id'];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Hozzászólás szerkesztése (admin)
     */
    public function updatePost(int $id, string $content, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $content = htmlspecialchars(trim($content), ENT_QUOTES, 'UTF-8');
        if (mb_strlen($content) < ForumConfig::POST_CONTENT_MIN || mb_strlen($content) > ForumConfig::POST_CONTENT_MAX) {
            throw new InvalidInputException('A hozzászólás ' . ForumConfig::POST_CONTENT_MIN . '-' . ForumConfig::POST_CONTENT_MAX . ' karakter között legyen!');
        }
        $this->db->executeStatement(
            "UPDATE forum_posts SET content = ? WHERE id = ?",
            [$content, $id]
        );
    }

    /**
     * Hozzászólás törlése (admin) — post_count csökkentés
     */
    public function deletePost(int $id, int $adminUserId): void
    {
        $this->ensureIsAdmin($adminUserId);
        $post = $this->db->fetchAssociative(
            "SELECT id, topic_id FROM forum_posts WHERE id = ?", [$id]
        );
        if (!$post) {
            throw new GameException('A hozzászólás nem létezik!');
        }

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("DELETE FROM forum_posts WHERE id = ?", [$id]);
            $this->db->executeStatement(
                "UPDATE forum_topics SET post_count = GREATEST(post_count - 1, 0) WHERE id = ?",
                [$post['topic_id']]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
