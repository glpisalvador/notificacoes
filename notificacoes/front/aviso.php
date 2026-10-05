<?php

/**
 * Plugin Notificações - lista de avisos (busca nativa do GLPI)
 */

Session::checkLoginUser();

if (!PluginNotificacoesAviso::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(PluginNotificacoesAviso::getTypeName(2), $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginNotificacoesMenu', 'aviso');
echo PluginNotificacoesConfig::assets();
Search::show('PluginNotificacoesAviso');
Html::footer();
