
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

        $host = 'localhost';
        $dbname = "websocket_chat";
        $user = "root";
        $pass = "root";

        try {
            $this->pdo = new PDO("mysql:host=localhost;dbname=websocket_chat;charset=utf8", $user, $pass);
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
        $conn->send("Welcome! You are user {$conn->resourceId}");
    }
    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo "Received from {$from->resourceId}: $msg\n";

        try {
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (sender_id, message) VALUES (?, ?)");
            $stmt->execute([$from->resourceId, $msg]);
        } catch (PDOException $e) {
            echo "DB Insert Error: " . $e->getMessage() . "\n";
            $from->send("Error saving message.");
            return; 
        }

        foreach ($this->clients as $client) {
            if ($from != $client) {
                $client->send("User {$from->resourceId}: $msg");
            } else {
                $client->send("You: $msg");
            }
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
>>>>>>> d0b0318 (Add/update client.html and server.php)
?>
