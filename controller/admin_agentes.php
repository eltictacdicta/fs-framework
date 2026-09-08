<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

/**
 * Controlador de admin -> agentes — HTMX-first CRUD (familias pattern).
 *
 * List renders via row fragments; create/update/delete respond with tbody
 * fragments + fs:modal-close; edit is an hx-get modal fragment. No-JS
 * fallbacks keep full-page PRG behavior. GET delete retired (POST-only,
 * hx-confirm). The standalone admin_agente page remains for direct URLs.
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class admin_agentes extends \FSFramework\Controller\HtmxCrudController
{
    private const AGENT_MSG_PREFIX = 'Agente ';

    /** @var agente Model instance for list + save operations. */
    public $agente;

    /** @var agente|false Agente being edited (fragment + ?cod= fallback). */
    public $editing_agente = false;

    /** @var bool Include agents with f_baja set in the list (toolbar toggle). */
    public $show_debaja = false;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Agentes', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        parent::private_core();

        $this->agente = new agente();
        $this->editing_agente = false;
        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
        $this->show_debaja = (bool) filter_input(INPUT_GET, 'debaja');

        $this->crud('agentes')
            ->rowPartial('partials/agentes/agente_row.html.twig')
            ->rowSwap(false);

        if (isset($_REQUEST['action'])) {
            $this->process_action($_REQUEST['action']);
            return;
        }

        // No-JS fallbacks (full-page PRG)
        if (isset($_POST['save_agente'])) {
            $this->save_agente();
        } elseif (isset($_GET['cod'])) {
            $this->modificar_agente();
        }
    }

    private function process_action(string $action): void
    {
        $petitionId = $_POST['petition_id'] ?? $_GET['petition_id'] ?? '';
        if ($petitionId !== '' && $this->isDuplicatedPetition($petitionId)) {
            $this->noContentWithFlash();
            return;
        }

        switch ($action) {
            case 'edit_form':
                $this->action_edit_form();
                break;
            case 'delete':
                $this->delete_agente();
                break;
            default:
                $this->noContentWithFlash();
                break;
        }
    }

    /**
     * GET fragment: edit modal for one agente — HTMX-driven edit without a
     * full page reload. The same partial renders inline for the no-JS
     * fallback (?cod=CODE).
     */
    private function action_edit_form(): void
    {
        $codagente = isset($_GET['codagente']) ? trim($_GET['codagente']) : '';
        if ($codagente === '') {
            $this->new_error_msg('Código de agente no proporcionado.');
            $this->noContentWithFlash();
            return;
        }

        $this->editing_agente = $this->agente->get($codagente);
        if (!$this->editing_agente) {
            $this->new_error_msg('Agente no encontrado.');
            $this->noContentWithFlash();
            return;
        }

        $this->renderFragment('partials/agentes/edit_modal.html.twig');
    }

    private function save_agente()
    {
        $data = $this->collectAgentFormData();
        $codagente = trim((string) $data['codagente']);

        if ($codagente === '') {
            $this->createAgente($data);
            return;
        }

        $this->updateAgente($data);
    }

    private function collectAgentFormData(): array
    {
        $fields = [
            'codagente', 'nombre', 'apellidos', 'dnicif', 'email', 'telefono',
            'codpostal', 'provincia', 'ciudad', 'direccion', 'seg_social',
            'cargo', 'banco', 'f_nacimiento', 'f_alta', 'f_baja',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = fs_filter_input_req($field, '');
        }
        $data['porcomision'] = floatval(fs_filter_input_req('porcomision', '0'));
        return $data;
    }

    private function applyAgentData(object $agente_obj, array $data): void
    {
        $agente_obj->nombre = $data['nombre'];
        $agente_obj->apellidos = $data['apellidos'];
        $agente_obj->dnicif = $data['dnicif'];
        $agente_obj->email = $data['email'];
        $agente_obj->telefono = $data['telefono'];
        $agente_obj->codpostal = $data['codpostal'];
        $agente_obj->provincia = $data['provincia'];
        $agente_obj->ciudad = $data['ciudad'];
        $agente_obj->direccion = $data['direccion'];
        $agente_obj->seg_social = $data['seg_social'];
        $agente_obj->cargo = $data['cargo'];
        $agente_obj->banco = $data['banco'];
        $agente_obj->f_nacimiento = ($data['f_nacimiento'] != '') ? $data['f_nacimiento'] : NULL;
        $agente_obj->f_alta = ($data['f_alta'] != '') ? $data['f_alta'] : NULL;
        $agente_obj->f_baja = ($data['f_baja'] != '') ? $data['f_baja'] : NULL;
        $agente_obj->porcomision = $data['porcomision'];
    }

    private function createAgente(array $data): void
    {
        $agente_obj = new agente();
        $this->applyAgentData($agente_obj, $data);

        if ($agente_obj->save()) {
            $this->respondAgenteSaved($agente_obj->codagente, 'creado');
        } else {
            $this->respondAgenteError('Error al crear el agente.');
        }
    }

    private function updateAgente(array $data): void
    {
        $agente_obj = $this->agente->get($data['codagente']);
        if (!$agente_obj) {
            $this->respondAgenteError('Agente no encontrado.');
            return;
        }

        $this->applyAgentData($agente_obj, $data);

        if ($agente_obj->save()) {
            $this->respondAgenteSaved($agente_obj->codagente, 'modificado');
        } else {
            $this->respondAgenteError('Error al modificar el agente.');
        }
    }

    /**
     * Shared success response: fragment for HTMX, PRG for the no-JS fallback.
     */
    private function respondAgenteSaved(string $codagente, string $verb): void
    {
        $this->new_message(self::AGENT_MSG_PREFIX . $this->no_html($codagente) . ' ' . $verb . ' correctamente.');
        if ($this->requireHtmx()) {
            $this->renderTbodyFragment($this->agente->all($this->show_debaja), ['events' => ['fs:modal-close' => []]]);
        } else {
            \FSFramework\Security\SafeRedirect::redirect($this->listUrl(), 'index.php?page=admin_agentes');
        }
    }

    /**
     * Shared error response: 204 + flash for HTMX, PRG for no-JS.
     */
    private function respondAgenteError(string $message): void
    {
        $this->new_error_msg($message);
        if ($this->requireHtmx()) {
            $this->noContentWithFlash();
        } else {
            \FSFramework\Security\SafeRedirect::redirect($this->listUrl(), 'index.php?page=admin_agentes');
        }
    }

    /**
     * POST-only delete (GET delete retired, familias CRD-02 pattern).
     */
    private function delete_agente()
    {
        if (!$this->allow_delete) {
            $this->respondAgenteError('No tienes permiso para eliminar en esta página.');
            return;
        }

        $codagente = fs_filter_input_req('codagente', '');
        $agente_obj = $this->agente->get($codagente);

        if (!$agente_obj) {
            $this->respondAgenteError('¡Agente no encontrado!');
            return;
        }

        if (defined('FS_DEMO') && FS_DEMO) {
            $this->respondAgenteError('En el modo <b>demo</b> no se pueden eliminar agentes. Esto es así para evitar malas prácticas entre usuarios que prueban la demo.');
            return;
        }

        if ($agente_obj->delete()) {
            $this->new_message(self::AGENT_MSG_PREFIX . $this->no_html($agente_obj->codagente) . ' eliminado correctamente.');
            if ($this->requireHtmx()) {
                $this->renderTbodyFragment($this->agente->all($this->show_debaja));
            } else {
                \FSFramework\Security\SafeRedirect::redirect($this->listUrl(), 'index.php?page=admin_agentes');
            }
        } else {
            $this->respondAgenteError('¡Imposible eliminar al agente!');
        }
    }

    private function modificar_agente()
    {
        $codagente = filter_input(INPUT_GET, 'cod');
        $this->editing_agente = $this->agente->get($codagente);

        if (!$this->editing_agente) {
            $this->new_error_msg('Agente no encontrado.');
        }
    }

    /**
     * Duplicated petition guard — session-based deduplication.
     */
    private function isDuplicatedPetition(string $petitionId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $key = 'petition_' . md5($petitionId);
        if (isset($_SESSION[$key])) {
            return true;
        }

        // Stored as an expiry timestamp; cleanup below prunes only expired
        // markers and always preserves the freshly created one.
        $_SESSION[$key] = time() + 300; // 5-minute deduplication window

        if (count($_SESSION) > 100) {
            $now = time();
            foreach ($_SESSION as $k => $v) {
                if ($k === $key) {
                    continue;
                }
                if (strpos($k, 'petition_') === 0 && is_int($v) && $v < $now) {
                    unset($_SESSION[$k]);
                }
            }
        }

        return false;
    }

    public function all_pages()
    {
        $returnlist = $this->initPageList();
        $users = $this->getSortedUsers();
        $this->applyUserPermissions($returnlist, $users);

        usort($returnlist, fn($a, $b) => strcmp($a->name, $b->name));

        return $returnlist;
    }

    private function initPageList(): array
    {
        $returnlist = [];
        foreach ($this->menu as $m) {
            $m->enabled = FALSE;
            $m->allow_delete = FALSE;
            $m->users = [];
            $returnlist[] = $m;
        }
        return $returnlist;
    }

    private function getSortedUsers(): array
    {
        $users = $this->user->all();
        usort($users, function ($a, $b) {
            if ($a->admin) {
                return -1;
            } else if ($b->admin) {
                return 1;
            }
            return 0;
        });
        return $users;
    }

    private function applyUserPermissions(array &$returnlist, array $users): void
    {
        foreach ($users as $user) {
            if ($user->admin) {
                $this->applyAdminPermissions($returnlist, $user);
            } else {
                $this->applyRegularUserPermissions($returnlist, $user);
            }
        }
    }

    private function applyAdminPermissions(array &$returnlist, $user): void
    {
        foreach ($returnlist as $i => $value) {
            $returnlist[$i]->users[$user->nick] = ['modify' => TRUE, 'delete' => TRUE];
        }
    }

    private function applyRegularUserPermissions(array &$returnlist, $user): void
    {
        foreach ($returnlist as $i => $value) {
            $returnlist[$i]->users[$user->nick] = ['modify' => FALSE, 'delete' => FALSE];
        }

        foreach ($user->get_accesses() as $a) {
            foreach ($returnlist as $i => $value) {
                if ($a->fs_page == $value->name) {
                    $returnlist[$i]->users[$user->nick]['modify'] = TRUE;
                    $returnlist[$i]->users[$user->nick]['delete'] = $a->allow_delete;
                    break;
                }
            }
        }
    }
}
