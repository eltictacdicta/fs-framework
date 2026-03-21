<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Plugins\legacy_support\Template;

/**
 * RainToTwig
 * ----------
 * Translates RainTPL syntax to Twig syntax to maintain backward compatibility.
 *
 * @deprecated Será retirado en v3.0. Migrar vistas RainTPL a Twig nativo.
 */
class RainToTwig
{
    /**
     * Translates a RainTPL string to Twig.
     *
     * @param string $content
     * @return string
     */
    public static function translate(string $content): string
    {
        $content = self::normalizeEntities($content);
        $content = self::translateComments($content);
        $content = self::translateIncludes($content);
        $content = self::translateLoops($content);
        $content = self::translateConditionals($content);
        $content = self::translateVariables($content);
        $content = self::translateFunctionTags($content);
        $content = self::translateConstants($content);

        return $content;
    }

    private static function normalizeEntities(string $content): string
    {
        return preg_replace_callback('/\{([a-z]+)=&quot;(.+?)&quot;\}/', function ($matches) {
            $tag = $matches[1];
            $value = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
            return '{' . $tag . '="' . $value . '"}';
        }, $content);
    }

    private static function translateComments(string $content): string
    {
        $content = preg_replace('/{\*(.*?)\*}/s', '{# $1 #}', $content);
        $content = preg_replace('/{ignore}(.*?){\/ignore}/s', '{# $1 #}', $content);
        $content = preg_replace('/{noparse}(.*?){\/noparse}/s', '{% verbatim %}$1{% endverbatim %}', $content);

        return $content;
    }

    private static function translateIncludes(string $content): string
    {
        return preg_replace_callback('/{include="([^"]+)"}/', function ($matches) {
            $file = $matches[1];
            if (str_starts_with($file, '$')) {
                $expr = self::translateExpression($file);
                return "{{ include(auto_ext($expr)) }}";
            }

            if (!str_contains($file, '.')) {
                $file .= '.html';
            }
            return "{{ include('$file') }}";
        }, $content);
    }

    private static function translateLoops(string $content): string
    {
        $loopLevel = 0;
        $content = preg_replace_callback(
            '/(?:{loop="(?<variable>\${0,1}[^"]*)"(?: as (?<key>\$.*?)(?: => (?<value>\$.*?)){0,1}){0,1}})|(?<close>{\/loop})/',
            function ($matches) use (&$loopLevel) {
                if (!empty($matches['close'])) {
                    $loopLevel--;
                    return "{% endfor %}";
                }

                $loopLevel++;
                $var = $matches['variable'];

                $cStyleResult = self::tryCStyleLoop($var);
                if ($cStyleResult !== null) {
                    return $cStyleResult;
                }

                $var = self::translateExpression($var);

                if (!empty($matches['key']) && !empty($matches['value'])) {
                    $key = str_replace('$', '', $matches['key']);
                    $val = str_replace('$', '', $matches['value']);
                    return "{% for $key, $val in $var %}";
                }

                if (!empty($matches['key'])) {
                    $val = str_replace('$', '', $matches['key']);
                    return "{% for $val in $var %}";
                }

                $val = "value" . $loopLevel;
                $key = "key" . $loopLevel;
                return "{% for $key, $val in $var %}{% set value = $val %}{% set key = $key %}";
            },
            $content
        );

        $content = str_replace('{break}', '{% break %}', $content);
        $content = str_replace('{continue}', '{% continue %}', $content);

        return $content;
    }

