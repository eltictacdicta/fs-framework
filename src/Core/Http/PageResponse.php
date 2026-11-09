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
namespace FSFramework\Core\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Response for the ?page= dispatch. Sending it runs the page flow, which
 * writes its own headers and output (downloads and progress streams
 * included) and may exit; this response adds no headers of its own.
 */
final class PageResponse extends StreamedResponse
{
    public function sendHeaders(?int $statusCode = null): static
    {
        return $this;
    }
}
