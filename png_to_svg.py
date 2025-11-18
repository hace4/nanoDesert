#!/usr/bin/env python3
import sys
import cv2
import numpy as np
import networkx as nx
from skimage import morphology
import itertools
from collections import deque

def find_continuous_path(binary_img):
    """Находит непрерывный путь через все линии изображения с возможностью многократного прохода"""
    # Создаем скелет
    skeleton = morphology.skeletonize(binary_img > 0)
    skeleton = skeleton.astype(np.uint8) * 255
    
    # Строим граф из скелета
    G = build_skeleton_graph(skeleton)
    
    if len(G.nodes) == 0:
        return []
    
    # Находим путь, покрывающий все ребра
    path = find_eulerian_path(G)
    
    if not path:
        # Fallback: используем BFS обход
        start_node = find_start_point(G)
        path = bfs_traversal(G, start_node)
    
    return simplify_path(path, tolerance=1.0)

def build_skeleton_graph(skeleton):
    """Строит граф из скелета изображения"""
    G = nx.Graph()
    
    # Добавляем все точки скелета как узлы
    points = np.where(skeleton > 0)
    for y, x in zip(points[0], points[1]):
        G.add_node((x, y))
    
    # Добавляем ребра между соседними точками (8-связность)
    for y in range(skeleton.shape[0]):
        for x in range(skeleton.shape[1]):
            if skeleton[y, x] > 0:
                # Проверяем всех соседей
                for dx, dy in [(1, 0), (0, 1), (1, 1), (-1, 1), 
                              (-1, 0), (0, -1), (-1, -1), (1, -1)]:
                    nx_, ny = x + dx, y + dy
                    if (0 <= nx_ < skeleton.shape[1] and 0 <= ny < skeleton.shape[0] and
                        skeleton[ny, nx_] > 0 and (x, y) != (nx_, ny)):
                        # Вычисляем расстояние для веса
                        dist = np.sqrt(dx*dx + dy*dy)
                        if not G.has_edge((x, y), (nx_, ny)):
                            G.add_edge((x, y), (nx_, ny), weight=dist)
    
    return G

def find_eulerian_path(G):
    """Находит эйлеров путь или путь китайского почтальона"""
    if len(G.edges) == 0:
        return []
    
    # Проверяем, является ли граф эйлеровым
    if nx.is_eulerian(G):
        try:
            start_node = find_start_point(G)
            euler_circuit = list(nx.eulerian_circuit(G, source=start_node))
            return circuit_to_path(euler_circuit, start_node)
        except:
            pass
    
    # Пытаемся найти полу-эйлеров путь (с ровно двумя нечетными вершинами)
    odd_degree_nodes = [v for v, d in G.degree() if d % 2 == 1]
    
    if len(odd_degree_nodes) == 2:
        try:
            start_node = odd_degree_nodes[0]
            euler_path = list(nx.eulerian_path(G, source=start_node))
            return circuit_to_path(euler_path, start_node)
        except:
            pass
    
    # Используем алгоритм китайского почтальона
    return chinese_postman_path(G)

def chinese_postman_path(G):
    """Реализация алгоритма китайского почтальона с минимальными дублированиями"""
    if len(G) == 0:
        return []
    
    # Создаем копию графа для работы
    G_working = G.copy()
    path = []
    
    # Начинаем с произвольной точки
    current_node = find_start_point(G_working)
    path.append(current_node)
    
    # Пока есть непройденные ребра
    while G_working.number_of_edges() > 0:
        # Находим непройденные ребра из текущей вершины
        unvisited_edges = [edge for edge in G_working.edges(current_node)]
        
        if unvisited_edges:
            # Берем первое непройденное ребро
            next_node = unvisited_edges[0][1] if unvisited_edges[0][0] == current_node else unvisited_edges[0][0]
            path.append(next_node)
            
            # Удаляем пройденное ребро
            G_working.remove_edge(current_node, next_node)
            current_node = next_node
        else:
            # Если нет непройденных ребр из текущей вершины, ищем ближайшую вершину с непройденными ребрами
            found = False
            for i in range(len(path) - 1, -1, -1):
                node = path[i]
                if G_working.degree(node) > 0:
                    # Находим кратчайший путь к этой вершине
                    try:
                        shortest_path = nx.shortest_path(G, current_node, node, weight='weight')
                        # Добавляем путь к найденной вершине (пропускаем первую, т.к. она уже в пути)
                        for path_node in shortest_path[1:]:
                            path.append(path_node)
                        current_node = node
                        found = True
                        break
                    except:
                        continue
            
            if not found:
                break
    
    return path

def find_start_point(G):
    """Находит хорошую стартовую точку (предпочтительно конечную)"""
    # Ищем концевые точки (степень 1)
    endpoints = [node for node, degree in G.degree() if degree == 1]
    if endpoints:
        return endpoints[0]
    
    # Ищем точки с нечетной степенью
    odd_degree = [node for node, degree in G.degree() if degree % 2 == 1]
    if odd_degree:
        return odd_degree[0]
    
    # Берем любую точку
    if len(G.nodes) > 0:
        return list(G.nodes)[0]
    
    return None

def circuit_to_path(circuit, start_node):
    """Преобразует список ребер в путь"""
    path = [start_node]
    for u, v in circuit:
        path.append(v)
    return path

