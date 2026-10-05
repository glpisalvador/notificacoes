<?php

/**
 * Plugin Notificações - formulário do aviso (processamento nativo: add, update, delete, restore, purge)
 */

Session::checkLoginUser();

$aviso = new PluginNotificacoesAviso();
$lista = PluginNotificacoesConfig::url('aviso.php');

if (isset($_POST['add'])) {
    $aviso->check(-1, CREATE, $_POST);
    $id = $aviso->add($_POST);
    if ($id) {
        Html::redirect($aviso->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $aviso->check((int) $_POST['id'], UPDATE);
    if ($aviso->update($_POST)) {
        Session::addMessageAfterRedirect('Aviso atualizado.', false, INFO);
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $aviso->check((int) $_POST['id'], DELETE);
    $aviso->delete($_POST);
    Html::redirect($lista);
} elseif (isset($_POST['restore'])) {
    $aviso->check((int) $_POST['id'], DELETE);
    $aviso->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $aviso->check((int) $_POST['id'], PURGE);
    $aviso->delete($_POST, true);
    Html::redirect($lista);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $aviso->check($id, READ);
} else {
    $aviso->check(-1, CREATE);
}

Html::header(PluginNotificacoesAviso::getTypeName(2), $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginNotificacoesMenu', 'aviso');
if ($id > 0) {
    $aviso->display(['id' => $id]);
} else {
    $aviso->showForm(0);
}
Html::footer();
