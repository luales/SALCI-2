/* SALCI · RNF06 — Tema oscuro slate-blue con conmutador persistente.
   Se carga en el <head> para aplicar el tema guardado antes de pintar. */
(function () {
    'use strict';

    var CLAVE = 'salci_tema';
    var VALIDOS = ['oscuro', 'claro'];
    var tema = 'oscuro';

    try {
        var guardado = localStorage.getItem(CLAVE);
        if (VALIDOS.indexOf(guardado) !== -1) {
            tema = guardado;
        }
    } catch (e) {
        /* localStorage bloqueado: se mantiene el tema por defecto. */
    }

    function aplicarTema(t) {
        tema = t;
        document.documentElement.setAttribute('data-tema', tema);
        document.documentElement.style.colorScheme = tema === 'oscuro' ? 'dark' : 'light';
        var meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) {
            meta.setAttribute('content', tema === 'oscuro' ? 'dark' : 'light');
        }
    }

    aplicarTema(tema);

    function actualizarBoton() {
        var botones = document.querySelectorAll('[data-tema-toggle]');
        for (var i = 0; i < botones.length; i++) {
            var esOscuro = tema === 'oscuro';
            botones[i].setAttribute('aria-pressed', esOscuro ? 'true' : 'false');
            botones[i].setAttribute(
                'title',
                esOscuro ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'
            );
            botones[i].textContent = esOscuro ? '◐' : '◑';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var botones = document.querySelectorAll('[data-tema-toggle]');
        for (var i = 0; i < botones.length; i++) {
            botones[i].addEventListener('click', function () {
                var nuevo = tema === 'oscuro' ? 'claro' : 'oscuro';
                aplicarTema(nuevo);
                try {
                    localStorage.setItem(CLAVE, nuevo);
                } catch (e) {
                    /* El cambio sigue aplicado en esta sesión. */
                }
                actualizarBoton();
            });
        }
        actualizarBoton();
    });
}());
