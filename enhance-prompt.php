<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userPrompt = $input['prompt'] ?? '';

if (empty($userPrompt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt is required']);
    exit;
}

// Ваш API ключ от ProxyAPI
$proxyapi_token = 'sk-E566OJR2OFGXnweAbhdNuQ8LbvUCRkIb'; // Замените на ваш токен

$systemMessage = "Ты профессиональный дизайнер и инженер, специализирующийся на создании векторных изображений для ЧПУ станков. 
Твоя задача - преобразовать описание пользователя в идеальный промпт для DALL-E, который создаст черно-белое векторное изображение, 
оптимизированное для преобразования в G-code.

Требования к промпту:
- Черно-белая графика, только контуры
- Минималистичный стиль
- Толстые непрерывные линии
- Высокий контраст
- Оптимизация для векторной трассировки
- Простые геометрические формы
- Без заливок, теней и цветов
- Полностью белый фон, соотношение сторон 1:1

Верни ТОЛЬКО финальный промпт на английском языке, без дополнительных объяснений.";

$data = [
    'model' => 'gpt-4o',
    'messages' => [
        [
            'role' => 'system',
            'content' => $systemMessage
        ],
        [
            'role' => 'user',
            'content' => "Создай идеальный промпт для этого описания: \"{$userPrompt}\""
        ]
    ],
    'max_tokens' => 500,
    'temperature' => 0.7
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.proxyapi.ru/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $proxyapi_token
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    error_log("ProxyAPI error: " . $response);
    http_response_code(500);
    echo json_encode(['error' => 'AI service temporarily unavailable']);
    exit;
}

$response_data = json_decode($response, true);

if (isset($response_data['choices'][0]['message']['content'])) {
    echo json_encode([
        'success' => true,
        'enhanced_prompt' => trim($response_data['choices'][0]['message']['content'])
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate enhanced prompt']);
}
