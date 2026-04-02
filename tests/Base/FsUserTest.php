<?php
/*
 * This file is part of FS-Framework.
 *
 * Copyright (C) 2024 Your Organization
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/**
 * Tests para fs_user que no requieren conexión a base de datos.
 */

namespace Tests\Base;

use FSFramework\model\fs_user;
use PHPUnit\Framework\TestCase;

class FsUserTest extends TestCase
{
    private fs_user $user;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/core/fs_user.php';

        $reflection = new \ReflectionClass(fs_user::class);
        $this->user = $reflection->newInstanceWithoutConstructor();
        $this->user->log_key = null;
        $this->user->logged_on = false;

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    public function testNewLogkeyGeneratesSecureHexToken(): void
    {
        $this->user->new_logkey();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->user->log_key);
        $this->assertTrue($this->user->logged_on);
        $this->assertSame('127.0.0.1', $this->user->last_ip);
        $this->assertSame('PHPUnit', $this->user->last_browser);
    }

    public function testNewLogkeyRegeneratesTokenOutsideDemoMode(): void
    {
        $this->user->new_logkey();
        $firstToken = $this->user->log_key;

        $this->user->new_logkey();

        $this->assertNotSame($firstToken, $this->user->log_key);
    }
}