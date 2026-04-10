<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Actions;

use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BankOpenAction
{
    private BankService $bankService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(BankService $bankService, SessionService $session)
    {
        $this->bankService = $bankService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        try {
            $accountNumber = $this->bankService->openAccount(UserId::of((int)$userId));
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('bank_success', "Sikeres számlanyitás! Számlaszámod: $accountNumber");
        } catch (\Throwable $e) {
            $this->session->flash('bank_error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/bank')
            ->withStatus(302);
    }
}
