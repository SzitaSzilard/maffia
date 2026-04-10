<?php
declare(strict_types=1);

/**
 * NetMafia WebSocket Server
 * 
 * Indítás: php bin/server.php
 * 
 * Ez a szerver kezeli a real-time kommunikációt a játékban.
 * Az ARCHITECTURE.md-ben leírt Hibrid modellt követi:
 * - HTTP kérések normál módon futnak
 * - A szerver Redis-en keresztül kap értesítést az eseményekről
 * - WebSocket-en keresztül értesíti a klienseket
 */

require __DIR__ . '/../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

/**
 * NetMafiaWebSocket - Fő WebSocket kezelő osztály
 */
class NetMafiaWebSocket implements MessageComponentInterface
{
    /** @var \SplObjectStorage Kapcsolódott kliensek */
    protected \SplObjectStorage $clients;
    
    /** @var array<int, array> User ID -> Connection mapping */
    protected array $userConnections = [];
    
    /** @var array<string, \SplObjectStorage> Szobák (pl. kocsma, banda) */
    protected array $rooms = [];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        echo "WebSocket Server inicializálva.\n";
    }

    /**
     * Új kapcsolat kezelése
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        echo "Új kapcsolat: {$conn->resourceId}\n";
    }

    /**
     * Bejövő üzenet kezelése
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'auth':
                // Autentikáció - user ID hozzárendelése a kapcsolathoz
                $this->handleAuth($from, $data);
                break;
                
            case 'join_room':
                // Szobához csatlakozás
                $this->handleJoinRoom($from, $data);
                break;
                
            case 'leave_room':
                // Szoba elhagyása
                $this->handleLeaveRoom($from, $data);
                break;
                
            case 'message':
                // Üzenet küldése szobába
                $this->handleMessage($from, $data);
                break;
                
            case 'ping':
                // Életben tartás
                $from->send(json_encode(['type' => 'pong']));
                break;
        }
    }

    /**
     * Kapcsolat bezárása
     */
    public function onClose(ConnectionInterface $conn): void
    {
        // Eltávolítás minden szobából
        foreach ($this->rooms as $roomName => $clients) {
            if ($clients->contains($conn)) {
                $clients->detach($conn);
            }
        }
        
        // Eltávolítás a user mappingből
        foreach ($this->userConnections as $userId => $connections) {
            $key = array_search($conn, $connections, true);
            if ($key !== false) {
                unset($this->userConnections[$userId][$key]);
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                }
            }
        }
        
        $this->clients->detach($conn);
        echo "Kapcsolat lezárva: {$conn->resourceId}\n";
    }

    /**
     * Hiba kezelése
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "Hiba: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Autentikáció kezelése
     */
    protected function handleAuth(ConnectionInterface $conn, array $data): void
    {
        if (!isset($data['user_id']) || !isset($data['token'])) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Hiányzó autentikációs adatok'
            ]));
            return;
        }

        $userId = (int) $data['user_id'];
        
        // TODO: Token validáció az adatbázisból
        // Jelenleg egyszerű regisztráció
        
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][] = $conn;
        
        // Connection tulajdonság hozzáadása
        $conn->userId = $userId;
        
        $conn->send(json_encode([
            'type' => 'auth_success',
            'user_id' => $userId
        ]));
        
        echo "User autentikálva: {$userId} (conn: {$conn->resourceId})\n";
    }

    /**
     * Szobához csatlakozás
     */
    protected function handleJoinRoom(ConnectionInterface $conn, array $data): void
    {
        if (!isset($data['room'])) {
            return;
        }
        
        $roomName = $data['room'];
        
        if (!isset($this->rooms[$roomName])) {
            $this->rooms[$roomName] = new \SplObjectStorage;
        }
        
        $this->rooms[$roomName]->attach($conn);
        
        $conn->send(json_encode([
            'type' => 'room_joined',
            'room' => $roomName
        ]));
        
        echo "Conn {$conn->resourceId} csatlakozott: {$roomName}\n";
    }

    /**
     * Szoba elhagyása
     */
    protected function handleLeaveRoom(ConnectionInterface $conn, array $data): void
    {
        if (!isset($data['room'])) {
            return;
        }
        
        $roomName = $data['room'];
        
        if (isset($this->rooms[$roomName])) {
            $this->rooms[$roomName]->detach($conn);
        }
        
        $conn->send(json_encode([
            'type' => 'room_left',
            'room' => $roomName
        ]));
    }

    /**
     * Üzenet küldése szobába
     */
    protected function handleMessage(ConnectionInterface $from, array $data): void
    {
        if (!isset($data['room']) || !isset($data['content'])) {
            return;
        }
        
        $roomName = $data['room'];
        
        if (!isset($this->rooms[$roomName])) {
            return;
        }
        
        $message = json_encode([
            'type' => 'message',
            'room' => $roomName,
            'user_id' => $from->userId ?? null,
            'content' => $data['content'],
            'timestamp' => time()
        ]);
        
        // Broadcast minden szobatársnak
        foreach ($this->rooms[$roomName] as $client) {
            $client->send($message);
        }
    }

    /**
     * Üzenet küldése egy adott usernek
     */
    public function sendToUser(int $userId, array $data): void
    {
        if (!isset($this->userConnections[$userId])) {
            return;
        }
        
        $message = json_encode($data);
        
        foreach ($this->userConnections[$userId] as $conn) {
            $conn->send($message);
        }
    }

    /**
     * Broadcast az összes kliensnek
     */
    public function broadcast(array $data): void
    {
        $message = json_encode($data);
        
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    /**
     * Broadcast egy szoba összes tagjának
     */
    public function broadcastToRoom(string $roomName, array $data): void
    {
        if (!isset($this->rooms[$roomName])) {
            return;
        }
        
        $message = json_encode($data);
        
        foreach ($this->rooms[$roomName] as $client) {
            $client->send($message);
        }
    }
}

// Szerver indítása
$port = (int) ($_ENV['WS_PORT'] ?? 8080);

echo "NetMafia WebSocket Server indítása a {$port} porton...\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new NetMafiaWebSocket()
        )
    ),
    $port
);

echo "Szerver fut! Várakozás kapcsolatokra...\n";

$server->run();
