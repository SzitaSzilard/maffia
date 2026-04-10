<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class MarketSellItemSelectAction
{
    private Twig $view;
    private MarketService $marketService;

    public function __construct(Twig $view, MarketService $marketService)
    {
        $this->view = $view;
        $this->marketService = $marketService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        $data = $request->getParsedBody();
        $category = $data['category'] ?? '';
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : null;

        try {
            // A verifikáció során lekérdezzük, hogy mennyi van neki ebből
            $itemDetails = $this->marketService->verifyItemFromDb((int)$user['id'], $category, $itemId, 1);
            
            return $this->view->render($response, 'market/partials/sell_configure.twig', [
                'category' => $category,
                'itemId' => $itemId,
                'itemName' => $itemDetails['name'],
                'maxQuantity' => $itemDetails['max_quantity']
            ]);

        } catch (GameException | InvalidInputException $e) {
            $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response;
        } catch (\Throwable $e) {
            $response->getBody()->write('<div class="alert-error">Váratlan hiba történt. Kérlek próbáld újra.</div>');
            return $response;
        }
    }
}
