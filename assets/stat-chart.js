// Hover crosshair/tooltip for admin_dashboard.twig's line chart - the
// geometry constants mirror ChartGeometry.php (the chart itself is drawn
// server-side; this only maps a mouse position back to a day's values).
(function () {
    var WIDTH = 720;
    var PAD_LEFT = 42;
    var PAD_RIGHT = 10;

    document.querySelectorAll('[data-stat-chart]').forEach(function (wrap) {
        var svg = wrap.querySelector('svg');
        var tooltip = wrap.querySelector('[data-stat-tooltip]');
        var series;
        try {
            series = JSON.parse(wrap.dataset.series);
        } catch (e) {
            return;
        }
        if (!svg || !tooltip || !series.length) {
            return;
        }

        function indexAt(clientX) {
            var rect = svg.getBoundingClientRect();
            var svgX = (clientX - rect.left) / rect.width * WIDTH;
            var plotWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
            var ratio = (svgX - PAD_LEFT) / plotWidth;
            var index = Math.round(ratio * (series.length - 1));

            return Math.min(series.length - 1, Math.max(0, index));
        }

        wrap.addEventListener('mousemove', function (event) {
            var row = series[indexAt(event.clientX)];
            if (!row) {
                return;
            }
            tooltip.textContent = row.day + ': ' + row.views + ' / ' + row.visitors;
            tooltip.hidden = false;
            tooltip.classList.add('visible');
            var wrapRect = wrap.getBoundingClientRect();
            tooltip.style.left = (event.clientX - wrapRect.left) + 'px';
            tooltip.style.top = (event.clientY - wrapRect.top) + 'px';
        });

        wrap.addEventListener('mouseleave', function () {
            tooltip.classList.remove('visible');
            tooltip.hidden = true;
        });
    });
})();
