<?php
declare(strict_types=1);

namespace Netmafia\Modules\Profile\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Profile\Domain\ProfileService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Slim\Exception\HttpNotFoundException;

class ProfileAction
{
    private Twig $view;
    private ProfileService $profileService;
    private AuthService $authService;

    public function __construct(Twig $view, ProfileService $profileService, AuthService $authService)
    {
        $this->view = $view;
        $this->profileService = $profileService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // 1. Determine Target Identifier (ID or Username)
        // A route paraméter nevét 'id'-ról 'identifier'-re változtatjuk a routes.php-ben, 
        // vagy megtartjuk 'id'-nak, de kezeljük stringként is. 
        // A routes.php-t is módosítani fogom, hogy {identifier} legyen.
        $identifier = $args['identifier'] ?? $args['id'] ?? null;

        // If no ID provided, maybe show own profile? 
        if (!$identifier) {
             $identifier = (int)($request->getAttribute('user_id') ?? 0);
        }

        // 2. Fetch Profile Data
        $profileUser = $this->profileService->getUserProfile($identifier);

        if (!$profileUser) {
            // User not found
            throw new HttpNotFoundException($request, "Felhasználó nem található.");
        }

        // 3. Get Authenticated User (for Header Stats)
        $currentUserId = $request->getAttribute('user_id');
        $currentUser = $currentUserId ? $this->authService->getAuthenticatedUser((int)$currentUserId) : null;

        // 4. Render
        return $this->view->render($response, 'profile/view.twig', [
            'user' => $currentUser,   // For header
            'profile' => $profileUser // The user being viewed
        ]);
    }
}
