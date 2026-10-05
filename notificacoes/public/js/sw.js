/* Plugin Notificações - service worker: exibe os avisos do navegador e, ao clicar,
 * leva a pessoa ao item (reaproveita uma aba do GLPI já aberta quando existe). */
'use strict';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || self.registration.scope;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (abas) {
            var mesmaOrigem = abas.filter(function (c) {
                return new URL(c.url).origin === new URL(url).origin;
            });
            if (mesmaOrigem.length && 'navigate' in mesmaOrigem[0]) {
                return mesmaOrigem[0].focus().then(function (c) {
                    return c.navigate(url);
                }).catch(function () {
                    return self.clients.openWindow(url);
                });
            }
            return self.clients.openWindow(url);
        })
    );
});
