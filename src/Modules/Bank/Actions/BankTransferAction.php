<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Actions;

use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BankTransferAction
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

        $data = $request->getParsedBody();
        $targetAccount = (int) ($data['target_account'] ?? 0);
        $rawAmount = trim((string)($data['amount'] ?? ''));

        if (str_starts_with($rawAmount, '0')) {
             $this->session->flash('error', "Az összeg nem kezdődhet 0-val!");
             return $response->withHeader('Location', '/bank')->withStatus(302);
        }

        $amount = (int) $rawAmount;
        $note = trim($data['note'] ?? '');

        try {
            $this->bankService->transfer(UserId::of((int)$userId), $targetAccount, $amount, $note);
            $this->session->flash('success', "Sikeres utalás!");
        } catch (\Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/bank')
            ->withStatus(302);
    }
}
