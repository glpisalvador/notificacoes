<?php

/**
 * Plugin Notificações - instalação e desinstalação
 */

function plugin_notificacoes_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $colunaExiste = fn(string $t, string $c) => $DB->tableExists($t) && $DB->fieldExists($t, $c, false);

    // ------------------------------------------------------------ configurações (chave/valor)
    if (!$DB->tableExists('glpi_plugin_notificacoes_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_notificacoes_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    foreach (PluginNotificacoesConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_notificacoes_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_notificacoes_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // ------------------------------------------------------------ eventos (uma linha por acontecimento)
    if (!$DB->tableExists('glpi_plugin_notificacoes_eventos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_notificacoes_eventos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo` varchar(30) NOT NULL DEFAULT '',
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `titulo` varchar(255) NOT NULL DEFAULT '',
            `referencia` varchar(255) NOT NULL DEFAULT '',
            `conteudo` text NULL,
            `nivel` varchar(20) NOT NULL DEFAULT '',
            `plugin_notificacoes_avisos_id` int unsigned NOT NULL DEFAULT 0,
            `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_fim` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tipo` (`tipo`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_notificacoes_avisos_id` (`plugin_notificacoes_avisos_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ destinatários (estado de leitura por usuário)
    if (!$DB->tableExists('glpi_plugin_notificacoes_destinatarios')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_notificacoes_destinatarios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_notificacoes_eventos_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `date_read` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `evento_usuario` (`plugin_notificacoes_eventos_id`, `users_id`),
            KEY `usuario_lida` (`users_id`, `is_read`, `plugin_notificacoes_eventos_id`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ avisos enviados pela equipe
    if (!$DB->tableExists('glpi_plugin_notificacoes_avisos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_notificacoes_avisos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_recursive` tinyint(1) NOT NULL DEFAULT 1,
            `name` varchar(255) NOT NULL DEFAULT '',
            `conteudo` longtext NULL,
            `prioridade` varchar(20) NOT NULL DEFAULT 'normal',
            `destino` varchar(20) NOT NULL DEFAULT 'todos',
            `destinos` text NULL,
            `date_inicio` timestamp NULL DEFAULT NULL,
            `date_fim` timestamp NULL DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`),
            KEY `users_id` (`users_id`),
            KEY `date_inicio` (`date_inicio`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ preferências de cada usuário
    if (!$DB->tableExists('glpi_plugin_notificacoes_preferencias')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_notificacoes_preferencias` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `tipos_desligados` text NULL,
            `navegador` tinyint(1) NOT NULL DEFAULT 1,
            `som` tinyint(1) NOT NULL DEFAULT 0,
            `ler_ao_abrir` tinyint(1) NOT NULL DEFAULT 1,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`)
        ) $opcoes");
    } elseif (!$colunaExiste('glpi_plugin_notificacoes_preferencias', 'ler_ao_abrir')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_notificacoes_preferencias` ADD COLUMN `ler_ao_abrir` tinyint(1) NOT NULL DEFAULT 1 AFTER `som`");
    }

    // ------------------------------------------------------------ colunas padrão da lista de avisos
    if (count($DB->request(['FROM' => 'glpi_displaypreferences', 'WHERE' => ['itemtype' => 'PluginNotificacoesAviso', 'users_id' => 0], 'LIMIT' => 1])) === 0) {
        $comInterface = $DB->fieldExists('glpi_displaypreferences', 'interface', false);
        foreach ([3, 4, 5, 6, 7, 8, 9] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', ['itemtype' => 'PluginNotificacoesAviso', 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0] + ($comInterface ? ['interface' => 'central'] : []));
        }
    }

    // ------------------------------------------------------------ direito nativo para enviar avisos
    $direito = PluginNotificacoesConfig::DIREITO;
    if (count($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito], 'LIMIT' => 1])) === 0) {
        ProfileRight::addProfileRights([$direito]);
        $todos = READ | CREATE | UPDATE | DELETE | PURGE;
        $perfis = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE) {
                $perfis[] = (int) $r['profiles_id'];
            }
        }
        if ($perfis) {
            $DB->update('glpi_profilerights', ['rights' => $todos], ['name' => $direito, 'profiles_id' => $perfis]);
        }
        if (isset($_SESSION['glpiactiveprofile']['id']) && in_array((int) $_SESSION['glpiactiveprofile']['id'], $perfis, true)) {
            $_SESSION['glpiactiveprofile'][$direito] = $todos;
        }
    }

    // ------------------------------------------------------------ limpeza diária das notificações antigas
    CronTask::register('PluginNotificacoesEvento', 'NotificacoesLimpar', DAY_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'hourmin' => 0,
        'hourmax' => 24,
        'comment' => 'Remove as notificações mais antigas que o prazo de retenção',
    ]);

    return true;
}

function plugin_notificacoes_uninstall(): bool
{
    // Regra do projeto: as tabelas e os direitos ficam (reinstalar recupera tudo)
    return true;
}
