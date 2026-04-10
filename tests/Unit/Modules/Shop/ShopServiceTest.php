<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Shop;

use Netmafia\Modules\Shop\Domain\ShopService;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class ShopServiceTest extends TestCase
{
    private $db;
    private $inventoryService;
    private $moneyService;
    private ShopService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->inventoryService = $this->createMock(InventoryService::class);
        $this->moneyService = $this->createMock(MoneyService::class);
        $this->service = new ShopService($this->db, $this->inventoryService, $this->moneyService);
    }

    // --- Validáció ---

    public function testBuyItemWithZeroQuantityThrows(): void
    {
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Legalább 1 darabot');
        $this->service->buyItem(UserId::of(1), 10, 0);
    }

    public function testBuyItemWithNegativeQuantityThrows(): void
    {
        $this->expectException(GameException::class);
        $this->service->buyItem(UserId::of(1), 10, -5);
    }

    // --- Item nem található ---

    public function testBuyNonExistentItemThrows(): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('fetchAssociative')->willReturn(false);
        $this->db->method('rollBack');

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('nem található');
        $this->service->buyItem(UserId::of(1), 999, 1);
    }

    // --- Stock elfogyott ---

    public function testBuyItemOutOfStockThrows(): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('fetchAssociative')->willReturn([
            'id' => 10, 'name' => 'Kés', 'type' => 'weapon',
            'attack' => 5, 'defense' => 0, 'price' => 100,
            'stock' => 0, 'is_shop_item' => 1, 'stackable' => 0
        ]);
        $this->db->method('rollBack');

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Nincs elég készlet');
        $this->service->buyItem(UserId::of(1), 10, 1);
    }

    // --- Sikeres vásárlás ---

    public function testBuyItemSuccessDeductsMoneyAndStock(): void
    {
        $item = [
            'id' => 10, 'name' => 'Kés', 'type' => 'weapon',
            'attack' => 5, 'defense' => 0, 'price' => 100,
            'stock' => 5, 'is_shop_item' => 1, 'stackable' => 0
        ];

        $this->db->method('beginTransaction');
        $this->db->method('fetchAssociative')->willReturn($item);
        $this->db->expects($this->once())->method('commit');

        // MoneyService should be called with the total price
        $this->moneyService->expects($this->once())
            ->method('spendMoney')
            ->with(UserId::of(1), 200); // 2 x 100

        // Stock should decrease
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE items SET stock = stock - ?'),
                [2, 10]
            );

        // InventoryService should add item
        $this->inventoryService->expects($this->once())
            ->method('addItem')
            ->with(1, 10, 2);

        $this->service->buyItem(UserId::of(1), 10, 2);
    }

    // --- getShopData ---

    public function testGetShopDataReturnsAllCategories(): void
    {
        $this->db->method('fetchAllAssociative')->willReturn([]);
        $this->db->method('fetchOne')->willReturn(null);

        $data = $this->service->getShopData();
        $this->assertArrayHasKey('weapons', $data);
        $this->assertArrayHasKey('armors', $data);
        $this->assertArrayHasKey('consumables', $data);
        $this->assertArrayHasKey('jets', $data);
        $this->assertArrayHasKey('next_restock', $data);
    }
}
