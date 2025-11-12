<?php
class DalleProxy
{
    private $proxyApiKey;
    private $proxyApiUrl;
    private $timeout = 30;
    private $downloadPath;
    private $pythonScript;
    private $svgToGcodeScript;

    public function __construct()
    {
        $this->proxyApiKey = 'sk-E566OJR2OFGXnweAbhdNuQ8LbvUCRkIb';
        $this->proxyApiUrl = 'https://api.proxyapi.ru/openai/v1/images/generations';
        $this->downloadPath = __DIR__ . '/downloads/';
        $this->pythonScript = __DIR__ . '/png_to_svg.py';
        $this->svgToGcodeScript = __DIR__ . '/convert_svg_to_gcode.py';

        if (!is_dir($this->downloadPath)) {
            mkdir($this->downloadPath, 0755, true);
        }

        // Создаем Python скрипты при инициализации
        $this->createPythonScripts();
    }

    public function handleRequest()
    {
        try {
            $inputData = $this->getAndValidateInput();
            $prompt = $inputData['prompt'];
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

    private function convertToGcode($pngPath, $prompt)
    {
        try {
            $safePrompt = $this->createSafeFileName($prompt);
            $baseName = 'dalle_' . time() . '_' . $safePrompt;

            // Конвертируем PNG в SVG используя Python
            $svgPath = $this->convertPngToSvgWithPython($pngPath, $baseName);

            // Конвертируем SVG в G-code используя Python с библиотекой
            $gcodePath = $this->convertSvgToGcodeWithPython($svgPath, $baseName);

            return [
                'svg_path' => $svgPath,
                'svg_url' => $this->getWebUrl(basename($svgPath)),
                'gcode_path' => $gcodePath,
                'gcode_url' => $this->getWebUrl(basename($gcodePath)),
                'conversion_success' => true,
                'conversion_type' => 'python_library'
            ];
        } catch (Exception $e) {
            throw new Exception("Ошибка конвертации в G-code: " . $e->getMessage());
        }
    }

    private function convertPngToSvgWithPython($pngPath, $baseName)
    {
        $svgPath = $this->downloadPath . $baseName . '.svg';

        // Проверяем существование Python скрипта
        if (!file_exists($this->pythonScript)) {
            throw new Exception("Python скрипт не найден: " . $this->pythonScript);
        }

        // Определяем команду в зависимости от ОС
        $pythonCommand = $this->getPythonCommand();

        // Выполняем Python скрипт с увеличенным временем выполнения
        $command = $pythonCommand . " " . escapeshellarg($this->pythonScript) . " " .
            escapeshellarg($pngPath) . " " . escapeshellarg($svgPath) . " 2>&1";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        // Логируем вывод для отладки
        error_log("Python SVG conversion output: " . implode("\n", $output));

        if ($returnCode !== 0) {
            throw new Exception("Ошибка конвертации PNG в SVG (код: $returnCode): " . implode("\n", $output));
        }

        if (!file_exists($svgPath)) {
            throw new Exception("SVG файл не был создан");
        }

        // Проверяем что файл не пустой
        if (filesize($svgPath) < 100) {
            throw new Exception("Созданный SVG файл слишком мал или пуст");
        }

        return $svgPath;
    }

    private function convertSvgToGcodeWithPython($svgPath, $baseName)
    {
        $gcodePath = $this->downloadPath . $baseName . '.gcode';

        // Проверяем существование Python скрипта
        if (!file_exists($this->svgToGcodeScript)) {
            throw new Exception("Python скрипт для G-code не найден: " . $this->svgToGcodeScript);
        }

        // Проверяем существование SVG файла
        if (!file_exists($svgPath)) {
            throw new Exception("SVG файл не существует: " . $svgPath);
        }

        // Определяем команду в зависимости от ОС
        $pythonCommand = $this->getPythonCommand();

        // Выполняем Python скрипт для конвертации SVG в G-code
        // Правильная команда с раздельными аргументами
        $command = $pythonCommand . " " .
            escapeshellarg($this->svgToGcodeScript) . " " .
            escapeshellarg($svgPath) . " " .
            escapeshellarg($gcodePath) . " 1 1 2>&1";

        $output = [];

        $returnCode = 0;
        exec($command, $output, $returnCode);

        // Логируем вывод для отладки
        error_log("Python G-code conversion command: " . $command);
        error_log("Python G-code conversion output: " . implode("\n", $output));
        error_log("Python G-code return code: " . $returnCode);

        // Проверяем существование G-code файла
        if (!file_exists($gcodePath)) {
            throw new Exception("G-code файл не был создан по пути: " . $gcodePath . ". Output: " . implode("\n", $output));
        }

        // Проверяем что файл не пустой
        $fileSize = filesize($gcodePath);
        if ($fileSize < 10) {
            throw new Exception("Созданный G-code файл слишком мал (" . $fileSize . " bytes): " . implode("\n", $output));
        }

        error_log("G-code file created successfully: " . $gcodePath . " (" . $fileSize . " bytes)");

        return $gcodePath;
    }

    private function getPythonCommand()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return 'python';
        } else {
            return 'python3';
        }
    }

