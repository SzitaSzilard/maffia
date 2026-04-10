<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NewsDeleteConfirmAction
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$user['is_admin']) {
            return $response->withStatus(403);
        }

        $id = (int)$args['id'];
        
        // Return inline HTML for confirmation
        // "Biztos? [Igen] [Nem]"
        $html = sprintf(
            '<div class="delete-container d-inline-block ms-2" style="font-size: 11px;">
                <span class="text-danger fw-bold me-1">Biztos?</span>
                <button class="btn btn-sm btn-danger p-0 px-1" style="font-size: 10px;" 
                        hx-post="/game/news/%d/delete" 
                        hx-target="#news-container"
                        hx-swap="outerHTML">Igen</button>
                <button class="btn btn-sm btn-secondary p-0 px-1" style="font-size: 10px;" 
                        hx-get="/game/news/%d/delete-cancel" 
                        hx-target="closest .delete-container" 
                        hx-swap="outerHTML">Nem</button>
             </div>',
            $id, $id
        );

        $response->getBody()->write($html);
        return $response;
    }
}
