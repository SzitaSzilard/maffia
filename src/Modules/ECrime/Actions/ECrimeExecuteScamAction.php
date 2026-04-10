<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Actions;

use Netmafia\Modules\ECrime\Domain\ECrimeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Infrastructure\SessionService;

class ECrimeExecuteScamAction
{
    private ECrimeService $eCrimeService;
    private SessionService $session;

    public function __construct(ECrimeService $eCrimeService, SessionService $session)
    {
        $this->eCrimeService = $eCrimeService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $scamTypeId = isset($data['scam_type']) ? (int)$data['scam_type'] : 0;

        if ($scamTypeId === 0) {
            $this->session->flash('error', 'Kérlek válassz ki egy módszert a listából!');
            return $response->withHeader('Location', '/e-bunozes')->withStatus(303);
        }

        try {
            $result = $this->eCrimeService->executeScam($userId, $scamTypeId);
            
            // Ahelyett, hogy renderelnék egyből (ami nem PRG), eltárolom Flash üzenetben az eredményt, 
            // így az IndexAction fogja megjeleníteni a frissülő fülön egy redirect után.
            // Ez betartja a 5.2 POST-Redirect-GET mintát.
            $this->session->flash('ecrime_result', json_encode($result));
            
        } catch (\DomainException $e) {
            // Energiahiány vagy Cooldown hiba
            $this->session->flash('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log("ECrimeExecuteAction Exception: " . $e->getMessage());
            $this->session->flash('error', 'Hiba történt a művelet közben!');
        }

        return $response->withHeader('Location', '/e-bunozes')->withStatus(303);
    }
}
