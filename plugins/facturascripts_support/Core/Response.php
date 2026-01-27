<?php

namespace FacturaScripts\Core;

class Response
{
    public function file($path, $name = null, $disposition = 'attachment')
    {
        if (file_exists($path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: ' . $disposition . '; filename="' . ($name ?? basename($path)) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }

    public function send()
    {
        // default send
    }
}
