/**
 * RepuVio - dashboard charts (Chart.js 4).
 * Reads data from window.RM_DATA (rendered server-side with hex-escaped JSON).
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	var data = window.RM_DATA;

	if ( ! data || 'undefined' === typeof window.Chart ) {
		return;
	}

	var ink = '#0F172A';
	var accent = '#2563EB';
	var muted = '#64748B';
	var border = '#E2E8F0';

	Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
	Chart.defaults.font.size = 12;
	Chart.defaults.color = muted;

	var activity = document.getElementById( 'rmActivityChart' );

	if ( activity ) {
		var ctx = activity.getContext( '2d' );
		var gradient = ctx.createLinearGradient( 0, 0, 0, 300 );
		gradient.addColorStop( 0, 'rgba(37, 99, 235, 0.18)' );
		gradient.addColorStop( 1, 'rgba(37, 99, 235, 0)' );

		new Chart( activity, {
			type: 'line',
			data: {
				labels: data.labels,
				datasets: [
					{
						label: 'Vizualizări',
						data: data.views,
						borderColor: accent,
						backgroundColor: gradient,
						fill: true,
						tension: 0.4,
						borderWidth: 2,
						pointRadius: 0,
						pointHoverRadius: 4
					},
					{
						label: 'Redirecționări Google',
						data: data.redirects,
						borderColor: ink,
						backgroundColor: 'transparent',
						tension: 0.4,
						borderWidth: 2,
						pointRadius: 0,
						pointHoverRadius: 4
					},
					{
						label: 'Feedback privat',
						data: data.feedback,
						borderColor: '#B91C1C',
						backgroundColor: 'transparent',
						borderDash: [ 4, 4 ],
						tension: 0.4,
						borderWidth: 2,
						pointRadius: 0,
						pointHoverRadius: 4
					}
				]
			},
			options: {
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
					tooltip: { backgroundColor: ink, padding: 10, cornerRadius: 8 }
				},
				scales: {
					x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
					y: { beginAtZero: true, grid: { color: border }, border: { display: false }, ticks: { precision: 0 } }
				}
			}
		} );
	}

	var stars = document.getElementById( 'rmStarsChart' );

	if ( stars ) {
		new Chart( stars, {
			type: 'doughnut',
			data: {
				labels: [ '1★', '2★', '3★', '4★', '5★' ],
				datasets: [ {
					data: data.distribution,
					backgroundColor: [ '#B91C1C', '#F87171', '#CBD5E1', '#60A5FA', accent ],
					borderColor: '#FFFFFF',
					borderWidth: 3
				} ]
			},
			options: {
				maintainAspectRatio: false,
				cutout: '68%',
				plugins: {
					legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
					tooltip: { backgroundColor: ink, padding: 10, cornerRadius: 8 }
				}
			}
		} );
	}
}() );
