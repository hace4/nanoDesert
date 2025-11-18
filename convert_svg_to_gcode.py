#!/usr/bin/env python3
import sys
import os
import re
import xml.etree.ElementTree as ET
from svg_to_gcode.svg_parser import parse_file
from svg_to_gcode.compiler import Compiler, interfaces


def clean_svg_namespaces(input_svg: str, cleaned_svg: str):
    """Полностью удаляет все namespace префиксы из SVG"""
    try:
        with open(input_svg, 'r', encoding='utf-8') as f:
            svg_content = f.read()

        # 1. Удаляем ВСЕ xmlns объявления с префиксами (ns0:, inkscape: и т.д.)
        svg_content = re.sub(r'\s+xmlns:[a-zA-Z0-9]+="[^"]*"', '', svg_content)
        
        # 2. Удаляем ВСЕ префиксы из тегов (ns0:svg -> svg, ns0:path -> path)
        # Обрабатываем открывающие и закрывающие теги
        svg_content = re.sub(r'<([/?]?)([a-zA-Z0-9]+):([a-zA-Z0-9-]+)', r'<\1\3', svg_content)
        
        # 3. Удаляем ВСЕ префиксы из атрибутов
        svg_content = re.sub(r' ([a-zA-Z0-9]+):([a-zA-Z0-9-]+)=', r' \2=', svg_content)
        
        # 4. Убеждаемся что есть основной SVG namespace
        if 'xmlns="http://www.w3.org/2000/svg"' not in svg_content:
            # Находим тег svg и добавляем namespace
            svg_content = re.sub(r'<svg([^>]*)', r'<svg xmlns="http://www.w3.org/2000/svg"\1', svg_content)
        
        # 5. Удаляем возможные дублирующиеся xmlns
        svg_content = re.sub(r'xmlns="[^"]*"\s+xmlns="[^"]*"', 'xmlns="http://www.w3.org/2000/svg"', svg_content)

        # 6. Удаляем невидимые элементы и стили, которые могут создавать лишние пути
        svg_content = re.sub(r'<style[^>]*>.*?</style>', '', svg_content, flags=re.DOTALL)
        svg_content = re.sub(r'display\s*:\s*none', '', svg_content)
        svg_content = re.sub(r'visibility\s*:\s*hidden', '', svg_content)
        
        # 7. Удаляем пустые пути и группы
        svg_content = re.sub(r'<path[^>]*d\s*=\s*"[\s,]*"[^>]*/>', '', svg_content)
        svg_content = re.sub(r'<g[^>]*></g>', '', svg_content)

        # Записываем очищенный контент
        with open(cleaned_svg, 'w', encoding='utf-8') as f:
            f.write(svg_content)

        # Проверяем валидность
        try:
            ET.fromstring(svg_content)
            return True
        except ET.ParseError as e:
           
            return False

    except Exception as e:
        
        return False


def optimize_gcode_commands(gcode_file: str):
    """Оптимизирует G-код для удаления лишних перемещений"""
    try:
        with open(gcode_file, 'r') as f:
            lines = f.readlines()
        
        optimized_lines = []
        last_command = None
        last_position = None
        
        for line in lines:
            line = line.strip()
            if not line or line.startswith(';'):
                optimized_lines.append(line + '\n')
                continue
                
            # Удаляем дублирующиеся команды перемещения
            if line.startswith('G0') or line.startswith('G1'):
                if line == last_command:
                    continue
                    
                # Проверяем изменение позиции
                if last_position and line == last_position:
                    continue
                    
                last_command = line
                last_position = line
            
            optimized_lines.append(line + '\n')
        
        # Перезаписываем файл с оптимизированными командами
        with open(gcode_file, 'w') as f:
            f.writelines(optimized_lines)
            
    except Exception as e:
        pass


def find_output_file(output_gcode: str):
    """Поиск сгенерированного G-код файла"""
    # Проверяем прямое расположение
    if os.path.exists(output_gcode):
        full_path = os.path.abspath(output_gcode)
        
        return True
    
    # Проверяем текущую рабочую директорию
    current_dir = os.getcwd()
    filename_only = os.path.basename(output_gcode)
    possible_path = os.path.join(current_dir, filename_only)
    
    if os.path.exists(possible_path):
       
        return True
    
    # Ищем любые G-код файлы в текущей директории
    current_dir = os.getcwd()
    gcode_files = [f for f in os.listdir(current_dir) if f.endswith('.gcode')]
    
    if gcode_files:
      
        for file in gcode_files:
            full_path = os.path.join(current_dir, file)
            size = os.path.getsize(full_path)
          
        return True
    
    return False


def convert_svg_to_gcode(input_svg: str, output_gcode: str,
                         scale: float = 1.0,
                         movement_speed: float = 3000,
                         cutting_speed: float = 1000,
                         passes: int = 1,
                         pass_depth: float = 1.0):
    """Конвертирует SVG в G-код с оптимизацией против лишних линий"""
    
    if not os.path.exists(input_svg):

        return False

    tmp_svg = os.path.splitext(output_gcode)[0] + "_clean.svg"

    # Очищаем SVG от namespace и потенциально проблемных элементов
    if not clean_svg_namespaces(input_svg, tmp_svg):

        return False

    # Проверяем что временный файл создан и не пустой
    if not os.path.exists(tmp_svg) or os.path.getsize(tmp_svg) == 0:
        
        return False

    try:
        # Парсим SVG с дополнительными опциями
        curves = parse_file(tmp_svg)
        
        if not curves:

            # Создаем пустой G-code файл
            with open(output_gcode, 'w') as f:
                f.write("; Empty G-code file - no paths found in SVG\n")
            find_output_file(output_gcode)
            return True



        # Компилируем в G-code с оптимизацией
        compiler = Compiler(
            interfaces.Gcode,
            movement_speed=movement_speed,
            cutting_speed=cutting_speed,
            pass_depth=pass_depth
        )

        # Добавляем кривые с проверкой
        compiler.append_curves(curves)
        
        # Компилируем в файл
        compiler.compile_to_file(output_gcode, passes=passes)

        # Оптимизируем G-код для удаления лишних команд
        if os.path.exists(output_gcode):
            optimize_gcode_commands(output_gcode)

            
            # Показываем статистику
            with open(output_gcode, 'r') as f:
                lines = f.readlines()
                gcode_lines = [l for l in lines if l.strip() and not l.startswith(';')]
               
                
        # Ищем файл вывода
        find_output_file(output_gcode)
        
        return True

    except Exception as e:

        import traceback
        traceback.print_exc()
        return False
    finally:
        # Всегда удаляем временный файл
        if os.path.exists(tmp_svg):
            os.remove(tmp_svg)


def main():
    if len(sys.argv) < 3:
        
        sys.exit(1)

    input_svg = sys.argv[1]
    output_gcode = sys.argv[2]
    scale = float(sys.argv[3]) if len(sys.argv) > 3 else 1.0
    pass_depth = float(sys.argv[4]) if len(sys.argv) > 4 else 1.0


    success = convert_svg_to_gcode(
        input_svg, 
        output_gcode, 
        scale=scale,
        pass_depth=pass_depth
    )

    if success:
        pass
    else:

        sys.exit(1)


if __name__ == "__main__":
    main()