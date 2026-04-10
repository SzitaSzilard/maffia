<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCategoryToVehicles extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('vehicles');
        
        if (!$table->hasColumn('category')) {
            $table->addColumn('category', 'enum', [
                'values' => ['sport', 'suv', 'motor', 'sedan', 'other'],
                'default' => 'sedan',
                'null' => false,
                'after' => 'name'
            ])->update();
        }

        // Update some known vehicles for demo (optional, but good for testing)
        $this->execute("UPDATE vehicles SET category = 'sport' WHERE name LIKE '%Ferrari%' OR name LIKE '%Lamborghini%' OR name LIKE '%Porsche%' OR name LIKE '%BMW M%'");
        $this->execute("UPDATE vehicles SET category = 'suv' WHERE name LIKE '%Jeep%' OR name LIKE '%Range Rover%' OR name LIKE '%G-Class%'");
        $this->execute("UPDATE vehicles SET category = 'motor' WHERE name LIKE '%Ducati%' OR name LIKE '%Kawasaki%' OR name LIKE '%Harley%'");
    }
}
