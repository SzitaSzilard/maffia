<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Netmafia\Infrastructure\RateLimiter;
use Netmafia\Infrastructure\CacheService;

/**
 * RateLimiter Unit Tests
 */
class RateLimiterTest extends TestCase
{
    private RateLimiter $limiter;
    private CacheService $cache;

    protected function setUp(): void
    {
        $this->cache = new CacheService('array');
        $this->limiter = new RateLimiter($this->cache);
    }

    public function testFirstAttemptIsAllowed(): void
    {
        $result = $this->limiter->attempt('test:key', 5, 60);
        
        $this->assertTrue($result['allowed']);
        $this->assertEquals(4, $result['remaining']);
        $this->assertEquals(0, $result['retryAfter']);
    }

    public function testMultipleAttemptsDecrementRemaining(): void
    {
        $this->limiter->attempt('test:key', 5, 60);
        $this->limiter->attempt('test:key', 5, 60);
        $result = $this->limiter->attempt('test:key', 5, 60);
        
        $this->assertTrue($result['allowed']);
        $this->assertEquals(2, $result['remaining']);
    }

    public function testBlockedAfterMaxAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test:key', 5, 60);
        }
        
        $result = $this->limiter->attempt('test:key', 5, 60);
        
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertGreaterThan(0, $result['retryAfter']);
    }

    public function testResetClearsAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test:key', 5, 60);
        }
        
        $this->limiter->reset('test:key');
        
        $result = $this->limiter->attempt('test:key', 5, 60);
        $this->assertTrue($result['allowed']);
        $this->assertEquals(4, $result['remaining']);
    }

    public function testRemainingMethod(): void
    {
        $this->assertEquals(5, $this->limiter->remaining('new:key', 5));
        
        $this->limiter->attempt('new:key', 5, 60);
        $this->limiter->attempt('new:key', 5, 60);
        
        $this->assertEquals(3, $this->limiter->remaining('new:key', 5));
    }

    public function testTooManyAttemptsMethod(): void
    {
        $this->assertFalse($this->limiter->tooManyAttempts('test:key', 5));
        
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test:key', 5, 60);
        }
        
        $this->assertTrue($this->limiter->tooManyAttempts('test:key', 5));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('user:1', 5, 60);
        }
        
        // user:1 blocked
        $this->assertTrue($this->limiter->tooManyAttempts('user:1', 5));
        
        // user:2 not blocked
        $this->assertFalse($this->limiter->tooManyAttempts('user:2', 5));
        $this->assertEquals(5, $this->limiter->remaining('user:2', 5));
    }
}
