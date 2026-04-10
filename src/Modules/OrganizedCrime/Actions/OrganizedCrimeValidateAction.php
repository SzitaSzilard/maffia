<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\CrimeRequirementsValidator;
use Netmafia\Modules\Auth\Domain\AuthService;

class OrganizedCrimeValidateAction
{
    private CrimeRequirementsValidator $validator;
    private AuthService $authService;

    public function __construct(
        CrimeRequirementsValidator $validator, 
        AuthService $authService
    ) {
        $this->validator = $validator;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = (int)$request->getAttribute('user_id');
        
        $data = $request->getParsedBody();
        // A HTMX mezők neve változó lehet (username_role vagy sima username)
        $targetUsername = '';
        $role = $data['role'] ?? '';
        
        foreach ($data as $key => $val) {
            if (str_starts_with($key, 'username')) {
                $targetUsername = trim($val);
                // Extract role if it was in the input name (e.g. username_gunman_1)
                if (empty($role) && $key !== 'username') {
                    $role = str_replace('username_', '', $key);
                }
                break;
            }
        }

        if (empty($targetUsername)) {
            $response->getBody()->write('');
            return $response->withHeader('Content-Type', 'text/html');
        }

        // Whitelist validáció
        if (!in_array($role, \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeConfig::VALID_ROLES, true)) {
            $role = 'hacker'; // fallback for just basic validation if no role matched
        }

        $user = $this->authService->getUserById($sessionUserId);
        if (strtolower($targetUsername) === strtolower($user['username'])) {
            $html = '<div style="color:#d32f2f; font-size:11px; margin-top:3px; font-weight:bold;">❌ Magadat nem hívhatod meg!</div>';
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html');
        }

        $validation = $this->validator->validateInvite($sessionUserId, $targetUsername, $role);
        
        if (!$validation['success']) {
            $html = '<div style="color:#d32f2f; font-size:11px; margin-top:3px; font-weight:bold;">❌ ' . htmlspecialchars($validation['error']) . '</div>';
        } else {
            $html = '<div style="color:#388e3c; font-size:11px; margin-top:3px; font-weight:bold;">✅ Alkalmas!</div>';
        }

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
