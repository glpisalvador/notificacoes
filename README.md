# Notificações para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Um **sino de notificações** na barra superior do GLPI, como nas redes sociais. Cada pessoa vê o que aconteceu nos itens dela, e a equipe pode publicar **avisos** para grupos de pessoas.

## O que o plugin faz

### Notificações automáticas
Geradas **no momento em que algo acontece** no GLPI, já com os destinatários certos:
- novos **acompanhamentos** e **tarefas**;
- **atribuições** a você ou ao seu grupo;
- **soluções**;
- **validações** pedidas a você e respostas às que você pediu;
- **mudanças de status**;
- **menções** (@nome) nos textos.

Regras para não incomodar:
- quem fez a ação **não recebe** a própria notificação;
- acompanhamentos **privados** só vão para os técnicos atribuídos;
- uma menção não gera notificação duplicada.

### O sino
- **Contador de não lidas** no ícone e no título da aba do navegador.
- **Painel** com as últimas notificações: marcar como lida ou não lida, marcar todas e ir direto ao item.
- **Sincronização entre abas:** ler numa aba atualiza as outras.
- **Som** opcional e **avisos do navegador**, que exigem o GLPI em HTTPS.
- **Central de notificações** com a lista completa e as preferências de cada pessoa: quais tipos receber e o som.

### Avisos da equipe
- **Avisos** são itens nativos do GLPI, com lista, formulário em texto rico, documentos e histórico.
- **Destino:** todos os usuários, usuários escolhidos, membros de grupos, usuários com determinados perfis ou usuários de entidades.
- Ao salvar, o aviso é publicado no sino dos destinatários. Se ficar inativo ou ir para a lixeira, ele some do sino.

### Manutenção
Uma tarefa automática diária apaga as notificações mais antigas que o período de retenção (padrão de 90 dias).

## Configuração e direitos

- **Direito nativo** de enviar avisos, na aba **Notificações** do perfil.
- Opções da página de configuração:
  - perfis que veem o sino;
  - tipos de notificação ativos;
  - intervalo de atualização;
  - retenção;
  - som padrão.

O menu fica em **Ferramentas → Notificações**.

---

## Download e instalação

1. Baixe o arquivo `notificacoes-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/notificacoes/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/notificacoes
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install notificacoes -u <usuário administrador>
   php bin/console plugin:activate notificacoes
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/notificacoes` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install notificacoes -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).