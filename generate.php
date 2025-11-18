<?php

class DalleProxy
{
    private $proxyApiKey;
    private $proxyApiUrl;
    private $timeout = 30;

    private $downloadPath;

    // Внешние python-файлы (теперь НЕ генерируются)
    private $pythonScriptPngToSvg;
    private $pythonScriptSvgToGcode;

    public function __construct()
    {
        $this->proxyApiKey = 'sk-E566OJR2OFGXnweAbhdNuQ8LbvUCRkIb';
        $this->proxyApiUrl = 'https://api.proxyapi.ru/openai/v1/images/generations';

        $this->downloadPath = __DIR__ . '/downloads/';
        $this->pythonScriptPngToSvg = __DIR__ . '/png_to_svg.py';
        $this->pythonScriptSvgToGcode = __DIR__ . '/convert_svg_to_gcode.py';

        if (!is_dir($this->downloadPath)) {
            mkdir($this->downloadPath, 0755, true);
        }
    }

public function handleRequest()
{
    try {
        $inputData = $this->getAndValidateInput();

        // Точный оригинальный prompt (никаких правок)
        $prompt = <<<'PROMPT'
"One continuous line drawing of a elegant cat, single line art, minimalist. The line is unbroken and forms the entire silhouette. Pure white background, no shadows, no colors."
PROMPT;

        $size = $inputData['size'] ?? '1024x1024';
        $quality = $inputData['quality'] ?? 'standard';
        $style = $inputData['style'] ?? 'vivid';
        $convert_to_gcode = $inputData['convert_to_gcode'] ?? false;

        $result = $this->callDalleProxy($prompt, $size, $quality, $style);
        $downloadResult = $this->downloadImage($result['image_url'], $prompt);
        $result = array_merge($result, $downloadResult);

        if ($convert_to_gcode) {
            $conversionResult = $this->convertToGcode($result['local_path'], $prompt);
            $result = array_merge($result, $conversionResult);
        }

        $this->sendSuccessResponse($result, $convert_to_gcode);
    } catch (Exception $e) {
        $this->sendErrorResponse($e->getMessage(), $e->getCode() ?: 500);
    }
}

private function callDalleProxy($prompt, $size, $quality, $style)
    {
        $payload = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'style' => $style,
            'n' => 1
        ];

