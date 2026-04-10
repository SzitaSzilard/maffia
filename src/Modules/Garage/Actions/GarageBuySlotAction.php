<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Infrastructure\AuditLogger;

class GarageBuySlotAction
{
    private GarageService $garageService;
    private AuthService $authService;
    private AuditLogger $logger;
    private \Netmafia\Infrastructure\SessionService $sessionService;

    public function __construct(GarageService $garageService, AuthService $authService, AuditLogger $logger, \Netmafia\Infrastructure\SessionService $sessionService)
    {
        $this->garageService = $garageService;
        $this->authService = $authService;
        $this->logger = $logger;
        $this->sessionService = $sessionService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $isHtmx = $request->hasHeader('HX-Request');
        
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            $this->logger->log('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageBuySlotAction']);
            return $this->redirect($response, '/login', $isHtmx);
        }

        $data = $request->getParsedBody();
        
        if (!isset($data['slots'])) {
            $this->logger->log('garage_buy_invalid_input', (int)$userId, ['error' => 'slots_parameter_missing']);
            return $this->errorResponse($response, $isHtmx, 'Nincs slots paraméter!');
        }

        $rawSlots = $data['slots'];
        if (!is_numeric($rawSlots)) {
             $this->logger->log('garage_buy_invalid_input', (int)$userId, ['error' => 'slots_value_is_not_numeric', 'value' => $rawSlots]);
             return $this->errorResponse($response, $isHtmx, 'Érvénytelen érték!');
        }

        $slots = (int) $rawSlots;

        if ($slots === 0) {
            $this->logger->log('garage_buy_invalid_input', (int)$userId, ['error' => 'slots_value_is_0']);
            return $this->errorResponse($response, $isHtmx, '0 slotot nem vehetsz!');
        }
        
        // Validate package - use Config
        $allowedPackages = \Netmafia\Modules\Garage\GarageConfig::EXPANSION_PACKAGES;

        if (!in_array($slots, $allowedPackages)) {
            $this->logger->log('garage_buy_invalid_input', (int)$userId, ['error' => 'invalid_package_slots', 'value' => $slots]);
             return $this->errorResponse($response, $isHtmx, 'Érvénytelen csomag!');
        }
        
        $price = $slots * \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT;
        
        try {
            // Get user to get country code
            // Optimization: Get country from session if available or just pass userId to service and let service handle it?
            // Service needs country code. Auth service getAuthenticatedUser returns array.
            
            $user = $this->authService->getAuthenticatedUser((int)$userId);
            if (!$user) {
                throw new GameException("User not found");
            }
            
            $this->garageService->buyGarageSlots((int)$userId, $user['country_code'], $slots, $price);
            
            // On Success: Redirect to List
            // With HTMX, we probably want to refresh the "expand" page to show it as purchased
            // Or redirect back to /garazs if that's the desired flow.
            // The template form targets #game-content.
            // Let's redirect to /garazs/bovites to show updated state (button disabled)
             return $this->redirect($response, '/garazs/bovites', $isHtmx, ['type' => 'success', 'message' => 'Sikeres vásárlás!']);
            
        } catch (\Throwable $e) {
            $this->logger->log('garage_buy_action_error', (int)$userId, ['exception' => $e->getMessage()]);
            // On Error: Stay here (No redirect), just trigger notification
            return $this->errorResponse($response, $isHtmx, $e->getMessage());
        }
    }
    
    private function errorResponse(Response $response, bool $isHtmx, string $message): Response
    {
         if ($isHtmx) {
             return $response
                ->withHeader('HX-Trigger', json_encode([
                    'notification' => ['type' => 'error', 'message' => $message]
                ]))
                ->withHeader('HX-Reswap', 'none') // Don't replace content, just show notification
                ->withStatus(200);
         }
         
         // Fallback
         $this->sessionService->flash('error', $message);
         return $response->withHeader('Location', '/garazs/bovites')->withStatus(302);
    }

    private function redirect(Response $response, string $url, bool $isHtmx, ?array $notification = null): Response
    {
        if ($isHtmx) {
            // Use HX-Location for SPA-style navigation
            $response = $response->withHeader('HX-Location', json_encode([
                'path' => $url, 
                'target' => '#game-content'
            ]));
            
            if ($notification) {
                $response = $response->withHeader('HX-Trigger', json_encode([
                    'notification' => $notification
                ]));
            }
            return $response->withStatus(200);
        }
        
        if ($notification) {
            $this->sessionService->flash($notification['type'], $notification['message']);
        }
        
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
