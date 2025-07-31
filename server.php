<?php
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

require __DIR__ . '/vendor/autoload.php';

class Chat implements MessageComponentInterface
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

        $conn->send(json_encode([
            'sender_id' => 'System',
            'message' => "Welcome! You are user {$conn->resourceId}",
            'file_type' => null,
            'file_url' => null
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        echo "Received from {$from->resourceId}: $msg\n";
        $data = json_decode($msg, true);
        if (!$data)
            return;

        // MARK AS READ
        if ($data['type'] === 'mark_read') {
            $messageId = $data['message_id'] ?? null;

            if (!$messageId) {
                $from->send(json_encode(['error' => 'No message_id provided for mark_read']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET status = 'read' WHERE id = ?");
                $stmt->execute([$messageId]);

                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'type' => 'mark_read',
                        'message_id' => $messageId,
                        'status' => 'read'
                    ]));
                }

                echo "Marked message {$messageId} as read\n";
            } catch (PDOException $e) {
                echo "Read status update error: " . $e->getMessage() . "\n";
            }
            return;
        }

        // TEXT EDIT
        if ($data['type'] === 'edit') {
            $messageId = $data['message_id'] ?? null;
            $newText = $data['new_text'] ?? null;

            if (!$messageId || !$newText) {
                $from->send(json_encode(['error' => 'Missing message_id or new_text']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET message = ?, image_path = NULL, file_path = NULL WHERE id = ?");
                $stmt->execute([$newText, $messageId]);

                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'type' => 'edit',
                        'message_id' => $messageId,
                        'new_text' => $newText,
                        'sender_id' => ($client === $from) ? 'You' : "User {$from->resourceId}",
                        'status' => 'unread'
                    ]));
                }
            } catch (PDOException $e) {
                echo 'DB Update Error: ' . $e->getMessage() . "\n";
            }
            return;
        }

        // IMAGE EDIT
        if ($data['type'] === 'edit_image') {
            $messageId = $data['message_id'] ?? null;
            $newImageUrl = $data['new_image_url'] ?? null;

            if (!$messageId || !$newImageUrl) {
                $from->send(json_encode(['error' => 'Missing parameters for image edit']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET image_path = ?, file_path = NULL, message = NULL WHERE id = ?");
                $stmt->execute([$newImageUrl, $messageId]);

                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'type' => 'edit_image',
                        'message_id' => $messageId,
                        'new_image_url' => $newImageUrl,
                        'sender_id' => ($client === $from) ? 'You' : "User {$from->resourceId}",
                        'status' => 'unread'
                    ]));
                }
            } catch (PDOException $e) {
                echo 'DB Image Update Error: ' . $e->getMessage() . "\n";
            }
            return;
        }

        // PDF EDIT
        if ($data['type'] === 'edit_pdf') {
            $messageId = $data['message_id'] ?? null;
            $newPdfUrl = $data['new_pdf_url'] ?? null;

            if (!$messageId || !$newPdfUrl) {
                $from->send(json_encode(['error' => 'Missing parameters for PDF edit']));
                return;
            }

            try {
                $stmt = $this->pdo->prepare("UPDATE chat_messages SET file_path = ?, image_path = NULL, message = NULL WHERE id = ?");
                $stmt->execute([$newPdfUrl, $messageId]);

                foreach ($this->clients as $client) {
                    $client->send(json_encode([
                        'type' => 'edit_pdf',
                        'message_id' => $messageId,
                        'new_pdf_url' => $newPdfUrl,
                        'sender_id' => ($client === $from) ? 'You' : "User {$from->resourceId}",
                        'status' => 'unread'
                    ]));
                }
            } catch (PDOException $e) {
                echo 'DB PDF Update Error: ' . $e->getMessage() . "\n";
            }
            return;
        }

        // NEW MESSAGE
        $text = $data['message'] ?? '';
        $fileType = $data['file_type'] ?? '';
        $fileUrl = $data['file_url'] ?? '';
        $imagePath = null;
        $filePath = null;

        if ($fileType === 'image')
            $imagePath = $fileUrl;
        if ($fileType === 'pdf')
            $filePath = $fileUrl;

        try {
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (sender_id, message, image_path, file_path, status) VALUES (?, ?, ?, ?, 'unread')");
            $stmt->execute([$from->resourceId, $text, $imagePath, $filePath]);
            $messageId = $this->pdo->lastInsertId();

            foreach ($this->clients as $client) {
                if ($client === $from) {
                    $client->send(json_encode([
                        'type' => 'new',
                        'message_id' => $messageId,
                        'sender_id' => 'You',
                        'message' => $text,
                        'file_type' => $fileType,
                        'file_url' => $fileUrl,
                        'status' => 'unread'
                    ]));
                } else {
                    $client->send(json_encode([
                        'type' => 'notification',
                        'message_id' => $messageId,
                        'sender_id' => "User {$from->resourceId}",
                        'message' => $text,
                        'file_type' => $fileType,
                        'file_url' => $fileUrl,
                        'status' => 'unread'
                    ]));
                }
            }
        } catch (PDOException $e) {
            echo "DB Insert Error: " . $e->getMessage() . "\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "User {$conn->resourceId} disconnected\n";
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat()
        )
    ),
    9000
);

echo "WebSocket server running on port 9000...\n";
$server->run();
?>