        $ch = curl_init($this->proxyApiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->proxyApiKey
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Dalle-Proxy/1.0'
        ]);

        return $this->executeCurlRequest($ch, 'ProxyAPI.ru DALL-E');
    }
    private function convertToGcode($pngPath, $prompt)
    {
        $safePrompt = $this->createSafeFileName($prompt);
        $baseName = 'dalle_' . time() . '_' . $safePrompt;

        // PNG → SVG
        $svgPath = $this->convertPngToSvgWithPython($pngPath, $baseName);

        // SVG → GCODE
        $gcodePath = $this->convertSvgToGcodeWithPython($svgPath, $baseName);

        return [
            'svg_path' => $svgPath,
            'svg_url' => $this->getWebUrl(basename($svgPath)),
            'gcode_path' => $gcodePath,
            'gcode_url' => $this->getWebUrl(basename($gcodePath)),
            'conversion_success' => true,
            'conversion_type' => 'python'
        ];
    }

 private function executeCurlRequest($ch, $apiName)
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Ошибка cURL ($apiName): " . $error, 500);
        }

        if ($httpCode !== 200) {
            throw new Exception("Ошибка $apiName. HTTP Code: $httpCode, Ответ: " . substr($response, 0, 500), $httpCode);
        }

        return $this->parseApiResponse($response, $apiName);
    }
      private function parseApiResponse($response, $apiName)
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Неверный JSON ответ от $apiName", 500);
        }

        if (isset($data['data'][0]['url'])) {
            return [
                'image_url' => $data['data'][0]['url'],
                'revised_prompt' => $data['data'][0]['revised_prompt'] ?? null
            ];
        } elseif (isset($data['error'])) {
            throw new Exception("Ошибка API: " . $data['error']['message'], 400);
        } else {
            throw new Exception("Не удалось получить URL изображения от $apiName", 500);
        }
    }
    private function convertPngToSvgWithPython($pngPath, $baseName)
    {
        $svgPath = $this->downloadPath . $baseName . '.svg';

        if (!file_exists($this->pythonScriptPngToSvg)) {
            throw new Exception("Python script not found: " . $this->pythonScriptPngToSvg);
        }

        $cmd = $this->getPythonCommand() . " " .
            escapeshellarg($this->pythonScriptPngToSvg) . " " .
            escapeshellarg($pngPath) . " " .
            escapeshellarg($svgPath) . " 2>&1";

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("PNG→SVG error: " . implode("\n", $output));
        }

        if (!file_exists($svgPath)) {
            throw new Exception("SVG file was not created");
        }

        return $svgPath;
    }


    private function convertSvgToGcodeWithPython($svgPath, $baseName)
    {
        $gcodePath = $this->downloadPath . $baseName . '.gcode';

        if (!file_exists($this->pythonScriptSvgToGcode)) {
            throw new Exception("Python script not found: " . $this->pythonScriptSvgToGcode);
        }

        $cmd = $this->getPythonCommand() . " " .
            escapeshellarg($this->pythonScriptSvgToGcode) . " " .
            escapeshellarg($svgPath) . " " .
            escapeshellarg($gcodePath) . " 1 1 2>&1";

        exec($cmd, $output, $returnCode);

        if (!file_exists($gcodePath)) {
            throw new Exception("GCODE file was not created: " . implode("\n", $output));
        }

        return $gcodePath;
    }

        private function getAndValidateInput()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Метод не разрешен. Используйте POST.', 405);
        }

        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Отсутствуют данные в запросе.', 400);
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Неверный JSON формат: ' . json_last_error_msg(), 400);
        }

        if (!isset($data['prompt']) || empty(trim($data['prompt']))) {
            throw new Exception('Отсутствует текст-запрос (prompt) для генерации изображения.', 400);
        }

        return [
            'prompt' => trim($data['prompt']),
            'size' => $data['size'] ?? '1024x1024',
            'quality' => $data['quality'] ?? 'standard',
            'style' => $data['style'] ?? 'vivid',
            'convert_to_gcode' => $data['convert_to_gcode'] ?? false
        ];
    }
    private function getPythonCommand()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'python'
            : 'python3';
    }


    private function downloadImage($imageUrl, $prompt)
    {
        try {
            $safePrompt = $this->createSafeFileName($prompt);
            $fileName = 'dalle_' . time() . '_' . $safePrompt . '.png';
            $localPath = $this->downloadPath . $fileName;

            $ch = curl_init($imageUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("Ошибка загрузки изображения: " . $error);
            }

            if ($httpCode !== 200) {
                throw new Exception("Ошибка загрузки изображения. HTTP Code: $httpCode");
            }

            if (strpos($contentType, 'image/') === false) {
                throw new Exception("Получен не изображение. Content-Type: $contentType");
            }

            $fileSize = file_put_contents($localPath, $imageData);

            if ($fileSize === false) {
                throw new Exception("Не удалось сохранить файл: $localPath");
            }

            $fileInfo = getimagesize($localPath);
            $dimensions = $fileInfo ? $fileInfo[0] . 'x' . $fileInfo[1] : 'unknown';

            return [
                'local_path' => $localPath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'dimensions' => $dimensions,
                'web_url' => $this->getWebUrl($fileName),
                'download_success' => true
            ];
        } catch (Exception $e) {
            throw new Exception("Ошибка при скачивании изображения: " . $e->getMessage());
        }
    }

    private function createSafeFileName($prompt)
    {
        $prompt = substr($prompt, 0, 50);
        $safeName = preg_replace('/[^a-zA-Z0-9а-яА-Я_-]/u', '_', $prompt);
        $safeName = preg_replace('/_{2,}/', '_', $safeName);
        $safeName = trim($safeName, '_');

        if (empty($safeName)) {
            $safeName = time();
        }

        return $safeName;
    }

    private function getWebUrl($fileName)
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $scriptPath = rtrim($scriptPath, '/');

        return $protocol . '://' . $host . $scriptPath . '/downloads/' . urlencode($fileName);
    }

    private function sendSuccessResponse($data, $convertToGcode)
    {
        $response = [
            'success' => true,
            'image_url' => $data['image_url'],
            'revised_prompt' => $data['revised_prompt'],
            'timestamp' => time(),
            'downloaded' => true,
            'local_path' => $data['local_path'],
            'file_name' => $data['file_name'],
            'file_size' => $data['file_size'],
            'dimensions' => $data['dimensions'],
            'web_url' => $data['web_url']
        ];

        if ($convertToGcode && isset($data['conversion_success'])) {
            $response['conversion'] = [
                'svg_path' => $data['svg_path'],
                'svg_url' => $data['svg_url'],
                'gcode_path' => $data['gcode_path'],
                'gcode_url' => $data['gcode_url'],
                'conversion_success' => true,
                'conversion_type' => 'professional'
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function sendErrorResponse($message, $code)
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

$proxy = new DalleProxy();
$proxy->handleRequest();
