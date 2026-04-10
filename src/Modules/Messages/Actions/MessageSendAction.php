<?php
declare(strict_types=1);

namespace Netmafia\Modules\Messages\Actions;

use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MessageSendAction - Üzenet küldése
 */
class MessageSendAction
{
    private MessageService $messageService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(MessageService $messageService, SessionService $session)
    {
        $this->messageService = $messageService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $recipientName = trim($data['recipient'] ?? '');
        $subject = trim($data['subject'] ?? '');
        $body = trim($data['body'] ?? '');

        // Validáció
        $errors = [];
        if (empty($recipientName)) {
            $errors[] = 'Címzett megadása kötelező!';
        }
        if (empty($subject)) {
            $errors[] = 'Tárgy megadása kötelező!';
        } elseif (mb_strlen($subject) > 255) {
            $errors[] = 'A tárgy maximum 255 karakter lehet!';
        }
        if (empty($body)) {
            $errors[] = 'Üzenet szövege kötelező!';
        } elseif (mb_strlen($body) > 10000) {
            $errors[] = 'Az üzenet maximum 10000 karakter lehet!';
        }

        // Címzett keresése
        $recipient = null;
        if (empty($errors)) {
            $recipient = $this->messageService->findUserByUsername($recipientName);
            if (!$recipient) {
                $errors[] = 'A címzett felhasználó nem található!';
            }
            if ($recipient && $recipient['id'] === (int)$userId) {
                $errors[] = 'Magadnak nem küldhetsz üzenetet!';
            }
        }

        // Spam védelem
        if (empty($errors)) {
            try {
                $this->messageService->validateSpam((int)$userId);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Hibák esetén visszairányítás
        if (!empty($errors)) {
            // [2026-02-28] FIX: SessionService set() használata tömb adatokhoz
            $this->session->set('message_errors', $errors);
            $this->session->set('message_form', [
                'recipient' => $recipientName,
                'subject' => $subject,
                'body' => $body,
            ]);
            return $response
                ->withHeader('Location', '/uzenetek/send')
                ->withHeader('HX-Redirect', '/uzenetek/send')
                ->withStatus(302);
        }

        // Üzenet küldése
        $success = $this->messageService->sendMessage(
            (int)$userId,
            $recipient['id'],
            $subject,
            $body
        );

        if ($success) {
            $this->session->flash('message_success', 'Üzenet sikeresen elküldve!');
        } else {
            $this->session->set('message_errors', ['Hiba történt az üzenet küldése közben!']);
        }

        return $response
            ->withHeader('Location', '/uzenetek/outbox')
            ->withHeader('HX-Redirect', '/uzenetek/outbox')
            ->withStatus(302);
    }
}
