<?php
namespace FSFramework\Controller;

use FSFramework\Attribute\FSRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Base\Controller;

#[FSRoute('/test-modern', methods: ['GET'], name: 'test_modern')]
class TestModernController extends Controller
{
    public function getPageData(): array
    {
        return [
            'name' => 'test_modern',
            'title' => 'Modernization Success!',
            'menu' => 'admin',
            'showonmenu' => true,
            'ordernum' => 1
        ];
    }

    public function handle(Request $request): Response
    {
        // El Kernel ya ha cargado los plugins en boot()
        $content = Html::render('test.html.twig', [
            'title' => $this->getPageData()['title'],
            'message' => 'This page is rendered using Twig and Tailwind CSS bridge.'
        ]);

        return new Response($content);
    }
}
