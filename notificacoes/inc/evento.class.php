<?php

/**
 * Plugin Notificações - geração dos eventos.
 * Os hooks item_add/item_update do GLPI chamam esta classe no momento em que algo acontece;
 * cada evento é gravado uma vez com a lista de destinatários (estado de leitura por pessoa).
 * Nenhuma falha aqui pode interromper a operação do GLPI: tudo roda dentro de try/catch.
 */
class PluginNotificacoesEvento extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_notificacoes_eventos';
    public const DESTINOS = 'glpi_plugin_notificacoes_destinatarios';

    public const CLASSES_ADICAO = [
        'ITILFollowup', 'TicketTask', 'ProblemTask', 'ChangeTask', 'ITILSolution',
        'TicketValidation', 'ChangeValidation',
        'Ticket_User', 'Problem_User', 'Change_User', 'Group_Ticket', 'Group_Problem', 'Change_Group',
    ];
    public const CLASSES_ATUALIZACAO = ['Ticket', 'Problem', 'Change', 'TicketValidation', 'ChangeValidation', 'TicketTask', 'ProblemTask', 'ChangeTask'];

    /** Tabelas de atores: itemtype => [usuários, grupos, chave] */
    private const VINCULOS = [
        'Ticket'  => ['glpi_tickets_users', 'glpi_groups_tickets', 'tickets_id'],
        'Problem' => ['glpi_problems_users', 'glpi_groups_problems', 'problems_id'],
        'Change'  => ['glpi_changes_users', 'glpi_changes_groups', 'changes_id'],
    ];

    /** Classes de ator: classe => [itemtype pai, chave, é grupo] */
    private const ATORES = [
        'Ticket_User'   => ['Ticket', 'tickets_id', false],
        'Problem_User'  => ['Problem', 'problems_id', false],
        'Change_User'   => ['Change', 'changes_id', false],
        'Group_Ticket'  => ['Ticket', 'tickets_id', true],
        'Group_Problem' => ['Problem', 'problems_id', true],
        'Change_Group'  => ['Change', 'changes_id', true],
    ];

    private const VALIDACOES = ['TicketValidation' => ['Ticket', 'tickets_id'], 'ChangeValidation' => ['Change', 'changes_id']];

    public static function getTypeName($nb = 0): string
    {
        return 'Notificações';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function pronto(): bool
    {
        global $DB;
        static $ok = null;
        return $ok ??= ($DB->tableExists(self::TABELA) && $DB->tableExists(self::DESTINOS));
    }

    private static function agora(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function autorAtual(): int
    {
        return (int) Session::getLoginUserID();
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    public static function aoAdicionar(CommonDBTM $item): void
    {
        if (!self::pronto()) {
            return;
        }
        try {
            $classe = get_class($item);
            if ($item instanceof ITILFollowup) {
                self::acompanhamento($item);
            } elseif ($item instanceof CommonITILTask) {
                self::tarefa($item, true);
            } elseif ($item instanceof ITILSolution) {
                self::solucao($item);
            } elseif (isset(self::VALIDACOES[$classe])) {
                self::validacaoPedida($item);
            } elseif (isset(self::ATORES[$classe])) {
                self::ator($item);
            }
        } catch (\Throwable $e) {
            error_log('Plugin notificacoes: ' . $e->getMessage());
        }
    }

    public static function aoAtualizar(CommonDBTM $item): void
    {
        if (!self::pronto() || empty($item->updates) || !is_array($item->updates)) {
            return;
        }
        try {
            $classe = get_class($item);
            if ($item instanceof CommonITILObject && in_array('status', $item->updates, true)) {
                self::status($item);
            } elseif ($item instanceof CommonITILTask && array_intersect(['users_id_tech', 'groups_id_tech'], $item->updates)) {
                self::tarefa($item, false);
            } elseif (isset(self::VALIDACOES[$classe]) && in_array('status', $item->updates, true)) {
                self::validacaoRespondida($item);
            }
        } catch (\Throwable $e) {
            error_log('Plugin notificacoes: ' . $e->getMessage());
        }
    }

    /** Item purgado: as notificações dele saem junto */
    public static function aoRemover(CommonDBTM $item): void
    {
        global $DB;
        if (!self::pronto()) {
            return;
        }
        try {
            $ids = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['itemtype' => get_class($item), 'items_id' => (int) $item->getID()]]) as $r) {
                $ids[] = (int) $r['id'];
            }
            self::apagar($ids);
        } catch (\Throwable $e) {
            error_log('Plugin notificacoes: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // Tipos de evento
    // =====================================================================

    private static function acompanhamento(ITILFollowup $f): void
    {
        $pai = self::pai((string) $f->fields['itemtype'], (int) $f->fields['items_id']);
        if (!$pai) {
            return;
        }
        $atores = self::atores($pai);
        $privado = !empty($f->fields['is_private']);
        $publico = $privado ? $atores['tecnicos'] : array_merge($atores['requerentes'], $atores['observadores'], $atores['tecnicos']);
        $autor = (int) ($f->fields['users_id'] ?? 0) ?: self::autorAtual();
        $mencionados = self::mencoes((string) $f->fields['content']);
        if ($privado) {
            $mencionados = array_values(array_intersect($mencionados, $atores['tecnicos']));
        }
        self::registrar('acompanhamento', $pai, $autor, $privado ? 'Novo acompanhamento privado' : 'Novo acompanhamento', (string) $f->fields['content'], array_diff($publico, $mencionados));
        self::registrar('mencao', $pai, $autor, 'Você foi mencionado num acompanhamento', (string) $f->fields['content'], $mencionados);
    }

    private static function tarefa(CommonITILTask $t, bool $nova): void
    {
        $tipoPai = $t::getItilObjectItemType();
        $chave = getForeignKeyFieldForItemType($tipoPai);
        $pai = self::pai($tipoPai, (int) ($t->fields[$chave] ?? 0));
        if (!$pai) {
            return;
        }
        $autor = $nova ? ((int) ($t->fields['users_id'] ?? 0) ?: self::autorAtual()) : self::autorAtual();
        $conteudo = (string) ($t->fields['content'] ?? '');
        $tecnico = (int) ($t->fields['users_id_tech'] ?? 0);
        $grupo = (int) ($t->fields['groups_id_tech'] ?? 0);
        $mudouTecnico = $nova || in_array('users_id_tech', $t->updates ?? [], true);
        $mudouGrupo = $nova || in_array('groups_id_tech', $t->updates ?? [], true);
        $mencionados = $nova ? self::mencoes($conteudo) : [];

        $pessoais = $mudouTecnico && $tecnico > 0 ? [$tecnico] : [];
        self::registrar('tarefa', $pai, $autor, 'Tarefa atribuída a você', $conteudo, array_diff($pessoais, $mencionados));
        if ($mudouGrupo && $grupo > 0) {
            $nomeGrupo = (string) Dropdown::getDropdownName('glpi_groups', $grupo);
            self::registrar('tarefa', $pai, $autor, 'Tarefa atribuída ao grupo ' . $nomeGrupo, $conteudo, array_diff(self::membros([$grupo]), $pessoais, $mencionados));
        }
        self::registrar('mencao', $pai, $autor, 'Você foi mencionado numa tarefa', $conteudo, $mencionados);
    }

    private static function solucao(ITILSolution $s): void
    {
        $pai = self::pai((string) $s->fields['itemtype'], (int) $s->fields['items_id']);
        if (!$pai) {
            return;
        }
        $atores = self::atores($pai);
        $autor = (int) ($s->fields['users_id'] ?? 0) ?: self::autorAtual();
        $conteudo = (string) ($s->fields['content'] ?? '');
        $mencionados = self::mencoes($conteudo);
        self::registrar('solucao', $pai, $autor, 'Solução proposta', $conteudo, array_diff(array_merge($atores['requerentes'], $atores['observadores']), $mencionados), ['nivel' => 'ok']);
        self::registrar('mencao', $pai, $autor, 'Você foi mencionado numa solução', $conteudo, $mencionados);
    }

    private static function validacaoPedida(CommonDBTM $v): void
    {
        [$tipoPai, $chave] = self::VALIDACOES[get_class($v)];
        $pai = self::pai($tipoPai, (int) ($v->fields[$chave] ?? 0));
        if (!$pai) {
            return;
        }
        $alvoTipo = (string) ($v->fields['itemtype_target'] ?? '');
        $alvoId = (int) ($v->fields['items_id_target'] ?? 0);
        if ($alvoTipo === 'Group' && $alvoId > 0) {
            $destinos = self::membros([$alvoId]);
            $titulo = 'Validação solicitada ao grupo ' . Dropdown::getDropdownName('glpi_groups', $alvoId);
        } else {
            $destinos = [$alvoTipo === 'User' && $alvoId > 0 ? $alvoId : (int) ($v->fields['users_id_validate'] ?? 0)];
            $titulo = 'Validação solicitada a você';
        }
        $autor = (int) ($v->fields['users_id'] ?? 0) ?: self::autorAtual();
        self::registrar('validacao_pedido', $pai, $autor, $titulo, (string) ($v->fields['comment_submission'] ?? ''), $destinos, ['nivel' => 'atencao']);
    }

    private static function validacaoRespondida(CommonDBTM $v): void
    {
        $status = (int) ($v->fields['status'] ?? 0);
        if (!in_array($status, [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], true)) {
            return;
        }
        [$tipoPai, $chave] = self::VALIDACOES[get_class($v)];
        $pai = self::pai($tipoPai, (int) ($v->fields[$chave] ?? 0));
        if (!$pai) {
            return;
        }
        $aprovada = $status === CommonITILValidation::ACCEPTED;
        $autor = (int) ($v->fields['users_id_validate'] ?? 0) ?: self::autorAtual();
        self::registrar('validacao_resposta', $pai, $autor, $aprovada ? 'Validação aprovada' : 'Validação recusada', (string) ($v->fields['comment_validation'] ?? ''), [(int) ($v->fields['users_id'] ?? 0)], ['nivel' => $aprovada ? 'ok' : 'erro']);
    }

    private static function ator(CommonDBTM $a): void
    {
        [$tipoPai, $chave, $ehGrupo] = self::ATORES[get_class($a)];
        $tipoAtor = (int) ($a->fields['type'] ?? 0);
        $pai = self::pai($tipoPai, (int) ($a->fields[$chave] ?? 0));
        if (!$pai) {
            return;
        }
        $autor = self::autorAtual();
        $conteudo = (string) ($pai->fields['content'] ?? '');
        if ($ehGrupo) {
            $grupo = (int) ($a->fields['groups_id'] ?? 0);
            if ($tipoAtor === CommonITILActor::ASSIGN && $grupo > 0) {
                self::registrar('atribuicao', $pai, $autor, 'Atribuído ao grupo ' . Dropdown::getDropdownName('glpi_groups', $grupo), $conteudo, self::membros([$grupo]));
            }
            return;
        }
        $usuario = (int) ($a->fields['users_id'] ?? 0);
        if ($usuario <= 0) {
            return;
        }
        if ($tipoAtor === CommonITILActor::ASSIGN) {
            self::registrar('atribuicao', $pai, $autor, 'Atribuído a você', $conteudo, [$usuario]);
        } elseif ($tipoAtor === CommonITILActor::OBSERVER) {
            self::registrar('atribuicao', $pai, $autor, 'Você foi incluído como observador', $conteudo, [$usuario]);
        }
    }

    private static function status(CommonITILObject $item): void
    {
        $novo = (int) ($item->fields['status'] ?? 0);
        // Solução proposta já gera a notificação própria
        if ($novo === CommonITILObject::SOLVED) {
            return;
        }
        $atores = self::atores($item);
        $nome = (string) $item::getStatus($novo);
        self::registrar('status', $item, self::autorAtual(), 'Status alterado para ' . $nome, '', array_merge($atores['requerentes'], $atores['observadores']), ['nivel' => $novo === CommonITILObject::CLOSED ? 'ok' : '']);
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    /**
     * Grava um evento e os destinatários. O autor nunca recebe a própria notificação.
     * $extra: nivel, date_creation, date_fim, plugin_notificacoes_avisos_id, titulo_referencia, entities_id
     */
    public static function registrar(string $tipo, ?CommonDBTM $pai, int $autor, string $titulo, string $conteudo, array $destinos, array $extra = []): int
    {
        global $DB;
        if (!in_array($tipo, PluginNotificacoesConfig::tiposAtivos(), true)) {
            return 0;
        }
        $destinos = self::usuariosAtivos(array_diff(array_map('intval', $destinos), $autor > 0 ? [$autor] : []));
        if (!$destinos) {
            return 0;
        }
        $referencia = $extra['referencia'] ?? ($pai ? self::referencia($pai) : '');
        $DB->insert(self::TABELA, [
            'tipo'                          => $tipo,
            'itemtype'                      => $pai ? get_class($pai) : '',
            'items_id'                      => $pai ? (int) $pai->getID() : 0,
            'entities_id'                   => (int) ($extra['entities_id'] ?? ($pai->fields['entities_id'] ?? 0)),
            'users_id'                      => max(0, $autor),
            'titulo'                        => mb_substr($titulo, 0, 255),
            'referencia'                    => mb_substr($referencia, 0, 255),
            'conteudo'                      => PluginNotificacoesConfig::textoSimples($conteudo, 400),
            'nivel'                         => (string) ($extra['nivel'] ?? ''),
            'plugin_notificacoes_avisos_id' => (int) ($extra['plugin_notificacoes_avisos_id'] ?? 0),
            'is_hidden'                     => (int) ($extra['is_hidden'] ?? 0),
            'date_creation'                 => $extra['date_creation'] ?? self::agora(),
            'date_fim'                      => $extra['date_fim'] ?? null,
        ]);
        $id = (int) $DB->insertId();
        if ($id > 0) {
            self::adicionarDestinatarios($id, $destinos);
        }
        return $id;
    }

    public static function adicionarDestinatarios(int $evento, array $usuarios): int
    {
        global $DB;
        $ja = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => self::DESTINOS, 'WHERE' => ['plugin_notificacoes_eventos_id' => $evento]]) as $r) {
            $ja[(int) $r['users_id']] = true;
        }
        $n = 0;
        foreach (array_unique(array_map('intval', $usuarios)) as $u) {
            if ($u > 0 && !isset($ja[$u])) {
                $DB->insert(self::DESTINOS, ['plugin_notificacoes_eventos_id' => $evento, 'users_id' => $u, 'is_read' => 0]);
                $n++;
            }
        }
        return $n;
    }

    /** Remove eventos e destinatários */
    public static function apagar(array $ids): void
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        foreach (array_chunk($ids, 500) as $lote) {
            $DB->delete(self::DESTINOS, ['plugin_notificacoes_eventos_id' => $lote]);
            $DB->delete(self::TABELA, ['id' => $lote]);
        }
    }

    // =====================================================================
    // Apoio
    // =====================================================================

    /** Chamado, problema ou mudança (não excluídos) */
    private static function pai(string $itemtype, int $id): ?CommonITILObject
    {
        if (!isset(self::VINCULOS[$itemtype]) || $id <= 0) {
            return null;
        }
        $item = new $itemtype();
        if (!$item->getFromDB($id) || !empty($item->fields['is_deleted'])) {
            return null;
        }
        return $item;
    }

    public static function referencia(CommonDBTM $item): string
    {
        $nome = PluginNotificacoesConfig::ITENS[get_class($item)] ?? $item::getTypeName(1);
        return $nome . ' #' . $item->getID() . (trim((string) ($item->fields['name'] ?? '')) !== '' ? ' · ' . trim((string) $item->fields['name']) : '');
    }

    /**
     * Pessoas envolvidas no item: requerentes e observadores (usuários; observadores também por grupo)
     * e técnicos (usuários atribuídos e membros dos grupos atribuídos).
     */
    public static function atores(CommonITILObject $item): array
    {
        global $DB;
        [$tUsuarios, $tGrupos, $chave] = self::VINCULOS[get_class($item)];
        $r = ['requerentes' => [], 'observadores' => [], 'tecnicos' => []];
        $mapa = [CommonITILActor::REQUESTER => 'requerentes', CommonITILActor::OBSERVER => 'observadores', CommonITILActor::ASSIGN => 'tecnicos'];
        foreach ($DB->request(['SELECT' => ['users_id', 'type'], 'FROM' => $tUsuarios, 'WHERE' => [$chave => (int) $item->getID(), 'users_id' => ['>', 0]]]) as $l) {
            if (isset($mapa[(int) $l['type']])) {
                $r[$mapa[(int) $l['type']]][] = (int) $l['users_id'];
            }
        }
        $grupos = [CommonITILActor::OBSERVER => [], CommonITILActor::ASSIGN => []];
        foreach ($DB->request(['SELECT' => ['groups_id', 'type'], 'FROM' => $tGrupos, 'WHERE' => [$chave => (int) $item->getID()]]) as $l) {
            if (isset($grupos[(int) $l['type']])) {
                $grupos[(int) $l['type']][] = (int) $l['groups_id'];
            }
        }
        $r['observadores'] = array_merge($r['observadores'], self::membros($grupos[CommonITILActor::OBSERVER]));
        $r['tecnicos'] = array_merge($r['tecnicos'], self::membros($grupos[CommonITILActor::ASSIGN]));
        return array_map(fn($l) => array_values(array_unique($l)), $r);
    }

    /** Membros ativos dos grupos (glpi_groups não tem is_deleted) */
    public static function membros(array $grupos): array
    {
        global $DB;
        $grupos = array_values(array_filter(array_map('intval', $grupos), fn($v) => $v > 0));
        if (!$grupos) {
            return [];
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['gu.users_id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_groups_users AS gu',
            'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['gu' => 'users_id', 'u' => 'id']]],
            'WHERE'      => ['gu.groups_id' => $grupos, 'u.is_active' => 1, 'u.is_deleted' => 0],
        ]) as $l) {
            $ids[] = (int) $l['users_id'];
        }
        return $ids;
    }

    /** Somente usuários ativos e não excluídos */
    public static function usuariosAtivos(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            return [];
        }
        $ativos = [];
        foreach (array_chunk($ids, 1000) as $lote) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $lote, 'is_active' => 1, 'is_deleted' => 0]]) as $l) {
                $ativos[] = (int) $l['id'];
            }
        }
        return $ativos;
    }

    /** Usuários mencionados com @ no editor rico do GLPI */
    public static function mencoes(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ids = [];
        if (preg_match_all('/<[^>]*data-user-mention="true"[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/data-user-id="(\d+)"/i', $tag, $m)) {
                    $ids[] = (int) $m[1];
                }
            }
        }
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }

    // =====================================================================
    // Tarefa automática: limpeza pelo prazo de retenção
    // =====================================================================

    public static function cronInfo($name)
    {
        return ['description' => 'Notificações: remove as notificações mais antigas que o prazo de retenção'];
    }

    public static function cronNotificacoesLimpar($task = null)
    {
        global $DB;
        $dias = max(7, (int) PluginNotificacoesConfig::getConfig('retencao'));
        $limite = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
        $total = 0;
        do {
            $ids = [];
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABELA,
                'WHERE'  => [
                    'date_creation' => ['<', $limite],
                    'OR'            => [['date_fim' => null], ['date_fim' => ['<', $limite]]],
                ],
                'LIMIT'  => 1000,
            ]) as $r) {
                $ids[] = (int) $r['id'];
            }
            self::apagar($ids);
            $total += count($ids);
        } while (count($ids) === 1000);
        if ($task instanceof CronTask) {
            $task->addVolume($total);
        }
        return $total > 0 ? 1 : 0;
    }
}
