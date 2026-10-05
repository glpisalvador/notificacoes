<?php

/**
 * Plugin Notificações - configurações, catálogo de tipos e utilitários comuns
 */
class PluginNotificacoesConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_notificacoes_configs';
    public const DIREITO = 'plugin_notificacoes_aviso';

    /** Tipos de notificação: chave => [rótulo, ícone, tom, descrição] */
    public const TIPOS = [
        'aviso'              => ['Avisos', 'ti ti-speakerphone', 'aviso', 'Comunicados enviados pela equipe em Ferramentas > Notificações > Avisos.'],
        'acompanhamento'     => ['Acompanhamentos', 'ti ti-message', 'azul', 'Novo acompanhamento em chamado, problema ou mudança em que a pessoa é ator (privados só para os técnicos atribuídos).'],
        'tarefa'             => ['Tarefas', 'ti ti-checkbox', 'ciano', 'Tarefa atribuída à pessoa ou ao grupo técnico dela.'],
        'atribuicao'         => ['Atribuições', 'ti ti-user-check', 'cinza', 'A pessoa ou o grupo dela foi atribuído, ou ela foi incluída como observadora.'],
        'solucao'            => ['Soluções', 'ti ti-circle-check', 'verde', 'Solução proposta em item em que a pessoa é requerente ou observadora.'],
        'validacao_pedido'   => ['Validações solicitadas', 'ti ti-thumb-up', 'aviso', 'Pedido de validação para a pessoa ou para o grupo dela.'],
        'validacao_resposta' => ['Validações respondidas', 'ti ti-clipboard-check', 'verde', 'Resposta (aprovada ou recusada) a uma validação que a pessoa solicitou.'],
        'status'             => ['Mudanças de status', 'ti ti-arrows-exchange', 'cinza', 'Status alterado em item em que a pessoa é requerente ou observadora.'],
        'mencao'             => ['Menções', 'ti ti-at', 'azul', 'A pessoa foi mencionada com @ num acompanhamento, tarefa ou solução.'],
    ];

    public const ITENS = ['Ticket' => 'Chamado', 'Problem' => 'Problema', 'Change' => 'Mudança'];

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
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'perfis_sino'  => [],
            'tipos_ativos' => array_keys(self::TIPOS),
            'intervalo'    => '30',
            'retencao'     => '90',
            'som_padrao'   => '0',
        ];
    }

    /** Configurações lidas uma vez por requisição (o sino consulta várias vezes) */
    private static ?array $notificacoesConfigs = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$notificacoesConfigs === null) {
            self::$notificacoesConfigs = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$notificacoesConfigs[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$notificacoesConfigs)) {
            return self::$notificacoesConfigs[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$notificacoesConfigs = null;
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    /** Tipos ligados na configuração geral */
    public static function tiposAtivos(): array
    {
        return array_values(array_intersect(array_keys(self::TIPOS), array_map('strval', self::getArrayConfig('tipos_ativos'))));
    }

    /** Intervalo de consulta do sino, em segundos (15 a 600) */
    public static function intervalo(): int
    {
        return max(15, min(600, (int) self::getConfig('intervalo')));
    }

    // =====================================================================
    // Listas
    // =====================================================================

    /** glpi_profiles não tem is_deleted */
    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = ['rotulo' => (string) $r['name'], 'detalhe' => $r['interface'] === 'helpdesk' ? 'simplificada' : 'padrão'];
        }
        return $lista;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['firstname ASC', 'realname ASC', 'name ASC'],
        ]) as $u) {
            $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
            $lista[(int) $u['id']] = ['rotulo' => $nome !== '' ? $nome : (string) $u['name'], 'detalhe' => $nome !== '' ? (string) $u['name'] : ''];
        }
        return $lista;
    }

    /** glpi_groups não tem is_deleted */
    public static function listarGrupos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename ASC']) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        return $lista;
    }

    /** Entidades pai e entidades sem filho (as filhas ficam ocultas) */
    public static function listarEntidadesPai(): array
    {
        global $DB;
        $filhas = array_flip(self::idsEntidadesFilhas());
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'ORDER' => 'completename ASC']) as $r) {
            if (!isset($filhas[(int) $r['id']])) {
                $lista[(int) $r['id']] = (string) ($r['completename'] ?: 'Entidade raiz');
            }
        }
        return $lista;
    }

    public static function idsEntidadesFilhas(): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    public static function nomeUsuario(int $id): string
    {
        static $cache = [];
        if ($id <= 0) {
            return '';
        }
        if (!isset($cache[$id])) {
            $cache[$id] = (string) getUserName($id);
        }
        return $cache[$id];
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/notificacoes/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/notificacoes/' . $caminho . '?v=' . PLUGIN_NOTIFICACOES_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    /** CSS e JS das páginas do plugin (o sino já vem pelos hooks globais quando o perfil tem acesso) */
    public static function assets(bool $multiselect = false): string
    {
        static $feito = [];
        $h = '';
        foreach (array_merge(['css/notificacoes.css'], $multiselect ? ['js/multiselect.js'] : []) as $a) {
            if (isset($feito[$a])) {
                continue;
            }
            $feito[$a] = true;
            $h .= str_ends_with($a, '.css')
                ? '<link rel="stylesheet" href="' . self::e(self::urlAsset($a)) . '">'
                : '<script src="' . self::e(self::urlAsset($a)) . '"></script>';
        }
        return $h;
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Script que esconde as entidades filhas dos seletores de entidade do GLPI */
    public static function filtroEntidades(): string
    {
        return '<script>window.notificacoesEntidadesOcultas = ' . json_encode(self::idsEntidadesFilhas()) . ';</script>';
    }

    /** "agora", "há 5 min", "há 3 h", "ontem", "há 4 dias" ou a data */
    public static function tempoRelativo(?string $data): string
    {
        $t = $data ? strtotime($data) : false;
        if (!$t) {
            return '';
        }
        $s = max(0, time() - $t);
        if ($s < 60) {
            return 'agora';
        }
        if ($s < 3600) {
            return 'há ' . floor($s / 60) . ' min';
        }
        if ($s < 86400) {
            return 'há ' . floor($s / 3600) . ' h';
        }
        $dias = (int) floor($s / 86400);
        if ($dias === 1) {
            return 'ontem';
        }
        return $dias < 7 ? 'há ' . $dias . ' dias' : date('d/m/Y', $t);
    }

    /** Texto simples a partir de HTML do GLPI (conteúdo do editor rico) */
    public static function textoSimples(?string $html, int $max = 280): string
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\b[^>]*>#i', ' ', $html);
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $texto = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_strlen($texto) > $max ? rtrim(mb_substr($texto, 0, $max - 1)) . '…' : $texto;
    }

    /**
     * Multiselect com pesquisa, marcar todos e selecionados primeiro.
     * $opcoes: [valor => rótulo] ou [valor => ['rotulo' => ..., 'detalhe' => ...]]
     */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...', array $attrs = []): string
    {
        $selecionados = array_map('strval', $selecionados);
        $itens = [];
        foreach ($opcoes as $valor => $o) {
            $o = is_array($o) ? $o : ['rotulo' => $o];
            $itens[] = ['valor' => (string) $valor, 'marcado' => in_array((string) $valor, $selecionados, true)] + $o + ['detalhe' => ''];
        }
        usort($itens, fn($a, $b) => [$b['marcado'], mb_strtolower($a['rotulo'])] <=> [$a['marcado'], mb_strtolower($b['rotulo'])]);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . self::e($k) . '="' . self::e($v) . '"';
        }
        $h = '<div class="notificacoes-ms" data-notificacoes-ms data-placeholder="' . self::e($placeholder) . '"' . $extra . '>';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="-1">';
        $h .= '<button type="button" class="notificacoes-ms-cabecalho form-select form-select-sm" data-notificacoes-ms-abrir><span class="notificacoes-ms-texto"></span></button>';
        $h .= '<div class="notificacoes-ms-dropdown" hidden>';
        $h .= '<div class="notificacoes-ms-topo"><input type="text" class="form-control form-control-sm notificacoes-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="notificacoes-ms-todos"><input type="checkbox" class="notificacoes-check" data-notificacoes-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="notificacoes-ms-opcoes">';
        foreach ($itens as $i) {
            $h .= '<label class="notificacoes-ms-opcao' . ($i['marcado'] ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower($i['rotulo'] . ' ' . $i['detalhe'])) . '">'
                . '<input type="checkbox" class="notificacoes-check" name="' . self::e($name) . '[]" value="' . self::e($i['valor']) . '"' . ($i['marcado'] ? ' checked' : '') . '>'
                . '<span class="notificacoes-ms-rotulo">' . self::e($i['rotulo']) . ($i['detalhe'] !== '' ? ' <small>' . self::e($i['detalhe']) . '</small>' : '') . '</span>'
                . '</label>';
        }
        $h .= '</div></div><div class="notificacoes-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo, ?array $origem = null): array
    {
        $ids = array_map('intval', (array) (($origem ?? $_POST)[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }
}
