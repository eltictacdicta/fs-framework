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

namespace FSFramework\Core;

use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\CSSList\KeyFrame;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Parsing\UnexpectedEOFException;
use Sabberworm\CSS\Parsing\UnexpectedTokenException;
use Sabberworm\CSS\Property\Declaration;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Value\CSSFunction;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\URL;
use Sabberworm\CSS\Value\Value;
use Sabberworm\CSS\Value\ValueList;

class CssSanitizer
{
    private const ALLOWED_CSS_BLOCK_AT_RULES = [
        'media',
        'supports',
    ];

    private const ALLOWED_CSS_KEYFRAME_AT_RULES = [
        '-webkit-keyframes',
        'keyframes',
    ];

    private const ALLOWED_CSS_URL_PROPERTIES = [
        'background',
        'background-image',
        'cursor',
        'list-style',
        'list-style-image',
    ];

    public function sanitizeCss(string $css): ?string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        try {
            $document = (new CssParser($css))->parse();
        } catch (SourceException | UnexpectedEOFException | UnexpectedTokenException $exception) {
            return null;
        }

        if (!$this->sanitizeCssList($document)) {
            return null;
        }

        return trim($document->render(OutputFormat::createCompact()));
    }

    private function sanitizeCssList(CSSList $list): bool
    {
        foreach ($list->getContents() as $item) {
            if ($item instanceof DeclarationBlock) {
                if (!$this->sanitizeCssDeclarationBlock($item)) {
                    return false;
                }

                if ($item->getDeclarations() === [] || $item->getSelectors() === []) {
                    $list->remove($item);
                }

                continue;
            }

            if ($item instanceof AtRuleBlockList) {
                if (!in_array(strtolower($item->atRuleName()), self::ALLOWED_CSS_BLOCK_AT_RULES, true)) {
                    return false;
                }

                if (!$this->sanitizeCssList($item)) {
                    return false;
                }

                if ($item->getContents() === []) {
                    $list->remove($item);
                }

                continue;
            }

            if ($item instanceof KeyFrame) {
                if (!in_array(strtolower($item->atRuleName()), self::ALLOWED_CSS_KEYFRAME_AT_RULES, true)) {
                    return false;
                }

                if (!$this->sanitizeCssList($item)) {
                    return false;
                }

                if ($item->getContents() === []) {
                    $list->remove($item);
                }

                continue;
            }

            return false;
        }

        return true;
    }

    private function sanitizeCssDeclarationBlock(DeclarationBlock $block): bool
    {
        foreach ($block->getDeclarations() as $declaration) {
            if (!$this->isAllowedCssDeclaration($declaration)) {
                return false;
            }
        }

        return true;
    }

    private function isAllowedCssDeclaration(Declaration $declaration): bool
    {
        $property = strtolower($declaration->getPropertyName());
        if (!$this->isAllowedCssProperty($property)) {
            return false;
        }

        $value = $declaration->getValue();
        if ($this->containsCssUrlValue($value) && !in_array($property, self::ALLOWED_CSS_URL_PROPERTIES, true)) {
            return false;
        }

        return $this->isSafeCssValue($value);
    }

    private function isAllowedCssProperty(string $property): bool
    {
        return preg_match(
            '/^(--[a-z0-9_-]+|align-(content|items|self)|animation(-[a-z]+)?|background(-[a-z]+)?|border(-[a-z]+)?|bottom|box-shadow|color|column-gap|content|cursor|display|flex(-[a-z]+)?|font(-[a-z]+)?|gap|grid(-[a-z]+)?|height|justify-content|left|letter-spacing|line-height|list-style(-[a-z]+)?|margin(-[a-z]+)?|max-(height|width)|min-(height|width)|object-(fit|position)|opacity|outline(-[a-z]+)?|overflow(-[xy])?|padding(-[a-z]+)?|pointer-events|position|right|row-gap|text(-[a-z]+)?|top|transform|transition(-[a-z]+)?|visibility|white-space|width|word-spacing|z-index)$/',
            $property
        ) === 1;
    }

    private function containsCssUrlValue(mixed $value): bool
    {
        if ($value instanceof URL) {
            return true;
        }

        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $component) {
                if ($this->containsCssUrlValue($component)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isSafeCssValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_string($value)) {
            return !$this->containsUnsafeCssToken($value);
        }

        if ($value instanceof URL) {
            return $this->isSafeCssUrl($value->getURL()->getString());
        }

        if ($value instanceof CSSString) {
            return !$this->containsUnsafeCssToken($value->getString());
        }

        if ($value instanceof CSSFunction && strtolower($value->getName()) === 'expression') {
            return false;
        }

        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $component) {
                if (!$this->isSafeCssValue($component)) {
                    return false;
                }
            }

            return true;
        }

        if ($value instanceof Value) {
            return !$this->containsUnsafeCssToken($value->render(OutputFormat::createCompact()));
        }

        return true;
    }

    private function containsUnsafeCssToken(string $value): bool
    {
        $normalized = strtolower(rawurldecode($value));

        return str_contains($normalized, '@import')
            || str_contains($normalized, 'expression(')
            || str_contains($normalized, 'javascript:')
            || str_contains($normalized, 'vbscript:')
            || str_contains($normalized, 'data:');
    }

    private function isSafeCssUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $decodedUrl = strtolower(rawurldecode($url));
        if (str_starts_with($decodedUrl, 'javascript:')
            || str_starts_with($decodedUrl, 'data:')
            || str_starts_with($decodedUrl, 'vbscript:')) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme === null) {
            return $host === null;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
