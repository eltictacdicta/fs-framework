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
 * Marks a page controller as self-service: any logged-in user may open it,
 * without a role grant. Use it only for pages that act on the current user
 * alone (their own sessions, their own preferences). #[AdminOnly] wins if both
 * are present.
 *
 * Valid on legacy `fs_controller` subclasses, resolved by attribute name
 * string only, like {@see AdminOnly}.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class SelfService
{
}
