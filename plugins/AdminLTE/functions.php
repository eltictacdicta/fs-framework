<?php
/*
 * @author Carlos García Gómez      neorazorx@gmail.com
 * @copyright 2016-2018, Carlos García Gómez. All Rights Reserved. 
 */

if (!function_exists('get_gravatar')) {

    function get_gravatar($email, $size = 80)
    {
        return "https://www.gravatar.com/avatar/" . md5(strtolower(trim($email))) . "?s=" . $size;
    }
}

if (!function_exists('adminlte_menu_icon')) {

    function adminlte_menu_icon($value)
    {
        $icon = '<i class="fa fa-th-large fa-fw"></i>';
        switch ($value) {
            case 'admin':
                $icon = '<i class="fa fa-flask fa-fw"></i>';
                break;

            case 'compras':
                $icon = '<i class="fa fa-ship fa-fw"></i>';
                break;

            case 'contabilidad':
                $icon = '<i class="fa fa-balance-scale fa-fw"></i>';
                break;

            case 'CRM':
                $icon = '<i class="fa fa-address-book fa-fw"></i>';
                break;

            case 'Expedientes':
                $icon = '<i class="fa fa-suitcase fa-fw"></i>';
                break;

            case 'informes':
                $icon = '<i class="fa fa-bar-chart-o fa-fw"></i>';
                break;

            case 'TPV':
                $icon = '<i class="fa fa-cc-visa fa-fw"></i>';
                break;

            case 'ventas':
                $icon = '<i class="fa fa-shopping-cart fa-fw"></i>';
                break;
        }
        return $icon;
    }
}

if (!function_exists('adminlte_page_icon')) {

    function adminlte_page_icon($value)
    {
        $icon = '<i class="fa fa-circle-o fa-fw"></i>';
        if ($value->showing()) {
            $icon = '<i class="fa fa-check-circle fa-fw"></i>';
        }
        switch ($value->name) {
            case 'admin_empresa':
                $icon = '<i class="fa fa-suitcase fa-fw"></i>';
                break;

            case 'admin_home':
                $icon = '<i class="fa fa-cogs fa-fw"></i>';
                break;

            case 'admin_prestashop':
            case 'pedidos_prestashop':
                $icon = '<i class="fa fa-shopping-bag fa-fw"></i>';
                break;

            case 'admin_users':
            case 'admin_agentes':
            case 'compras_proveedores':
            case 'ventas_clientes':
                $icon = '<i class="fa fa-users fa-fw"></i>';
                break;

            case 'admin_woocommerce':
                $icon = '<i class="fa fa-wordpress fa-fw"></i>';
                break;

            case 'compras_articulos':
            case 'ventas_articulos':
                $icon = '<i class="fa fa-cubes fa-fw"></i>';
                break;

            case 'contabilidad_ejercicios':
                $icon = '<i class="fa fa-calendar fa-fw"></i>';
                break;

            case 'crm_contactos':
                $icon = '<i class="fa fa-address-book fa-fw"></i>';
                break;

            case 'dashboard':
                $icon = '<i class="fa fa-dashboard fa-fw"></i>';
                break;

            case 'informe_contabilidad':
                $icon = '<i class="fa fa-balance-scale fa-fw"></i>';
                break;
        }
        return $icon;
    }
}

if (!function_exists('fs_honest_orig')) {

    function fs_honest_orig()
    {
        $fname = 'view/login/default.html';
        foreach ($GLOBALS['plugins'] as $plugin) {
            if (file_exists('plugins/' . $plugin . '/view/login/default.html')) {
                $fname = 'plugins/' . $plugin . '/view/login/default.html';
                break;
            }
        }

        $txt = file_get_contents($fname);
        if ($txt) {
            if (stripos($txt, 'facturascripts') === FALSE) {
                return FALSE;
            }
            
            return TRUE;
        }
        
        return FALSE;
    }
}

if (!function_exists('fs_fake_msg')) {

    function fs_fake_msg()
    {
        return base64_decode('PGgxPkZhY3R1cmFTY3JpcHRzPC9oMT48bWFyaz5Fc3TDoSB1c2Fu'
            . 'ZG8gZWwgc29mdHdhcmUgZGUgY8OzZGlnbyBhYmllcnRvIEZhY3R1cmFTY3JpcHRz'
            . 'LCBwZXJvIDxiPnN1IHByb3ZlZWRvciBsZSBoYSBjYW1iaWFkbyBlbCBub21icmU8'
            . 'L2I+LiBFcyBwcmVmZXJpYmxlIHF1ZSB1c2Ugc29mdHdhcmUgb3JpZ2luYWwsIGFj'
            . 'dHVhbGl6YWRvIHkgY29uIHNvcG9ydGUgZGUgbG9zIHByb2dyYW1hZG9yZXMgb3Jp'
            . 'Z2luYWxlcy48L21hcms+PHA+VmlzaXRlIDxhIGhyZWY9Imh0dHBzOi8vd3d3LmZh'
            . 'Y3R1cmFzY3JpcHRzLmNvbSI+ZmFjdHVyYXNjcmlwdHMuY29tPC9hPjwvcD4=');
    }
}