/*
 * Central Chart.js theming.
 *
 * Every chart page used to carry its own copy of:
 *     Chart.defaults.font.family = '-apple-system,...';
 *     Chart.defaults.color       = '#292b2c';
 * duplicated verbatim in 13 files. Because that colour is a hard-coded near-black
 * and the app renders a dark theme on a #14201f surface, every chart was
 * effectively unreadable in dark mode - dark grey text and rgba(0,0,0,.125)
 * gridlines drawn on a dark background.
 *
 * This module reads the app's own design tokens instead, so charts follow the
 * active theme and any per-company accent colour without each page knowing
 * anything about it.
 *
 * Load order matters and is handled in includes/footer.php: this file is
 * deferred immediately after chart.umd.min.js, so Chart exists when it runs, and
 * both run before the DOMContentLoaded handlers that individual pages use to
 * construct their charts.
 */
(function () {
    'use strict';

    function token(name, fallback) {
        // Tokens live on :root (--if-ink, --if-muted, --if-border) and on body
        // (--if-primary and friends, which are declared there so they resolve
        // against whichever --color-accent is active). Reading from body picks up
        // both, since body inherits the :root values.
        try {
            var v = getComputedStyle(document.body).getPropertyValue(name);
            v = (v || '').trim();
            return v !== '' ? v : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function rgba(rgbToken, alpha, fallback) {
        var triplet = token(rgbToken, '');
        if (!triplet) {
            return fallback;
        }
        return 'rgba(' + triplet + ', ' + alpha + ')';
    }

    var theme = {
        // Semantic series colours. Kept as an ordered list so a chart can just take
        // the first N without choosing colours itself.
        palette: function () {
            return [
                token('--if-primary', '#0d9488'),
                '#3b82f6',
                '#f59e0b',
                '#8b5cf6',
                '#059669',
                '#dc2626',
                '#0891b2',
                '#64748b'
            ];
        },

        semantic: function () {
            return {
                primary: token('--if-primary', '#0d9488'),
                success: '#059669',
                warning: '#d97706',
                danger:  '#dc2626',
                info:    '#0891b2',
                muted:   token('--if-muted', '#5d6f76')
            };
        },

        // Applied to Chart.defaults so every chart on the page inherits it.
        apply: function () {
            if (typeof Chart === 'undefined') {
                return false;
            }

            var ink    = token('--if-ink', '#16232a');
            var muted  = token('--if-muted', '#5d6f76');
            var border = token('--if-border', '#e3e9ea');

            Chart.defaults.font.family =
                "'Plex Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
            Chart.defaults.color       = ink;
            Chart.defaults.borderColor = border;

            /* Reduced motion. Chart.js draws on a canvas, so the global
               @media (prefers-reduced-motion: reduce) guard in css/itflow_motion.css
               physically cannot reach it - CSS does not apply inside a canvas. Left
               alone, Chart.defaults.animation stays at the stock
               {duration: 1000, easing: 'easeOutQuart'}, which made the dashboard the
               one surface still moving for a full second on every load while every
               other element on the page was pinned to 1ms. Measured: a probe bar
               chart's final geometry first appeared at dt=1020ms under
               reduced_motion="reduce".

               Read at apply() time rather than cached at parse time, so repaint()
               picks up a preference change without a reload. */
            var reduceMotion = window.matchMedia
                && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            Chart.defaults.animation = reduceMotion
                ? false
                : { duration: 1000, easing: 'easeOutQuart' };

            if (Chart.defaults.scales) {
                ['linear', 'category', 'logarithmic', 'time', 'radialLinear'].forEach(function (kind) {
                    var scale = Chart.defaults.scales[kind];
                    if (!scale) {
                        return;
                    }
                    scale.grid  = scale.grid  || {};
                    scale.ticks = scale.ticks || {};
                    scale.grid.color       = border;
                    scale.grid.borderColor = border;
                    scale.ticks.color      = muted;
                });
            }

            if (Chart.defaults.plugins) {
                if (Chart.defaults.plugins.legend && Chart.defaults.plugins.legend.labels) {
                    Chart.defaults.plugins.legend.labels.color = ink;
                }
                if (Chart.defaults.plugins.tooltip) {
                    Chart.defaults.plugins.tooltip.backgroundColor =
                        rgba('--if-primary-rgb', '.92', 'rgba(20,35,42,.92)');
                    Chart.defaults.plugins.tooltip.borderColor = border;
                }
            }

            return true;
        },

        // Re-theme and redraw every live chart. The app renders its theme
        // server-side today, so nothing calls this yet - it exists so that adding a
        // client-side theme toggle later does not mean revisiting 13 files again.
        repaint: function () {
            if (typeof Chart === 'undefined' || !Chart.instances) {
                return;
            }
            theme.apply();
            Object.keys(Chart.instances).forEach(function (key) {
                try {
                    Chart.instances[key].update();
                } catch (e) { /* a chart mid-teardown is not worth throwing over */ }
            });
        }
    };

    theme.apply();
    window.itflowChartTheme = theme;
}());
