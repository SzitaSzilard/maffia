<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Modules\Postal\PostalConfig;
use Netmafia\Modules\Postal\Domain\PostalService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostalAddToCartAction
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
        $userId = (int)$user['id'];
        $data = $request->getParsedBody() ?? [];

        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 3) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
            $this->session->flash('postal_error', "A posta használatához szükséges minimum rang: {$requiredRankName}");
            return $response->withHeader('Location', '/posta')->withStatus(303);
        }

        $category = $data['category'] ?? '';
        $itemId = isset($data['item_id']) ? (int)$data['item_id'] : null;
        $requestedQuantity = max(1, (int)($data['quantity'] ?? 1));

        // Kategória validálás
        if (!array_key_exists($category, PostalConfig::ACTIVE_CATEGORIES)) {
            $this->session->flash('postal_error', 'Érvénytelen kategória!');
            return $response->withHeader('Location', '/posta')->withStatus(303);
        }

        try {
            // SZERVEROLDALI ELLENŐRZÉS ÉS ADATFELKÜLÉS (Csalás elleni védelem)
            // Itt NEm fogadjuk el a kliens által küldött nevet/árat, hanem kiszedjük a DB-ből!
            $verifiedData = $this->postalService->verifyItemFromDb($userId, $category, $itemId, $requestedQuantity);
            
            $itemName = $verifiedData['name'];
            $unitPrice = $verifiedData['unit_price'];
            $maxQuantity = $verifiedData['max_quantity'];
            
            // Kosár lekérdezése
            $cart = $this->session->get('postal_cart', []);

            // Max tétel check
            if (count($cart) >= PostalConfig::MAX_ITEMS_PER_PACKAGE) {
                throw new \Netmafia\Shared\Exceptions\GameException('Maximum ' . PostalConfig::MAX_ITEMS_PER_PACKAGE . ' tétel küldhető egy csomagban!');
            }

            // Még egy biztonsági ellenőrzés: ha már van a kosárban ilyen tétel, akkor a mennyiségek
            // ÖSSZEGE sem haladhatja meg a valós, adatbázisban lévő mennyiséget!
            $alreadyInCartQty = 0;
            $existingIndex = null;
            
            foreach ($cart as $index => $existing) {
                if ($existing['category'] === $category && $existing['item_id'] === $itemId) {
                    $alreadyInCartQty += $existing['quantity'];
                    $existingIndex = $index;
                }
            }

            $totalQuantity = $requestedQuantity + $alreadyInCartQty;

            if ($totalQuantity > $maxQuantity) {
                throw new \Netmafia\Shared\Exceptions\GameException("Nincs ennyi ebből a tételből! (Kosárban: {$alreadyInCartQty}, Kért: {$requestedQuantity}, Maximum: {$maxQuantity})");
            }

            // Ha már benne van, csak növeljük a mennyiséget
            if ($existingIndex !== null) {
                $cart[$existingIndex]['quantity'] = $totalQuantity;
                $this->session->flash('postal_success', "{$itemName} mennyisége frissítve a csomagban!");
            } else {
                // Új tétel felvétele
                $cart[] = [
                    'category' => $category,
                    'item_id' => $itemId,
                    'name' => $itemName, // MEGBÍZHATÓ NÉV
                    'quantity' => $requestedQuantity,
                    'unit_price' => $unitPrice, // MEGBÍZHATÓ ÁR
                ];
                $this->session->flash('postal_success', "{$itemName} hozzáadva a csomaghoz!");
            }

            $this->session->set('postal_cart', $cart);

        } catch (\Netmafia\Shared\Exceptions\GameException | \Netmafia\Shared\Exceptions\InvalidInputException $e) {
            $this->session->flash('postal_error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->session->flash('postal_error', 'Hiba történt a tétel ellenőrzésekor: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/posta')->withStatus(303);
    }
}
