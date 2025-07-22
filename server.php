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

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        echo "Chat server started...\n";
    }
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New Connection! ({$conn->resourceId})\n";
    }
    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo "Received from {$from->resourceId}: $msg\n";
        foreach ($this->clients as $client) {
            if ($from != $client) {
                $client->send("User {$from->resourcedId}: $msg");
            } else {
                $client->send("You: $msg");
            }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconneced\n";
    }
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occured: {$e->getMessage()}\n";
        $conn->close();
    }
}
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new chat()
        )
    ),
    8090
);
echo "Websocket server is running on port 8090...\n";
$server->run();
?>