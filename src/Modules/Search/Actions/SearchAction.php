<?php
declare(strict_types=1);

namespace Netmafia\Modules\Search\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Search\Domain\SearchService;
use Netmafia\Modules\Auth\Domain\AuthService;

class SearchAction
{
    private Twig $view;
    private SearchService $searchService;
    private AuthService $authService;

    public function __construct(Twig $view, SearchService $searchService, AuthService $authService)
    {
        $this->view = $view;
        $this->searchService = $searchService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $username = $queryParams['username'] ?? null;
        $status = $queryParams['status'] ?? 'alive';
        $isSearch = isset($queryParams['username']); // Did user click search?

        $results = [];
        $error = null;

        if ($isSearch) {
            try {
                $results = $this->searchService->searchUsers($username, $status);
            } catch (\Netmafia\Shared\Exceptions\InvalidInputException|\Netmafia\Shared\Exceptions\GameException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                error_log("Search Error: " . $e->getMessage());
                $error = 'Váratlan hiba történt a keresés során.';
            }
        }

        // Get Authenticated User (for Header Stats)
        $currentUserId = $request->getAttribute('user_id');
        $currentUser = $currentUserId ? $this->authService->getAuthenticatedUser((int)$currentUserId) : null;

        return $this->view->render($response, 'search/index.twig', [
            'user' => $currentUser,
            'results' => $results,
            'is_search' => $isSearch,
            'searched_username' => $username,
            'searched_status' => $status,
            'result_count' => count($results),
            'error' => $error ?? null
        ]);
    }
}
