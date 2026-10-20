<?php

namespace FSFramework\Core\Base;

/**
 * Permisos efectivos del controller para la página actual.
 *
 * Los valores arrancan en false (fail-closed): un controller que consulte
 * `allowDelete` sin haberlo derivado de fs_user::allow_delete_on() no debe
 * obtener permiso por omisión. El core no consume estas propiedades todavía;
 * están para que los controllers modernos y los plugins las alimenten.
 */
class ControllerPermissions
{
    public $allowExport = false;
    public $allowDelete = false;
    public $allowImport = false;
    public $allowUpdate = false;
    public $allowAccess = false;
}
