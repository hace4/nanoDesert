#!/usr/bin/env python3
import sys
import cv2
import numpy as np
from scipy import ndimage


def fast_skeletonize(img):
    """Быстрая скелетизация с использованием морфологических операций"""
    # Используем оптимизированную реализацию из OpenCV
    kernel = cv2.getStructuringElement(cv2.MORPH_CROSS, (3, 3))
    skel = np.zeros(img.shape, np.uint8)
    
    img_bin = (img > 0).astype(np.uint8)
    while True:
        eroded = cv2.erode(img_bin, kernel)
        temp = cv2.dilate(eroded, kernel)
        temp = cv2.subtract(img_bin, temp)
        skel = cv2.bitwise_or(skel, temp)
        img_bin = eroded.copy()
        
        if cv2.countNonZero(img_bin) == 0:
            break
            
    return skel * 255


def optimized_order_path(skel):
    """Оптимизированное упорядочивание точек с использованием BFS от конечных точек"""
    points = np.column_stack(np.where(skel > 0))
    if len(points) == 0:
        return []
    
    # Создаем граф связности
    h, w = skel.shape
    skel_bool = skel > 0
    
    # Находим конечные точки (имеющие только 1 соседа)
    def count_neighbors(y, x):
        y1, y2 = max(0, y-1), min(h, y+2)
        x1, x2 = max(0, x-1), min(w, x+2)
        return np.sum(skel_bool[y1:y2, x1:x2]) - 1
    
    # Начинаем с конечной точки
    endpoints = []
    for y, x in points[:min(100, len(points))]:  # Проверяем только первые точки
        if count_neighbors(y, x) == 1:
            endpoints.append((x, y))
            if len(endpoints) >= 2:
                break
    
    if not endpoints:
        start_point = (points[0][1], points[0][0])
    else:
        start_point = endpoints[0]
    
    # BFS для построения пути
    point_set = set((x, y) for y, x in points)
    ordered = [start_point]
    used = set([start_point])
    
    while len(used) < len(point_set):
        last_x, last_y = ordered[-1]
        
        # Ищем соседей
        neighbors = []
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                if dx == 0 and dy == 0:
                    continue
                nx, ny = last_x + dx, last_y + dy
                if (nx, ny) in point_set and (nx, ny) not in used:
                    neighbors.append((nx, ny))
        
        if neighbors:
            # Предпочитаем прямых соседей
            direct_neighbors = [n for n in neighbors if n[0] == last_x or n[1] == last_y]
            if direct_neighbors:
                next_pt = direct_neighbors[0]
            else:
                next_pt = neighbors[0]
            ordered.append(next_pt)
            used.add(next_pt)
        else:
            # Если соседей нет, ищем ближайшую неиспользованную точку
            unused = point_set - used
            if unused:
                next_pt = min(unused, key=lambda p: (p[0]-last_x)**2 + (p[1]-last_y)**2)
                ordered.append(next_pt)
                used.add(next_pt)
            else:
                break
    
    return ordered


def convert(png, svg):
    img = cv2.imread(png, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise Exception("Cannot read PNG")

    # Быстрая бинаризация
    _, binary = cv2.threshold(img, 200, 255, cv2.THRESH_BINARY_INV)
    
    # Оптимизированное закрытие разрывов
    if np.sum(binary > 0) > 0:  # Только если есть объекты
        binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, 
                                cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3)))
    
    # Быстрая скелетизация
    skel = fast_skeletonize(binary)
    
    # Упорядочивание
    points = optimized_order_path(skel)
    if not points:
        raise Exception("No skeleton path")

    # SVG
    h, w = img.shape
    path_data = "M " + " L ".join(f"{x} {y}" for x, y in points)
    
    with open(svg, "w") as f:
        f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">'
                f'<path d="{path_data}" stroke="black" fill="none" stroke-width="1"/></svg>')
    
    print("OK:", svg)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: converter.py input.png output.svg")
        sys.exit(1)
    convert(sys.argv[1], sys.argv[2])