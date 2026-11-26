// Perfil del usuario (D-46): datos, foto, métricas y órdenes activas.
// perfil.html = el propio; perfil.html?id=N = el de otro usuario (solo Administrador).
(function () {
    'use strict';
    var API = '../backend/perfil.php';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    var id = new URLSearchParams(location.search).get('id') || '';

    function api(accion, cuerpo) {
        var op = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo !== undefined) { op.method = 'POST'; op.body = cuerpo; }
        return fetch(API + '?accion=' + accion + (accion === 'ver' && id ? '&id=' + encodeURIComponent(id) : ''), op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status);
                return j;
            });
        });
    }

    function urlFoto(ruta) { return ruta ? '../' + ruta.split('/').map(encodeURIComponent).join('/') : ''; }
    function iniciales(nombre) {
        return String(nombre || '?').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
    }
    function fecha(f) {
        var p = String(f || '').slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : '';
    }
    function kpi(icono, titulo, valor, color) {
        return '<div class="pf-kpi ' + (color || '') + '"><i class="fas ' + icono + '"></i><b>' + valor + '</b><span>' + titulo + '</span></div>';
    }

    function pintar(d) {
        var u = d.usuario, m = d.metricas;
        document.title = (d.propio ? 'Mi perfil' : 'Perfil de ' + u.nombre) + ' · Servidesk';  // U-22
        var foto = u.foto ? '<img src="' + esc(urlFoto(u.foto)) + '?t=' + Date.now() + '" alt="Foto de ' + esc(u.nombre) + '">' : '<span>' + esc(iniciales(u.nombre)) + '</span>';
        var html = '<section class="pf-cabecera">' +
            '<div class="pf-foto" id="pf-foto">' + foto + '</div>' +
            '<div class="pf-datos"><h1>' + esc(u.nombre) + '</h1><p class="pf-rol">' + esc(u.rol) + '</p>' +
            '<p><i class="fas fa-envelope"></i> ' + esc(u.correo || '—') + ' &nbsp; <i class="fas fa-phone"></i> ' + esc(u.telefono || '—') + '</p>' +
            '<p class="pf-suave">Usuario desde el ' + esc(fecha(u.fecha_creacion)) + '</p>' +
            (d.propio ? '<div class="pf-acciones"><label class="btn-secundario pf-subir"><i class="fas fa-camera"></i> ' + (u.foto ? 'Cambiar foto' : 'Subir foto') +
                '<input type="file" id="pf-archivo" accept="image/jpeg,image/png,image/webp" hidden></label>' +
                (u.foto ? '<button type="button" class="btn-link-mini" id="pf-quitar"><i class="fas fa-trash-alt"></i> Quitar foto</button>' : '') +
                '<small class="pf-suave">JPG, PNG o WEBP de hasta 1 MB. Se ajusta y se le quitan los datos de ubicación.</small></div>' : '') +
            '</div></section>' +
            '<section class="pf-kpis">' +
            kpi('fa-tasks', 'Órdenes activas', m.activas, 'azul') +
            kpi('fa-exclamation-triangle', 'Atrasadas', m.atrasadas, m.atrasadas ? 'rojo' : '') +
            kpi('fa-check-double', 'Completadas por cerrar', m.completadas_por_cerrar, 'verde') +
            kpi('fa-calendar-alt', 'Programadas', m.programadas, '') +
            kpi('fa-flag-checkered', 'Cerradas (30 días)', m.cerradas_30_dias, '') +
            kpi('fa-history', 'Órdenes en total', m.total_historico, '') +
            '</section>' +
            '<section class="pf-tarjeta"><h2>Órdenes de servicio activas' + (d.propio ? ' <a href="dashboard-tecnico.html">Ver mis métricas <i class="fas fa-arrow-right"></i></a>' : '') + '</h2>';
        if (!d.activas.length) {
            html += '<p class="pf-suave">Sin órdenes de servicio activas.</p>';
        } else {
            html += '<ul class="pf-ordenes">' + d.activas.map(function (o) {
                return '<li' + (o.atrasada ? ' class="tarde"' : '') + '><a href="detalle.html?id=' + o.id + '"><b>' + esc(o.numero_incidente) + ' · ' + esc(o.cliente) + '</b>' +
                    '<small>' + esc([o.sucursal, o.falla].filter(Boolean).join(' · ')) + '</small></a>' +
                    '<span>' + (window.AppTablas ? AppTablas.estatusHTML(o.estatus) : esc(o.estatus)) + '<em>' + esc(fecha(o.fecha)) + '</em></span></li>';
            }).join('') + '</ul>';
        }
        html += '</section>';
        $('perfil').innerHTML = html;
        enlazar(d);
    }

    function enlazar(d) {
        if (!d.propio) return;
        $('pf-archivo').addEventListener('change', function () {
            var archivo = this.files[0];
            if (!archivo) return;
            if (archivo.size > 1024 * 1024) { AppDialogo.mostrar({ tipo: 'error', titulo: 'Foto muy pesada', mensaje: 'La foto pesa más de 1 MB. Elige una más ligera.' }); return; }
            var datos = new FormData();
            datos.append('foto', archivo);
            api('subir_foto', datos).then(cargar).catch(function (e) { AppDialogo.mostrar({ tipo: 'error', titulo: 'No se subió la foto', mensaje: e.message }); });
        });
        if ($('pf-quitar')) $('pf-quitar').addEventListener('click', function () {
            api('quitar_foto', new FormData()).then(cargar).catch(function (e) { AppDialogo.mostrar({ tipo: 'error', titulo: 'No se quitó la foto', mensaje: e.message }); });
        });
    }

    function cargar() {
        return api('ver').then(pintar).catch(function (e) {
            $('perfil').innerHTML = '<p class="pf-error"><i class="fas fa-exclamation-circle"></i> ' + esc(e.message) + '</p>';
        });
    }
    document.addEventListener('DOMContentLoaded', cargar);
})();
