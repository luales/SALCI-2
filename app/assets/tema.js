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

    document.documentElement.setAttribute('data-tema', tema);

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
                tema = tema === 'oscuro' ? 'claro' : 'oscuro';
                document.documentElement.setAttribute('data-tema', tema);
                try {
                    localStorage.setItem(CLAVE, tema);
                } catch (e) {
                    /* El cambio sigue aplicado en esta sesión. */
                }
                actualizarBoton();
            });
        }
        actualizarBoton();
    });
}());
