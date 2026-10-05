<?php

/**
 * Plugin Notificações - GLPI 11 e 12
 * Sino na barra superior com as notificações de cada usuário: acompanhamentos, tarefas,
 * atribuições, soluções, validações, mudanças de status, menções e avisos enviados pela equipe.
 * Os eventos são gravados pelos hooks do GLPI no momento em que acontecem, já com os
 * destinatários, e o sino só consulta as tabelas do plugin.
 */

define('PLUGIN_NOTIFICACOES_VERSION', '1.0.0');
define('PLUGIN_NOTIFICACOES_MIN_GLPI', '11.0.0');
define('PLUGIN_NOTIFICACOES_MAX_GLPI', '12.99.99');

function plugin_init_notificacoes(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['notificacoes'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('notificacoes')) {
        return;
    }

    // document_types: a aba nativa "Documentos" aparece no aviso
    Plugin::registerClass('PluginNotificacoesAviso', ['document_types' => true]);
    Plugin::registerClass('PluginNotificacoesProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginNotificacoesMenu');
    Plugin::registerClass('PluginNotificacoesEvento');

    $PLUGIN_HOOKS['config_page']['notificacoes'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['notificacoes'] = ['tools' => 'PluginNotificacoesMenu'];

    // Eventos do GLPI que geram notificação
    $adicionar = ['PluginNotificacoesEvento', 'aoAdicionar'];
    $atualizar = ['PluginNotificacoesEvento', 'aoAtualizar'];
    $remover = ['PluginNotificacoesEvento', 'aoRemover'];
    $PLUGIN_HOOKS['item_add']['notificacoes'] = array_fill_keys(PluginNotificacoesEvento::CLASSES_ADICAO, $adicionar);
    $PLUGIN_HOOKS['item_update']['notificacoes'] = array_fill_keys(PluginNotificacoesEvento::CLASSES_ATUALIZACAO, $atualizar);
    $PLUGIN_HOOKS['item_purge']['notificacoes'] = array_fill_keys(['Ticket', 'Problem', 'Change'], $remover);

    // Sino: só para quem está logado e tem um perfil liberado
    if (Session::getLoginUserID() && PluginNotificacoesCaixa::temAcesso()) {
        $PLUGIN_HOOKS['add_css']['notificacoes'] = ['css/notificacoes.css'];
        $PLUGIN_HOOKS['add_javascript']['notificacoes'] = ['js/notificacoes.js'];
    }
}

function plugin_version_notificacoes(): array
{
    return [
        'name'         => 'Notificações',
        'version'      => PLUGIN_NOTIFICACOES_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_NOTIFICACOES_MIN_GLPI,
                'max' => PLUGIN_NOTIFICACOES_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_notificacoes_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_NOTIFICACOES_MIN_GLPI, '>=');
}

function plugin_notificacoes_check_config($verbose = false): bool
{
    return true;
}
