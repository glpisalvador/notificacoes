<?php

/**
 * Plugin Notificações - item no menu Ferramentas (central de notificações e avisos)
 */
class PluginNotificacoesMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Notificações';
    }

    public static function getMenuName(): string
    {
        return 'Notificações';
    }

    public static function getIcon(): string
    {
        return 'ti ti-bell';
    }

    public static function canView(): bool
    {
        return PluginNotificacoesCaixa::temAcesso() || PluginNotificacoesAviso::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $C = PluginNotificacoesConfig::class;
        $central = $C::url('notificacoes.php');
        $menu = [
            'title' => self::getMenuName(),
            'page'  => PluginNotificacoesCaixa::temAcesso() ? $central : $C::url('aviso.php'),
            'icon'  => self::getIcon(),
            'links' => ['search' => PluginNotificacoesCaixa::temAcesso() ? $central : $C::url('aviso.php')],
        ];
        if (PluginNotificacoesAviso::canView()) {
            $menu['options']['aviso'] = [
                'title' => PluginNotificacoesAviso::getTypeName(2),
                'page'  => $C::url('aviso.php'),
                'icon'  => PluginNotificacoesAviso::getIcon(),
                'links' => ['search' => $C::url('aviso.php')],
            ];
            if (PluginNotificacoesAviso::canCreate()) {
                $menu['options']['aviso']['links']['add'] = $C::url('aviso.form.php');
                $menu['links']['add'] = $C::url('aviso.form.php');
            }
        }
        if ($C::ehAdmin()) {
            $menu['links']['config'] = $C::url('config.form.php');
            if (isset($menu['options']['aviso'])) {
                $menu['options']['aviso']['links']['config'] = $C::url('config.form.php');
            }
        }
        return $menu;
    }
}
