<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FSFramework\Translation;

use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Caches what another loader reads from a translation file as a PHP array
 * in tmp/, keyed by the file path and its mtime, so the file is parsed again
 * only when it changes. Parsing the YAML catalogues on every request was
 * about 40 % of a page request.
 */
final class CachedFileLoader implements LoaderInterface
{
    private const FORMAT = 'v1';

    public function __construct(
        private readonly LoaderInterface $loader,
        private readonly string $cacheDir,
    ) {
    }

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        $mtime = is_string($resource) ? @filemtime($resource) : false;
        if ($mtime === false) {
            return $this->loader->load($resource, $locale, $domain);
        }

        // mtime + size: two writes within the same second still change the key.
        // Bump FORMAT when the cached shape or a loader's parsing changes.
        $key = implode('|', [self::FORMAT, $this->loader::class, $resource, $locale, $domain, $mtime, (int) @filesize($resource)]);
        $file = $this->cacheDir . '/' . md5($key) . '.php';
        $messages = is_file($file) ? @include $file : null;

        if (!is_array($messages)) {
            $catalogue = $this->loader->load($resource, $locale, $domain);
            $messages = $catalogue->all();
            $this->write($file, $messages);
        }

        $catalogue = new MessageCatalogue($locale);
        foreach ($messages as $catalogueDomain => $domainMessages) {
            $catalogue->add($domainMessages, $catalogueDomain);
        }

        return $catalogue;
    }

    /**
     * @param array<string, array<string, string>> $messages
     */
    private function write(string $file, array $messages): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }

        // Atomic: a concurrent request reads either nothing or the whole file.
        $tmp = @tempnam($this->cacheDir, 'tr');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, '<?php return ' . var_export($messages, true) . ';') === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return;
        }
        @chmod($file, 0664);
    }
}
