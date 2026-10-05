<?php

use Glpi\DBAL\QueryExpression;

/**
 * Plugin Notificações - a caixa de cada usuário: contador, lista, leitura e preferências
 */
class PluginNotificacoesCaixa
{
    public const PREFERENCIAS = 'glpi_plugin_notificacoes_preferencias';

    /** Perfil ativo liberado para o sino (lista vazia = todos os perfis) */
    public static function temAcesso(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        $perfis = PluginNotificacoesConfig::ids('perfis_sino');
        return !$perfis || in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $perfis, true);
    }

    // =====================================================================
    // Preferências
    // =====================================================================

    public static function preferencias(int $usuario): array
    {
        global $DB;
        $p = [
            'tipos_desligados' => [],
            'navegador'        => true,
            'som'              => PluginNotificacoesConfig::getConfig('som_padrao') === '1',
            'ler_ao_abrir'     => true,
        ];
        foreach ($DB->request(['FROM' => self::PREFERENCIAS, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1]) as $r) {
            $p['tipos_desligados'] = array_values(array_intersect(array_keys(PluginNotificacoesConfig::TIPOS), json_decode((string) $r['tipos_desligados'], true) ?: []));
            $p['navegador'] = (bool) $r['navegador'];
            $p['som'] = (bool) $r['som'];
            $p['ler_ao_abrir'] = (bool) $r['ler_ao_abrir'];
        }
        return $p;
    }

    public static function salvarPreferencias(int $usuario, array $p): bool
    {
        global $DB;
        $dados = [
            'tipos_desligados' => json_encode(array_values(array_intersect(array_keys(PluginNotificacoesConfig::TIPOS), array_map('strval', (array) ($p['tipos_desligados'] ?? []))))),
            'navegador'        => empty($p['navegador']) ? 0 : 1,
            'som'              => empty($p['som']) ? 0 : 1,
            'ler_ao_abrir'     => empty($p['ler_ao_abrir']) ? 0 : 1,
        ];
        if (count($DB->request(['FROM' => self::PREFERENCIAS, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1])) > 0) {
            return (bool) $DB->update(self::PREFERENCIAS, $dados, ['users_id' => $usuario]);
        }
        return (bool) $DB->insert(self::PREFERENCIAS, $dados + ['users_id' => $usuario]);
    }

    /** Tipos ligados na configuração e não desligados pela pessoa */
    public static function tiposVisiveis(int $usuario): array
    {
        return array_values(array_diff(PluginNotificacoesConfig::tiposAtivos(), self::preferencias($usuario)['tipos_desligados']));
    }

    // =====================================================================
    // Consultas
    // =====================================================================

    /** Critério base: notificações da pessoa, já liberadas, ainda válidas e de tipos visíveis */
    private static function criterio(int $usuario, array $filtros = []): array
    {
        $agora = date('Y-m-d H:i:s');
        $tipos = self::tiposVisiveis($usuario);
        if (!empty($filtros['tipo'])) {
            $tipos = array_values(array_intersect($tipos, (array) $filtros['tipo']));
        }
        $where = [
            'd.users_id'      => $usuario,
            'e.is_hidden'     => 0,
            'e.tipo'          => $tipos ?: ['-'],
            'e.date_creation' => ['<=', $agora],
            ['OR' => [['e.date_fim' => null], ['e.date_fim' => ['>', $agora]]]],
        ];
        $estado = (string) ($filtros['estado'] ?? 'todas');
        if ($estado === 'nao_lidas') {
            $where['d.is_read'] = 0;
        } elseif ($estado === 'lidas') {
            $where['d.is_read'] = 1;
        }
        if (!empty($filtros['desde'])) {
            $where[] = ['e.id' => ['>', (int) $filtros['desde']]];
        }
        if (!empty($filtros['dias'])) {
            $where[] = ['e.date_creation' => ['>=', date('Y-m-d H:i:s', strtotime('-' . (int) $filtros['dias'] . ' days'))]];
        }
        $busca = trim((string) ($filtros['busca'] ?? ''));
        if ($busca !== '') {
            $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
            $where[] = ['OR' => [['e.titulo' => ['LIKE', $termo]], ['e.referencia' => ['LIKE', $termo]], ['e.conteudo' => ['LIKE', $termo]]]];
        }
        return [
            'FROM'       => PluginNotificacoesEvento::DESTINOS . ' AS d',
            'INNER JOIN' => [PluginNotificacoesEvento::TABELA . ' AS e' => ['ON' => ['d' => 'plugin_notificacoes_eventos_id', 'e' => 'id']]],
            'WHERE'      => $where,
        ];
    }

    private static function contar(array $criterio): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => [new QueryExpression('COUNT(*) AS total')]] + $criterio) as $r) {
            return (int) $r['total'];
        }
        return 0;
    }

    /** Contador do sino; com $desde, também as não lidas mais novas que esse id (para o aviso do navegador) */
    public static function resumo(int $usuario, int $desde = 0): array
    {
        global $DB;
        $naoLidas = self::contar(self::criterio($usuario, ['estado' => 'nao_lidas']));
        $ultimo = 0;
        foreach ($DB->request(['SELECT' => [new QueryExpression('MAX(e.id) AS ultimo')]] + self::criterio($usuario)) as $r) {
            $ultimo = (int) $r['ultimo'];
        }
        $novas = [];
        if ($desde > 0 && $ultimo > $desde) {
            $novas = self::listar($usuario, ['estado' => 'nao_lidas', 'desde' => $desde, 'limite' => 5])['itens'];
        }
        return ['nao_lidas' => $naoLidas, 'ultimo_id' => $ultimo, 'novas' => $novas];
    }

    /** Lista paginada. Filtros: estado (todas, nao_lidas, lidas), tipo, busca, dias, inicio, limite, desde */
    public static function listar(int $usuario, array $filtros = []): array
    {
        global $DB;
        $criterio = self::criterio($usuario, $filtros);
        $limite = max(1, min(100, (int) ($filtros['limite'] ?? 20)));
        $inicio = max(0, (int) ($filtros['inicio'] ?? 0));
        $itens = [];
        foreach ($DB->request([
            'SELECT' => ['e.*', 'd.is_read', 'd.date_read'],
            'ORDER'  => ['e.id DESC'],
            'LIMIT'  => $limite,
            'START'  => $inicio,
        ] + $criterio) as $r) {
            $itens[] = self::formatar($r);
        }
        return ['itens' => $itens, 'total' => self::contar($criterio), 'inicio' => $inicio, 'limite' => $limite];
    }

    /** Não lidas por tipo (chips de filtro) */
    public static function porTipo(int $usuario): array
    {
        global $DB;
        $contagem = [];
        foreach ($DB->request(['SELECT' => ['e.tipo', new QueryExpression('COUNT(*) AS total')], 'GROUPBY' => 'e.tipo'] + self::criterio($usuario, ['estado' => 'nao_lidas'])) as $r) {
            $contagem[(string) $r['tipo']] = (int) $r['total'];
        }
        return $contagem;
    }

    public static function formatar(array $r): array
    {
        $C = PluginNotificacoesConfig::class;
        $tipo = (string) $r['tipo'];
        [$rotulo, $icone, $tom] = $C::TIPOS[$tipo] ?? ['Notificação', 'ti ti-bell', 'cinza'];
        $url = '';
        if (isset($C::ITENS[$r['itemtype']]) && (int) $r['items_id'] > 0) {
            $classe = (string) $r['itemtype'];
            $url = $classe::getFormURLWithID((int) $r['items_id']);
        }
        $nivel = (string) $r['nivel'];
        if ($tipo === 'aviso') {
            $icone = PluginNotificacoesAviso::PRIORIDADES[$nivel][1] ?? $icone;
        }
        return [
            'id'         => (int) $r['id'],
            'tipo'       => $tipo,
            'rotulo'     => $rotulo,
            'icone'      => $icone,
            'tom'        => $tipo === 'aviso' ? 'aviso-' . ($nivel ?: 'normal') : ($nivel === 'erro' ? 'vermelho' : $tom),
            'titulo'     => (string) $r['titulo'],
            'referencia' => (string) $r['referencia'],
            'conteudo'   => (string) $r['conteudo'],
            'autor'      => (int) $r['users_id'] > 0 ? $C::nomeUsuario((int) $r['users_id']) : '',
            'tempo'      => $C::tempoRelativo((string) $r['date_creation']),
            'data'       => Html::convDateTime((string) $r['date_creation']),
            'lida'       => (bool) $r['is_read'],
            'url'        => $url,
            'aviso'      => (int) $r['plugin_notificacoes_avisos_id'],
            'nivel'      => $nivel,
        ];
    }

    // =====================================================================
    // Leitura
    // =====================================================================

    /** Marca (ou desmarca) notificações da própria pessoa */
    public static function marcar(int $usuario, array $eventos, bool $lida = true): int
    {
        global $DB;
        $eventos = array_values(array_unique(array_filter(array_map('intval', $eventos), fn($v) => $v > 0)));
        if (!$eventos) {
            return 0;
        }
        $DB->update(PluginNotificacoesEvento::DESTINOS, ['is_read' => $lida ? 1 : 0, 'date_read' => $lida ? date('Y-m-d H:i:s') : null], [
            'users_id'                       => $usuario,
            'plugin_notificacoes_eventos_id' => $eventos,
            'is_read'                        => $lida ? 0 : 1,
        ]);
        return (int) $DB->affectedRows();
    }

    /** Todas as não lidas visíveis (opcionalmente de um tipo) */
    public static function marcarTodas(int $usuario, string $tipo = ''): int
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['e.id']] + self::criterio($usuario, ['estado' => 'nao_lidas', 'tipo' => $tipo !== '' ? [$tipo] : []])) as $r) {
            $ids[] = (int) $r['id'];
        }
        $total = 0;
        foreach (array_chunk($ids, 500) as $lote) {
            $total += self::marcar($usuario, $lote);
        }
        return $total;
    }

    /** Ao abrir um chamado, problema ou mudança: as notificações dele ficam lidas */
    public static function lerItem(int $usuario, string $itemtype, int $id): int
    {
        global $DB;
        if (!isset(PluginNotificacoesConfig::ITENS[$itemtype]) || $id <= 0) {
            return 0;
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['e.id'],
            'FROM'       => PluginNotificacoesEvento::DESTINOS . ' AS d',
            'INNER JOIN' => [PluginNotificacoesEvento::TABELA . ' AS e' => ['ON' => ['d' => 'plugin_notificacoes_eventos_id', 'e' => 'id']]],
            'WHERE'      => ['d.users_id' => $usuario, 'd.is_read' => 0, 'e.itemtype' => $itemtype, 'e.items_id' => $id],
        ]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return self::marcar($usuario, $ids);
    }

    /** Conteúdo completo de um aviso recebido pela pessoa (marca como lido) */
    public static function aviso(int $usuario, int $evento): ?array
    {
        global $DB;
        foreach ($DB->request([
            'SELECT'     => ['e.id', 'e.plugin_notificacoes_avisos_id', 'e.date_creation'],
            'FROM'       => PluginNotificacoesEvento::DESTINOS . ' AS d',
            'INNER JOIN' => [PluginNotificacoesEvento::TABELA . ' AS e' => ['ON' => ['d' => 'plugin_notificacoes_eventos_id', 'e' => 'id']]],
            'WHERE'      => ['d.users_id' => $usuario, 'e.id' => $evento, 'e.tipo' => 'aviso', 'e.is_hidden' => 0],
            'LIMIT'      => 1,
        ]) as $r) {
            $aviso = new PluginNotificacoesAviso();
            if (!$aviso->getFromDB((int) $r['plugin_notificacoes_avisos_id'])) {
                return null;
            }
            self::marcar($usuario, [$evento]);
            $prioridade = (string) $aviso->fields['prioridade'];
            return [
                'titulo'     => (string) $aviso->fields['name'],
                'html'       => self::embutirImagens(\Glpi\RichText\RichText::getSafeHtml((string) $aviso->fields['conteudo'])),
                'autor'      => PluginNotificacoesConfig::nomeUsuario((int) $aviso->fields['users_id']),
                'data'       => Html::convDateTime((string) $r['date_creation']),
                'prioridade' => PluginNotificacoesAviso::PRIORIDADES[$prioridade][0] ?? '',
                'nivel'      => $prioridade,
                'validade'   => $aviso->fields['date_fim'] ? Html::convDateTime((string) $aviso->fields['date_fim']) : '',
            ];
        }
        return null;
    }

    /**
     * Imagens coladas no aviso viram documentos do GLPI, que só abrem para quem tem acesso ao aviso.
     * Para qualquer destinatário vê-las, elas são enviadas embutidas (até 3 MB cada).
     */
    private static function embutirImagens(string $html): string
    {
        return (string) preg_replace_callback('/src="([^"]*document\.send\.php\?(?:[^"]*?&(?:amp;)?)?docid=(\d+)[^"]*)"/i', function ($m) {
            $doc = new Document();
            if (!$doc->getFromDB((int) $m[2]) || !str_starts_with((string) $doc->fields['mime'], 'image/')) {
                return $m[0];
            }
            $arquivo = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
            if (!is_file($arquivo) || filesize($arquivo) > 3 * 1024 * 1024) {
                return $m[0];
            }
            return 'src="data:' . $doc->fields['mime'] . ';base64,' . base64_encode((string) file_get_contents($arquivo)) . '"';
        }, $html);
    }
}
