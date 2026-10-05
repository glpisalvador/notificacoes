<?php

/**
 * Plugin Notificações - central de notificações do usuário (lista completa e preferências)
 */

Session::checkLoginUser();

$C = PluginNotificacoesConfig::class;
$K = PluginNotificacoesCaixa::class;
$e = [$C, 'e'];

if (!$K::temAcesso()) {
    if (PluginNotificacoesAviso::canView()) {
        Html::redirect($C::url('aviso.php'));
    }
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$usuario = (int) Session::getLoginUserID();
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'lista');
$aba = in_array($aba, ['lista', 'preferencias'], true) ? $aba : 'lista';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_preferencias':
            $ligados = array_map('strval', (array) ($_POST['tipos'] ?? []));
            $K::salvarPreferencias($usuario, [
                'tipos_desligados' => array_values(array_diff($C::tiposAtivos(), $ligados)),
                'navegador'        => !empty($_POST['navegador']),
                'som'              => !empty($_POST['som']),
                'ler_ao_abrir'     => !empty($_POST['ler_ao_abrir']),
            ]);
            Session::addMessageAfterRedirect('Preferências salvas.', false, INFO);
            break;
    }
}

// Filtros da lista
$estado = (string) ($_GET['estado'] ?? 'nao_lidas');
$estado = in_array($estado, ['nao_lidas', 'todas', 'lidas'], true) ? $estado : 'nao_lidas';
$tipo = (string) ($_GET['tipo'] ?? '');
$tipo = isset($C::TIPOS[$tipo]) ? $tipo : '';
$dias = (int) ($_GET['dias'] ?? 0);
$dias = in_array($dias, [1, 7, 30, 90], true) ? $dias : 0;
$busca = mb_substr(trim((string) ($_GET['busca'] ?? '')), 0, 100);
$porPagina = 25;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$filtros = ['estado' => $estado, 'tipo' => $tipo !== '' ? [$tipo] : [], 'dias' => $dias, 'busca' => $busca, 'limite' => $porPagina, 'inicio' => ($pagina - 1) * $porPagina];
$lista = $K::listar($usuario, $filtros);
if (!$lista['itens'] && $pagina > 1 && $lista['total'] > 0) {
    $pagina = (int) ceil($lista['total'] / $porPagina);
    $filtros['inicio'] = ($pagina - 1) * $porPagina;
    $lista = $K::listar($usuario, $filtros);
}
$naoLidas = $K::resumo($usuario)['nao_lidas'];
$porTipo = $K::porTipo($usuario);
$visiveis = $K::tiposVisiveis($usuario);
$prefs = $K::preferencias($usuario);
$link = function (array $mudar) use ($C, $estado, $tipo, $dias, $busca) {
    $p = array_filter(array_merge(['estado' => $estado, 'tipo' => $tipo, 'dias' => $dias ?: '', 'busca' => $busca], $mudar), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return $C::url('notificacoes.php', $p);
};

Html::header('Notificações', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginNotificacoesMenu');
echo $C::assets(true);

echo '<div class="notificacoes-pagina" data-notificacoes-central data-ajax="' . $e($C::url('ajax.php')) . '">';
echo '<ul class="nav nav-tabs notificacoes-abas">'
    . '<li class="nav-item"><a class="nav-link' . ($aba === 'lista' ? ' active' : '') . '" href="#" data-aba="lista"><i class="ti ti-bell"></i> Minhas notificações'
    . ($naoLidas > 0 ? ' <span class="notificacoes-contador" data-notificacoes-total>' . (int) $naoLidas . '</span>' : '') . '</a></li>'
    . '<li class="nav-item"><a class="nav-link' . ($aba === 'preferencias' ? ' active' : '') . '" href="#" data-aba="preferencias"><i class="ti ti-adjustments"></i> Preferências</a></li>';
if (PluginNotificacoesAviso::canView()) {
    echo '<li class="nav-item ms-auto"><a class="nav-link" href="' . $e($C::url('aviso.php')) . '"><i class="ti ti-speakerphone"></i> Avisos</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- lista
echo '<div data-aba-painel="lista"' . ($aba !== 'lista' ? ' hidden' : '') . '>';
echo '<div class="card notificacoes-card"><div class="card-header notificacoes-barra">';
echo '<div class="btn-group notificacoes-estados" role="group">';
foreach (['nao_lidas' => 'Não lidas', 'todas' => 'Todas', 'lidas' => 'Lidas'] as $chave => $rotulo) {
    echo '<a class="btn btn-sm ' . ($estado === $chave ? 'btn-secondary' : 'btn-outline-secondary') . '" href="' . $e($link(['estado' => $chave])) . '">' . $e($rotulo)
        . ($chave === 'nao_lidas' && $naoLidas > 0 ? ' <span class="notificacoes-contador">' . (int) $naoLidas . '</span>' : '') . '</a>';
}
echo '</div>';
echo '<form method="get" action="' . $e($C::url('notificacoes.php')) . '" class="notificacoes-filtros">'
    . '<input type="hidden" name="estado" value="' . $e($estado) . '">'
    . '<select name="tipo" class="form-select form-select-sm" data-notificacoes-auto><option value="">Todos os tipos</option>';
foreach ($visiveis as $t) {
    echo '<option value="' . $e($t) . '"' . ($t === $tipo ? ' selected' : '') . '>' . $e($C::TIPOS[$t][0]) . (!empty($porTipo[$t]) ? ' (' . (int) $porTipo[$t] . ')' : '') . '</option>';
}
echo '</select><select name="dias" class="form-select form-select-sm" data-notificacoes-auto>';
foreach ([0 => 'Qualquer data', 1 => 'Últimas 24 horas', 7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias'] as $d => $rotulo) {
    echo '<option value="' . $d . '"' . ($d === $dias ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
}
echo '</select><div class="notificacoes-busca"><i class="ti ti-search"></i><input type="search" name="busca" class="form-control form-control-sm" placeholder="Pesquisar título, item ou texto..." value="' . $e($busca) . '"></div>'
    . '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-filter"></i><span>Filtrar</span></button></form>';
echo '<div class="notificacoes-acoes-barra">'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-selecionadas="ler" disabled><i class="ti ti-check"></i><span>Marcar selecionadas</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-selecionadas="nao_lida" disabled><i class="ti ti-mail"></i><span>Marcar como não lidas</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-todas="' . $e($tipo) . '"' . ($naoLidas > 0 ? '' : ' disabled') . '><i class="ti ti-checks"></i><span>Marcar todas como lidas</span></button>'
    . '</div>';
echo '</div><div class="card-body p-0">';

if (!$lista['itens']) {
    $texto = $estado === 'nao_lidas' && $tipo === '' && $busca === '' && !$dias ? 'Tudo em dia: nenhuma notificação não lida.' : 'Nenhuma notificação encontrada com esses filtros.';
    echo '<div class="notificacoes-vazio"><i class="ti ti-bell-check"></i><span>' . $e($texto) . '</span></div>';
} else {
    echo '<label class="notificacoes-selecionar-todas"><input type="checkbox" class="notificacoes-check" data-notificacoes-marcar-pagina> Selecionar todas desta página</label>';
    echo '<ul class="notificacoes-lista notificacoes-lista-grande">';
    foreach ($lista['itens'] as $n) {
        echo '<li class="notificacoes-item' . ($n['lida'] ? '' : ' nao-lida') . '" data-id="' . (int) $n['id'] . '">'
            . '<input type="checkbox" class="notificacoes-check" data-notificacoes-selecao value="' . (int) $n['id'] . '" aria-label="Selecionar">'
            . '<span class="notificacoes-icone notificacoes-tom-' . $e($n['tom']) . '"><i class="' . $e($n['icone']) . '"></i></span>'
            . '<a class="notificacoes-corpo" href="' . $e($n['url'] ?: '#') . '"' . ($n['aviso'] ? ' data-notificacoes-aviso="' . (int) $n['id'] . '"' : '') . ' data-notificacoes-abrir>'
            . '<span class="notificacoes-linha1"><span class="notificacoes-titulo">' . $e($n['titulo']) . '</span><span class="notificacoes-rotulo">' . $e($n['rotulo']) . '</span></span>'
            . ($n['referencia'] !== '' ? '<span class="notificacoes-ref">' . $e($n['referencia']) . '</span>' : '')
            . ($n['conteudo'] !== '' ? '<span class="notificacoes-texto">' . $e($n['conteudo']) . '</span>' : '')
            . '<span class="notificacoes-meta">' . ($n['autor'] !== '' ? '<i class="ti ti-user"></i> ' . $e($n['autor']) . ' · ' : '') . '<span title="' . $e($n['data']) . '">' . $e($n['tempo']) . '</span> · ' . $e($n['data']) . '</span>'
            . '</a>'
            . '<span class="notificacoes-acoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-alternar title="' . ($n['lida'] ? 'Marcar como não lida' : 'Marcar como lida') . '"><i class="ti ' . ($n['lida'] ? 'ti-mail' : 'ti-check') . '"></i></button></span>'
            . '</li>';
    }
    echo '</ul>';
}
echo '</div>';

// Paginação
$paginas = (int) ceil($lista['total'] / $porPagina);
echo '<div class="card-footer notificacoes-rodape"><span class="notificacoes-pequeno">' . (int) $lista['total'] . ' notificação(ões)'
    . ($lista['total'] > 0 ? ' · exibindo ' . ($lista['inicio'] + 1) . '–' . min($lista['total'], $lista['inicio'] + $porPagina) : '') . '</span>';
if ($paginas > 1) {
    echo '<nav class="notificacoes-paginacao">';
    $botao = fn(int $p, string $rotulo, bool $ativo = false, bool $desligado = false) => '<a class="btn btn-sm ' . ($ativo ? 'btn-secondary' : 'btn-ghost-secondary') . ($desligado ? ' disabled' : '') . '" href="' . $e($link(['pagina' => $p])) . '">' . $rotulo . '</a>';
    echo $botao(max(1, $pagina - 1), '<i class="ti ti-chevron-left"></i>', false, $pagina <= 1);
    $faixa = array_unique(array_filter([1, $pagina - 2, $pagina - 1, $pagina, $pagina + 1, $pagina + 2, $paginas], fn($p) => $p >= 1 && $p <= $paginas));
    sort($faixa);
    $anterior = 0;
    foreach ($faixa as $p) {
        if ($p - $anterior > 1) {
            echo '<span class="notificacoes-reticencias">…</span>';
        }
        echo $botao($p, (string) $p, $p === $pagina);
        $anterior = $p;
    }
    echo $botao(min($paginas, $pagina + 1), '<i class="ti ti-chevron-right"></i>', false, $pagina >= $paginas);
    echo '</nav>';
}
echo '</div></div></div>';

// ---------------------------------------------------------------- preferências
echo '<div data-aba-painel="preferencias"' . ($aba !== 'preferencias' ? ' hidden' : '') . '>';
echo '<form method="post" action="' . $e($C::url('notificacoes.php')) . '">'
    . '<input type="hidden" name="save_action" value="salvar_preferencias"><input type="hidden" name="aba" value="preferencias">';
echo '<div class="notificacoes-grade-2">';
echo '<div class="card notificacoes-card"><div class="card-header"><h5><i class="ti ti-list-check"></i> O que eu quero receber</h5></div><div class="card-body">';
echo '<p class="notificacoes-explicacao"><i class="ti ti-info-circle"></i><span>Desligar um tipo esconde também as notificações dele que já chegaram; ao religar, elas voltam.</span></p>';
foreach ($C::tiposAtivos() as $t) {
    [$rotulo, $icone, , $descricao] = $C::TIPOS[$t];
    echo '<div class="form-check form-switch notificacoes-switch notificacoes-switch-linha">'
        . '<input class="form-check-input" type="checkbox" id="notificacoes-tipo-' . $e($t) . '" name="tipos[]" value="' . $e($t) . '"' . (!in_array($t, $prefs['tipos_desligados'], true) ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="notificacoes-tipo-' . $e($t) . '"><span><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</span><small>' . $e($descricao) . '</small></label></div>';
}
echo '</div></div>';

echo '<div class="card notificacoes-card"><div class="card-header"><h5><i class="ti ti-device-desktop"></i> Como eu quero ser avisado</h5></div><div class="card-body">';
$switch = fn(string $nome, string $rotulo, string $descricao, bool $ligado) => '<div class="form-check form-switch notificacoes-switch notificacoes-switch-linha">'
    . '<input class="form-check-input" type="checkbox" id="notificacoes-pref-' . $nome . '" name="' . $nome . '" value="1"' . ($ligado ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="notificacoes-pref-' . $nome . '">' . $e($rotulo) . '<small>' . $e($descricao) . '</small></label></div>';
echo $switch('navegador', 'Aviso do navegador', 'Mostra um aviso do sistema operacional quando chega notificação nova, mesmo com o GLPI em outra aba.', $prefs['navegador']);
echo '<div class="notificacoes-permissao" data-notificacoes-permissao hidden><span data-notificacoes-permissao-texto></span>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary" data-notificacoes-pedir-permissao><i class="ti ti-bell-ringing"></i><span>Permitir neste navegador</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-testar><i class="ti ti-send"></i><span>Testar</span></button></div>';
echo $switch('som', 'Som discreto', 'Toca um som curto quando chega notificação nova.', $prefs['som']);
echo $switch('ler_ao_abrir', 'Marcar como lidas ao abrir o item', 'Ao abrir um chamado, problema ou mudança, as notificações dele ficam lidas.', $prefs['ler_ao_abrir']);
echo '</div></div></div>';
echo '<div class="notificacoes-rodape-form"><button type="submit" class="btn btn-sm notificacoes-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar preferências</span></button></div>';
echo Html::closeForm(false);
echo '</div>';

echo '</div>';
Html::footer();
