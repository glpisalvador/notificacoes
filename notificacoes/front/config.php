<?php

/**
 * Plugin Notificações - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginNotificacoesConfig::url('config.form.php'));
