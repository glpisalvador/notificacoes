/* Plugin Notificações - páginas do plugin: multiselect com pesquisa, abas, pesquisa em tabelas,
 * destino do aviso e filtro de entidades filhas nos seletores do GLPI. */
(function () {
    'use strict';

    if (window.notificacoesPaginasCarregado) {
        return;
    }
    window.notificacoesPaginasCarregado = true;

    // ------------------------------------------------------------------ entidades filhas ocultas nos seletores do GLPI

    if (window.jQuery && Array.isArray(window.notificacoesEntidadesOcultas) && window.notificacoesEntidadesOcultas.length) {
        var ocultas = window.notificacoesEntidadesOcultas.map(String);
        var filtrar = function (lista) {
            return (lista || []).filter(function (r) {
                if (r.children) {
                    r.children = filtrar(r.children);
                    return r.children.length > 0;
                }
                return ocultas.indexOf(String(r.id)) < 0;
            });
        };
        window.jQuery.ajaxPrefilter(function (opcoes) {
            var dados = typeof opcoes.data === 'string' ? opcoes.data : '';
            if ((opcoes.url || '').indexOf('getDropdownValue') < 0 || !/itemtype=Entity(&|$)/.test(dados)) {
                return;
            }
            var original = opcoes.success;
            opcoes.success = function (resposta) {
                if (resposta && Array.isArray(resposta.results)) {
                    resposta.results = filtrar(resposta.results);
                }
                if (typeof original === 'function') {
                    return original.apply(this, arguments);
                }
            };
        });
    }

    // ------------------------------------------------------------------ multiselect

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.notificacoes-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var texto = ms.querySelector('.notificacoes-ms-texto');
        var nome = function (o) { return o.querySelector('.notificacoes-ms-rotulo').childNodes[0].textContent.trim(); };
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Selecione...';
        } else if (marcadas.length <= 3) {
            texto.textContent = marcadas.map(nome).join(', ');
        } else {
            texto.textContent = marcadas.slice(0, 2).map(nome).join(', ') + ' e mais ' + (marcadas.length - 2);
        }
        ms.querySelector('.notificacoes-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-notificacoes-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.notificacoes-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.notificacoes-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-notificacoes-ms-abrir]');
        document.querySelectorAll('[data-notificacoes-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.notificacoes-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.notificacoes-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.notificacoes-ms-busca')) {
            filtrarMs(e.target.closest('[data-notificacoes-ms]'));
        }
        if (e.target.matches('[data-notificacoes-busca-tabela]')) {
            var termo = e.target.value.trim().toLowerCase();
            var raiz = e.target.closest('.notificacoes-pagina, .card-body') || document;
            raiz.querySelectorAll('tr[data-search]').forEach(function (tr) {
                tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0;
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.notificacoes-ms-busca, [data-notificacoes-busca-tabela]')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-notificacoes-auto]') && e.target.form) {
            e.target.form.submit();
            return;
        }
        if (e.target.matches('.notificacoes-destino-tipo')) {
            var bloco = e.target.closest('[data-notificacoes-destino]');
            bloco.querySelectorAll('[data-destino-painel]').forEach(function (p) {
                p.hidden = p.dataset.destinoPainel !== e.target.value;
            });
            return;
        }
        var ms = e.target.closest('[data-notificacoes-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-notificacoes-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.notificacoes-ms-opcao')) {
            e.target.closest('.notificacoes-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.notificacoes-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
                busca.focus();
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    // ------------------------------------------------------------------ abas (configuração e central)

    document.addEventListener('click', function (e) {
        var link = e.target.closest('.notificacoes-abas [data-aba]');
        if (!link) {
            return;
        }
        e.preventDefault();
        var raiz = link.closest('.notificacoes-pagina');
        var aba = link.dataset.aba;
        raiz.querySelectorAll('.notificacoes-abas [data-aba]').forEach(function (l) { l.classList.toggle('active', l === link); });
        raiz.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== aba; });
        raiz.querySelectorAll('input[name="aba"]').forEach(function (i) { i.value = aba; });
        var url = new URL(window.location.href);
        url.searchParams.set('aba', aba);
        window.history.replaceState(null, '', url.toString());
    });

    var iniciar = function () {
        document.querySelectorAll('[data-notificacoes-ms]').forEach(atualizarMs);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
