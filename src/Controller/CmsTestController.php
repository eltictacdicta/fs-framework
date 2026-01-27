<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for CMS test route.
 * 
 * This controller provides a simple test endpoint to verify
 * that the Symfony routing is working correctly.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class CmsTestController extends BaseController
{
    /**
     * Test endpoint to verify routing is working.
     * 
     * @param Request $request The HTTP request
     * @return JsonResponse JSON response with test data
     */
    public function index(Request $request): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => 'Global Symfony Routing is working!',
            'type' => 'CMS Core'
        ]);
    }
}
