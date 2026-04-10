<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPettyCrimeColumns extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('users');
        $table->addColumn('petty_crime_scan_cooldown_until', 'datetime', ['null' => true, 'default' => null])
              ->addColumn('petty_crime_commit_cooldown_until', 'datetime', ['null' => true, 'default' => null])
              ->addColumn('petty_crime_attempts', 'integer', ['null' => false, 'default' => 0, 'signed' => false])
              ->addIndex(['petty_crime_scan_cooldown_until'])
              ->addIndex(['petty_crime_commit_cooldown_until'])
              ->update();
    }
}
