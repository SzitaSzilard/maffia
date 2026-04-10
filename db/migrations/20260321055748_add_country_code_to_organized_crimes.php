<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCountryCodeToOrganizedCrimes extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('organized_crimes');
        $table->addColumn('country_code', 'string', ['limit' => 2, 'default' => 'HU', 'after' => 'crime_type'])
              ->update();
    }

    public function down(): void
    {
        $table = $this->table('organized_crimes');
        $table->removeColumn('country_code')
              ->update();
    }
}
