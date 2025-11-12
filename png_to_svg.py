#!/usr/bin/env python3
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
        img = original_img.convert('L')
        
        # Сохраняем оригинальные размеры для SVG
        svg_width = original_width
        svg_height = original_height
        
        # Создаем временный файл для PGM (Potrace лучше работает с PGM)
        with tempfile.NamedTemporaryFile(suffix='.pgm', delete=False) as temp_pgm:
            temp_pgm_path = temp_pgm.name
        
        # Сохраняем как PGM
        img.save(temp_pgm_path)
        print("Created temporary PGM file")
        
        # Создаем временный SVG файл
        with tempfile.NamedTemporaryFile(suffix='.svg', delete=False) as temp_svg:
            temp_svg_path = temp_svg.name
        
        # Вызываем Potrace для конвертации в SVG
        cmd = [
            'potrace',
            temp_pgm_path,
            '-s',  # SVG output
            '-o', temp_svg_path,
            '--group',  # Группировать все пути
            '--tight',  # Плотное обрезание
            '--opttolerance', '0.2',  # Точность оптимизации
            '--unit', '1',  # 1 unit = 1 pixel
            '--scale', '1.0',  # Без масштабирования
            '--rotate', '0'  # Без вращения
        ]
        
        print(f"Running command: {' '.join(cmd)}")
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
        if 'temp_pgm_path' in locals() and os.path.exists(temp_pgm_path):
            os.unlink(temp_pgm_path)
        if 'temp_svg_path' in locals() and os.path.exists(temp_svg_path):
            os.unlink(temp_svg_path)
        return False

def fix_svg_dimensions(input_svg_path, output_svg_path, width, height):
    """Исправляет размеры SVG чтобы соответствовать оригинальному изображению"""
    
    # Читаем SVG созданный Potrace
    with open(input_svg_path, 'r', encoding='utf-8') as f:
        svg_content = f.read()
    
    # Парсим SVG
    try:
        root = ET.fromstring(svg_content)
    except ET.ParseError:
        # Если не парсится, используем простой метод замены
        svg_content = svg_content.replace(
            '<svg ', 
            f'<svg width="{width}" height="{height}" viewBox="0 0 {width} {height}" '
        )
    else:
        # Устанавливаем правильные атрибуты
        root.set('width', str(width))
        root.set('height', str(height))
        root.set('viewBox', f'0 0 {width} {height}')
        
        # Конвертируем обратно в строку
        svg_content = ET.tostring(root, encoding='unicode')
    
    # Сохраняем исправленный SVG
    with open(output_svg_path, 'w', encoding='utf-8') as f:
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
        sys.exit(1)