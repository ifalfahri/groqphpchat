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
$uploadedImage = '';

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
        $imageType = $_FILES['image']['type'];
        $prompt = $_POST['image_prompt'];

        // Create a data URL for the uploaded image
        $imageData = base64_encode(file_get_contents($imagePath));
        $uploadedImage = "data:$imageType;base64,$imageData";

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
    <title>Groq PHP Chatbot</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Groq PHP Chatbot</h1>
        <div class="tabs">
            <button class="tab" onclick="openTab(event, 'chatTab')">Chatbot</button>
            <button class="tab" onclick="openTab(event, 'imageTab')">Image Analysis</button>
        </div>

        <div id="chatTab" class="tab-content">
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
            <form id="image-form" method="post" action="" enctype="multipart/form-data">
                <div class="file-input-wrapper">
                    <button class="btn">Choose an image</button>
                    <input type="file" name="image" accept="image/*" required onchange="previewImage(event)">
                </div>
                <input type="text" id="image-prompt" name="image_prompt" placeholder="Enter prompt for image analysis" required>
                <input type="submit" class="submit-button" value="Analyze Image">
            </form>
            <img id="image-preview" src="" alt="Image preview" style="display: none;">
            <?php if (!empty($imageAnalysis)): ?>
                <div class="image-analysis">
                    <h3>Image Analysis Result:</h3>
                    <p><?= htmlspecialchars($imageAnalysis) ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($uploadedImage)): ?>
                <img src="<?= $uploadedImage ?>" alt="Uploaded image" style="max-width: 100%; margin-top: 10px;">
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

        function previewImage(event) {
            var reader = new FileReader();
            reader.onload = function(){
                var output = document.getElementById('image-preview');
                output.src = reader.result;
                output.style.display = 'block';
            };
            reader.readAsDataURL(event.target.files[0]);
        }

        // Set the active tab based on the last action
        window.onload = function() {
            var activeTab = '<?php echo !empty($_POST['image_prompt']) ? "imageTab" : "chatTab"; ?>';
            openTab({ currentTarget: document.querySelector(`.tab[onclick*="${activeTab}"]`) }, activeTab);
        }

        // Prevent form submission from changing the active tab
        document.getElementById('image-form').addEventListener('submit', function(e) {
            e.preventDefault();
            fetch('', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(response => response.text())
            .then(html => {
                document.open();
                document.write(html);
                document.close();
                openTab({ currentTarget: document.querySelector('.tab[onclick*="imageTab"]') }, 'imageTab');
            });
        });

        // Scroll to bottom of chat container
        var chatContainer = document.getElementById('chat-container');
        chatContainer.scrollTop = chatContainer.scrollHeight;
    </script>
</body>
</html>