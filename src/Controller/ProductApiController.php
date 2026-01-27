<?php

namespace FSFramework\Controller;

use FSFramework\Attribute\FSRoute;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for managing products.
 * 
 * This controller provides RESTful API endpoints for product management.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
#[FSRoute('/api/products', name: 'api_products_')]
class ProductApiController extends BaseController
{
    /**
     * List all products.
     * 
     * @return JsonResponse JSON response with products list
     */
    #[FSRoute('/', methods: ['GET'], name: 'list')]
    public function listProducts(Request $request)
    {
        $products = [
            ['id' => 1, 'name' => 'Product 1', 'price' => 19.99],
            ['id' => 2, 'name' => 'Product 2', 'price' => 29.99],
            ['id' => 3, 'name' => 'Product 3', 'price' => 39.99]
        ];
        
        return $this->json($products);
    }

    /**
     * Show a single product.
     * 
     * @param int $id Product ID
     * @return JsonResponse JSON response with product details
     */
    #[FSRoute('/{id}', methods: ['GET'], requirements: ['id' => '\d+'], name: 'show')]
    public function showProduct(Request $request, int $id)
    {
        $product = ['id' => $id, 'name' => 'Product ' . $id, 'price' => $id * 10.99];
        return $this->json($product);
    }

    /**
     * Create a new product.
     * 
     * @return JsonResponse JSON response with created product
     */
    #[FSRoute('/', methods: ['POST'], name: 'create')]
    public function createProduct(Request $request)
    {
        $data = $request->request->all();
        
        $product = [
            'id' => 4,
            'name' => $data['name'] ?? 'New Product',
            'price' => $data['price'] ?? 0.00
        ];
        
        return $this->json($product, 201);
    }

    /**
     * Update a product.
     * 
     * @param int $id Product ID
     * @return JsonResponse JSON response with updated product
     */
    #[FSRoute('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'], name: 'update')]
    public function updateProduct(Request $request, int $id)
    {
        $data = $request->request->all();
        
        $product = [
            'id' => $id,
            'name' => $data['name'] ?? 'Product ' . $id,
            'price' => $data['price'] ?? $id * 10.99
        ];
        
        return $this->json($product);
    }

    /**
     * Delete a product.
     * 
     * @param int $id Product ID
     * @return JsonResponse JSON response with success message
     */
    #[FSRoute('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'], name: 'delete')]
    public function deleteProduct(Request $request, int $id)
    {
        return $this->json(['message' => 'Product ' . $id . ' deleted successfully']);
    }
}