<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
namespace FacturaScripts\model;

/**
 * Esta clase almacena los principales datos de la empresa.
 * Solamente se puede manejar una empresa en cada base de datos.
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class empresa extends \fs_model
{

    /**
     * Clave primaria. Integer.
     * @var integer 
     */
    public $id;
    public $xid;

    /**
     * Todavía sin uso.
     * @var boolean
     */
    public $stockpedidos;

    /**
     * TRUE -> activa la contabilidad integrada. Se genera el asiento correspondiente
     * cada vez que se crea/modifica una factura.
     * @var boolean
     */
    public $contintegrada;

    /**
     * TRUE -> activa el uso de recargo de equivalencia en los albaranes y facturas de compra.
     * @var boolean
     */
    public $recequivalencia;

    /**
     * Código de la serie por defecto.
     * @var string
     */
    public $codserie;

    /**
     * Código del almacén predeterminado.
     * @var string
     */
    public $codalmacen;

    /**
     * Código de la forma de pago predeterminada.
     * @var string
     */
    public $codpago;

    /**
     * Código de la divisa predeterminada.
     * @var string
     */
    public $coddivisa;

    /**
     * Código del ejercicio predeterminado.
     * @var string
     */
    public $codejercicio;
    public $web;
    public $email;
    public $fax;
    public $telefono;
    public $codpais;
    public $apartado;
    public $provincia;
    public $ciudad;
    public $codpostal;
    public $direccion;

    /**
     * Nombre del administrador de la empresa.
     * @var string
     */
    public $administrador;

    /**
     * Actualmente sin uso.
     * @var string
     */
    public $codedi;
    public $cifnif;
    public $nombre;

    /**
     * Nombre corto de la empresa, para mostrar en el menú
     * @var string Nombre a mostrar en el menú de facturaScripts.
     */
    public $nombrecorto;

    /**
     * Lema de la empresa
     * @var string
     */
    public $lema;

    /**
     * Horario de apertura
     * @var string
     */
    public $horario;

    /**
     * Texto al pié de las facturas de venta.
     * @var string
     */
    public $pie_factura;

    /**
     * Fecha de inicio de la actividad.
     * @var string
     */
    public $inicio_actividad;

    /**
     * Régimen de IVA de la empresa.
     * @var string
     */
    public $regimeniva;
    public $email_config;
    private static $email_cache;

    public function __construct()
    {
        parent::__construct('empresa');

        /// leemos los datos de la empresa de memcache o de la base de datos
        $data = $this->cache->get_array('empresa');
        if (empty($data)) {
            $data = $this->db->select("SELECT * FROM " . $this->table_name . ";");
            $this->cache->set('empresa', $data);
        }

        if (!empty($data)) {
            $this->id = $this->intval($data[0]['id']);
            $this->xid = $data[0]['xid'];
            $this->stockpedidos = $this->str2bool($data[0]['stockpedidos']);
            $this->contintegrada = $this->str2bool($data[0]['contintegrada']);
            $this->recequivalencia = $this->str2bool($data[0]['recequivalencia']);
            $this->codserie = $data[0]['codserie'];
            $this->codalmacen = $data[0]['codalmacen'];
            $this->codpago = $data[0]['codpago'];
            $this->coddivisa = $data[0]['coddivisa'];
            $this->codejercicio = $data[0]['codejercicio'];
            $this->web = $data[0]['web'];
            $this->email = $data[0]['email'];
            $this->fax = $data[0]['fax'];
            $this->telefono = $data[0]['telefono'];
            $this->codpais = $data[0]['codpais'];
            $this->apartado = $data[0]['apartado'];
            $this->provincia = $data[0]['provincia'];
            $this->ciudad = $data[0]['ciudad'];
            $this->codpostal = $data[0]['codpostal'];
            $this->direccion = $data[0]['direccion'];
            $this->administrador = $data[0]['administrador'];
            $this->codedi = $data[0]['codedi'];
            $this->cifnif = $data[0]['cifnif'];
            $this->nombre = $data[0]['nombre'];
            $this->nombrecorto = $data[0]['nombrecorto'];
            $this->lema = $data[0]['lema'];
            $this->horario = $data[0]['horario'];
            $this->pie_factura = $data[0]['pie_factura'];
            $this->inicio_actividad = $data[0]['inicioact'] ? date('d-m-Y', strtotime($data[0]['inicioact'])) : date('d-m-Y');
            $this->regimeniva = $data[0]['regimeniva'];
            $this->get_email_config();

            if (is_null($this->xid)) {
                $this->xid = $this->random_string(30);
                $this->save();
            }
        }
    }

    private function get_email_config()
    {
        if (self::$email_cache === null) {
            /// cargamos las opciones de email por defecto
            self::$email_cache = array(
                'mail_password' => '',
                'mail_bcc' => '',
                'mail_firma' => "\n---\nEnviado con FacturaScripts",
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.gmail.com',
                'mail_port' => '465',
                'mail_enc' => 'ssl',
                'mail_user' => '',
                'mail_low_security' => FALSE,
            );

            $fsvar = new \fs_var();
            self::$email_cache = $fsvar->array_get(self::$email_cache, FALSE);
        }

        $this->email_config = self::$email_cache;
    }

    protected function install()
    {
        $this->clean_cache();
        $num = mt_rand(1, 9999);
        return "INSERT INTO " . $this->table_name . " (stockpedidos,contintegrada,recequivalencia,codserie,"
            . "codalmacen,codpago,coddivisa,codejercicio,web,email,fax,telefono,codpais,apartado,provincia,"
            . "ciudad,codpostal,direccion,administrador,codedi,cifnif,nombre,nombrecorto,lema,horario)"
            . "VALUES (NULL,FALSE,NULL,'A','ALG','CONT','EUR','0001','',"
            . "NULL,NULL,NULL,'ESP',NULL,NULL,NULL,NULL,'C/ Falsa, 123','',NULL,'00000014Z','Empresa " . $num . " S.L.',"
            . "'E-" . $num . "','','');";
    }

    /**
     * Devuelve la url donde ver/modificar los datos
     * @return string
     */
    public function url()
    {
        return 'index.php?page=admin_empresa';
    }

    /**
     * Devuelve TRUE si están definidos el email y la contraseña
     * @return boolean
     */
    public function can_send_mail()
    {
        if ($this->email && $this->email_config['mail_password']) {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Devuelve un objeto PHPMailer con la configuración ya preparada.
     * @return \PHPMailer
     */
    public function new_mail()
    {
        $mail = new \PHPMailer();
        $mail->CharSet = 'UTF-8';
        $mail->WordWrap = 50;
        $mail->Mailer = $this->email_config['mail_mailer'];
        $mail->SMTPAuth = TRUE;
        $mail->SMTPSecure = $this->email_config['mail_enc'];
        $mail->Host = $this->email_config['mail_host'];
        $mail->Port = intval($this->email_config['mail_port']);

        $mail->Username = $this->email;
        if ($this->email_config['mail_user'] != '') {
            $mail->Username = $this->email_config['mail_user'];
        }

        $mail->Password = $this->email_config['mail_password'];
        $mail->From = $this->email;
        $mail->FromName = $this->nombre;

        if ($this->email_config['mail_bcc']) {
            $mail->addBCC($this->email_config['mail_bcc']);
        }

        return $mail;
    }

    public function mail_connect(&$mail)
    {
        if ($this->email_config['mail_mailer'] == 'smtp') {
            return $mail->smtpConnect($this->smtp_options());
        }

        return TRUE;
    }

    /**
     * Devuelve un array con las opciones para $mail->smtpConnect) de PHPMailer
     * @return array
     */
    public function smtp_options()
    {
        $SMTPOptions = [];

        if ($this->email_config['mail_low_security']) {
            $SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
        }

        return $SMTPOptions;
    }

    /**
     * Función llamada al enviar correctamente un email
     * @param \PHPMailer $mail
     */
    public function save_mail($mail)
    {
        /// tu código aquí
        /// $mail es el email ya enviado (es un objeto PHPMailer)
    }

    /**
     * Devuelve TRUE si existe
     * @return boolean
     */
    public function exists()
    {
        if (is_null($this->id)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE id = " . $this->var2str($this->id) . ";");
    }

    /**
     * Comprueba los datos de la empresa, devuelve TRUE si está todo correcto
     * @return boolean
     */
    public function test()
    {
        $status = FALSE;

        $this->nombre = $this->no_html($this->nombre);
        $this->nombrecorto = $this->no_html($this->nombrecorto);
        $this->administrador = $this->no_html($this->administrador);
        $this->apartado = $this->no_html($this->apartado);
        $this->cifnif = $this->no_html($this->cifnif);
        $this->ciudad = $this->no_html($this->ciudad);
        $this->codpostal = $this->no_html($this->codpostal);
        $this->direccion = $this->no_html($this->direccion);
        $this->email = $this->no_html($this->email);
        $this->fax = $this->no_html($this->fax);
        $this->horario = $this->no_html($this->horario);
        $this->lema = $this->no_html($this->lema);
        $this->pie_factura = $this->no_html($this->pie_factura);
        $this->provincia = $this->no_html($this->provincia);
        $this->telefono = $this->no_html($this->telefono);
        $this->web = $this->no_html($this->web);

        if (strlen($this->nombre) < 1 || strlen($this->nombre) > 100) {
            $this->new_error_msg("Nombre de empresa no válido.");
        } else if (strlen($this->nombre) < strlen($this->nombrecorto)) {
            $this->new_error_msg("El Nombre Corto debe ser más corto que el Nombre.");
        } else {
            $status = TRUE;
        }

        return $status;
    }

    /**
     * Guarda los datos en la base de datos
     * @return boolean
     */
    public function save()
    {
        if ($this->test()) {
            $this->clean_cache();

            /// guardamos la configuración de email
            $fsvar = new \fs_var();
            $fsvar->array_save($this->email_config);

            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET nombre = " . $this->var2str($this->nombre)
                    . ", nombrecorto = " . $this->var2str($this->nombrecorto)
                    . ", cifnif = " . $this->var2str($this->cifnif)
                    . ", codedi = " . $this->var2str($this->codedi)
                    . ", administrador = " . $this->var2str($this->administrador)
                    . ", direccion = " . $this->var2str($this->direccion)
                    . ", codpostal = " . $this->var2str($this->codpostal)
                    . ", ciudad = " . $this->var2str($this->ciudad)
                    . ", provincia = " . $this->var2str($this->provincia)
                    . ", apartado = " . $this->var2str($this->apartado)
                    . ", codpais = " . $this->var2str($this->codpais)
                    . ", telefono = " . $this->var2str($this->telefono)
                    . ", fax = " . $this->var2str($this->fax)
                    . ", email = " . $this->var2str($this->email)
                    . ", web = " . $this->var2str($this->web)
                    . ", codejercicio = " . $this->var2str($this->codejercicio)
                    . ", coddivisa = " . $this->var2str($this->coddivisa)
                    . ", codpago = " . $this->var2str($this->codpago)
                    . ", codalmacen = " . $this->var2str($this->codalmacen)
                    . ", codserie = " . $this->var2str($this->codserie)
                    . ", recequivalencia = " . $this->var2str($this->recequivalencia)
                    . ", contintegrada = " . $this->var2str($this->contintegrada)
                    . ", stockpedidos = " . $this->var2str($this->stockpedidos)
                    . ", xid = " . $this->var2str($this->xid)
                    . ", lema = " . $this->var2str($this->lema)
                    . ", horario = " . $this->var2str($this->horario)
                    . ", pie_factura = " . $this->var2str($this->pie_factura)
                    . ", inicioact = " . $this->var2str($this->inicio_actividad)
                    . ", regimeniva = " . $this->var2str($this->regimeniva)
                    . "  WHERE id = " . $this->var2str($this->id) . ";";

                return $this->db->exec($sql);
            }

            $sql = "INSERT INTO " . $this->table_name . " (stockpedidos,contintegrada,recequivalencia,codserie,
               codalmacen,codpago,coddivisa,codejercicio,web,email,fax,telefono,
               codpais,apartado,provincia,ciudad,codpostal,direccion,administrador,codedi,cifnif,nombre,
               nombrecorto,lema,horario,pie_factura,inicioact,regimeniva) VALUES 
                      (" . $this->var2str($this->stockpedidos)
                . "," . $this->var2str($this->contintegrada)
                . "," . $this->var2str($this->recequivalencia)
                . "," . $this->var2str($this->codserie)
                . "," . $this->var2str($this->codalmacen)
                . "," . $this->var2str($this->codpago)
                . "," . $this->var2str($this->coddivisa)
                . "," . $this->var2str($this->codejercicio)
                . "," . $this->var2str($this->web)
                . "," . $this->var2str($this->email)
                . "," . $this->var2str($this->fax)
                . "," . $this->var2str($this->telefono)
                . "," . $this->var2str($this->codpais)
                . "," . $this->var2str($this->apartado)
                . "," . $this->var2str($this->provincia)
                . "," . $this->var2str($this->ciudad)
                . "," . $this->var2str($this->codpostal)
                . "," . $this->var2str($this->direccion)
                . "," . $this->var2str($this->administrador)
                . "," . $this->var2str($this->codedi)
                . "," . $this->var2str($this->cifnif)
                . "," . $this->var2str($this->nombre)
                . "," . $this->var2str($this->nombrecorto)
                . "," . $this->var2str($this->lema)
                . "," . $this->var2str($this->horario)
                . "," . $this->var2str($this->pie_factura)
                . "," . $this->var2str($this->inicio_actividad)
                . "," . $this->var2str($this->regimeniva) . ");";

            if ($this->db->exec($sql)) {
                $this->id = $this->db->lastval();
                return TRUE;
            }
        }

        return FALSE;
    }

    public function delete()
    {
        /// no se puede borrar la empresa
        return FALSE;
    }

    /**
     * Limpia la caché
     */
    public function clean_cache()
    {
        $this->cache->delete('empresa');
        self::$email_cache = null;
    }
}
