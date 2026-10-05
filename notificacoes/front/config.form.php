<?php

/**
 * Plugin Notificações - configuração (marketplace e menu). Cada aba é um formulário próprio
 * que faz POST para esta mesma página.
 */

use Glpi\DBAL\QueryExpression;

Session::checkLoginUser();

global $DB;
$C = PluginNotificacoesConfig::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'geral'    => ['ti ti-settings', 'Geral'],
    'tipos'    => ['ti ti-list-check', 'Tipos de notificação'],
    'avisos'   => ['ti ti-shield-lock', 'Quem envia avisos'],
    'situacao' => ['ti ti-chart-bar', 'Situação'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'geral');
if (!isset($ABAS[$aba])) {
    $aba = 'geral';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            $C::setArrayConfig('perfis_sino', $C::idsPost('perfis_sino'));
            $C::setConfig('intervalo', (string) max(15, min(600, (int) ($_POST['intervalo'] ?? 30))));
            $C::setConfig('retencao', (string) max(7, min(3650, (int) ($_POST['retencao'] ?? 90))));
            $C::setConfig('som_padrao', empty($_POST['som_padrao']) ? '0' : '1');
            Session::addMessageAfterRedirect('Configuração geral salva.', false, INFO);
            break;

        case 'salvar_tipos':
            $tipos = array_values(array_intersect(array_keys($C::TIPOS), array_map('strval', (array) ($_POST['tipos'] ?? []))));
            $C::setArrayConfig('tipos_ativos', $tipos);
            Session::addMessageAfterRedirect('Tipos de notificação salvos.', false, INFO);
            break;

        case 'limpar_agora':
            $antes = countElementsInTable(PluginNotificacoesEvento::TABELA);
            PluginNotificacoesEvento::cronNotificacoesLimpar();
            $removidas = $antes - countElementsInTable(PluginNotificacoesEvento::TABELA);
            Session::addMessageAfterRedirect($removidas > 0 ? $removidas . ' notificação(ões) antiga(s) removida(s).' : 'Nenhuma notificação passou do prazo de retenção.', false, INFO);
            break;
    }
}