    private function createPythonScripts()
    {
        $this->createPngToSvgScript();
    }

    private function createPngToSvgScript()
    {
        $pythonCode = '#!/usr/bin/env python3
import sys
import os
from PIL import Image
import numpy as np
import subprocess
import tempfile
import xml.etree.ElementTree as ET

def png_to_svg(png_path, svg_path, threshold=128):
    """
    Конвертирует PNG в SVG используя Potrace с сохранением оригинальных размеров
    """
    try:
        print(f"Converting {png_path} to {svg_path}")
        
        # Открываем и обрабатываем изображение
        original_img = Image.open(png_path)
        original_width, original_height = original_img.size
        print(f"Original image size: {original_width}x{original_height}")
        
        # Конвертируем в grayscale
        img = original_img.convert(\'L\')
        
        # Сохраняем оригинальные размеры для SVG
        svg_width = original_width
        svg_height = original_height
        
        # Создаем временный файл для PGM (Potrace лучше работает с PGM)
        with tempfile.NamedTemporaryFile(suffix=\'.pgm\', delete=False) as temp_pgm:
            temp_pgm_path = temp_pgm.name
        
        # Сохраняем как PGM
        img.save(temp_pgm_path)
        print("Created temporary PGM file")
        
        # Создаем временный SVG файл
        with tempfile.NamedTemporaryFile(suffix=\'.svg\', delete=False) as temp_svg:
            temp_svg_path = temp_svg.name
        
        # Вызываем Potrace для конвертации в SVG
        cmd = [
            \'potrace\',
            temp_pgm_path,
            \'-s\',  # SVG output
            \'-o\', temp_svg_path,
            \'--group\',  # Группировать все пути
            \'--tight\',  # Плотное обрезание
            \'--opttolerance\', \'0.2\',  # Точность оптимизации
            \'--unit\', \'1\',  # 1 unit = 1 pixel
            \'--scale\', \'1.0\',  # Без масштабирования
            \'--rotate\', \'0\'  # Без вращения
        ]
        
        print(f"Running command: {\' \'.join(cmd)}")
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
        
        # Удаляем временный PGM файл
        os.unlink(temp_pgm_path)
        
        if result.returncode != 0:
            raise Exception(f"Potrace error: {result.stderr}")
        
        print("Potrace conversion completed, fixing SVG dimensions...")
        
        # Исправляем размеры SVG чтобы соответствовать оригинальному изображению
        fix_svg_dimensions(temp_svg_path, svg_path, svg_width, svg_height)
        
        # Удаляем временный SVG
        os.unlink(temp_svg_path)
        
        print("SVG dimensions fixed successfully")
        return True
        
    except Exception as e:
        print(f"Error during conversion: {str(e)}", file=sys.stderr)
        # Удаляем временные файлы в случае ошибки
        if \'temp_pgm_path\' in locals() and os.path.exists(temp_pgm_path):
            os.unlink(temp_pgm_path)
        if \'temp_svg_path\' in locals() and os.path.exists(temp_svg_path):
            os.unlink(temp_svg_path)
        return False

def fix_svg_dimensions(input_svg_path, output_svg_path, width, height):
    """Исправляет размеры SVG чтобы соответствовать оригинальному изображению"""
    
    # Читаем SVG созданный Potrace
    with open(input_svg_path, \'r\', encoding=\'utf-8\') as f:
        svg_content = f.read()
    
    # Парсим SVG
    try:
        root = ET.fromstring(svg_content)
    except ET.ParseError:
        # Если не парсится, используем простой метод замены
        svg_content = svg_content.replace(
            \'<svg \', 
            f\'<svg width="{width}" height="{height}" viewBox="0 0 {width} {height}" \'
        )
    else:
        # Устанавливаем правильные атрибуты
        root.set(\'width\', str(width))
        root.set(\'height\', str(height))
        root.set(\'viewBox\', f\'0 0 {width} {height}\')
        
        # Конвертируем обратно в строку
        svg_content = ET.tostring(root, encoding=\'unicode\')
    
    # Сохраняем исправленный SVG
    with open(output_svg_path, \'w\', encoding=\'utf-8\') as f:
        f.write(svg_content)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: python png_to_svg.py <input.png> <output.svg>")
        sys.exit(1)
    
    png_path = sys.argv[1]
    svg_path = sys.argv[2]
    
    if not os.path.exists(png_path):
        print(f"Error: Input file {png_path} does not exist")
        sys.exit(1)
    
    # Пытаемся использовать Potrace
    if png_to_svg(png_path, svg_path):
        print(f"Successfully converted {png_path} to {svg_path} using Potrace")
        sys.exit(0)
    else:
        print("Conversion failed")
        sys.exit(1)';

        if (file_put_contents($this->pythonScript, $pythonCode) === false) {
            throw new Exception("Не удалось создать Python скрипт для SVG");
        }

        // Устанавливаем права на выполнение (для Linux)
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            chmod($this->pythonScript, 0755);
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
