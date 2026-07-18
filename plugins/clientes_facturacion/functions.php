<?php
/**
 * This file is part of clientes_facturacion
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * Document numbering helpers extracted from facturacion_base so sales documents
 * work when only clientes_facturacion (and tpvmod) are active.
 *
 * Loaded automatically by base/config2.php for each active plugin.
 */

if (!function_exists('fs_documento_new_numero')) {

    /**
     * @param \fs_db2 $db
     */
    function fs_documento_new_numero(&$db, string $table_name, string $codejercicio, string $codserie, string $nombresec): string
    {
        $numero = 1;
        $sec = false;

        if (FS_NEW_CODIGO == 'eneboo' && class_exists('secuencia', false)) {
            $sec0 = new \secuencia();
            $sec = $sec0->get_by_params2($codejercicio, $codserie, $nombresec);
            if ($sec) {
                $numero = $sec->valorout;
                $sec->valorout++;
                $sec->save();
            }
        }

        if (!$sec || $numero <= 1) {
            $sql = 'SELECT MAX(' . $db->sql_to_int('numero') . ') as num FROM ' . $table_name;
            if (!in_array(FS_NEW_CODIGO, ['NUM', '0-NUM'], true)) {
                $sql .= ' WHERE codejercicio = ' . $db->var2str($codejercicio)
                    . ' AND codserie = ' . $db->var2str($codserie) . ';';
            }

            $data = $db->select($sql);
            if ($data) {
                $numero = 1 + (int) $data[0]['num'];
            }

            if ($sec) {
                $sec->valorout = 1 + $numero;
                $sec->save();
            }
        }

        return (string) $numero;
    }
}

if (!function_exists('fs_documento_new_codigo')) {

    function fs_documento_new_codigo(string $tipodoc, string $codejercicio, string $codserie, string|int $numero, string $sufijo = ''): string
    {
        switch (FS_NEW_CODIGO) {
            case 'eneboo':
                return $codejercicio . str_pad($codserie, 2, '0', STR_PAD_LEFT) . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);

            case '0-NUM':
                return str_pad((string) $numero, 12, '0', STR_PAD_LEFT);

            case 'NUM':
                return (string) $numero;

            case 'SERIE-YY-0-NUM':
                return $codserie . substr($codejercicio, -2) . str_pad((string) $numero, 12, '0', STR_PAD_LEFT);

            case 'SERIE-YY-0-NUM-CORTO':
                if (strlen((string) $numero) < 4) {
                    $numero = str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
                }
                return $codserie . substr($codejercicio, -2) . $numero;
        }

        return strtoupper(substr($tipodoc, 0, 3)) . $codejercicio . $codserie . $numero . $sufijo;
    }
}

if (!function_exists('fs_huecos_facturas_cliente')) {

    function fs_huecos_facturas_cliente(&$db, string $table_name): array
    {
        if (in_array(FS_NEW_CODIGO, ['NUM', '0-NUM'], true)) {
            return fs_huecos_facturas_cliente_continua($db, $table_name);
        }

        $huecolist = [];
        $ejercicio = new \ejercicio();
        $serie = new \serie();
        foreach ($ejercicio->all_abiertos() as $eje) {
            $codserie = '';
            $num = 1;
            $sql = 'SELECT codserie,' . $db->sql_to_int('numero') . ' as numero,fecha,hora FROM '
                . $table_name . ' WHERE codejercicio = ' . $ejercicio->var2str($eje->codejercicio)
                . ' ORDER BY codserie ASC, numero ASC;';

            $data = $db->select($sql);
            if (empty($data)) {
                continue;
            }

            foreach ($data as $d) {
                if ($d['codserie'] != $codserie) {
                    $codserie = $d['codserie'];
                    $num = 1;

                    $se = $serie->get($codserie);
                    if ($se && $eje->codejercicio == $se->codejercicio) {
                        $num = $se->numfactura;
                    }
                }

                if ((int) $d['numero'] < $num) {
                    continue;
                }
                if ((int) $d['numero'] == $num) {
                    $num++;
                    continue;
                }

                $pasos = 0;
                while ($num < (int) $d['numero'] && $pasos < 100) {
                    $huecolist[] = [
                        'codigo' => \fs_documento_new_codigo(FS_FACTURA, $eje->codejercicio, $codserie, $num),
                        'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                        'hora' => $d['hora'],
                    ];
                    $num++;
                    $pasos++;
                }

                $num++;
            }
        }

        return $huecolist;
    }

    function fs_huecos_facturas_cliente_continua(&$db, string $table_name): array
    {
        $num = 1;
        $sql2 = 'SELECT ' . $db->sql_to_int('numero') . ' as numero FROM ' . $table_name . ' ORDER BY numero ASC;';
        $data2 = $db->select($sql2);
        if ($data2) {
            $num = max([$num, (int) $data2[0]['num']]);
        }

        $sql = 'SELECT ' . $db->sql_to_int('numero') . ' as numero,fecha,hora FROM ' . $table_name . ' ORDER BY numero ASC;';
        $data = $db->select($sql);
        if (empty($data)) {
            return [];
        }

        $huecolist = [];
        foreach ($data as $d) {
            if ((int) $d['numero'] < $num) {
                continue;
            }
            if ((int) $d['numero'] == $num) {
                $num++;
                continue;
            }

            $pasos = 0;
            while ($num < (int) $d['numero'] && $pasos < 100) {
                $huecolist[] = [
                    'codigo' => \fs_documento_new_codigo(FS_FACTURA, '', '', $num),
                    'fecha' => date('d-m-Y', strtotime($d['fecha'])),
                    'hora' => $d['hora'],
                ];
                $num++;
                $pasos++;
            }

            $num++;
        }

        return $huecolist;
    }
}
