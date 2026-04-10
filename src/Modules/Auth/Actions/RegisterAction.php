<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth\Actions;

use Netmafia\Infrastructure\RateLimiter;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class RegisterAction
{
    private AuthService $authService;
    private RateLimiter $rateLimiter;
    private SessionService $session;
    private Twig $view;

    public function __construct(
        AuthService $authService,
        RateLimiter $rateLimiter,
        SessionService $session,
        Twig $view
    ) {
        $this->authService = $authService;
        $this->rateLimiter = $rateLimiter;
        $this->session = $session;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $countries = $this->authService->getStartingCountries();

        if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $username = $data['username'] ?? '';
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $passwordConfirm = $data['password_confirm'] ?? '';
            $countryCode = $data['country_code'] ?? '';

            // PRG Helper — izolált _reg_msg kulcs, FlashMiddleware soha nem látja
            $redirectWithError = function (string $errorMsg) use ($response, $username, $email) {
                $this->session->set('_reg_msg', ['type' => 'error', 'message' => $errorMsg]);
                if ($username) {
                    $this->session->set('_reg_last_username', $username);
                }
                if ($email) {
                    $this->session->set('_reg_last_email', $email);
                }
                return $response->withHeader('Location', '/register')->withStatus(303);
            };

            // Rate limiting - IP alapján
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
            $rateLimitKey = "register:{$ip}";
            
            $rateLimit = $this->rateLimiter->attempt(
                $rateLimitKey,
                \Netmafia\Modules\Auth\AuthConfig::MAX_REGISTER_ATTEMPTS,
                \Netmafia\Modules\Auth\AuthConfig::LOCKOUT_MINUTES * 60
            );
            
            if (!$rateLimit['allowed']) {
                return $redirectWithError(sprintf(
                    'Túl sok regisztrációs kísérlet. Próbáld újra %d perc múlva.',
                    ceil($rateLimit['retryAfter'] / 60)
                ));
            }

            if (empty($countryCode)) {
                return $redirectWithError('Válassz országot!');
            }

            // Basic validation
            if (strlen($username) < \Netmafia\Modules\Auth\AuthConfig::USERNAME_MIN_LENGTH || strlen($username) > \Netmafia\Modules\Auth\AuthConfig::USERNAME_MAX_LENGTH) {
                return $redirectWithError(sprintf('A felhasználónév %d-%d karakter hosszú legyen!', \Netmafia\Modules\Auth\AuthConfig::USERNAME_MIN_LENGTH, \Netmafia\Modules\Auth\AuthConfig::USERNAME_MAX_LENGTH));
            }

            if (strlen($password) < \Netmafia\Modules\Auth\AuthConfig::PASSWORD_MIN_LENGTH) {
                return $redirectWithError(sprintf('A jelszó minimum %d karakter legyen!', \Netmafia\Modules\Auth\AuthConfig::PASSWORD_MIN_LENGTH));
            }

            // [FIX #7] Jelszó megerősítés
            if ($password !== $passwordConfirm) {
                return $redirectWithError('A két jelszó nem egyezik!');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $redirectWithError('Érvénytelen email cím!');
            }

            // [FIX #6] Exception-alapú hibakezelés — a `register()` exception-t dob, nem false-t
            // [FIX #3] IP paraméterként átadva
            
            try {
                $this->authService->register($username, $email, $password, $countryCode, $ip);
                $this->rateLimiter->reset($rateLimitKey);
                
                $this->session->flash('success', 'Sikeres regisztráció! Jelentkezz be.');
                return $response->withHeader('Location', '/login')->withStatus(303);
            } catch (InvalidInputException $e) {
                return $redirectWithError($e->getMessage());
            }
        }

        // GET — izolált _reg_msg kulcsból olvassuk, FlashMiddleware nem érinti
        $regMsg       = $this->session->get('_reg_msg');
        $lastUsername = $this->session->get('_reg_last_username');
        $lastEmail    = $this->session->get('_reg_last_email');
        $this->session->remove('_reg_msg');
        $this->session->remove('_reg_last_username');
        $this->session->remove('_reg_last_email');

        return $this->view->render($response, 'auth/register.twig', [
            'countries'     => $countries,
            'reg_msg'       => $regMsg,
            'last_username' => $lastUsername,
            'last_email'    => $lastEmail,
        ]);
    }
}
