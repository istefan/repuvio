/**
 * RepuVio - Super Admin "Abonamente clienți" charts: monthly cash-in (12 months) and MRR per plan.
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

	Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
	Chart.defaults.color = '#64748B';

	var ron = function ( value ) {
		return Number( value ).toLocaleString( 'ro-RO', { maximumFractionDigits: 0 } ) + ' RON';
	};

	var revenue = document.getElementById( 'rmRevenueChart' );

	if ( revenue ) {
		new Chart( revenue, {
			type: 'bar',
			data: {
				labels: data.labels,
				datasets: [
					{
						type: 'bar',
						label: 'Încasări (RON)',
						data: data.revenue,
						backgroundColor: '#0F172A',
						borderRadius: 6,
						maxBarThickness: 34,
						yAxisID: 'y'
					},
					{
						type: 'line',
						label: 'Număr plăți',
						data: data.payments,
						borderColor: '#F59E0B',
						backgroundColor: '#F59E0B',
						tension: 0.35,
						pointRadius: 3,
						borderWidth: 2,
						yAxisID: 'y1'
					}
				]
			},
			options: {
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
					tooltip: {
						callbacks: {
							label: function ( context ) {
								return 'y' === context.dataset.yAxisID ? ' ' + ron( context.parsed.y ) : ' ' + context.parsed.y + ' plăți';
							}
						}
					}
				},
				scales: {
					x: { grid: { display: false } },
					y: { beginAtZero: true, grid: { color: '#E2E8F0' }, border: { display: false }, ticks: { callback: ron } },
					y1: { beginAtZero: true, position: 'right', grid: { display: false }, border: { display: false }, ticks: { precision: 0 } }
				}
			}
		} );
	}

	var mrr = document.getElementById( 'rmMrrChart' );

	if ( mrr ) {
		new Chart( mrr, {
			type: 'doughnut',
			data: {
				labels: data.planNames,
				datasets: [ {
					data: data.planMrr,
					backgroundColor: [ '#0F172A', '#475569', '#F59E0B', '#94A3B8', '#CBD5E1' ],
					borderWidth: 0
				} ]
			},
			options: {
				maintainAspectRatio: false,
				cutout: '68%',
				plugins: {
					legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
					tooltip: {
						callbacks: {
							label: function ( context ) {
								return ' ' + context.label + ': ' + ron( context.parsed );
							}
						}
					}
				}
			}
		} );
	}
}() );
