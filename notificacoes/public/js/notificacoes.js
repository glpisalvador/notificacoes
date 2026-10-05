/* Plugin Notificações - sino na barra superior do GLPI, painel de notificações, avisos do
 * navegador, som, sincronização entre abas e ações da central de notificações. */
(function () {
    'use strict';

    if (window.notificacoesSinoCarregado) {
        return;
    }
    window.notificacoesSinoCarregado = true;

    var origem = (document.currentScript && document.currentScript.src) || '';
    var base = origem.split('?')[0].replace(/js\/notificacoes\.js$/, '');
    if (!base) {
        return;
    }
    var AJAX = base + 'front/ajax.php';

    var estado = {
        config: null,
        naoLidas: 0,
        ultimo: 0,
        filtro: 'nao_lidas',
        tipo: '',
        inicio: 0,
        total: 0,
        aberto: false,
        timer: null,
        parado: false,
        tituloOriginal: document.title.replace(/^\(\d+\+?\)\s*/, ''),
        canal: null,
        sw: null
    };

    // ------------------------------------------------------------------ utilitários

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var token = function () {
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    };

    var pedir = function (acao, dados, metodo, manter) {
        var opcoes = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' };
        var url = AJAX + '?action=' + encodeURIComponent(acao);
        if (metodo === 'POST') {
            var fd = new FormData();
            fd.append('action', acao);
            Object.keys(dados || {}).forEach(function (k) {
                if (Array.isArray(dados[k])) {
                    dados[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.append(k, dados[k]);
                }
            });
            var t = token();
            if (t) {
                fd.append('_glpi_csrf_token', t);
                opcoes.headers['X-Glpi-Csrf-Token'] = t;
            }
            opcoes.method = 'POST';
            opcoes.body = fd;
            opcoes.keepalive = !!manter;
        } else {
            Object.keys(dados || {}).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(dados[k]);
            });
        }
        return fetch(url, opcoes).then(function (r) { return r.text(); }).then(function (texto) {
            var r = lerJson(texto);
            if (r.new_token) {
                var m = document.querySelector('meta[property="glpi:csrf_token"]');
                if (m) {
                    m.setAttribute('content', r.new_token);
                }
            }
            return r;
        });
    };

    var urlAbsoluta = function (u) {
        try {
            return new URL(u, window.location.origin).toString();
        } catch (e) {
            return window.location.origin;
        }
    };

    // ------------------------------------------------------------------ sino

    var sino = null;

    var montarSino = function () {
        if (document.querySelector('[data-notificacoes-sino]')) {
            sino = document.querySelector('[data-notificacoes-sino]');
            return true;
        }
        var cont = document.querySelector('header .header-container') || document.querySelector('header.navbar .container-fluid');
        if (!cont) {
            return false;
        }
        sino = document.createElement('div');
        sino.className = 'notificacoes-sino';
        sino.setAttribute('data-notificacoes-sino', '');
        sino.innerHTML =
            '<button type="button" class="notificacoes-sino-botao" aria-label="Notificações" aria-haspopup="dialog" aria-expanded="false" title="Notificações">' +
                '<i class="ti ti-bell"></i><span class="notificacoes-badge" hidden></span></button>' +
            '<div class="notificacoes-painel" role="dialog" aria-label="Notificações" hidden>' +
                '<div class="notificacoes-painel-topo"><span class="notificacoes-painel-titulo">Notificações</span>' +
                    '<span class="notificacoes-painel-botoes">' +
                        '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="ler_todas" title="Marcar todas como lidas"><i class="ti ti-checks"></i></button>' +
                        '<a class="btn btn-sm btn-ghost-secondary" data-link="preferencias" title="Preferências"><i class="ti ti-adjustments"></i></a>' +
                    '</span></div>' +
                '<div class="notificacoes-painel-filtros">' +
                    '<button type="button" class="notificacoes-filtro ativo" data-filtro="nao_lidas">Não lidas <span data-contagem></span></button>' +
                    '<button type="button" class="notificacoes-filtro" data-filtro="todas">Todas</button>' +
                    '<select class="form-select form-select-sm notificacoes-painel-tipo" aria-label="Tipo"><option value="">Todos os tipos</option></select>' +
                '</div>' +
                '<div class="notificacoes-permissao-mini" hidden><i class="ti ti-bell-ringing"></i><span>Receba avisos mesmo com o GLPI em segundo plano.</span>' +
                    '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="permitir">Ativar</button></div>' +
                '<ul class="notificacoes-lista" data-lista></ul>' +
                '<div class="notificacoes-painel-mais" hidden><button type="button" class="btn btn-sm btn-ghost-secondary" data-acao="mais">Carregar mais</button></div>' +
                '<a class="notificacoes-painel-rodape" data-link="central"><i class="ti ti-list"></i> Ver todas as notificações</a>' +
            '</div>';
        var alvo = Array.from(cont.children).filter(function (el) {
            return el.classList.contains('ms-md-4') || el.querySelector('.user-menu, [data-testid="user-menu"]');
        }).pop();
        if (alvo) {
            cont.insertBefore(sino, alvo);
        } else {
            cont.appendChild(sino);
        }
        ligarSino();
        return true;
    };

    var badge = function (n) {
        estado.naoLidas = Math.max(0, n | 0);
        if (sino) {
            var b = sino.querySelector('.notificacoes-badge');
            b.hidden = estado.naoLidas === 0;
            b.textContent = estado.naoLidas > 99 ? '99+' : String(estado.naoLidas);
            var c = sino.querySelector('[data-contagem]');
            c.textContent = estado.naoLidas > 0 ? String(estado.naoLidas) : '';
            sino.querySelector('.notificacoes-sino-botao').setAttribute('title', estado.naoLidas > 0 ? estado.naoLidas + ' notificação(ões) não lida(s)' : 'Notificações');
            sino.querySelector('[data-acao="ler_todas"]').disabled = estado.naoLidas === 0;
        }
        document.querySelectorAll('[data-notificacoes-total]').forEach(function (el) {
            el.textContent = String(estado.naoLidas);
            el.hidden = estado.naoLidas === 0;
        });
        var limpo = document.title.replace(/^\(\d+\+?\)\s*/, '');
        document.title = (estado.naoLidas > 0 ? '(' + (estado.naoLidas > 99 ? '99+' : estado.naoLidas) + ') ' : '') + limpo;
    };

    var itemHtml = function (n) {
        var href = n.url ? esc(n.url) : '#';
        return '<li class="notificacoes-item' + (n.lida ? '' : ' nao-lida') + '" data-id="' + n.id + '">' +
            '<span class="notificacoes-icone notificacoes-tom-' + esc(n.tom) + '"><i class="' + esc(n.icone) + '"></i></span>' +
            '<a class="notificacoes-corpo" href="' + href + '"' + (n.aviso ? ' data-notificacoes-aviso="' + n.id + '"' : '') + ' data-notificacoes-abrir>' +
                '<span class="notificacoes-linha1"><span class="notificacoes-titulo">' + esc(n.titulo) + '</span></span>' +
                (n.referencia ? '<span class="notificacoes-ref">' + esc(n.referencia) + '</span>' : '') +
                (n.conteudo ? '<span class="notificacoes-texto">' + esc(n.conteudo) + '</span>' : '') +
                '<span class="notificacoes-meta">' + (n.autor ? esc(n.autor) + ' · ' : '') + '<span title="' + esc(n.data) + '">' + esc(n.tempo) + '</span> · ' + esc(n.rotulo) + '</span>' +
            '</a>' +
            '<span class="notificacoes-acoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-notificacoes-alternar title="' + (n.lida ? 'Marcar como não lida' : 'Marcar como lida') + '">' +
                '<i class="ti ' + (n.lida ? 'ti-mail' : 'ti-check') + '"></i></button></span>' +
            '</li>';
    };

    var carregarLista = function (acrescentar) {
        if (!sino) {
            return;
        }
        var ul = sino.querySelector('[data-lista]');
        if (!acrescentar) {
            estado.inicio = 0;
            if (!ul.children.length) {
                ul.innerHTML = '<li class="notificacoes-carregando"><i class="ti ti-loader-2"></i> Carregando...</li>';
            }
        }
        pedir('listar', { estado: estado.filtro, tipo: estado.tipo, inicio: estado.inicio, limite: 15 }).then(function (r) {
            if (!r.success) {
                ul.innerHTML = '<li class="notificacoes-vazio-mini">' + esc(r.mensagem || 'Não foi possível carregar.') + '</li>';
                return;
            }
            var html = r.itens.map(itemHtml).join('');
            if (acrescentar) {
                ul.insertAdjacentHTML('beforeend', html);
            } else {
                ul.innerHTML = html || '<li class="notificacoes-vazio-mini"><i class="ti ti-bell-check"></i><span>' +
                    (estado.filtro === 'nao_lidas' ? 'Tudo em dia. Nenhuma notificação não lida.' : 'Nenhuma notificação por aqui.') + '</span></li>';
            }
            estado.total = r.total;
            estado.inicio = r.inicio + r.itens.length;
            sino.querySelector('.notificacoes-painel-mais').hidden = estado.inicio >= estado.total;
            badge(r.nao_lidas);
        });
    };

    var abrirPainel = function (abrir) {
        if (!sino) {
            return;
        }
        estado.aberto = abrir;
        var painel = sino.querySelector('.notificacoes-painel');
        painel.hidden = !abrir;
        sino.querySelector('.notificacoes-sino-botao').setAttribute('aria-expanded', abrir ? 'true' : 'false');
        sino.classList.toggle('aberto', abrir);
        if (abrir) {
            atualizarPermissaoMini();
            carregarLista(false);
        }
    };

    var ligarSino = function () {
        sino.querySelector('.notificacoes-sino-botao').addEventListener('click', function (e) {
            e.stopPropagation();
            abrirPainel(!estado.aberto);
        });
        document.addEventListener('click', function (e) {
            if (estado.aberto && !sino.contains(e.target) && !e.target.closest('.notificacoes-modal')) {
                abrirPainel(false);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && estado.aberto) {
                abrirPainel(false);
                sino.querySelector('.notificacoes-sino-botao').focus();
            }
        });
        sino.querySelectorAll('[data-filtro]').forEach(function (b) {
            b.addEventListener('click', function () {
                estado.filtro = b.dataset.filtro;
                sino.querySelectorAll('[data-filtro]').forEach(function (x) { x.classList.toggle('ativo', x === b); });
                carregarLista(false);
            });
        });
        sino.querySelector('.notificacoes-painel-tipo').addEventListener('change', function () {
            estado.tipo = this.value;
            carregarLista(false);
        });
        sino.querySelector('[data-acao="mais"]').addEventListener('click', function () { carregarLista(true); });
        sino.querySelector('[data-acao="ler_todas"]').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            pedir('ler_todas', { tipo: estado.tipo }, 'POST').then(function (r) {
                if (r.success) {
                    badge(r.nao_lidas);
                    avisarAbas();
                    carregarLista(false);
                } else {
                    aviso(r.mensagem, true);
                    btn.disabled = false;
                }
            });
        });
        sino.querySelector('[data-acao="permitir"]').addEventListener('click', function () { pedirPermissao(); });
    };

    var configurarLinks = function () {
        if (!sino || !estado.config) {
            return;
        }
        sino.querySelector('[data-link="central"]').href = estado.config.central;
        sino.querySelector('[data-link="preferencias"]').href = estado.config.preferencias;
        var sel = sino.querySelector('.notificacoes-painel-tipo');
        sel.innerHTML = '<option value="">Todos os tipos</option>' + estado.config.tipos.map(function (t) {
            return '<option value="' + esc(t.chave) + '">' + esc(t.rotulo) + '</option>';
        }).join('');
    };

    // ------------------------------------------------------------------ leitura (sino e central)

    var marcarElemento = function (li, lida) {
        li.classList.toggle('nao-lida', !lida);
        var btn = li.querySelector('[data-notificacoes-alternar]');
        if (btn) {
            btn.title = lida ? 'Marcar como não lida' : 'Marcar como lida';
            btn.querySelector('i').className = 'ti ' + (lida ? 'ti-mail' : 'ti-check');
        }
    };

    document.addEventListener('click', function (e) {
        var alternar = e.target.closest('[data-notificacoes-alternar]');
        if (alternar) {
            e.preventDefault();
            e.stopPropagation();
            var li = alternar.closest('.notificacoes-item');
            var lida = li.classList.contains('nao-lida');
            marcarElemento(li, lida);
            pedir(lida ? 'ler' : 'nao_lida', { ids: [li.dataset.id] }, 'POST').then(function (r) {
                if (r.success) {
                    badge(r.nao_lidas);
                    avisarAbas();
                } else {
                    marcarElemento(li, !lida);
                    aviso(r.mensagem, true);
                }
            });
            return;
        }
        var abrir = e.target.closest('[data-notificacoes-abrir]');
        if (!abrir) {
            return;
        }
        var item = abrir.closest('.notificacoes-item');
        if (abrir.dataset.notificacoesAviso) {
            e.preventDefault();
            abrirAviso(abrir.dataset.notificacoesAviso, item);
            return;
        }
        if (abrir.getAttribute('href') === '#') {
            e.preventDefault();
        }
        if (item && item.classList.contains('nao-lida')) {
            marcarElemento(item, true);
            badge(estado.naoLidas - 1);
            pedir('ler', { ids: [item.dataset.id] }, 'POST', true).then(function () { avisarAbas(); });
        }
    });

    // ------------------------------------------------------------------ aviso completo (janela)

    var modal = null;

    var abrirAviso = function (id, item) {
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade notificacoes-modal';
            modal.tabIndex = -1;
            modal.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>' +
                '<div class="modal-body"></div>' +
                '<div class="modal-footer"><span class="notificacoes-modal-meta"></span><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fechar</button></div>' +
                '</div></div>';
            document.body.appendChild(modal);
        }
        modal.querySelector('.modal-title').textContent = 'Carregando...';
        modal.querySelector('.modal-body').innerHTML = '<div class="notificacoes-carregando"><i class="ti ti-loader-2"></i> Carregando...</div>';
        modal.querySelector('.notificacoes-modal-meta').textContent = '';
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
        pedir('aviso', { id: id }).then(function (r) {
            if (!r.success) {
                modal.querySelector('.modal-title').textContent = 'Aviso';
                modal.querySelector('.modal-body').innerHTML = '<div class="notificacoes-vazio-mini">' + esc(r.mensagem) + '</div>';
                return;
            }
            var a = r.aviso;
            modal.querySelector('.modal-title').innerHTML = '<span class="notificacoes-prioridade notificacoes-tom-aviso-' + esc(a.nivel) + '">' + esc(a.prioridade) + '</span> ' + esc(a.titulo);
            modal.querySelector('.modal-body').innerHTML = '<div class="notificacoes-aviso-conteudo">' + a.html + '</div>';
            modal.querySelector('.notificacoes-modal-meta').textContent = 'Enviado por ' + a.autor + ' em ' + a.data + (a.validade ? ' · válido até ' + a.validade : '');
            if (item) {
                marcarElemento(item, true);
            }
            badge(r.nao_lidas);
            avisarAbas();
        });
    };

    // ------------------------------------------------------------------ avisos do navegador e som

    var podeNotificar = function () {
        return 'Notification' in window && window.isSecureContext && estado.config && estado.config.navegador;
    };

    var registrarSw = function () {
        if (estado.sw || !('serviceWorker' in navigator)) {
            return Promise.resolve(estado.sw);
        }
        return navigator.serviceWorker.register(base + 'js/sw.js', { scope: base + 'js/' }).then(function (reg) {
            estado.sw = reg;
            return reg;
        }).catch(function () { return null; });
    };

    var mostrarNotificacao = function (titulo, corpo, url, tag, insistente) {
        var opcoes = {
            body: corpo,
            icon: estado.config ? urlAbsoluta(estado.config.icone) : undefined,
            tag: tag,
            renotify: false,
            requireInteraction: !!insistente,
            lang: 'pt-BR',
            data: { url: urlAbsoluta(url) }
        };
        registrarSw().then(function (reg) {
            if (reg && reg.showNotification) {
                return reg.showNotification(titulo, opcoes);
            }
            var n = new Notification(titulo, opcoes);
            n.onclick = function () {
                window.focus();
                window.location.href = opcoes.data.url;
                n.close();
            };
        }).catch(function () { /* navegador recusou */ });
    };

    var tocarSom = function () {
        if (!estado.config || !estado.config.som) {
            return;
        }
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            var ctx = new Ctx();
            [[880, 0], [1175, 0.12]].forEach(function (nota) {
                var osc = ctx.createOscillator();
                var vol = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = nota[0];
                vol.gain.setValueAtTime(0.0001, ctx.currentTime + nota[1]);
                vol.gain.exponentialRampToValueAtTime(0.06, ctx.currentTime + nota[1] + 0.02);
                vol.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + nota[1] + 0.25);
                osc.connect(vol).connect(ctx.destination);
                osc.start(ctx.currentTime + nota[1]);
                osc.stop(ctx.currentTime + nota[1] + 0.3);
            });
            setTimeout(function () { ctx.close(); }, 800);
        } catch (e) { /* sem áudio */ }
    };

    /** Avisa só uma vez por notificação, mesmo com várias abas abertas */
    var chavePush = function () {
        return 'notificacoes-push-' + (estado.config ? estado.config.usuario : 0);
    };

    var avisarNovas = function (novas) {
        var jaAvisado = 0;
        try {
            jaAvisado = parseInt(window.localStorage.getItem(chavePush()) || '0', 10) || 0;
        } catch (e) { /* armazenamento indisponível */ }
        var pendentes = novas.filter(function (n) { return n.id > jaAvisado; });
        if (!pendentes.length) {
            return;
        }
        try {
            window.localStorage.setItem(chavePush(), String(Math.max.apply(null, pendentes.map(function (n) { return n.id; }))));
        } catch (e) { /* armazenamento indisponível */ }
        tocarSom();
        if (!podeNotificar() || Notification.permission !== 'granted') {
            return;
        }
        if (pendentes.length > 3) {
            mostrarNotificacao(pendentes.length + ' novas notificações', pendentes.slice(0, 3).map(function (n) { return n.titulo; }).join(' · '), estado.config.central, 'notificacoes-resumo', false);
            return;
        }
        pendentes.forEach(function (n) {
            var url = n.url || (estado.config.central + (estado.config.central.indexOf('?') < 0 ? '?' : '&') + 'aviso=' + n.id);
            var corpo = [n.referencia, n.conteudo].filter(Boolean).join('\n');
            mostrarNotificacao(n.titulo, corpo, url, 'notificacoes-' + n.id, n.nivel === 'alta' || n.nivel === 'urgente');
        });
    };

    var pedirPermissao = function () {
        if (!('Notification' in window)) {
            aviso('Este navegador não oferece avisos do sistema.', true);
            return;
        }
        Notification.requestPermission().then(function (p) {
            atualizarPermissaoMini();
            atualizarPermissaoCentral();
            if (p === 'granted') {
                registrarSw();
                aviso('Avisos do navegador ativados.');
            } else if (p === 'denied') {
                aviso('O navegador bloqueou os avisos. Libere nas configurações do site, no ícone ao lado do endereço.', true);
            }
        });
    };

    var atualizarPermissaoMini = function () {
        if (!sino) {
            return;
        }
        sino.querySelector('.notificacoes-permissao-mini').hidden = !(podeNotificar() && Notification.permission === 'default');
    };

    // ------------------------------------------------------------------ consulta periódica

    var agendar = function () {
        clearTimeout(estado.timer);
        if (estado.parado) {
            return;
        }
        var segundos = estado.config ? estado.config.intervalo : 30;
        estado.timer = setTimeout(atualizar, segundos * 1000);
    };

    var atualizar = function () {
        var primeira = !estado.config;
        pedir('resumo', primeira ? { config: 1 } : { desde: estado.ultimo }).then(function (r) {
            if (!r.success) {
                if (r.sessao === false || r.sem_acesso) {
                    estado.parado = true;
                    if (sino) {
                        sino.hidden = true;
                    }
                }
                agendar();
                return;
            }
            if (primeira) {
                estado.config = r.config;
                configurarLinks();
                lerItemAberto();
                if (podeNotificar() && Notification.permission === 'granted') {
                    registrarSw();
                }
            } else if (r.novas && r.novas.length) {
                avisarNovas(r.novas);
                if (estado.aberto) {
                    carregarLista(false);
                }
            }
            if (r.ultimo_id > estado.ultimo) {
                estado.ultimo = r.ultimo_id;
            }
            badge(r.nao_lidas);
            agendar();
        }).catch(function () { agendar(); });
    };

    /** Outras abas do mesmo navegador atualizam o contador na hora */
    var avisarAbas = function () {
        if (estado.canal) {
            estado.canal.postMessage({ tipo: 'contagem', n: estado.naoLidas });
        }
    };

    var ligarCanal = function () {
        if (!('BroadcastChannel' in window)) {
            return;
        }
        estado.canal = new BroadcastChannel('notificacoes-glpi');
        estado.canal.onmessage = function (ev) {
            if (ev.data && ev.data.tipo === 'contagem') {
                badge(ev.data.n);
                if (estado.aberto) {
                    carregarLista(false);
                }
            }
        };
    };

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && estado.config && !estado.parado) {
            clearTimeout(estado.timer);
            atualizar();
        }
    });

    /** Ao abrir chamado, problema ou mudança, as notificações dele ficam lidas (preferência da pessoa) */
    var lerItemAberto = function () {
        if (!estado.config || !estado.config.ler_ao_abrir) {
            return;
        }
        var m = window.location.pathname.match(/\/front\/(ticket|problem|change)\.form\.php$/);
        var id = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
        if (!m || !(id > 0)) {
            return;
        }
        var tipos = { ticket: 'Ticket', problem: 'Problem', change: 'Change' };
        pedir('ler_item', { itemtype: tipos[m[1]], items_id: id }, 'POST').then(function (r) {
            if (r.success && r.alteradas > 0) {
                badge(r.nao_lidas);
                avisarAbas();
            }
        });
    };

    // ------------------------------------------------------------------ central de notificações

    var atualizarPermissaoCentral = function () {
        var bloco = document.querySelector('[data-notificacoes-permissao]');
        if (!bloco) {
            return;
        }
        var texto = bloco.querySelector('[data-notificacoes-permissao-texto]');
        var pedirBtn = bloco.querySelector('[data-notificacoes-pedir-permissao]');
        var testar = bloco.querySelector('[data-notificacoes-testar]');
        bloco.hidden = false;
        if (!('Notification' in window) || !window.isSecureContext) {
            texto.innerHTML = '<i class="ti ti-info-circle"></i> ' + (!window.isSecureContext
                ? 'Os avisos do sistema operacional exigem o GLPI em HTTPS. Neste endereço o sino, o contador no título da aba e o som continuam funcionando.'
                : 'Este navegador não oferece avisos do sistema.');
            pedirBtn.hidden = true;
            testar.hidden = true;
            return;
        }
        var p = Notification.permission;
        texto.innerHTML = '<i class="ti ' + (p === 'granted' ? 'ti-circle-check' : p === 'denied' ? 'ti-ban' : 'ti-help-circle') + '"></i> ' +
            (p === 'granted' ? 'Permitido neste navegador.' : p === 'denied' ? 'Bloqueado neste navegador: libere nas configurações do site.' : 'Ainda não permitido neste navegador.');
        pedirBtn.hidden = p !== 'default';
        testar.hidden = p !== 'granted';
    };

    var iniciarCentral = function () {
        var raiz = document.querySelector('[data-notificacoes-central]');
        if (!raiz) {
            return;
        }
        atualizarPermissaoCentral();
        var selecionados = function () {
            return Array.from(raiz.querySelectorAll('[data-notificacoes-selecao]:checked')).map(function (c) { return c.value; });
        };
        var atualizarBotoes = function () {
            var n = selecionados().length;
            raiz.querySelectorAll('[data-notificacoes-selecionadas]').forEach(function (b) { b.disabled = n === 0; });
        };
        raiz.addEventListener('change', function (e) {
            if (e.target.matches('[data-notificacoes-marcar-pagina]')) {
                raiz.querySelectorAll('[data-notificacoes-selecao]').forEach(function (c) { c.checked = e.target.checked; });
            }
            if (e.target.matches('[data-notificacoes-selecao], [data-notificacoes-marcar-pagina]')) {
                atualizarBotoes();
            }
        });
        raiz.addEventListener('click', function (e) {
            var sel = e.target.closest('[data-notificacoes-selecionadas]');
            var todas = e.target.closest('[data-notificacoes-todas]');
            if (e.target.closest('[data-notificacoes-pedir-permissao]')) {
                pedirPermissao();
            } else if (e.target.closest('[data-notificacoes-testar]')) {
                estado.config = estado.config || { icone: '', navegador: true };
                mostrarNotificacao('Teste de notificação', 'Assim chegam as novas notificações do GLPI.', window.location.href, 'notificacoes-teste', false);
            } else if (sel) {
                sel.disabled = true;
                pedir(sel.dataset.notificacoesSelecionadas, { ids: selecionados() }, 'POST').then(function (r) {
                    if (r.success) {
                        avisarAbas();
                        window.location.reload();
                    } else {
                        aviso(r.mensagem, true);
                        sel.disabled = false;
                    }
                });
            } else if (todas) {
                todas.disabled = true;
                pedir('ler_todas', { tipo: todas.dataset.notificacoesTodas || '' }, 'POST').then(function (r) {
                    if (r.success) {
                        avisarAbas();
                        window.location.reload();
                    } else {
                        aviso(r.mensagem, true);
                        todas.disabled = false;
                    }
                });
            }
        });
        var avisoUrl = parseInt(new URLSearchParams(window.location.search).get('aviso') || '0', 10);
        if (avisoUrl > 0) {
            abrirAviso(avisoUrl, raiz.querySelector('.notificacoes-item[data-id="' + avisoUrl + '"]'));
        }
    };

    // ------------------------------------------------------------------ início

    var iniciar = function () {
        ligarCanal();
        var tentativas = 0;
        var tentar = function () {
            if (montarSino() || ++tentativas > 20) {
                atualizar();
                iniciarCentral();
                return;
            }
            setTimeout(tentar, 150);
        };
        tentar();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
