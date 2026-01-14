<?php
$telegramToken = '0000'; // <-- Add your bot token here
$apiUrl = 'https://example.com/api.php'; // <-- Add your API endpoint here
$userData = [];
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update || !isset($update['message'])) {
    error_log("No valid update received");
    exit;
}

$chatId = $update['message']['chat']['id'];
$messageText = $update['message']['text'] ?? '';
$photo = $update['message']['photo'] ?? null;
$caption = $update['message']['caption'] ?? '';
error_log("Chat ID: $chatId, Text: $messageText, Has photo: " . (!empty($photo)));

if ($messageText === '/start') {
    sendMessage($chatId, "🎨 *Welcome to the Image Editing Bot!*\n\nSend me an image and description either together in one message or step by step.");
    exit;
}

if ($photo && !empty($caption)) {
    sendMessage($chatId, "⏳ *Processing your image...*");
    processImageWithDescription($chatId, $photo, $caption);
    exit;
}

if ($photo && empty($caption)) {
    $fileId = end($photo)['file_id'];
    $userData[$chatId] = ['file_id' => $fileId];
    sendMessage($chatId, "📸 *Image received!*\n\nNow send the description for editing:");
    exit;
}

if (!empty($messageText) && isset($userData[$chatId]['file_id'])) {
    $fileId = $userData[$chatId]['file_id'];
    sendMessage($chatId, "⏳ *Processing your image...*");
    processImageWithDescription($chatId, [['file_id' => $fileId]], $messageText);
    unset($userData[$chatId]);
    exit;
}

if (!empty($messageText) && !isset($userData[$chatId]['file_id'])) {
    sendMessage($chatId, "📸 *Please send an image first*, then provide the description.");
    exit;
}

function processImageWithDescription($chatId, $photo, $description) {
    global $telegramToken, $apiUrl;
    
    $largestPhoto = end($photo);
    $fileId = $largestPhoto['file_id'];
    
    $filePath = getFile($fileId);
    if (!$filePath) {
        sendMessage($chatId, "❌ *Unable to retrieve image*");
        return;
    }

    $imageUrl = "https://api.telegram.org/file/bot{$telegramToken}/{$filePath}";
    $imageContent = @file_get_contents($imageUrl);
    
    if (!$imageContent) {
        sendMessage($chatId, "❌ *Failed to download the image*");
        return;
    }
    
    $imageData = base64_encode($imageContent);

    $apiResponse = callGeminiAPI($imageData, $description);
    
    error_log("API Response for chat $chatId: " . json_encode($apiResponse));
    
    if (isset($apiResponse['error'])) {
        sendMessage($chatId, "❌ *Error:* " . $apiResponse['error']);
        return;
    }

    if (!empty($apiResponse['text'])) {
        sendMessage($chatId, "📝 *Result:* " . $apiResponse['text']);
    }
 
    if (isset($apiResponse['image']['data'])) {
        $imageData = $apiResponse['image']['data'];
        $mimeType = $apiResponse['image']['mime_type'] ?? 'image/jpeg';
        
        $sendResult = sendPhoto($chatId, $imageData, $mimeType);
        
        if (!$sendResult) {
            sendMessage($chatId, "⚠️ *Failed to send edited image*");
        }
    } else if (empty($apiResponse['text'])) { 
        sendMessage($chatId, "⚠️ *No new image or text generated. Try with a different description.*");
    }
}

function getFile($fileId) {
    global $telegramToken;
    $url = "https://api.telegram.org/bot{$telegramToken}/getFile?file_id={$fileId}";
    $response = @file_get_contents($url);
    
    if ($response) {
        $data = json_decode($response, true);
        return $data['result']['file_path'] ?? null;
    }
    return null;
}

function callGeminiAPI($imageData, $description) {
    global $apiUrl;
    
    $payload = json_encode([
        'image_data' => $imageData,
        'description' => $description
    ]);
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if (!$response) {
        return ['error' => 'No response from server'];
    }
    
    return json_decode($response, true);
}

function sendMessage($chatId, $text) {
    global $telegramToken;
    
    $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result !== false;
}

function sendPhoto($chatId, $imageData, $mimeType) {
    global $telegramToken;
    $tempFile = tempnam(sys_get_temp_dir(), 'telegram_img');
    $imageBinary = base64_decode($imageData);
    
    if (!$imageBinary) {
        error_log("Failed to decode base64 image data");
        return false;
    }
    
    file_put_contents($tempFile, $imageBinary);
    
    $url = "https://api.telegram.org/bot{$telegramToken}/sendPhoto";
    
    $postData = [
        'chat_id' => $chatId,
        'photo' => new CURLFile($tempFile, $mimeType, 'edited_image.jpg'),
        'caption' => '✅ *Edited Image*'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    unlink($tempFile);
    
    return $httpCode === 200;
}
?>