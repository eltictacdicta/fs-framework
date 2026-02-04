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

namespace FacturaScripts\Plugins\legacy_support\Template;

/**
 * RainToTwig
 * ----------
 * Translates RainTPL syntax to Twig syntax to maintain backward compatibility.
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
        // 0. Normalize HTML entities inside RainTPL tags
        // Some templates use &quot; instead of " inside RainTPL syntax
        $content = preg_replace_callback('/\{([a-z]+)=&quot;(.+?)&quot;\}/', function ($matches) {
            $tag = $matches[1];
            $value = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
            return '{' . $tag . '="' . $value . '"}';
        }, $content);
        
        // 1. Comments
        $content = preg_replace('/{\*(.*?)\*}/s', '{# $1 #}', $content);
        $content = preg_replace('/{ignore}(.*? ){\/ignore}/s', '{# $1 #}', $content);

        // 2. Noparse (verbatim)
        $content = preg_replace('/{noparse}(.*? ){\/noparse}/s', '{% verbatim %}$1{% endverbatim %}', $content);

        // 3. Includes
        // {include="header"} -> {{ include('header.html') }}
        // {include="$var"} -> {{ include(var) }}
        $content = preg_replace_callback('/{include="([^"]+)"}/', function ($matches) {
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

        // 4. Loops
        // We need to track nesting level to support legacy variable naming (value1, value2, etc.)
        $loopLevel = 0;
        $content = preg_replace_callback(
            '/(?:{loop="(?<variable>\${0,1}[^"]*)"(?: as (?<key>\$.*?)(?: => (?<value>\$.*?)){0,1}){0,1}})|(?<close>{\/loop})/',
            function ($matches) use (&$loopLevel) {
                // Formatting helper
                if (!empty($matches['close'])) {
                    $loopLevel--;
                    return "{% endfor %}";
                }

                // It's a loop start
                $loopLevel++;
                $var = $matches['variable'];
                
                // Check for C-style for loop: {loop="$i=1;$i<=N;$i++"}
                // Pattern: $var=start;$var<=end;$var++ or $var<end
                // Example: $page_num=1;$page_num<=$fsc->total_pages;$page_num++
                if (preg_match('/^\$(\w+)\s*=\s*(\d+)\s*;\s*\$\1\s*(<=?)\s*([^;]+)\s*;\s*\$\1\+\+$/', $var, $forMatch)) {
                    $loopVar = $forMatch[1];
                    $start = $forMatch[2];
                    $operator = $forMatch[3];
                    $endExpr = self::translateExpression(trim($forMatch[4]));
                    
                    // For <= we use the value directly, for < we subtract 1
                    if ($operator === '<=') {
                        return "{% for $loopVar in range($start, $endExpr) %}";
                    } else {
                        // < means we need end - 1
                        return "{% for $loopVar in range($start, $endExpr - 1) %}";
                    }
                }
                
                $var = self::translateExpression($var);

                // Explicit syntax: {loop="$list" as $key => $val}
                if (!empty($matches['key']) && !empty($matches['value'])) {
                    $key = str_replace('$', '', $matches['key']);
                    $val = str_replace('$', '', $matches['value']);
                    return "{% for $key, $val in $var %}";
                } elseif (!empty($matches['key'])) {
                    // Explicit syntax: {loop="$list" as $val}
                    $val = str_replace('$', '', $matches['key']);
                    return "{% for $val in $var %}";
                }

                // Implicit syntax: {loop="$list"}
                // Generate legacy variable names based on depth: value1, value2...
                $val = "value" . $loopLevel;
                $key = "key" . $loopLevel;

                // We define BOTH the numbered variables (value1) AND the standard ones (value)
                return "{% for $key, $val in $var %}{% set value = $val %}{% set key = $key %}";
            },
            $content
        );

        $content = str_replace('{break}', '{% break %}', $content); // Requires Twig Switch/Break extension usually
        $content = str_replace('{continue}', '{% continue %}', $content);

        // 5. Conditions
        // {if="$a == 1"} -> {% if a == 1 %}
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

        // 6. Variables and Functions
        // {$var} -> {{ var }}
        // {$fsc->url()} -> {{ fsc.url() }}
        // {function="name(args)"} -> {{ name(args) }}
        // {$var=$val} -> {% set var = val %}
        // {$var+=$val} -> {% set var = var + val %}
        // {$var|filter} -> {{ var|filter }} (with filter translation)
        $content = preg_replace_callback('/{\$([a-zA-Z_][^{}]*)}/', function ($matches) {
            $expr = self::translateExpression('$' . $matches[1]);

            // Check for compound assignment: var += value, var -= value, etc.
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*\+=\s*(.*)$/', $expr, $compoundMatch)) {
                $var = $compoundMatch[1];
                $val = $compoundMatch[2];
                return "{% set $var = $var + $val %}";
            }
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*-=\s*(.*)$/', $expr, $compoundMatch)) {
                $var = $compoundMatch[1];
                $val = $compoundMatch[2];
                return "{% set $var = $var - $val %}";
            }
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*\*=\s*(.*)$/', $expr, $compoundMatch)) {
                $var = $compoundMatch[1];
                $val = $compoundMatch[2];
                return "{% set $var = $var * $val %}";
            }
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*\/=\s*(.*)$/', $expr, $compoundMatch)) {
                $var = $compoundMatch[1];
                $val = $compoundMatch[2];
                return "{% set $var = $var / $val %}";
            }
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*\.=\s*(.*)$/', $expr, $compoundMatch)) {
                // .= is string concatenation in PHP, use ~ in Twig
                $var = $compoundMatch[1];
                $val = $compoundMatch[2];
                return "{% set $var = $var ~ $val %}";
            }

            // Check for simple assignment: var = value
            if (preg_match('/^([a-zA-Z0-9_\.]+)\s*=\s*(.*)$/', $expr, $assignMatch)) {
                $var = $assignMatch[1];
                $val = $assignMatch[2];
                return "{% set $var = $val %}";
            }

            // Translate RainTPL filters to Twig filters
            $expr = self::translateFilters($expr);

            return "{{ $expr|raw }}";
        }, $content);

        // Handle {function="..."} - supports both plain functions and method calls
        // Pattern 1: {function="funcName(args)"} - plain function
        // Pattern 2: {function="$obj->method(args)"} - method call on object
        $content = preg_replace_callback('/{function="([^"]+)"}/', function ($matches) {
            $expr = $matches[1];
            
            // Check if it's a method call: $obj->method(args)
            if (preg_match('/^\$([a-zA-Z_][a-zA-Z_0-9]*)((?:->[a-zA-Z_][a-zA-Z_0-9]*)+)\s*\(([^)]*)\)$/', $expr, $methodMatch)) {
                $obj = $methodMatch[1];
                $methodChain = str_replace('->', '.', $methodMatch[2]);
                $args = self::translateExpression($methodMatch[3]);
                return "{{ {$obj}{$methodChain}({$args})|raw }}";
            }
            
            // Plain function call: funcName(args)
            if (preg_match('/^([a-zA-Z_][a-zA-Z_0-9]*)\s*\(([^)]*)\)$/', $expr, $funcMatch)) {
                $func = $funcMatch[1];
                $args = $funcMatch[2];
                $translatedArgs = self::translateExpression($args);
                
                // Special handling for PHP functions that have Twig equivalents
                // addslashes() -> escape('js') filter (escapes for JavaScript strings)
                if ($func === 'addslashes') {
                    return "{{ {$translatedArgs}|escape('js') }}";
                }
                
                // htmlspecialchars() -> escape filter
                if ($func === 'htmlspecialchars') {
                    return "{{ {$translatedArgs}|escape }}";
                }
                
                // strip_tags() -> striptags filter
                if ($func === 'strip_tags') {
                    return "{{ {$translatedArgs}|striptags }}";
                }
                
                // nl2br() -> nl2br filter
                if ($func === 'nl2br') {
                    return "{{ {$translatedArgs}|nl2br }}";
                }
                
                // json_encode() -> json_encode filter
                if ($func === 'json_encode') {
                    return "{{ {$translatedArgs}|json_encode|raw }}";
                }
                
                // urlencode() -> url_encode filter
                if ($func === 'urlencode') {
                    return "{{ {$translatedArgs}|url_encode }}";
                }
                
                // Default: call function as-is
                return "{{ {$func}({$translatedArgs})|raw }}";
            }
            
            // Fallback: translate the entire expression as-is
            $translatedExpr = self::translateExpression($expr);
            return "{{ ({$translatedExpr})|raw }}";
        }, $content);

        // 7. Constants
        // {#CONST#} -> {{ constant('CONST') }}
        $content = preg_replace('/{#([a-zA-Z_][a-zA-Z0-9_]*)#{0,1}}/', "{{ constant('$1') }}", $content);

        return $content;
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