def bfs_traversal(G, start_node):
    """BFS обход графа для создания пути"""
    if start_node is None or start_node not in G:
        return []
    
    visited_edges = set()
    path = [start_node]
    stack = [start_node]
    
    while stack:
        current = stack[-1]
        
        # Ищем непосещенное ребро из текущей вершины
        found = False
        for neighbor in G.neighbors(current):
            edge = tuple(sorted([current, neighbor]))
            if edge not in visited_edges:
                visited_edges.add(edge)
                path.append(neighbor)
                stack.append(neighbor)
                found = True
                break
        
        # Если непосещенных ребр нет, возвращаемся назад
        if not found:
            stack.pop()
    
    return path

def interpolate_points(p1, p2):
    """Интерполирует точки между двумя точками"""
    x1, y1 = p1
    x2, y2 = p2
    points = []
    
    steps = max(abs(x2 - x1), abs(y2 - y1))
    if steps == 0:
        return [p1]
    
    for i in range(steps + 1):
        t = i / steps
        x = int(x1 + t * (x2 - x1))
        y = int(y1 + t * (y2 - y1))
        points.append((x, y))
    
    return points

def simplify_path(points, tolerance=2.0):
    """Упрощает путь используя алгоритм Рамера-Дугласа-Пекера"""
    if len(points) < 3:
        return points
    
    def perpendicular_distance(point, line_start, line_end):
        """Вычисляет перпендикулярное расстояние от точки до линии"""
        if line_start == line_end:
            return np.linalg.norm(np.array(point) - np.array(line_start))
        
        line_vec = np.array(line_end) - np.array(line_start)
        point_vec = np.array(point) - np.array(line_start)
        line_len = np.linalg.norm(line_vec)
        
        if line_len == 0:
            return np.linalg.norm(point_vec)
        
        line_unitvec = line_vec / line_len
        point_vec_scaled = point_vec / line_len
        t = np.dot(line_unitvec, point_vec_scaled)
        t = max(0, min(1, t))
        
        nearest = np.array(line_start) + t * line_vec
        dist = np.linalg.norm(np.array(point) - nearest)
        return dist
    
    def rdp_recursive(point_list, epsilon):
        """Рекурсивная часть алгоритма Рамера-Дугласа-Пекера"""
        if len(point_list) < 3:
            return point_list
        
        # Находим точку с максимальным расстоянием
        max_dist = 0
        max_idx = 0
        start, end = point_list[0], point_list[-1]
        
        for i in range(1, len(point_list) - 1):
            dist = perpendicular_distance(point_list[i], start, end)
            if dist > max_dist:
                max_dist = dist
                max_idx = i
        
        # Если максимальное расстояние больше epsilon, рекурсивно упрощаем
        if max_dist > epsilon:
            left = rdp_recursive(point_list[:max_idx + 1], epsilon)
            right = rdp_recursive(point_list[max_idx:], epsilon)
            return left[:-1] + right
        else:
            return [start, end]
    
    # Упрощаем путь
    simplified = rdp_recursive(points, tolerance)
    
    # Ограничиваем максимальную глубину рекурсии для очень длинных путей
    if len(simplified) < len(points) * 0.1:
        # Для очень длинных путей используем упрощенный подход
        step = max(1, len(points) // 1000)  # Ограничиваем до ~1000 точек
        simplified = points[::step]
        if points[-1] not in simplified:
            simplified.append(points[-1])
    
    return simplified

def enhance_skeleton_detection(binary_img):
    """Улучшает обнаружение скелета, чтобы включить все линии"""
    # Убираем шум
    cleaned = cv2.morphologyEx(binary_img, cv2.MORPH_OPEN, 
                             cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2, 2)))
    
    # Заполняем мелкие разрывы
    cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_CLOSE,
                             cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3)))
    
    return cleaned

def convert(png, svg):
    img = cv2.imread(png, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise Exception("Cannot read PNG")

    # Бинаризация
    _, binary = cv2.threshold(img, 128, 255, cv2.THRESH_BINARY_INV)
    
    # Улучшаем обнаружение скелета
    enhanced_binary = enhance_skeleton_detection(binary)
    
    # Находим непрерывный путь
    path_points = find_continuous_path(enhanced_binary)
    
    if not path_points:
        # Fallback: используем контуры
        contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if contours:
            path_points = [tuple(point[0]) for contour in contours for point in contour]
    
    if not path_points:
        raise Exception("No continuous path found")

    # Создаем SVG
    h, w = binary.shape
    
    if len(path_points) > 1:
        path_data = f"M {path_points[0][0]} {path_points[0][1]}"
        for x, y in path_points[1:]:
            path_data += f" L {x} {y}"
    else:
        path_data = f"M {path_points[0][0]} {path_points[0][1]}"
    
    with open(svg, "w") as f:
        f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">'
                f'<path d="{path_data}" stroke="black" fill="none" stroke-width="1"/></svg>')
        
    print("OK:", svg)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: converter.py input.png output.svg")
        sys.exit(1)
    
    # Увеличиваем лимит рекурсии для сложных изображений
    import sys
    sys.setrecursionlimit(10000)
    
    try:
        convert(sys.argv[1], sys.argv[2])
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)