    private static function tryCStyleLoop(string $var): ?string
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(\d+)\s*;\s*\$\1\s*(<=?)\s*([^;]+)\s*;\s*\$\1\+\+$/', $var, $forMatch)) {
            return null;
        }

        $loopVar = $forMatch[1];
        $start = $forMatch[2];
        $operator = $forMatch[3];
        $endExpr = self::translateExpression(trim($forMatch[4]));

        if ($operator === '<=') {
            return "{% for $loopVar in range($start, $endExpr) %}";
        }

        return "{% for $loopVar in range($start, $endExpr - 1) %}";
    }

    private static function translateConditionals(string $content): string
    {
        $content = preg_replace_callback('/{if(?: condition)?="([^"]*)"}/', function ($matches) {
            $cond = self::translateExpression($matches[1]);
            return "{% if $cond %}";
        }, $content);
        $content = preg_replace_callback('/{elseif="([^"]*)"}/', function ($matches) {
            $cond = self::translateExpression($matches[1]);
            return "{% elseif $cond %}";
        }, $content);
        $content = str_replace('{else}', '{% else %}', $content);
        $content = str_replace('{/if}', '{% endif %}', $content);

        return $content;
    }

    private static function translateVariables(string $content): string
    {
        return preg_replace_callback('/{\$([a-zA-Z_][^{}]*)}/', function ($matches) {
            $expr = self::translateExpression('$' . $matches[1]);

            $assignment = self::tryCompoundAssignment($expr);
            if ($assignment !== null) {
                return $assignment;
            }

            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*=\s*(.*)$/', $expr, $assignMatch)) {
                return "{% set {$assignMatch[1]} = {$assignMatch[2]} %}";
            }

            $expr = self::translateFilters($expr);
            return "{{ $expr|raw }}";
        }, $content);
    }

    private static function tryCompoundAssignment(string $expr): ?string
    {
        $operators = [
            '+=' => '+',
            '-=' => '-',
            '*=' => '*',
            '/=' => '/',
            '.=' => '~',
        ];

        foreach ($operators as $compound => $twig) {
            $escaped = preg_quote($compound, '/');
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*' . $escaped . '\s*(.*)$/', $expr, $m)) {
                return "{% set {$m[1]} = {$m[1]} $twig {$m[2]} %}";
            }
        }

        return null;
    }

    private static function translateFunctionTags(string $content): string
    {
        return preg_replace_callback('/{function="([^"]+)"}/', function ($matches) {
            $expr = $matches[1];

            if (preg_match('/^\$([a-zA-Z_][a-zA-Z_0-9]*)((?:->[a-zA-Z_][a-zA-Z_0-9]*)+)\s*\(([^)]*)\)$/', $expr, $methodMatch)) {
                $obj = $methodMatch[1];
                $methodChain = str_replace('->', '.', $methodMatch[2]);
                $args = self::translateExpression($methodMatch[3]);
                return "{{ {$obj}{$methodChain}({$args})|raw }}";
            }

            if (preg_match('/^([a-zA-Z_][a-zA-Z_0-9]*)\s*\(([^)]*)\)$/', $expr, $funcMatch)) {
                return self::translatePhpFunction($funcMatch[1], $funcMatch[2]);
            }

            $translatedExpr = self::translateExpression($expr);
            return "{{ ({$translatedExpr})|raw }}";
        }, $content);
    }

    private static function translatePhpFunction(string $func, string $rawArgs): string
    {
        $translatedArgs = self::translateExpression($rawArgs);

        $filterMap = [
            'addslashes'       => "{{ {ARG}|escape('js') }}",
            'htmlspecialchars' => "{{ {ARG}|escape }}",
            'strip_tags'       => "{{ {ARG}|striptags }}",
            'nl2br'            => "{{ {ARG}|nl2br }}",
            'json_encode'      => "{{ {ARG}|json_encode|raw }}",
            'urlencode'        => "{{ {ARG}|url_encode }}",
        ];

        if (isset($filterMap[$func])) {
            return str_replace('{ARG}', $translatedArgs, $filterMap[$func]);
        }

        return "{{ {$func}({$translatedArgs})|raw }}";
    }

    private static function translateConstants(string $content): string
    {
        return preg_replace('/{#([a-zA-Z_][a-zA-Z0-9_]*)#{0,1}}/', "{{ constant('$1') }}", $content);
    }

    /**
     * Translates RainTPL filters to Twig filters.
     * 
     * @param string $expr
     * @return string
     */
    protected static function translateFilters(string $expr): string
    {
        // Map RainTPL filters to Twig equivalents
        $filterMap = [
            'count' => 'length',
            'sizeof' => 'length',
            'upper' => 'upper',
            'lower' => 'lower',
            'capitalize' => 'capitalize',
            'ucfirst' => 'capitalize',
            'strtoupper' => 'upper',
            'strtolower' => 'lower',
            'nl2br' => 'nl2br',
            'escape' => 'escape',
            'e' => 'escape',
            'trim' => 'trim',
            'strip_tags' => 'striptags',
            'json_encode' => 'json_encode',
            'json' => 'json_encode',
            'reverse' => 'reverse',
            'sort' => 'sort',
            'keys' => 'keys',
            'values' => 'values',
            'first' => 'first',
            'last' => 'last',
            'join' => 'join',
            'split' => 'split',
            'default' => 'default',
            'date' => 'date',
            'abs' => 'abs',
            'round' => 'round',
            'floor' => 'floor',
            'ceil' => 'ceil',
            'number_format' => 'number_format',
        ];

        // Replace filters in the expression
        foreach ($filterMap as $rainFilter => $twigFilter) {
            // Match filter at end of expression or followed by another filter
            $expr = preg_replace('/\|' . preg_quote($rainFilter, '/') . '(?=\||$)/', '|' . $twigFilter, $expr);
        }

        return $expr;
    }

    /**
     * Translates a RainTPL expression (inside {if} or {$...}) to Twig.
     * 
     * @param string $expr
     * @return string
     */
    protected static function translateExpression(string $expr): string
    {
        // Remove $ from variables
        $expr = preg_replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '$1', $expr);

        // Translate -> to .
        $expr = str_replace('->', '.', $expr);

        // Translate PHP concatenation . to Twig ~
        // Handle concatenation with spaces: " . "
        $expr = str_replace(' . ', ' ~ ', $expr);

        // Handle concatenation between strings and variables/constants without spaces
        // Pattern: string followed by . followed by variable/constant or vice versa
        // Examples: 'text'.$var, $var.'text', 'text'.CONST, CONST.'text'
        // Also handles: FS_MYDOCS.'images/logo.png' -> FS_MYDOCS ~ 'images/logo.png'
        $expr = preg_replace("/(['\"])\.([A-Za-z_])/", '$1 ~ $2', $expr);
        $expr = preg_replace("/([A-Za-z0-9_\)])\.(['\"])/", '$1 ~ $2', $expr);

        // Translate PHP negation ! to Twig's not (but not !== or !=)
        $expr = preg_replace('/!(?!=)/', 'not ', $expr);

        // Translate PHP logical operators to Twig
        $expr = str_replace('&&', 'and', $expr);
        $expr = str_replace('||', 'or', $expr);
        $expr = str_replace(' AND ', ' and ', $expr);
        $expr = str_replace(' OR ', ' or ', $expr);

        return trim($expr);
    }

}
