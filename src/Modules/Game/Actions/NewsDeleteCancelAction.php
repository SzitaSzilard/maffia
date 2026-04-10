<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NewsDeleteCancelAction
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$user['is_admin']) {
            return $response->withStatus(403);
        }

        $id = (int)$args['id'];
        
        // Restore original button structure
        // Need to match what's in news_list.twig for consistency
        $html = sprintf(
            '<a href="#" class="text-danger text-decoration-none ms-2" style="font-size: 11px;"
               hx-get="/game/news/%d/delete-confirm" 
               hx-target="this" 
               hx-swap="outerHTML">
               [ Törlés ]
            </a>',
            $id
        );

        $response->getBody()->write($html);
        return $response;
    }
}
