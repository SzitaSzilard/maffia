<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Netmafia\Infrastructure\CacheService;

/**
 * CacheService Unit Tests
 */
class CacheServiceTest extends TestCase
{
    private CacheService $cache;

    protected function setUp(): void
    {
        $this->cache = new CacheService('array');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(CacheService::class, $this->cache);
    }

    public function testSetAndGetValue(): void
    {
        $this->cache->set('test_key', 'test_value', 60);
        $this->assertEquals('test_value', $this->cache->get('test_key'));
    }

    public function testGetReturnsNullForNonExistentKey(): void
    {
        $this->assertNull($this->cache->get('non_existent_key'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->cache->set('to_delete', 'value', 60);
        $this->cache->forget('to_delete');
        $this->assertNull($this->cache->get('to_delete'));
    }

    public function testRememberReturnsCachedValue(): void
    {
        $callCount = 0;
        
        $result1 = $this->cache->remember('remember_key', 60, function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $result2 = $this->cache->remember('remember_key', 60, function() use (&$callCount) {
            $callCount++;
            return 'should_not_be_called';
        });
        
        $this->assertEquals('computed_value', $result1);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    public function testRememberComputesValueOnMiss(): void
    {
        $result = $this->cache->remember('new_key', 60, function() {
            return 'freshly_computed';
        });
        
        $this->assertEquals('freshly_computed', $result);
        $this->assertEquals('freshly_computed', $this->cache->get('new_key'));
    }

    public function testFlushClearsAllKeys(): void
    {
        $this->cache->set('key1', 'value1', 60);
        $this->cache->set('key2', 'value2', 60);
        $this->cache->flush();
        
        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
    }

    public function testStatsReturnsDriverInfo(): void
    {
        $this->cache->set('stat_key', 'value', 60);
        $stats = $this->cache->stats();
        
        $this->assertEquals('array', $stats['driver']);
        $this->assertGreaterThanOrEqual(1, $stats['keys']);
    }

    public function testCanStoreComplexData(): void
    {
        $complexData = [
            'user' => ['id' => 1, 'name' => 'Test'],
            'items' => [1, 2, 3],
            'nested' => ['a' => ['b' => 'c']],
        ];
        
        $this->cache->set('complex', $complexData, 60);
        $this->assertEquals($complexData, $this->cache->get('complex'));
    }

    public function testCanStoreIntegers(): void
    {
        $this->cache->set('int_key', 42, 60);
        $this->assertSame(42, $this->cache->get('int_key'));
    }

    public function testCanStoreZero(): void
    {
        $this->cache->set('zero_key', 0, 60);
        $this->assertSame(0, $this->cache->get('zero_key'));
    }

    public function testForgetPatternRemovesMatchingKeys(): void
    {
        $this->cache->set('user:1:name', 'John', 60);
        $this->cache->set('user:1:email', 'john@test.com', 60);
        $this->cache->set('user:2:name', 'Jane', 60);
        $this->cache->set('other:key', 'value', 60);
        
        $this->cache->forgetPattern('user:1:*');
        
        $this->assertNull($this->cache->get('user:1:name'));
        $this->assertNull($this->cache->get('user:1:email'));
        $this->assertEquals('Jane', $this->cache->get('user:2:name'));
        $this->assertEquals('value', $this->cache->get('other:key'));
    }
}
