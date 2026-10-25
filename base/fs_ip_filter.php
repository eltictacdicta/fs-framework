<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

/**
 * Description of fs_ip_filter
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_ip_filter
{

    const BAN_SECONDS = 600;
    const MAX_ATTEMPTS = 5;

    /**
     * Optional bucket namespace.
     *
     * Lockout state is a security control: the authentication lockout bucket
     * (`tmp/<FS_TMP_NAME>ip.log`) may only be written by credential-submission
     * handlers. Any other throttling feature owns its state file by passing
     * its own namespace here, so exhausting one quota can never deny another
     * feature.
     */
    private const DEFAULT_NAMESPACE = '';

    /**
     *
     * @var string
     */
    private $filePath;

    /**
     *
     * @var array
     */
    private $ipList;

    /**
     * @param string $namespace optional bucket name; the empty namespace keeps
     *                          resolving to the authentication lockout bucket
     */
    public function __construct(string $namespace = self::DEFAULT_NAMESPACE)
    {
        $this->filePath = FS_FOLDER . '/tmp/' . FS_TMP_NAME . $this->namespaceSuffix($namespace) . 'ip.log';
        $this->ipList = [];

        if (file_exists($this->filePath)) {
            /// Read IP list file
            $file = fopen($this->filePath, 'rb');
            if ($file) {
                while (!feof($file)) {
                    $raw = fgets($file);
                    if ($raw === false) {
                        break;
                    }
                    $line = explode(';', trim($raw));
                    $this->read_line($line);
                }

                fclose($file);
            }
        }
    }

    /**
     * Sanitised file-name prefix for a bucket namespace.
     *
     * The namespace is lower-cased and stripped of everything outside
     * `[a-z0-9_]`, so a namespace derived from a non-literal value can never
     * introduce a path separator or escape `tmp/`. The empty namespace yields
     * an empty suffix, which keeps the authentication bucket byte-identical.
     */
    private function namespaceSuffix(string $namespace): string
    {
        if ($namespace === self::DEFAULT_NAMESPACE) {
            return '';
        }

        return preg_replace('/[^a-z0-9_]/', '', strtolower($namespace)) . '_';
    }

    /**
     * Clear IP list.
     */
    public function clear()
    {
        $this->ipList = [];
        $this->save();
    }

    /**
     * 
     * @param string $ip
     *
     * @return boolean
     */
    public function in_white_list($ip)
    {
        $whitelist = defined('FS_IP_WHITELIST') ? (string) FS_IP_WHITELIST : '*';

        if ($whitelist === '*' || $whitelist === '') {
            return TRUE;
        }

        $aux = array_map('trim', explode(',', $whitelist));
        return in_array($ip, $aux, true);
    }

    /**
     * 
     * @param string $ip
     *
     * @return boolean
     */
    public function is_banned($ip)
    {
        foreach ($this->ipList as $line) {
            if ($line['ip'] == $ip && $line['count'] > self::MAX_ATTEMPTS) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * 
     * @param string $ip
     */
    public function set_attempt($ip)
    {
        $found = FALSE;
        foreach ($this->ipList as $key => $line) {
            if ($line['ip'] == $ip) {
                $this->ipList[$key]['count'] ++;
                $this->ipList[$key]['expire'] = time() + self::BAN_SECONDS;
                $found = TRUE;
                break;
            }
        }

        if (!$found) {
            $this->ipList[] = [
                'ip' => $ip,
                'count' => 1,
                'expire' => time() + self::BAN_SECONDS
            ];
        }

        $this->save();
    }

    /**
     * 
     * @param array $line
     */
    private function read_line($line)
    {
        /// if not expired
        if (count($line) == 3 && intval($line[2]) > time()) {
            $this->ipList[] = [
                'ip' => $line[0],
                'count' => (int) $line[1],
                'expire' => (int) $line[2]
            ];
        }
    }

    private function save()
    {
        $file = fopen($this->filePath, 'wb');
        if ($file) {
            foreach ($this->ipList as $line) {
                fwrite($file, $line['ip'] . ';' . $line['count'] . ';' . $line['expire'] . "\n");
            }

            fclose($file);
        }
    }
}
