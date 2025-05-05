<?php
/**
 * Controller for the business_data plugin
 */

namespace FSFramework\Plugin\BusinessData\Controller;

use FSFramework\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use FSFramework\Plugin\BusinessData\Model\Empresa;
use FSFramework\Plugin\BusinessData\Model\Pais;
use FSFramework\Plugin\BusinessData\Model\Serie;
use FSFramework\Plugin\BusinessData\Model\Almacen;
use FSFramework\Plugin\BusinessData\Model\Agente;

/**
 * Controller for business data management
 */
class BusinessDataController extends BaseController
{
    #[Route('/business-data', name: 'business_data_index')]
    public function index(): Response
    {
        // Get data from models
        $empresa = new Empresa();
        $paises = (new Pais())->all();
        $series = (new Serie())->all();
        $almacenes = (new Almacen())->all();
        $agentes = (new Agente())->all();
        
        // Render the template
        return $this->render('base.html.twig', [
            'title' => 'Business Data Plugin',
            'message' => 'Este plugin contiene funcionalidades relacionadas con datos empresariales',
            'items' => [
                'Empresas: ' . ($empresa->nombre ?? 'No configurada'),
                'Países: ' . count($paises),
                'Series: ' . count($series),
                'Almacenes: ' . count($almacenes),
                'Agentes: ' . count($agentes)
            ]
        ]);
    }
}
