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

namespace FSFramework\Plugins\legacy_support;

/**
 * @deprecated Usar LegacyUsageTracker.
 */
require_once __DIR__ . '/LegacyUsageTracker.php';

if (!class_exists(__NAMESPACE__ . '\\LegacyTelemetry', false)) {
	class_alias(LegacyUsageTracker::class, __NAMESPACE__ . '\\LegacyTelemetry');
}

