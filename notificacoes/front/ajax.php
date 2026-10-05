<?php

/**
 * Plugin Notificações - endpoint AJAX do sino e da central (sempre JSON).
 * GET: resumo, listar, aviso. POST: ler, nao_lida, ler_todas, ler_item.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginNotificacoesConfig::class;
$K = PluginNotificacoesCaixa::class;
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$responder = function (array $dados) use ($C, $post): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $post ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem, array $extra = []) => $responder(['success' => false, 'mensagem' => $mensagem] + $extra);

$usuario = (int) Session::getLoginUserID();
if ($usuario <= 0) {
    $falhar('Sessão expirada. Recarregue a página.', ['sessao' => false]);
}
if (!$K::temAcesso()) {
    $falhar('Seu perfil não usa o sino de notificações.', ['sem_acesso' => true]);
}
$acao = (string) ($_REQUEST['action'] ?? '');
$exigirPost = function () use ($post, $falhar): void {
    if (!$post) {
        $falhar('Requisição inválida.');
    }
};
$idsPost = fn() => array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($v) => $v > 0));

try {
    switch ($acao) {
        case 'resumo':
            $dados = $K::resumo($usuario, (int) ($_GET['desde'] ?? 0));
            if (!empty($_GET['config'])) {
                global $CFG_GLPI;
                $prefs = $K::preferencias($usuario);
                $tipos = [];
                foreach ($K::tiposVisiveis($usuario) as $t) {
                    $tipos[] = ['chave' => $t, 'rotulo' => $C::TIPOS[$t][0], 'icone' => $C::TIPOS[$t][1]];
                }
                $dados['config'] = [
                    'intervalo'    => $C::intervalo(),
                    'navegador'    => $prefs['navegador'],
                    'som'          => $prefs['som'],
                    'ler_ao_abrir' => $prefs['ler_ao_abrir'],
                    'usuario'      => $usuario,
                    'tipos'        => $tipos,
                    'central'      => $C::url('notificacoes.php'),
                    'preferencias' => $C::url('notificacoes.php', ['aba' => 'preferencias']),
                    'icone'        => $CFG_GLPI['root_doc'] . '/pics/favicon.ico',
                    'raiz'         => $CFG_GLPI['root_doc'],
                ];
            }
            $responder(['success' => true] + $dados);

        case 'listar':
            $estado = (string) ($_GET['estado'] ?? 'todas');
            $tipo = (string) ($_GET['tipo'] ?? '');
            $lista = $K::listar($usuario, [
                'estado' => in_array($estado, ['todas', 'nao_lidas', 'lidas'], true) ? $estado : 'todas',
                'tipo'   => isset($C::TIPOS[$tipo]) ? [$tipo] : [],
                'busca'  => mb_substr((string) ($_GET['busca'] ?? ''), 0, 100),
                'inicio' => (int) ($_GET['inicio'] ?? 0),
                'limite' => (int) ($_GET['limite'] ?? 15),
            ]);
            $responder(['success' => true, 'nao_lidas' => $K::resumo($usuario)['nao_lidas']] + $lista);

        case 'aviso':
            $aviso = $K::aviso($usuario, (int) ($_GET['id'] ?? 0));
            if ($aviso === null) {
                $falhar('Aviso não encontrado ou fora do período de exibição.');
            }
            $responder(['success' => true, 'aviso' => $aviso, 'nao_lidas' => $K::resumo($usuario)['nao_lidas']]);

        case 'ler':
        case 'nao_lida':
            $exigirPost();
            $n = $K::marcar($usuario, $idsPost(), $acao === 'ler');
            $responder(['success' => true, 'alteradas' => $n, 'nao_lidas' => $K::resumo($usuario)['nao_lidas']]);

        case 'ler_todas':
            $exigirPost();
            $tipo = (string) ($_POST['tipo'] ?? '');
            $n = $K::marcarTodas($usuario, isset($C::TIPOS[$tipo]) ? $tipo : '');
            $responder(['success' => true, 'alteradas' => $n, 'nao_lidas' => $K::resumo($usuario)['nao_lidas'], 'mensagem' => $n === 1 ? '1 notificação marcada como lida.' : $n . ' notificações marcadas como lidas.']);

        case 'ler_item':
            $exigirPost();
            $n = $K::preferencias($usuario)['ler_ao_abrir'] ? $K::lerItem($usuario, (string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0)) : 0;
            $responder(['success' => true, 'alteradas' => $n, 'nao_lidas' => $K::resumo($usuario)['nao_lidas']]);
    }
    $falhar('Ação desconhecida.');
} catch (\Throwable $e) {
    error_log('Plugin notificacoes: ' . $e->getMessage());
    $falhar('Não foi possível concluir a operação.');
}
