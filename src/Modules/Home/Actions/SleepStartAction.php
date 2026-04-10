<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class SleepStartAction
{
    private SleepService $sleepService;
    private AuthService $authService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(SleepService $sleepService, AuthService $authService, SessionService $session)
    {
        $this->sleepService = $sleepService;
        $this->authService = $authService;
        $this->session = $session;
    }
    
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = UserId::of((int) $request->getAttribute('user_id'));
        // [2026-02-28] FIX: User adatok AuthService-ből, nem $_SESSION-ből
        $user = $this->authService->getAuthenticatedUser($userId->value());
        $userCountry = $user['country_code'] ?? 'US';
        
        $data = $request->getParsedBody();
        $hours = (int)($data['hours'] ?? 1);
        
        try {
            $this->sleepService->startSleep($userId, $hours, $userCountry);
            
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('sleep_success', 'Elaludtál! Jó pihenést!');
            
        } catch (\Throwable $e) {
            $this->session->flash('sleep_error', $e->getMessage());
        }
        
        return $response->withHeader('Location', '/otthon?tab=otthon')->withStatus(302);
    }
}
