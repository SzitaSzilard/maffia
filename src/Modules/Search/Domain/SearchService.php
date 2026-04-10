<?php
declare(strict_types=1);

namespace Netmafia\Modules\Search\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\RankCalculator;

class SearchService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function searchUsers(?string $username, string $status = 'alive'): array
    {
        // 1. Validation (Fat Service Logic)
        if ($username !== null) {
            $username = trim($username);
            // Strict check: Must be at least 3 chars. 
            // Empty string (length 0) gets caught here too.
            if (strlen($username) < 3) {
                throw new InvalidInputException('Legalább 3 karaktert írj be a kereséshez!');
            }
        }

        $qb = $this->db->createQueryBuilder();
        $qb->select('u.*')
           ->from('users', 'u')
           ->orderBy('u.username', 'ASC');

        // Username filter (partial match)
        if (!empty($username)) {
            $qb->andWhere('u.username LIKE :username')
               ->setParameter('username', '%' . $username . '%');
        }

        // Status filter
        if ($status === 'alive') {
            $qb->andWhere('u.health > 0');
        } elseif ($status === 'dead') {
            $qb->andWhere('u.health <= 0');
        }
        // If 'all', no filter needed
        
        // [2025-12-29 14:47:47] Teljesítmény védelem: Max 100 találat
        // Nagy keresések (pl. 'a' betű) esetén limitáljuk az eredményeket
        $qb->setMaxResults(100);

        $users = $qb->fetchAllAssociative();

        // Enrich data
        foreach ($users as &$user) {
            $user['rank_name'] = RankCalculator::getRank((int)$user['xp']);
            $user['gang_name'] = '-'; // Placeholder until Gang module
        }

        return $users;
    }
}
