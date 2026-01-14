<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

error_reporting(E_ALL);
ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", "");
error_log("=== API Request ===");
error_log("Time: " . date("Y-m-d H:i:s"));

$apiKey = "AIzaSyATuE5kJr4AQHgK0Cj04FQd03mTfVMScHQ"; // 🔑 Replace with your valid Google Cloud API key
$model = "gemini-2.5-flash-image-preview";

$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

// Get raw input
$inputData = file_get_contents("php://input");
error_log("Raw input: " . substr($inputData, 0, 500));

$input = json_decode($inputData, true);

// Validate JSON input
if (!$input) {
    error_log("Invalid JSON input");
    echo json_encode(["error" => "Invalid JSON data"]);
    exit;
}

// Check required fields
if (!isset($input["image_data"]) || !isset($input["description"])) {
    error_log("Missing image_data or description");
    echo json_encode(["error" => "You must send both image data and description"]);
    exit;
}

$imageData = $input["image_data"];
$editDescription = $input["description"];

// Clean base64
if (strpos($imageData, "base64,") !== false) {
    $imageData = substr($imageData, strpos($imageData, "base64,") + 7);
}
$imageData = preg_replace("/[^a-zA-Z0-9\/+=]/", "", $imageData);

if (empty($imageData) || empty($editDescription)) {
    echo json_encode(["error" => "Image data or description is empty"]);
    exit;
}

// Build request payload
$requestPayload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $editDescription],
                [
                    "inline_data" => [
                        "mime_type" => "image/jpeg",
                        "data" => $imageData
                    ]
                ]
            ]
        ]
    ],
    "generationConfig" => [
        "responseMimeType" => "application/json",
        "temperature" => 0.7
    ]
];

error_log("Sending to Gemini API");

// Send request to Gemini
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-goog-api-key: " . $apiKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

error_log("Gemini HTTP Code: " . $httpCode);
error_log("Gemini Response: " . substr($response, 0, 1000));

// Handle cURL errors
if ($curlError) {
    error_log("CURL Error: " . $curlError);
    echo json_encode(["error" => "Connection failed: " . $curlError]);
    exit;
}

// Handle API errors
if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $errorMsg = $errorData["error"]["message"] ?? "Unknown error";
    error_log("API Error: " . $errorMsg);
    echo json_encode(["error" => "Gemini Error: " . $errorMsg]);
    exit;
}

$responseData = json_decode($response, true);

// Validate response structure
if (!isset($responseData["candidates"][0]["content"]["parts"])) {
    error_log("Unexpected response format: " . json_encode($responseData));
    echo json_encode(["error" => "Unexpected response from Gemini"]);
    exit;
}

$result = ["text" => "", "image" => null];

// Extract text and image from response
foreach ($responseData["candidates"][0]["content"]["parts"] as $part) {
    if (isset($part["inlineData"])) {
        $result["image"] = [
            "mime_type" => $part["inlineData"]["mimeType"],
            "data" => $part["inlineData"]["data"]
        ];
    } elseif (isset($part["text"])) {
        $result["text"] = $part["text"];
    }
}

// Check if image was generated
if (!$result["image"]) {
    error_log("No image in response");
    $result["error"] = "No image was generated. Try a different description.";
}

error_log("Final result: " . json_encode([
    "text_length" => strlen($result["text"]),
    "has_image" => !empty($result["image"])
]));

echo json_encode($result);
?>
