<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Attribute;

/**
 * Marks a page controller as administrator-only.
 *
 * Valid on legacy `fs_controller` subclasses and on modern
 * `FSFramework\Core\Base\Controller` subclasses. The declaration is resolved
 * by attribute *name string* only (never `newInstance()`), so legacy plugin
 * controllers with unreliable namespaced autoloading stay safe.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AdminOnly
{
}