Html::header('Notificações', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginNotificacoesMenu');

$form = fn(string $acao, string $abaForm) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="notificacoes-form">'
    . '<input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($abaForm) . '">';
$salvar = '<div class="notificacoes-rodape-form"><button type="submit" class="btn btn-sm notificacoes-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card notificacoes-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$explicacao = fn(string $texto) => '<p class="notificacoes-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';
$campo = fn(string $rotulo, string $controle, string $dica = '', string $for = '') => '<div class="notificacoes-campo"><label' . ($for !== '' ? ' for="' . $e($for) . '"' : '') . '>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';

echo '<div class="notificacoes-pagina notificacoes-config" data-notificacoes-config>';
echo '<ul class="nav nav-tabs notificacoes-abas">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- Geral
echo '<div data-aba-painel="geral"' . ($aba !== 'geral' ? ' hidden' : '') . '>' . $form('salvar_geral', 'geral');
$corpo = $explicacao('O sino aparece na barra superior, ao lado do menu do usuário. Ele consulta o servidor no intervalo escolhido e, quando a aba volta a ficar visível, na hora.')
    . '<div class="notificacoes-grade-2">'
    . $campo('Perfis com o sino', $C::multiselect('perfis_sino', $C::listarPerfis(), $C::ids('perfis_sino'), 'Todos os perfis'), 'Vazio: todos os perfis, inclusive os da interface simplificada.')
    . '<div>'
    . $campo('Intervalo de consulta (segundos)', '<input type="number" class="form-control form-control-sm notificacoes-numero" id="notificacoes-cfg-intervalo" name="intervalo" min="15" max="600" value="' . (int) $C::intervalo() . '">', 'Entre 15 e 600. A consulta é leve: só o contador; a lista é baixada quando muda.', 'notificacoes-cfg-intervalo')
    . $campo('Guardar notificações por (dias)', '<input type="number" class="form-control form-control-sm notificacoes-numero" id="notificacoes-cfg-retencao" name="retencao" min="7" max="3650" value="' . (int) $C::getConfig('retencao') . '">', 'A tarefa automática "NotificacoesLimpar" remove as mais antigas uma vez por dia. Avisos com data final futura são mantidos.', 'notificacoes-cfg-retencao')
    . '<div class="form-check form-switch notificacoes-switch notificacoes-switch-linha"><input type="hidden" name="som_padrao" value="0">'
    . '<input class="form-check-input" type="checkbox" id="notificacoes-cfg-som" name="som_padrao" value="1"' . ($C::getConfig('som_padrao') === '1' ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="notificacoes-cfg-som">Som ligado por padrão<small>Vale para quem ainda não salvou as próprias preferências.</small></label></div>'
    . '</div></div>';
echo $card('ti ti-bell', 'Sino de notificações', $corpo) . $salvar;
echo Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Tipos
echo '<div data-aba-painel="tipos"' . ($aba !== 'tipos' ? ' hidden' : '') . '>' . $form('salvar_tipos', 'tipos');
$ativos = $C::tiposAtivos();
$corpo = $explicacao('Tipos desligados aqui deixam de ser gerados e somem do sino de todos. Cada pessoa ainda pode desligar os tipos que não quer em <strong>Notificações &gt; Preferências</strong>. Quem faz a ação nunca recebe a própria notificação.')
    . '<div class="notificacoes-grade-2">';
foreach ($C::TIPOS as $t => [$rotulo, $icone, , $descricao]) {
    $corpo .= '<div class="form-check form-switch notificacoes-switch notificacoes-switch-linha">'
        . '<input class="form-check-input" type="checkbox" id="notificacoes-cfg-tipo-' . $e($t) . '" name="tipos[]" value="' . $e($t) . '"' . (in_array($t, $ativos, true) ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="notificacoes-cfg-tipo-' . $e($t) . '"><span><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</span><small>' . $e($descricao) . '</small></label></div>';
}
$corpo .= '<input type="hidden" name="tipos[]" value=""></div>';
echo $card('ti ti-list-check', 'O que gera notificação', $corpo) . $salvar;
echo Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Quem envia avisos
echo '<div data-aba-painel="avisos"' . ($aba !== 'avisos' ? ' hidden' : '') . '>';
$direitos = [];
foreach ($DB->request(['SELECT' => ['pr.profiles_id', 'pr.rights', 'p.name'], 'FROM' => 'glpi_profilerights AS pr', 'INNER JOIN' => ['glpi_profiles AS p' => ['ON' => ['pr' => 'profiles_id', 'p' => 'id']]], 'WHERE' => ['pr.name' => $C::DIREITO], 'ORDER' => 'p.name ASC']) as $r) {
    $direitos[] = $r;
}
$nomesDireitos = [READ => 'Ver', CREATE => 'Criar', UPDATE => 'Editar', DELETE => 'Lixeira', PURGE => 'Excluir'];
$corpo = $explicacao('Enviar avisos usa o direito nativo do GLPI: em Administração &gt; Perfis, aba <strong>Notificações</strong>. Receber notificações não depende desse direito, só dos perfis liberados na aba Geral.')
    . '<div class="table-responsive"><table class="table table-sm table-hover notificacoes-tabela"><thead><tr><th>Perfil</th>';
foreach ($nomesDireitos as $rotulo) {
    $corpo .= '<th class="text-center">' . $e($rotulo) . '</th>';
}
$corpo .= '<th></th></tr></thead><tbody>';
foreach ($direitos as $r) {
    $corpo .= '<tr><td>' . $e($r['name']) . '</td>';
    foreach (array_keys($nomesDireitos) as $bit) {
        $corpo .= '<td class="text-center">' . (((int) $r['rights'] & $bit) === $bit ? '<i class="ti ti-check notificacoes-sim"></i>' : '<span class="notificacoes-nao">—</span>') . '</td>';
    }
    $corpo .= '<td class="text-end"><a class="btn btn-sm btn-ghost-secondary" href="' . $e(Profile::getFormURLWithID((int) $r['profiles_id'])) . '&forcetab=PluginNotificacoesProfile$1"><i class="ti ti-edit"></i> Alterar</a></td></tr>';
}
$corpo .= '</tbody></table></div>';
echo $card('ti ti-shield-lock', 'Direito "Avisos" por perfil', $corpo) . '</div>';

// ---------------------------------------------------------------- Situação
echo '<div data-aba-painel="situacao"' . ($aba !== 'situacao' ? ' hidden' : '') . '>';
$total = countElementsInTable(PluginNotificacoesEvento::TABELA);
$entregas = countElementsInTable(PluginNotificacoesEvento::DESTINOS);
$naoLidas = countElementsInTable(PluginNotificacoesEvento::DESTINOS, ['is_read' => 0]);
$semana = [];
foreach ($DB->request([
    'SELECT'  => ['tipo', new QueryExpression('COUNT(*) AS total')],
    'FROM'    => PluginNotificacoesEvento::TABELA,
    'WHERE'   => ['date_creation' => ['>=', date('Y-m-d H:i:s', strtotime('-7 days'))]],
    'GROUPBY' => 'tipo',
]) as $r) {
    $semana[(string) $r['tipo']] = (int) $r['total'];
}
$cron = new CronTask();
$temCron = $cron->getFromDBbyName('PluginNotificacoesEvento', 'NotificacoesLimpar');
$corpo = '<div class="notificacoes-numeros">'
    . '<div><strong>' . (int) $total . '</strong><span>notificações guardadas</span></div>'
    . '<div><strong>' . (int) $entregas . '</strong><span>entregas (pessoa × notificação)</span></div>'
    . '<div><strong>' . (int) $naoLidas . '</strong><span>ainda não lidas</span></div>'
    . '</div>'
    . '<div class="table-responsive"><table class="table table-sm table-hover notificacoes-tabela"><thead><tr><th>Tipo</th><th>Situação</th><th class="text-end">Últimos 7 dias</th></tr></thead><tbody>';
foreach ($C::TIPOS as $t => [$rotulo, $icone]) {
    $corpo .= '<tr><td><i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</td><td>' . (in_array($t, $ativos, true) ? '<span class="notificacoes-selo notificacoes-selo-verde">Ligado</span>' : '<span class="notificacoes-selo">Desligado</span>') . '</td><td class="text-end">' . (int) ($semana[$t] ?? 0) . '</td></tr>';
}
$corpo .= '</tbody></table></div>';
$corpo .= '<div class="notificacoes-linha-acao"><span class="notificacoes-pequeno"><i class="ti ti-clock"></i> Limpeza automática: '
    . ($temCron ? ($cron->fields['lastrun'] ? 'última execução em ' . $e(Html::convDateTime((string) $cron->fields['lastrun'])) : 'ainda não executada') . ' · <a href="' . $e(CronTask::getFormURLWithID((int) $cron->getID())) . '">ver tarefa</a>' : 'tarefa não registrada (reinstale o plugin)')
    . '</span>' . $form('limpar_agora', 'situacao')
    . '<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-eraser"></i><span>Limpar agora</span></button>' . Html::closeForm(false) . '</div>';
echo $card('ti ti-chart-bar', 'Situação', $corpo) . '</div>';

echo '</div>';
echo $C::assets(true);
Html::footer();
