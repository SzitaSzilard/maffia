<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Modules\Postal\Domain\PostalService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostalSendAction
{
    private PostalService $postalService;
    private SessionService $session;

    public function __construct(PostalService $postalService, SessionService $session)
    {
        $this->postalService = $postalService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];

        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 3) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
            $this->session->flash('postal_error', "A posta használatához szükséges minimum rang: {$requiredRankName}");
            return $response->withHeader('Location', '/posta')->withStatus(303);
        }

        $recipientUsername = trim($data['recipient'] ?? '');
        $cart = $this->session->get('postal_cart', []);

        if (empty($recipientUsername)) {
            $this->session->flash('postal_error', 'Add meg a címzett felhasználónevét!');
            return $response->withHeader('Location', '/posta')->withStatus(303);
        }

        if (empty($cart)) {
            $this->session->flash('postal_error', 'A csomag üres! Adj hozzá tételeket!');
            return $response->withHeader('Location', '/posta')->withStatus(303);
        }

        try {
            $this->postalService->sendPackage((int)$user['id'], $recipientUsername, $cart);

            // Sikeres küldés — kosár törlése
            $this->session->set('postal_cart', []);
            $this->session->flash('postal_success', "Csomag sikeresen elküldve {$recipientUsername} részére!");

        } catch (InvalidInputException $e) {
            $this->session->flash('postal_error', $e->getMessage());
        } catch (GameException $e) {
            $this->session->flash('postal_error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->session->flash('postal_error', 'Hiba történt a küldés során: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/posta')->withStatus(303);
    }
}
