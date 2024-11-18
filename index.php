<?php
require_once __DIR__ . '/vendor/autoload.php';

use LucianoTonet\GroqPHP\Groq;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize Groq client
$groq = new Groq($_ENV['GROQ_API_KEY']);

session_start();
if (!isset($_SESSION['chatHistory'])) {
    $_SESSION['chatHistory'] = [];
}

$botResponse = '';
$imageAnalysis = '';

$availableModels = [
    'llama3-8b-8192' => 'LLaMA 3 8B',
    'mixtral-8x7b-32768' => 'Mixtral 8x7B',
    'gemma-7b-it' => 'Gemma 7B-IT',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['message'])) {
        $userMessage = $_POST['message'];
        $selectedModel = $_POST['model'];
        $_SESSION['chatHistory'][] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = $groq->chat()->completions()->create([
                'model' => $selectedModel,
                'messages' => $_SESSION['chatHistory'],
                'stream' => true,
            ]);

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            $fullResponse = '';
            foreach ($response->chunks() as $chunk) {
                if (isset($chunk['choices'][0]['delta']['content'])) {
                    $content = $chunk['choices'][0]['delta']['content'];
                    $fullResponse .= $content;
                    echo "data: " . json_encode(['content' => $content]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }

            $_SESSION['chatHistory'][] = ['role' => 'assistant', 'content' => $fullResponse];
            echo "data: [DONE]\n\n";
            exit;
        } catch (Exception $e) {
            echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            exit;
        }
    } elseif (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $imagePath = $_FILES['image']['tmp_name'];
        $prompt = $_POST['image_prompt'];

        try {
            $analysis = $groq->vision()->analyze($imagePath, $prompt);
            $imageAnalysis = $analysis['choices'][0]['message']['content'];
        } catch (Exception $e) {
            $imageAnalysis = "Error analyzing image: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Groq PHP Chatbot</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f0f0f0; }
        .container { background-color: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; }
        #chat-container { border: 1px solid #ccc; height: 400px; overflow-y: scroll; padding: 10px; margin-bottom: 20px; background-color: #fff; }
        #user-input { width: 70%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
        #send-button { width: 25%; padding: 10px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        #model-select { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .image-upload { margin-top: 20px; }
        .image-analysis { margin-top: 20px; border: 1px solid #ccc; padding: 10px; background-color: #fff; }
        .tabs { display: flex; justify-content: center; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; background-color: #ddd; border: none; border-radius: 5px 5px 0 0; }
        .tab.active { background-color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .user-message { background-color: #e6f3ff; padding: 5px 10px; border-radius: 10px; margin: 5px 0; }
        .bot-message { background-color: #f0f0f0; padding: 5px 10px; border-radius: 10px; margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Enhanced Groq PHP Chatbot</h1>
        <div class="tabs">
            <button class="tab active" onclick="openTab(event, 'chatTab')">Chatbot</button>
            <button class="tab" onclick="openTab(event, 'imageTab')">Image Analysis</button>
        </div>

        <div id="chatTab" class="tab-content active">
            <div id="chat-container">
                <?php foreach ($_SESSION['chatHistory'] as $message): ?>
                    <div class="<?= $message['role'] === 'user' ? 'user-message' : 'bot-message' ?>">
                        <strong><?= $message['role'] === 'user' ? 'You' : 'Bot' ?>:</strong> <?= htmlspecialchars($message['content']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <form id="chat-form" onsubmit="sendMessage(event)">
                <select id="model-select" name="model">
                    <?php foreach ($availableModels as $modelId => $modelName): ?>
                        <option value="<?= $modelId ?>"><?= $modelName ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="user-input" name="message" placeholder="Type your message here..." required>
                <input type="submit" id="send-button" value="Send">
            </form>
        </div>

        <div id="imageTab" class="tab-content">
            <form method="post" action="" enctype="multipart/form-data">
                <input type="file" name="image" accept="image/*" required>
                <input type="text" name="image_prompt" placeholder="Enter prompt for image analysis" required>
                <input type="submit" value="Analyze Image">
            </form>
            <?php if (!empty($imageAnalysis)): ?>
                <div class="image-analysis">
                    <h3>Image Analysis Result:</h3>
                    <p><?= htmlspecialchars($imageAnalysis) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function openTab(evt, tabName) {
            var i, tabContent, tabLinks;
            tabContent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabContent.length; i++) {
                tabContent[i].style.display = "none";
            }
            tabLinks = document.getElementsByClassName("tab");
            for (i = 0; i < tabLinks.length; i++) {
                tabLinks[i].className = tabLinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }

        function sendMessage(event) {
            event.preventDefault();
            var form = document.getElementById('chat-form');
            var formData = new FormData(form);

            var userMessage = formData.get('message');
            appendMessage('You', userMessage, 'user-message');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                function readChunk() {
                    return reader.read().then(({ done, value }) => {
                        if (done) {
                            return;
                        }
                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop();
                        for (const line of lines) {
                            if (line.startsWith('data: ')) {
                                const data = JSON.parse(line.slice(6));
                                if (data.content) {
                                    appendMessage('Bot', data.content, 'bot-message', true);
                                } else if (data.error) {
                                    appendMessage('Error', data.error, 'bot-message');
                                }
                            }
                        }
                        return readChunk();
                    });
                }

                return readChunk();
            })
            .catch(error => {
                console.error('Error:', error);
                appendMessage('Error', 'An error occurred while sending the message.', 'bot-message');
            });

            form.reset();
        }

        function appendMessage(sender, content, className, append = false) {
            var chatContainer = document.getElementById('chat-container');
            var messageElement;
            
            if (append && chatContainer.lastElementChild && chatContainer.lastElementChild.classList.contains('bot-message')) {
                messageElement = chatContainer.lastElementChild;
                messageElement.innerHTML += content;
            } else {
                messageElement = document.createElement('div');
                messageElement.className = className;
                messageElement.innerHTML = `<strong>${sender}:</strong> ${content}`;
                chatContainer.appendChild(messageElement);
            }
            
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        // Scroll to bottom of chat container
        var chatContainer = document.getElementById('chat-container');
        chatContainer.scrollTop = chatContainer.scrollHeight;
    </script>
</body>
</html>