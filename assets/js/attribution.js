/**
 * Meta Dynamic Pixel — клиентская атрибуция.
 * Запоминает UTM-метки, реферер, origin лида и строит _fbc из fbclid.
 * Все данные пишутся в cookie, чтобы их видел и пиксель, и сервер (CAPI).
 */
(function () {
    'use strict';

    var DAYS = (window.MDP_ATTR && window.MDP_ATTR.days) ? window.MDP_ATTR.days : 90;

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m.pop()) : '';
    }

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    /* ---------- 1. UTM-метки (первое + последнее касание) ---------- */
    var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var current = {};
    var hasUtm = false;
    UTM_KEYS.forEach(function (k) {
        var v = param(k);
        if (v) { current[k] = v; hasUtm = true; }
    });

    var store = {};
    try { store = JSON.parse(getCookie('mdp_utm') || '{}') || {}; } catch (e) { store = {}; }

    if (hasUtm) {
        if (!store.first) { store.first = current; }   // первое касание не перезаписываем
        store.last = current;                          // последнее касание всегда обновляем
        setCookie('mdp_utm', JSON.stringify(store), DAYS);
    }

    /* ---------- 2. Реферер + origin (откуда пришёл лид) ---------- */
    function classify(host, utmSource) {
        host = (host || '').toLowerCase();
        if (/(^|\.)(facebook|fb)\.com$/.test(host) || host.indexOf('facebook.') !== -1) return 'facebook';
        if (host.indexOf('instagram.') !== -1) return 'instagram';
        if (host.indexOf('google.') !== -1) return 'google';
        if (host.indexOf('yandex.') !== -1) return 'yandex';
        if (host.indexOf('youtube.') !== -1 || host.indexOf('youtu.be') !== -1) return 'youtube';
        if (host.indexOf('t.co') !== -1 || host.indexOf('twitter.') !== -1 || host.indexOf('x.com') !== -1) return 'twitter';
        if (host.indexOf('tiktok.') !== -1) return 'tiktok';
        if (host.indexOf('vk.com') !== -1) return 'vk';
        if (host.indexOf('telegram.') !== -1 || host.indexOf('t.me') !== -1) return 'telegram';
        if (utmSource) return utmSource.toLowerCase();   // если есть utm_source — берём его
        if (host) return host;                           // иначе сам домен реферера
        return 'direct';                                 // прямой заход
    }

    // Реферер фиксируем только при первом касании в рамках срока хранения
    if (!getCookie('mdp_referrer') && document.referrer) {
        var refHost = '';
        try { refHost = new URL(document.referrer).hostname; } catch (e) {}
        // не считаем переходы внутри своего же сайта
        if (refHost && refHost !== window.location.hostname) {
            setCookie('mdp_referrer', document.referrer, DAYS);
        }
    }

    if (!getCookie('mdp_origin') || hasUtm) {
        var refHost2 = '';
        var ref = getCookie('mdp_referrer') || document.referrer;
        try { refHost2 = ref ? new URL(ref).hostname : ''; } catch (e) {}
        // Самореферал: переход внутри своего же сайта — не источник (иначе в отчёте
        // появляется собственный домен). Считаем такой заход прямым.
        if (refHost2 && refHost2 === window.location.hostname) { refHost2 = ''; }
        var origin = classify(refHost2, current.utm_source);
        // fbclid в URL — почти всегда трафик из Facebook/Instagram Ads
        if (param('fbclid') && origin === 'direct') { origin = 'facebook'; }
        setCookie('mdp_origin', origin, DAYS);
    }

    /* ---------- 3. fbclid -> _fbc (для качественной атрибуции и CAPI) ---------- */
    var fbclid = param('fbclid');
    if (fbclid) {
        if (!getCookie('mdp_fbclid')) {
            setCookie('mdp_fbclid', fbclid, DAYS);  // сырой fbclid (для справки)
        }
        if (!getCookie('_fbc')) {
            // Формат Meta: fb.<subdomainIndex>.<creationTime_ms>.<fbclid>
            setCookie('_fbc', 'fb.1.' + Date.now() + '.' + fbclid, DAYS);
        }
    }

    /* ---------- 4. external_id: постоянный first-party идентификатор ---------- */
    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    if (!getCookie('mdp_xid')) {
        // храним дольше обычной атрибуции — это стабильный ID пользователя
        setCookie('mdp_xid', uuid(), Math.max(DAYS, 365));
    }

    /* ---------- 5. Логирование событий во встроенную аналитику ---------- */
    window.mdpTrack = function (name, value, currency, eventId) {
        if (!window.MDP_LOG || !MDP_LOG.enabled) return;
        try {
            var id = eventId || (name + '.' + Date.now() + '.' + Math.floor(Math.random() * 1e6));
            var fd = new FormData();
            fd.append('action', 'mdp_track');
            fd.append('nonce', MDP_LOG.nonce);
            fd.append('event_name', name);
            fd.append('event_id', id);
            fd.append('value', value || 0);
            fd.append('currency', currency || '');
            fd.append('url', location.href);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(MDP_LOG.ajax, fd);
            } else {
                fetch(MDP_LOG.ajax, { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin' });
            }
        } catch (e) {}
    };
})();
