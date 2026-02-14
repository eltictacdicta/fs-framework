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

namespace FacturaScripts\Core;

/**
 * Bridge for FacturaScripts UploadedFile.
 * Provides helper methods for file upload limits as used by FS2025 plugins.
 */
class UploadedFile
{
    /**
     * Get the maximum file size that can be uploaded in bytes.
     * @return int
     */
    public static function getMaxFilesize(): int
    {
        $maxUpload = self::parseSize(ini_get('upload_max_filesize'));
        $maxPost = self::parseSize(ini_get('post_max_size'));
        $memoryLimit = self::parseSize(ini_get('memory_limit'));

        // Return the minimum of these three limits
        return min($maxUpload, $maxPost, $memoryLimit);
    }

    /**
     * Parse a size string (e.g., "8M", "1G") to bytes.
     * @param string $size
     * @return int
     */
    private static function parseSize(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int) $size;

        switch ($last) {
            case 'g':
                $value *= 1024;
            // fall through
            case 'm':
                $value *= 1024;
            // fall through
            case 'k':
                $value *= 1024;
                break;
            default:
                break;
        }

        return $value;
    }
}
