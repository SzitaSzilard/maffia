<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Domain;

use Doctrine\DBAL\Connection;

class NewsService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get latest news
     */
    public function getLatest(int $limit = 10): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, title, content, created_at, author_id FROM game_news ORDER BY created_at DESC LIMIT ?",
            [$limit],
            [\PDO::PARAM_INT]
        );
    }

    /**
     * Add new news post
     * Title is automatically set to current date (YYYY.MM.DD)
     */
    public function add(int $authorId, string $content, string $topic = 'Változások'): void
    {
        $this->db->insert('game_news', [
            'title' => $this->formatTitle($topic),
            'content' => $content,
            'author_id' => $authorId,
            'created_at' => gmdate('Y-m-d H:i:s')
        ]);
    }

    public function get(int $id): ?array
    {
        $result = $this->db->fetchAssociative("SELECT id, title, content, created_at, author_id FROM game_news WHERE id = ?", [$id]);
        return $result ?: null;
    }

    public function update(int $id, string $content, string $topic): void
    {
         $this->db->update('game_news', [
             'title' => $this->formatTitle($topic),
             'content' => $content
         ], ['id' => $id]);
    }

    private function formatTitle(string $topic): string
    {
        // Format: Téma - 2026-01-22 20:53
        return $topic . ' - ' . date('Y-m-d H:i');
    }

    public function delete(int $id): void
    {
        $this->db->delete('game_news', ['id' => $id]);
    }
}
