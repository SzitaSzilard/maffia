<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Home\Domain\PropertyService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class PropertyPurchaseAction
{
    private PropertyService $propertyService;
    private AuthService $authService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(
        PropertyService $propertyService,
        AuthService $authService,
        SessionService $session
    ) {
        $this->propertyService = $propertyService;
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
        $propertyId = (int) ($data['property_id'] ?? 0);
        
        try {
            $this->propertyService->purchaseProperty($userId, $propertyId, $userCountry);
            
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('property_success', 'Gratulálunk! Az ingatlan megvásárlása sikeres volt!');
            
            return $response->withHeader('Location', '/otthon?tab=ingatlanok')->withStatus(302);
            
        } catch (\Throwable $e) {
            $this->session->flash('property_error', $e->getMessage());
            
            return $response->withHeader('Location', '/otthon?tab=ingatlanok')->withStatus(302);
        }
    }
}
