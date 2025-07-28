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
        if (!$data) {
            return;
        }

        // Handle mark_read request
        if (isset($data['type']) && $data['type'] === 'mark_read') {
            $readerId = $from->resourceId;
            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET status = 'read' WHERE status = 'unread' AND sender_id != ?");
                $stmt->execute([$readerId]);
                $stmt = $this->pdo->query("SELECT id FROM chat_messages WHERE status = 'read' AND sender_id != $readerId");
                $messageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($this->clients as $client) {
                    foreach ($messageIds as $msgId) {
                        $client->send(json_encode([
                            'type' => 'mark_read',
                            'message_id' => $msgId,
                            'status' => 'read'
                        ]));
                    }
                }

                echo "Marked messages as read for User {$readerId}\n";
            } catch (PDOException $e) {
                echo "Read status update error: " . $e->getMessage() . "\n";
            }
            return;
        }

        // Check if this is a text edit request
        if (isset($data['type']) && $data['type'] === 'edit') {
            $messageId = $data['message_id'] ?? null;
            $newText = $data['new_text'] ?? null;

            if ($messageId === null) {
                $from->send(json_encode(['error' => 'Missing message_id for edit']));
                return;
            }
            try {
                // Update the existing message text in DB
                $stmt = $this->pdo->prepare('UPDATE chat_messages SET message = ?, image_path = NULL, file_path = NULL WHERE id = ?');
                $stmt->execute([$newText, $messageId]);

                // Get current status
                $stmt = $this->pdo->prepare('SELECT status FROM chat_messages WHERE id = ?');
                $stmt->execute([$messageId]);
                $status = $stmt->fetchColumn() ?: 'unread';

            } catch (PDOException $e) {
                echo 'DB Update Error: ' . $e->getMessage() . "\n";
                $from->send(json_encode(["error" => "Error updating message"]));
                return;
            }
            // Broadcast the edited message to all clients
            foreach ($this->clients as $client) {
                $payload = [
                    'type' => 'edit',
                    'message_id' => $messageId,
                    'new_text' => $newText,
                    'sender_id' => ($from === $client) ? 'You' : "User {$from->resourceId}",
                    'status' => $status
                ];
                $client->send(json_encode($payload));
            }
            return;
        }

        // Check if this is an image edit request
        if (isset($data['type']) && $data['type'] === 'edit_image') {
            $messageId = $data['message_id'] ?? null;
            $newImageUrl = $data['new_image_url'] ?? null;

            if ($messageId === null || $newImageUrl === null) {
                $from->send(json_encode(['error' => 'Missing parameters for image edit']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET image_path = ?, file_path = NULL, message = NULL WHERE id = ?");
                $stmt->execute([$newImageUrl, $messageId]);

                // Get current status
                $stmt = $this->pdo->prepare('SELECT status FROM chat_messages WHERE id = ?');
                $stmt->execute([$messageId]);
                $status = $stmt->fetchColumn() ?: 'unread';

            } catch (PDOException $e) {
                echo 'DB Image Update Error: ' . $e->getMessage() . "\n";
                $from->send(json_encode(["error" => "Error updating image"]));
                return;
            }

            foreach ($this->clients as $client) {
                $payload = [
                    'type' => 'edit_image',
                    'message_id' => $messageId,
                    'new_image_url' => $newImageUrl,
                    'sender_id' => ($from === $client) ? 'You' : "User {$from->resourceId}",
                    'status' => $status
                ];
                $client->send(json_encode($payload));
            }
            return;
        }

        // Check if this is a PDF edit request (new addition)
        if (isset($data['type']) && $data['type'] === 'edit_pdf') {
            $messageId = $data['message_id'] ?? null;
            $newPdfUrl = $data['new_pdf_url'] ?? null;

            if ($messageId === null || $newPdfUrl === null) {
                $from->send(json_encode(['error' => 'Missing parameters for PDF edit']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET file_path = ?, image_path = NULL, message = NULL WHERE id = ?");
                $stmt->execute([$newPdfUrl, $messageId]);

                // Get current status
                $stmt = $this->pdo->prepare('SELECT status FROM chat_messages WHERE id = ?');
                $stmt->execute([$messageId]);
                $status = $stmt->fetchColumn() ?: 'unread';

            } catch (PDOException $e) {
                echo 'DB PDF Update Error: ' . $e->getMessage() . "\n";
                $from->send(json_encode(["error" => "Error updating PDF"]));
                return;
            }

            foreach ($this->clients as $client) {
                $payload = [
                    'type' => 'edit_pdf',
                    'message_id' => $messageId,
                    'new_pdf_url' => $newPdfUrl,
                    'sender_id' => ($from === $client) ? 'You' : "User {$from->resourceId}",
                    'status' => $status
                ];
                $client->send(json_encode($payload));
            }
            return;
        }

        // Handle new message insert
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
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (sender_id, message, image_path, file_path, status) VALUES (?, ?, ?, ?, 'unread')");
            $stmt->execute([$from->resourceId, $messageText, $imagePath, $filePath]);
            $lastInsertId = $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            echo "DB Insert Error: " . $e->getMessage() . "\n";
            $from->send("Error saving message.");
            return;
        }
        // Broadcast new message to all clients
        foreach ($this->clients as $client) {
            $payload = [
                'type' => 'new',
                'message_id' => $lastInsertId,
                'sender_id' => ($from === $client) ? 'You' : "User {$from->resourceId}",
                'message' => $messageText,
                'file_type' => $fileType,
                'file_url' => $fileUrl,
                'status' => 'unread'
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
