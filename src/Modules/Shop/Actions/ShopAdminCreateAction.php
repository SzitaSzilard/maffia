<?php
declare(strict_types=1);

namespace Netmafia\Modules\Shop\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Shop\Domain\ShopService;
use Slim\Views\Twig;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Shop\ShopConfig;

class ShopAdminCreateAction
{
    private ShopService $shopService;
    private AuthService $authService;
    private Twig $twig;

    public function __construct(ShopService $shopService, AuthService $authService, Twig $twig)
    {
        $this->shopService = $shopService;
        $this->authService = $authService;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        
        // Admin check
        if (!$this->authService->isAdmin($user['id'])) {
            return $response->withHeader('Location', '/vasarlas')->withStatus(302);
        }

        $isAjax = $request->hasHeader('HX-Request');

        if ($request->getMethod() === 'GET') {
            return $this->twig->render($response, 'game/shop/admin.twig', ['user' => $user, 'is_ajax' => $isAjax]);
        }

        $data = $request->getParsedBody() ?? [];
        
        try {
            // Handle Next Restock Date Update
            if (isset($data['action']) && $data['action'] === 'update_restock') {
                $restockDate = $data['next_restock'] ?? '';
                if ($restockDate) {
                    $this->shopService->setNextRestockDate($restockDate);
                    $viewData = ['user' => $user, 'is_ajax' => $isAjax, 'success' => 'Következő készletfeltöltés dátuma frissítve!'];
                } else {
                    $viewData = ['user' => $user, 'is_ajax' => $isAjax, 'error' => 'Érvénytelen dátum!'];
                }
                return $this->twig->render($response, 'game/shop/admin.twig', $viewData);
            }

            // Handle Item Creation
            $itemData = [
                'name' => $data['name'] ?? '',
                'image_url' => null,
                'type' => $data['type'] ?? 'misc',
                'price' => (int)($data['price'] ?? 0),
                'stock' => (int)($data['stock'] ?? 0),
                'attack' => (int)($data['attack'] ?? 0),
                'defense' => (int)($data['defense'] ?? 0),
                'effects' => []
            ];

            // Számok validálása (Biztonsági javítás: túlcsordulás és negatív értékek ellen)
            if ($itemData['price'] < 0 || $itemData['price'] > 1000000000) {
                throw new \RuntimeException('Az árnak 0 és 1.000.000.000 között kell lennie!');
            }
            if ($itemData['stock'] < -1 || $itemData['stock'] > 1000000) {
                throw new \RuntimeException('Érvénytelen készlet érték!');
            }
            if ($itemData['attack'] < 0 || $itemData['attack'] > 1000000) {
                throw new \RuntimeException('A támadásérték maximum 1.000.000 lehet és nem lehet negatív!');
            }
            if ($itemData['defense'] < 0 || $itemData['defense'] > 1000000) {
                throw new \RuntimeException('A védelemérték maximum 1.000.000 lehet és nem lehet negatív!');
            }

            // Whitelist validáció (§10.1)
            if (!in_array($itemData['type'], ShopConfig::VALID_TYPES, true)) {
                throw new \RuntimeException('Érvénytelen tárgy típus!');
            }

            // Handle file upload
            $uploadedFiles = $request->getUploadedFiles();
            if (isset($uploadedFiles['image_file']) && $uploadedFiles['image_file']->getError() === UPLOAD_ERR_OK) {
                $uploadedFile = $uploadedFiles['image_file'];
                
                // [SECURITY] Fájlméret limit (2MB)
                if ($uploadedFile->getSize() > 2 * 1024 * 1024) {
                    throw new \RuntimeException('A fájl mérete maximum 2MB lehet!');
                }
                
                // [SECURITY] Extension whitelist — CSAK képformátumok
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $originalName = $uploadedFile->getClientFilename();
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                
                if (!in_array($extension, $allowedExtensions, true)) {
                    throw new \RuntimeException('Csak képfájlok engedélyezettek (jpg, png, gif, webp)!');
                }
                
                // [SECURITY] Dupla kiterjesztés védelem (pl. shell.php.jpg)
                $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                if (preg_match('/\.(php|phtml|phar|sh|exe|bat|cmd|cgi|pl|py|rb|js|asp|jsp)/i', $nameWithoutExt)) {
                    throw new \RuntimeException('Érvénytelen fájlnév!');
                }
                
                // [SECURITY] MIME type ellenőrzés
                $stream = $uploadedFile->getStream();
                $tempPath = tempnam(sys_get_temp_dir(), 'upload_');
                file_put_contents($tempPath, $stream);
                
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($tempPath);
                unlink($tempPath);
                
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    throw new \RuntimeException('A fájl tartalma nem érvényes képformátum!');
                }
                
                $basename = bin2hex(random_bytes(8));
                $filename = sprintf('%s.%s', $basename, $extension);

                // Define upload directory
                $directory = __DIR__ . '/../../../../public/images/items';
                
                // Create directory if it doesn't exist
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
                $itemData['image_url'] = '/images/items/' . $filename;
            }

            // If it's a consumable with a buff effect
            if ($itemData['type'] === 'consumable' && !empty($data['effect_type'])) {
                $effectValue = (int)($data['effect_value'] ?? 0);
                $effectDuration = (int)($data['effect_duration'] ?? 0);
                
                if ($effectValue < -1000000 || $effectValue > 1000000) {
                    throw new \RuntimeException('Érvénytelen effektus érték!');
                }
                if ($effectDuration < 0 || $effectDuration > 43200) { // Max 30 nap (percekben)
                    throw new \RuntimeException('Az effektus időtartama nem lehet negatív, és maximum 30 nap (43200 perc) lehet!');
                }

                $itemData['effects'][] = [
                    'type' => $data['effect_type'],
                    'value' => $effectValue,
                    'duration' => $effectDuration,
                    'context' => 'combat' // default context for now
                ];
            }
            
            // If it's a jet, save the cooldown reduction in defense or attack? 
            // Wait, we need to store the "Utazás utáni várakozási idő (perc)" somewhere.
            // Let's store it in `defense` for Jets, or make an effect.
            // Let's just use the `defense` column as the travel time in minutes for jets.
            if ($itemData['type'] === 'jet' && isset($data['travel_time'])) {
                $travelTime = (int)$data['travel_time'];
                if ($travelTime < 0 || $travelTime > 10000) {
                    throw new \RuntimeException('Érvénytelen utazási idő!');
                }
                $itemData['defense'] = $travelTime;
                $itemData['attack'] = 0;
            }

            $this->shopService->createShopItem($itemData);
            
            $viewData = ['user' => $user, 'is_ajax' => $isAjax, 'success' => 'Tárgy sikeresen feltöltve!'];
            return $this->twig->render($response, 'game/shop/admin.twig', $viewData);
            
        } catch (\Throwable $e) {
            $viewData = ['user' => $user, 'is_ajax' => $isAjax, 'error' => $e->getMessage()];
            return $this->twig->render($response, 'game/shop/admin.twig', $viewData);
        }
    }
}
