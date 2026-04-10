<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Actions;

use Netmafia\Modules\ECrime\Domain\ECrimeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Exceptions\GameException;

class ECrimeShopAction
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
        $data = (array)$request->getParsedBody();
        $action = $data['action'] ?? '';

        try {
            if ($action === 'buy_laptop') {
                $this->eCrimeService->buyLaptop($userId);
                $this->session->flash('success', 'Sikeresen megvásároltad a laptopot $30,000-ért! Most már nyitva áll az út az átverések felé.');
            } elseif ($action === 'buy_webserver') {
                $this->eCrimeService->rentWebserver($userId);
                $this->session->flash('success', 'Sikeresen kibéreltél egy webszervert 1 hónapra $20,000-ért! A 4-es szintű átverések immáron elérhetőek.');
            } elseif ($action === 'buy_peripherals') {
                $this->eCrimeService->buyPeripherals($userId);
                $this->session->flash('success', 'Sikeresen megvásároltad a profi perifériákat 4 kreditért! A vírusfejlesztés/terjesztés mostantól 30%-kal gyorsabb.');
            } else {
                throw new \InvalidArgumentException('Érvénytelen vásárlási művelet.');
            }
        } catch (GameException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->session->flash('error', 'Nincs elég kredit a vásárláshoz!');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Váratlan hiba történt a vásárlás során.');
        }

        // PRG pattern - visszairányítás a Tab url-re hogy a flash üzenet látszódjon
        return $response->withHeader('Location', '/e-bunozes/bolt')->withStatus(303);
    }
}
