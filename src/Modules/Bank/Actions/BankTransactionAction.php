<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BankTransactionAction
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
        $type = $data['type'] ?? ''; // 'deposit' or 'withdraw'
        $rawAmount = trim((string)($data['amount'] ?? ''));
        
        if (str_starts_with($rawAmount, '0')) {
             // redirect immediately with error if starts with 0
             $this->session->flash('error', "Az összeg nem kezdődhet 0-val!");
             return $response->withHeader('Location', '/bank')->withStatus(302);
        }

        $amount = (int) $rawAmount;

        try {
            if ($amount <= 0) {
                throw new InvalidInputException("Csak pozitív összeget adhatsz meg!");
            }

            if ($type === 'deposit') {
                $this->bankService->deposit(UserId::of((int)$userId), $amount);
                $this->session->flash('success', "Sikeres befizetés: \$$amount");
            } elseif ($type === 'withdraw') {
                $this->bankService->withdraw(UserId::of((int)$userId), $amount);
                $this->session->flash('success', "Sikeres kivét: \$$amount");
            } else {
                throw new InvalidInputException("Érvénytelen művelet.");
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/bank')
            ->withStatus(302);
    }
}
