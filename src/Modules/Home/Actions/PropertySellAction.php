<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Home\Domain\PropertyService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class PropertySellAction
{
    private PropertyService $propertyService;
    private SleepService $sleepService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(PropertyService $propertyService, SleepService $sleepService, SessionService $session)
    {
        $this->propertyService = $propertyService;
        $this->sleepService = $sleepService;
        $this->session = $session;
    }
    
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = UserId::of((int) $request->getAttribute('user_id'));
        
        // Védelem: alvás közben nem lehet eladni
        if ($this->sleepService->isUserSleeping($userId)) {
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('property_error', 'Alvás közben nem adhatsz el ingatlant!');
            return $response->withHeader('Location', '/otthon?tab=ingatlanok')->withStatus(302);
        }
        
        $data = $request->getParsedBody();
        $userPropertyId = (int) ($data['user_property_id'] ?? 0);
        
        try {
            $this->propertyService->sellProperty($userId, $userPropertyId);
            
            $this->session->flash('property_sold', 'Az ingatlan eladása sikeres! Pénz jóváírva (60%).');
            
            return $response->withHeader('Location', '/otthon?tab=ingatlanok')->withStatus(302);
            
        } catch (\Throwable $e) {
            $this->session->flash('property_error', $e->getMessage());
            
            return $response->withHeader('Location', '/otthon?tab=ingatlanok')->withStatus(302);
        }
    }
}
