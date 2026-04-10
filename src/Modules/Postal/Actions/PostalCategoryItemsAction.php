<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Modules\Postal\PostalConfig;
use Netmafia\Modules\Postal\Domain\PostalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PostalCategoryItemsAction
{
    private PostalService $postalService;
    private Twig $view;

    public function __construct(PostalService $postalService, Twig $view)
    {
        $this->postalService = $postalService;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $category = $args['category'] ?? '';

        // Ellenőrzés: aktív kategória-e
        if (!array_key_exists($category, PostalConfig::ACTIVE_CATEGORIES)) {
            $response->getBody()->write('<div class="error-box">Érvénytelen kategória!</div>');
            return $response;
        }

        $items = $this->postalService->getAvailableItems((int)$user['id'], $category);
        $categoryName = PostalConfig::ACTIVE_CATEGORIES[$category];

        return $this->view->render($response, 'postal/partials/category_items.twig', [
            'user' => $user,
            'category' => $category,
            'category_name' => $categoryName,
            'items' => $items,
        ]);
    }
}
