<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class SleepWakeAction
{
    private SleepService $sleepService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(SleepService $sleepService, SessionService $session)
    {
        $this->sleepService = $sleepService;
        $this->session = $session;
    }
    
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = UserId::of((int) $request->getAttribute('user_id'));
        
        try {
            $result = $this->sleepService->wakeUp($userId);
            
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('sleep_success', sprintf(
                'Felkeltél! %.1f órát aludtál. +%d%% élet, +%d%% energia.',
                $result['hours_slept'],
                $result['health_gained'],
                $result['energy_gained']
            ));
            
        } catch (\Throwable $e) {
            $this->session->flash('sleep_error', $e->getMessage());
        }
        
        return $response->withHeader('Location', '/otthon?tab=otthon')->withStatus(302);
    }
}
