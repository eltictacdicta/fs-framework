<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core;

/**
 * Barra de debug para modo desarrollo.
 *
 * Muestra en la parte inferior de la página información útil:
 * - Tiempo de ejecución
 * - Memoria usada
 * - Consultas SQL ejecutadas
 * - Traducciones no encontradas
 * - Logs de errores/mensajes
 *
 * Inspirado en FacturaScripts 2025 Core/DebugBar.php
 *
 * Se activa automáticamente cuando FS_DEBUG está definido y es true.
 * Se renderiza al final de cada página HTML.
 */
class DebugBar
{
    private static array $queries = [];
    private static array $logs = [];
    private static array $missingTranslations = [];
    private static float $startTime;
    private static int $startMemory;

    /**
     * Inicializa el contador de tiempo/memoria.
     */
    public static function init(): void
    {
        self::$queries = [];
        self::$logs = [];
        self::$missingTranslations = [];
        self::$startTime = defined('FS_START_TIME') ? FS_START_TIME : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        self::$startMemory = memory_get_usage();
    }

    /**
     * Registra una consulta SQL ejecutada.
     */
    public static function addQuery(string $sql, float $duration): void
    {
        self::$queries[] = ['sql' => $sql, 'duration' => $duration];
    }

    /**
     * Registra una entrada de log.
     */
    public static function addLog(string $level, string $message): void
    {
        self::$logs[] = ['level' => $level, 'message' => $message];
    }

    /**
     * Registra una traducción no encontrada.
     */
    public static function addMissingTranslation(string $key): void
    {
        self::$missingTranslations[] = $key;
    }

    /**
     * Renderiza la barra de debug HTML.
     * Solo se muestra cuando FS_DEBUG es true y la respuesta es HTML.
     */
    public static function render(): string
    {
        if (!defined('FS_DEBUG') || !FS_DEBUG) {
            return '';
        }

        $executionTime = round((microtime(true) - self::$startTime) * 1000, 2);
        $memoryPeak = round(memory_get_peak_usage() / 1024 / 1024, 2);
        $memoryCurrent = round((memory_get_usage() - self::$startMemory) / 1024 / 1024, 2);
        $queryCount = count(self::$queries);
        $totalQueryTime = 0;

        foreach (self::$queries as $q) {
            $totalQueryTime += $q['duration'];
        }

        $totalQueryTime = round($totalQueryTime * 1000, 2);

        $html = '<div id="fs-debugbar" style="
            position:fixed; bottom:0; left:0; right:0; z-index:99999;
            background:#2d2d2d; color:#ccc; font:12px monospace;
            border-top:2px solid #f0ad4e; max-height:40%;
            overflow:hidden; transition:max-height 0.3s;
        ">
            <div style="
                padding:4px 12px; cursor:pointer; background:#1a1a1a;
                display:flex; gap:16px; align-items:center;
                border-bottom:1px solid #444;
            " onclick="var db=document.getElementById(\'fs-debugbar\');
                var body=db.querySelector(\'.fs-debugbar-body\');
                if(body.style.display===\'none\'){body.style.display=\'block\';db.style.maxHeight=\'40%\';}
                else{body.style.display=\'none\';db.style.maxHeight=\'28px\';}">
                <strong style="color:#f0ad4e;">DEBUG</strong>
                <span>⏱ ' . $executionTime . ' ms</span>
                <span>📦 ' . $memoryCurrent . ' MB (peak ' . $memoryPeak . ' MB)</span>
                <span>🗄 ' . $queryCount . ' queries (' . $totalQueryTime . ' ms)</span>
                ' . (count(self::$logs) > 0 ? '<span>📋 ' . count(self::$logs) . ' logs</span>' : '') . '
                ' . (count(self::$missingTranslations) > 0 ? '<span>🌐 ' . count(self::$missingTranslations) . ' trans missing</span>' : '') . '
                <span style="margin-left:auto;opacity:0.5;">FSFramework</span>
            </div>
            <div class="fs-debugbar-body" style="display:none; overflow-y:auto; padding:8px 12px; max-height:calc(100% - 29px);">';

        if ($queryCount > 0) {
            $html .= '<details open><summary style="color:#5bc0de;cursor:pointer;">SQL Queries (' . $queryCount . ')</summary>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin-top:4px;">';
            foreach (self::$queries as $i => $q) {
                $html .= '<tr style="border-bottom:1px solid #333;">'
                    . '<td style="color:#888;padding:2px 4px;vertical-align:top;white-space:nowrap;">#' . ($i + 1) . '</td>'
                    . '<td style="padding:2px 4px;word-break:break-all;">' . htmlspecialchars($q['sql'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="color:#5cb85c;padding:2px 4px;white-space:nowrap;text-align:right;">' . round($q['duration'] * 1000, 2) . ' ms</td>'
                    . '</tr>';
            }
            $html .= '</table></details>';
        }

        if (count(self::$logs) > 0) {
            $html .= '<details><summary style="color:#d9534f;cursor:pointer;">Logs (' . count(self::$logs) . ')</summary>';
            $html .= '<div style="max-height:150px;overflow-y:auto;margin-top:4px;">';
            foreach (self::$logs as $log) {
                $color = match ($log['level']) {
                    'error' => '#d9534f',
                    'warning' => '#f0ad4e',
                    'info' => '#5bc0de',
                    default => '#ccc',
                };
                $html .= '<div style="padding:1px 0;border-bottom:1px solid #333;">'
                    . '<span style="color:' . $color . ';">[' . strtoupper($log['level']) . ']</span> '
                    . htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8')
                    . '</div>';
            }
            $html .= '</div></details>';
        }

        if (count(self::$missingTranslations) > 0) {
            $html .= '<details><summary style="color:#f0ad4e;cursor:pointer;">Missing Translations (' . count(self::$missingTranslations) . ')</summary>';
            $html .= '<div style="max-height:150px;overflow-y:auto;margin-top:4px;">';
            foreach (array_unique(self::$missingTranslations) as $key) {
                $html .= '<div style="padding:1px 0;border-bottom:1px solid #333;color:#f0ad4e;">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $html .= '</div></details>';
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Devuelve el HTML de la barra como string.
     * Alias de render().
     */
    public function __toString(): string
    {
        return self::render();
    }
}
