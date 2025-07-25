<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

require __DIR__ . '/vendor/autoload.php';

class chat implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected \PDO $pdo;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;

        $host = '127.0.0.1';
        $dbname = "websocket_chat";
        $user = "root";
        $pass = "";

        try {
            $this->pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=$dbname;charset=utf8", $user, $pass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "Database connected.\n";
        } catch (PDOException $e) {
            die("DB connection failed: " . $e->getMessage());
        }
        echo "Chat server started..\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New Connection! ({$conn->resourceId})\n";
        flush();
        $welcome = [
            'sender_id' => 'System',
            'message' => "Welcome! You are user {$conn->resourceId}",
            'file_type' => null,
            'file_url' => null
        ];
        $conn->send(json_encode($welcome));
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo "Received from {$from->resourceId}: $msg\n";

        $data = json_decode($msg, true);
        $messageText = $data["message"] ?? '';
        $fileType = $data['file_type'] ?? '';
        $fileUrl = $data['file_url'] ?? '';

        $imagePath = null;
        $filePath = null;

        if ($fileType == 'image') {
            $imagePath = $fileUrl;
        } else if ($fileType == 'pdf') {
            $filePath = $fileUrl;
        }

        try {
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (sender_id, message, image_path, file_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$from->resourceId, $messageText, $imagePath, $filePath]);
        } catch (PDOException $e) {
            echo "DB Insert Error: " . $e->getMessage() . "\n";
            $from->send("Error saving message.");
            return;
        }

        foreach ($this->clients as $client) {
            $payload = [
                'sender_id' => ($from === $client) ? 'You' : "User {$from->resourceId}",
                'message' => $messageText,
                'file_type' => $fileType,
                'file_url' => $fileUrl
            ];
            $client->send(json_encode($payload));
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new chat()
        )
    ),
    9000
);

echo "Websocket server is running on port 9000...\n";
$server->run();
?>
