/**
 * RepuVio - Super Admin overview chart.
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var data = window.RM_DATA;
	var canvas = document.getElementById( 'rmAdminChart' );

	if ( ! data || ! canvas || 'undefined' === typeof window.Chart ) {
		return;
	}

	Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
	Chart.defaults.color = '#64748B';

	new Chart( canvas, {
		type: 'bar',
		data: {
			labels: data.labels,
			datasets: [
				{
					type: 'line',
					label: 'Recenzii procesate',
					data: data.reviews,
					borderColor: '#2563EB',
					backgroundColor: 'rgba(37, 99, 235, 0.12)',
					fill: true,
					tension: 0.4,
					pointRadius: 0,
					borderWidth: 2,
					yAxisID: 'y'
				},
				{
					type: 'bar',
					label: 'Utilizatori noi',
					data: data.signups,
					backgroundColor: '#0F172A',
					borderRadius: 6,
					maxBarThickness: 14,
					yAxisID: 'y1'
				}
			]
		},
		options: {
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
			scales: {
				x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
				y: { beginAtZero: true, grid: { color: '#E2E8F0' }, ticks: { precision: 0 }, border: { display: false } },
				y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0 }, border: { display: false } }
			}
		}
	} );
}() );
