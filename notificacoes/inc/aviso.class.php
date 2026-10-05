<?php

/**
 * Plugin Notificações - avisos enviados pela equipe (item nativo do GLPI: busca, formulário,
 * documentos e histórico). Ao salvar, o aviso é publicado no sino dos destinatários.
 */
class PluginNotificacoesAviso extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas: são tipadas no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_notificacoes_avisos';

    /** prioridade => [rótulo, ícone] */
    public const PRIORIDADES = [
        'baixa'   => ['Baixa', 'ti ti-info-circle'],
        'normal'  => ['Normal', 'ti ti-speakerphone'],
        'alta'    => ['Alta', 'ti ti-alert-triangle'],
        'urgente' => ['Urgente', 'ti ti-alert-octagon'],
    ];

    public const DESTINOS = [
        'todos'     => 'Todos os usuários',
        'usuarios'  => 'Usuários escolhidos',
        'grupos'    => 'Membros de grupos',
        'perfis'    => 'Usuários com os perfis',
        'entidades' => 'Usuários das entidades',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Avisos' : 'Aviso';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon(): string
    {
        return 'ti ti-speakerphone';
    }

    private static function pode(int $direito): bool
    {
        return (bool) Session::haveRight(PluginNotificacoesConfig::DIREITO, $direito);
    }

    public static function canView(): bool
    {
        return self::pode(READ);
    }

    public static function canCreate(): bool
    {
        return self::pode(CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::pode(UPDATE);
    }

    public static function canDelete(): bool
    {
        return self::pode(DELETE);
    }

    public static function canPurge(): bool
    {
        return self::pode(PURGE);
    }

    public function getRights($interface = 'central')
    {
        return [
            READ   => __('Read'),
            CREATE => __('Create'),
            UPDATE => __('Update'),
            DELETE => __('Delete'),
            PURGE  => __('Delete permanently'),
        ];
    }

    // =====================================================================
    // Abas
    // =====================================================================

    public function defineTabs($options = [])
    {
        $abas = [];
        $this->addDefaultFormTab($abas);
        $this->addStandardTab(self::class, $abas, $options);
        $this->addStandardTab('Document_Item', $abas, $options);
        $this->addStandardTab('Log', $abas, $options);
        return $abas;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem()) {
            return '';
        }
        $n = $this->estatisticas((int) $item->getID());
        return self::createTabEntry('Destinatários', $n['total'], null, 'ti ti-users');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            $item->mostrarDestinatarios();
        }
        return true;
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    private function validar(array $input, bool $novo): array|false
    {
        $erros = [];
        if (($novo || array_key_exists('name', $input)) && trim((string) ($input['name'] ?? '')) === '') {
            $erros[] = 'Informe o título do aviso.';
        }
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim(strip_tags((string) $input['name'])), 0, 255);
        }
        if (($novo || array_key_exists('conteudo', $input)) && trim(strip_tags((string) ($input['conteudo'] ?? ''), '<img>')) === '') {
            $erros[] = 'Escreva a mensagem do aviso.';
        }
        if (isset($input['prioridade']) && !isset(self::PRIORIDADES[$input['prioridade']])) {
            $input['prioridade'] = 'normal';
        }
        if (isset($input['destino'])) {
            $destino = isset(self::DESTINOS[$input['destino']]) ? (string) $input['destino'] : 'todos';
            $input['destino'] = $destino;
            $ids = $destino === 'todos' ? [] : PluginNotificacoesConfig::idsPost('_' . $destino, $input);
            if ($destino !== 'todos' && !$ids) {
                $erros[] = 'Escolha pelo menos um destinatário em "' . self::DESTINOS[$destino] . '".';
            }
            $input['destinos'] = json_encode($ids);
        }
        foreach (['date_inicio', 'date_fim'] as $c) {
            if (array_key_exists($c, $input)) {
                $v = trim((string) $input[$c]);
                $input[$c] = ($v === '' || $v === 'NULL' || !strtotime($v)) ? 'NULL' : date('Y-m-d H:i:s', strtotime($v));
            }
        }
        $inicio = $input['date_inicio'] ?? ($this->fields['date_inicio'] ?? null);
        $fim = $input['date_fim'] ?? ($this->fields['date_fim'] ?? null);
        if ($fim && $fim !== 'NULL' && strtotime($fim) <= strtotime(($inicio && $inicio !== 'NULL') ? $inicio : 'now')) {
            $erros[] = 'A data final precisa ser depois do início.';
        }
        if ($erros) {
            foreach ($erros as $e) {
                Session::addMessageAfterRedirect($e, false, ERROR);
            }
            return false;
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->validar($input, true);
        if ($input === false) {
            return false;
        }
        $input['users_id'] = (int) Session::getLoginUserID();
        foreach (['date_inicio', 'date_fim'] as $c) {
            if (($input[$c] ?? '') === 'NULL') {
                unset($input[$c]);
            }
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['users_id']);
        return $this->validar($input, false);
    }

    /** Imagens coladas no editor viram documentos do aviso */
    private function guardarImagens(): void
    {
        if (!empty($this->input['_conteudo'])) {
            $this->input = $this->addFiles($this->input, ['force_update' => true, 'content_field' => 'conteudo', 'name' => 'conteudo']);
        }
    }

    public function post_addItem()
    {
        $this->guardarImagens();
        $this->publicar();
        $n = $this->estatisticas((int) $this->getID());
        Session::addMessageAfterRedirect('Aviso publicado para ' . $n['total'] . ' pessoa(s).', false, INFO);
    }

    public function post_updateItem($history = true)
    {
        $this->guardarImagens();
        $this->publicar();
    }

    public function post_deleteFromDB()
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => PluginNotificacoesEvento::TABELA, 'WHERE' => ['plugin_notificacoes_avisos_id' => (int) $this->getID()]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        PluginNotificacoesEvento::apagar($ids);
    }

    /** Lixeira e restauração apenas escondem ou mostram o aviso no sino */
    public function post_restoreItem()
    {
        $this->publicar();
    }

    public function post_deleteItem()
    {
        $this->publicar();
    }

    /** Cria ou atualiza a notificação do aviso e acerta a lista de destinatários */
    public function publicar(): void
    {
        global $DB;
        $id = (int) $this->getID();
        if ($id <= 0 || !$this->getFromDB($id)) {
            return;
        }
        $f = $this->fields;
        $destinos = array_diff($this->destinatarios(), [(int) $f['users_id']]);
        $dados = [
            'titulo'        => mb_substr((string) $f['name'], 0, 255),
            'referencia'    => 'Aviso · prioridade ' . mb_strtolower(self::PRIORIDADES[$f['prioridade']][0] ?? 'normal'),
            'conteudo'      => PluginNotificacoesConfig::textoSimples((string) $f['conteudo'], 400),
            'nivel'         => (string) $f['prioridade'],
            'entities_id'   => (int) $f['entities_id'],
            'date_creation' => $f['date_inicio'] ?: ($f['date_creation'] ?: date('Y-m-d H:i:s')),
            'date_fim'      => $f['date_fim'] ?: null,
            'is_hidden'     => (!empty($f['is_deleted']) || empty($f['is_active'])) ? 1 : 0,
        ];
        $evento = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => PluginNotificacoesEvento::TABELA, 'WHERE' => ['plugin_notificacoes_avisos_id' => $id], 'LIMIT' => 1]) as $r) {
            $evento = (int) $r['id'];
        }
        if ($evento === 0) {
            PluginNotificacoesEvento::registrar('aviso', null, (int) $f['users_id'], $dados['titulo'], (string) $f['conteudo'], $destinos, $dados + ['plugin_notificacoes_avisos_id' => $id]);
            return;
        }
        $DB->update(PluginNotificacoesEvento::TABELA, $dados, ['id' => $evento]);
        $ativos = PluginNotificacoesEvento::usuariosAtivos($destinos);
        PluginNotificacoesEvento::adicionarDestinatarios($evento, $ativos);
        $fora = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => PluginNotificacoesEvento::DESTINOS, 'WHERE' => ['plugin_notificacoes_eventos_id' => $evento]]) as $r) {
            if (!in_array((int) $r['users_id'], $ativos, true)) {
                $fora[] = (int) $r['users_id'];
            }
        }
        if ($fora) {
            $DB->delete(PluginNotificacoesEvento::DESTINOS, ['plugin_notificacoes_eventos_id' => $evento, 'users_id' => $fora]);
        }
    }

    public function idsDestino(): array
    {
        return array_values(array_filter(array_map('intval', json_decode((string) ($this->fields['destinos'] ?? ''), true) ?: []), fn($v) => $v > 0));
    }

    /** Usuários ativos que recebem o aviso */
    public function destinatarios(): array
    {
        global $DB;
        $ids = $this->idsDestino();
        $usuarios = [];
        switch ((string) $this->fields['destino']) {
            case 'todos':
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'id' => ['>', 0]]]) as $r) {
                    $usuarios[] = (int) $r['id'];
                }
                return $usuarios;
            case 'usuarios':
                $usuarios = $ids;
                break;
            case 'grupos':
                return PluginNotificacoesEvento::membros($ids);
            case 'perfis':
            case 'entidades':
                if (!$ids) {
                    return [];
                }
                $where = ['profiles_id' => $ids];
                if ($this->fields['destino'] === 'entidades') {
                    $entidades = [];
                    foreach ($ids as $e) {
                        $entidades = array_merge($entidades, array_map('intval', getSonsOf('glpi_entities', $e)));
                    }
                    $where = ['entities_id' => array_values(array_unique($entidades))];
                }
                foreach ($DB->request(['SELECT' => ['users_id'], 'DISTINCT' => true, 'FROM' => 'glpi_profiles_users', 'WHERE' => $where]) as $r) {
                    $usuarios[] = (int) $r['users_id'];
                }
                break;
        }
        return PluginNotificacoesEvento::usuariosAtivos($usuarios);
    }

    /** Total de destinatários e quantos já leram */
    public function estatisticas(int $id): array
    {
        global $DB;
        $r = ['total' => 0, 'lidas' => 0];
        foreach ($DB->request([
            'SELECT'     => ['d.is_read', new \Glpi\DBAL\QueryExpression('COUNT(*) AS n')],
            'FROM'       => PluginNotificacoesEvento::DESTINOS . ' AS d',
            'INNER JOIN' => [PluginNotificacoesEvento::TABELA . ' AS e' => ['ON' => ['d' => 'plugin_notificacoes_eventos_id', 'e' => 'id']]],
            'WHERE'      => ['e.plugin_notificacoes_avisos_id' => $id],
            'GROUPBY'    => 'd.is_read',
        ]) as $l) {
            $r['total'] += (int) $l['n'];
            if ((int) $l['is_read'] === 1) {
                $r['lidas'] += (int) $l['n'];
            }
        }
        return $r;
    }

    // =====================================================================
    // Busca nativa
    // =====================================================================

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => 'Título', 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'prioridade', 'name' => 'Prioridade', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
            ['id' => 4, 'table' => $t, 'field' => 'destino', 'name' => 'Destino', 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'date_inicio', 'name' => 'Exibir a partir de', 'datatype' => 'datetime'],
            ['id' => 6, 'table' => $t, 'field' => 'date_fim', 'name' => 'Exibir até', 'datatype' => 'datetime'],
            ['id' => 7, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id', 'name' => 'Enviado por', 'datatype' => 'dropdown', 'right' => 'all', 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 10, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 11, 'table' => $t, 'field' => 'conteudo', 'name' => 'Mensagem', 'datatype' => 'text', 'htmltext' => true, 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 86, 'table' => $t, 'field' => 'is_recursive', 'name' => __('Child entities'), 'datatype' => 'bool'],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $valores = is_array($values) ? $values : [$field => $values];
        switch ($field) {
            case 'prioridade':
                return htmlspecialchars(self::PRIORIDADES[$valores[$field] ?? ''][0] ?? '');
            case 'destino':
                return htmlspecialchars(self::DESTINOS[$valores[$field] ?? ''] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $valores = is_array($values) ? $values : [$field => $values];
        $lista = match ($field) {
            'prioridade' => array_map(fn($p) => $p[0], self::PRIORIDADES),
            'destino'    => self::DESTINOS,
            default      => null,
        };
        if ($lista !== null) {
            $options['display'] = false;
            $options['value'] = $valores[$field] ?? '';
            return Dropdown::showFromArray($name, $lista, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // =====================================================================
    // Formulário
    // =====================================================================

    public function showForm($ID, array $options = [])
    {
        $C = PluginNotificacoesConfig::class;
        $e = [$C, 'e'];
        $this->initForm($ID, $options);
        $novo = $this->isNewItem();
        if ($novo) {
            $this->fields['prioridade'] = 'normal';
            $this->fields['destino'] = 'todos';
            $this->fields['is_active'] = 1;
            $this->fields['is_recursive'] = 1;
        }
        $destino = (string) ($this->fields['destino'] ?: 'todos');
        $ids = $this->idsDestino();

        echo $C::filtroEntidades() . $C::assets(true);
        $this->showFormHeader($options);

        if (!$novo) {
            $n = $this->estatisticas((int) $ID);
            $pct = $n['total'] > 0 ? round($n['lidas'] * 100 / $n['total']) : 0;
            echo '<tr class="tab_bg_1"><td colspan="4"><div class="notificacoes-topo-aviso">'
                . '<span><i class="ti ti-send"></i> Enviado por ' . $e(getUserName((int) $this->fields['users_id'])) . ' em ' . $e(Html::convDateTime((string) $this->fields['date_creation'])) . '</span>'
                . '<span><i class="ti ti-users"></i> ' . (int) $n['total'] . ' destinatário(s)</span>'
                . '<span><i class="ti ti-eye"></i> lido por ' . (int) $n['lidas'] . ' (' . $pct . '%)</span>'
                . '<div class="notificacoes-progresso" title="' . $pct . '% leram"><div style="width:' . $pct . '%"></div></div>'
                . '</div></td></tr>';
        }

        echo '<tr class="tab_bg_1"><td><label for="notificacoes-titulo">Título <span class="required">*</span></label></td><td colspan="3">'
            . '<input type="text" id="notificacoes-titulo" class="form-control" name="name" maxlength="255" required value="' . $e($this->fields['name'] ?? '') . '"></td></tr>';

        echo '<tr class="tab_bg_1"><td>Prioridade</td><td>';
        Dropdown::showFromArray('prioridade', array_map(fn($p) => $p[0], self::PRIORIDADES), ['value' => $this->fields['prioridade']]);
        echo '<div class="notificacoes-dica">Alta e urgente ficam destacadas e pedem confirmação de leitura no aviso do navegador.</div>';
        echo '</td><td>Ativo</td><td><div class="form-check form-switch notificacoes-switch">'
            . '<input type="hidden" name="is_active" value="0">'
            . '<input class="form-check-input" type="checkbox" id="notificacoes-ativo" name="is_active" value="1"' . (!empty($this->fields['is_active']) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="notificacoes-ativo">Exibir no sino</label></div></td></tr>';

        echo '<tr class="tab_bg_1"><td>Exibir a partir de</td><td>';
        Html::showDateTimeField('date_inicio', ['value' => $this->fields['date_inicio'] ?? '', 'maybeempty' => true]);
        echo '<div class="notificacoes-dica">Vazio: imediatamente.</div></td><td>Exibir até</td><td>';
        Html::showDateTimeField('date_fim', ['value' => $this->fields['date_fim'] ?? '', 'maybeempty' => true]);
        echo '<div class="notificacoes-dica">Vazio: até a limpeza automática.</div></td></tr>';

        echo '<tr class="tab_bg_1"><td><label for="notificacoes-destino">Destinatários <span class="required">*</span></label></td><td colspan="3"><div class="notificacoes-destino" data-notificacoes-destino>';
        echo '<select class="form-select form-select-sm notificacoes-destino-tipo" id="notificacoes-destino" name="destino">';
        foreach (self::DESTINOS as $chave => $rotulo) {
            echo '<option value="' . $e($chave) . '"' . ($chave === $destino ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
        }
        echo '</select>';
        $listas = [
            'usuarios'  => [$C::listarUsuarios(), 'Nenhum usuário'],
            'grupos'    => [$C::listarGrupos(), 'Nenhum grupo'],
            'perfis'    => [$C::listarPerfis(), 'Nenhum perfil'],
            'entidades' => [$C::listarEntidadesPai(), 'Nenhuma entidade'],
        ];
        foreach ($listas as $chave => [$opcoes, $vazio]) {
            echo '<div class="notificacoes-destino-painel" data-destino-painel="' . $chave . '"' . ($chave !== $destino ? ' hidden' : '') . '>'
                . $C::multiselect('_' . $chave, $opcoes, $chave === $destino ? $ids : [], $vazio)
                . ($chave === 'entidades' ? '<div class="notificacoes-dica">Inclui as entidades filhas de cada entidade escolhida.</div>' : '')
                . '</div>';
        }
        echo '</div></td></tr>';

        echo '<tr class="tab_bg_1"><td>Mensagem <span class="required">*</span></td><td colspan="3">';
        Html::textarea([
            'name'            => 'conteudo',
            'value'           => $this->fields['conteudo'] ?? '',
            'enable_richtext' => true,
            'cols'            => 100,
            'rows'            => 8,
        ]);
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    /** Aba "Destinatários": quem recebeu e quem já leu */
    public function mostrarDestinatarios(): void
    {
        global $DB;
        $C = PluginNotificacoesConfig::class;
        $e = [$C, 'e'];
        $linhas = [];
        foreach ($DB->request([
            'SELECT'     => ['d.users_id', 'd.is_read', 'd.date_read'],
            'FROM'       => PluginNotificacoesEvento::DESTINOS . ' AS d',
            'INNER JOIN' => [PluginNotificacoesEvento::TABELA . ' AS e' => ['ON' => ['d' => 'plugin_notificacoes_eventos_id', 'e' => 'id']]],
            'WHERE'      => ['e.plugin_notificacoes_avisos_id' => (int) $this->getID()],
        ]) as $r) {
            $linhas[] = ['nome' => $C::nomeUsuario((int) $r['users_id']), 'lida' => (int) $r['is_read'] === 1, 'data' => (string) $r['date_read']];
        }
        usort($linhas, fn($a, $b) => [$a['lida'], mb_strtolower($a['nome'])] <=> [$b['lida'], mb_strtolower($b['nome'])]);
        echo $C::assets(true);
        echo '<div class="notificacoes-pagina">';
        if (!$linhas) {
            echo '<div class="notificacoes-vazio"><i class="ti ti-mood-empty"></i><span>Ninguém recebeu este aviso. Confira os destinatários, se ele está ativo e se o tipo "Avisos" está ligado na configuração.</span></div></div>';
            return;
        }
        $lidas = count(array_filter($linhas, fn($l) => $l['lida']));
        echo '<div class="notificacoes-ferramentas"><input type="search" class="form-control form-control-sm notificacoes-busca-tabela" placeholder="Pesquisar pessoa..." data-notificacoes-busca-tabela>'
            . '<span class="notificacoes-pequeno">' . count($linhas) . ' destinatário(s) · ' . $lidas . ' leram</span></div>';
        echo '<div class="table-responsive"><table class="table table-sm table-hover notificacoes-tabela"><thead><tr><th>Pessoa</th><th>Situação</th><th>Lido em</th></tr></thead><tbody>';
        foreach ($linhas as $l) {
            echo '<tr data-search="' . $e(mb_strtolower($l['nome'])) . '"><td>' . $e($l['nome']) . '</td>'
                . '<td>' . ($l['lida'] ? '<span class="notificacoes-selo notificacoes-selo-verde">Lido</span>' : '<span class="notificacoes-selo">Não lido</span>') . '</td>'
                . '<td>' . ($l['lida'] && $l['data'] ? $e(Html::convDateTime($l['data'])) : '<span class="notificacoes-pequeno">—</span>') . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
