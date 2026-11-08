/**
 * RepuVio - "Rapoarte echipă" charts (Chart.js 4): evaluations per QR code, average rating,
 * happy / neutral / unhappy split and month-by-month evolution.
 * Reads window.RM_DATA.teamReport (rendered server-side with hex-escaped JSON).
 *
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */
( function () {
	'use strict';

	// Person colours (dots, progress bars) come from data-color: no inline styles in the markup.
	document.querySelectorAll( '[data-color]' ).forEach( function ( element ) {
		element.style.backgroundColor = element.getAttribute( 'data-color' );
	} );

	var report = window.RM_DATA && window.RM_DATA.teamReport;

	if ( ! report || 'undefined' === typeof window.Chart ) {
		return;
	}

	var ink = '#0F172A';
	var muted = '#64748B';
	var border = '#E2E8F0';
	var positive = '#16A34A';
	var neutral = '#F59E0B';
	var negative = '#DC2626';
	var rows = report.rows;
	var labels = rows.map( function ( row ) {
		return row.label;
	} );

	Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
	Chart.defaults.font.size = 12;
	Chart.defaults.color = muted;

	function formatNumber( value, decimals ) {
		return Number( value ).toLocaleString( 'ro-RO', { minimumFractionDigits: decimals || 0, maximumFractionDigits: decimals || 0 } );
	}

	function alpha( hex, opacity ) {
		var value = parseInt( hex.slice( 1 ), 16 );

		return 'rgba(' + ( ( value >> 16 ) & 255 ) + ', ' + ( ( value >> 8 ) & 255 ) + ', ' + ( value & 255 ) + ', ' + opacity + ')';
	}

	// Same rule as the "Note slabe" label; grey while a code has too few evaluations to judge.
	function ratingColor( row ) {
		if ( ! row.avg || ! row.reliable ) {
			return '#CBD5E1';
		}

		if ( ! row.general && row.avg <= report.companyAvg - report.lowGap + 0.0001 ) {
			return negative;
		}

		return 4.3 <= row.avg ? positive : neutral;
	}

	// Horizontal charts grow with the number of codes, so every bar stays readable.
	document.querySelectorAll( '[data-team-chart-height]' ).forEach( function ( box ) {
		box.style.height = Math.max( 200, rows.length * 58 + 70 ) + 'px';
	} );

	var tooltip = { backgroundColor: ink, padding: 10, cornerRadius: 8 };
	var legend = { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } };

	var volume = document.getElementById( 'rmTeamVolumeChart' );

	if ( volume ) {
		new Chart( volume, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Scanări cod QR',
						data: rows.map( function ( row ) {
							return row.views;
						} ),
						backgroundColor: rows.map( function ( row ) {
							return alpha( row.color, 0.25 );
						} ),
						borderRadius: 6,
						barPercentage: 0.9,
						categoryPercentage: 0.75
					},
					{
						label: 'Evaluări primite',
						data: rows.map( function ( row ) {
							return row.reviews;
						} ),
						backgroundColor: rows.map( function ( row ) {
							return row.color;
						} ),
						borderRadius: 6,
						barPercentage: 0.9,
						categoryPercentage: 0.75
					}
				]
			},
			options: {
				indexAxis: 'y',
				maintainAspectRatio: false,
				plugins: {
					legend: legend,
					tooltip: Object.assign( {}, tooltip, {
						callbacks: {
							label: function ( context ) {
								return ' ' + context.dataset.label + ': ' + formatNumber( context.raw );
							}
						}
					} )
				},
				scales: {
					x: { beginAtZero: true, grid: { color: border }, ticks: { precision: 0 } },
					y: { grid: { display: false }, ticks: { color: ink, font: { weight: 600 } } }
				}
			}
		} );
	}

	var rating = document.getElementById( 'rmTeamRatingChart' );

	if ( rating ) {
		new Chart( rating, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Nota medie',
						data: rows.map( function ( row ) {
							return row.avg || null;
						} ),
						backgroundColor: rows.map( function ( row ) {
							return ratingColor( row );
						} ),
						borderRadius: 6,
						barPercentage: 0.6
					}
				]
			},
			options: {
				indexAxis: 'y',
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: Object.assign( {}, tooltip, {
						callbacks: {
							label: function ( context ) {
								return ' ' + formatNumber( context.raw, 1 ) + '★ (media companiei: ' + formatNumber( report.companyAvg, 1 ) + '★)';
							}
						}
					} )
				},
				scales: {
					x: {
						min: 1,
						max: 5,
						grid: { color: border },
						ticks: {
							stepSize: 1,
							callback: function ( value ) {
								return value + '★';
							}
						}
					},
					y: { grid: { display: false }, ticks: { color: ink, font: { weight: 600 } } }
				}
			}
		} );
	}

	var sentiment = document.getElementById( 'rmTeamSentimentChart' );

	if ( sentiment ) {
		var percent = function ( row, key ) {
			var total = row.positive + row.neutral + row.negative;

			return total ? Math.round( row[ key ] / total * 100 ) : 0;
		};

		new Chart( sentiment, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Mulțumiți (4–5★)',
						data: rows.map( function ( row ) {
							return percent( row, 'positive' );
						} ),
						backgroundColor: positive,
						barPercentage: 0.6
					},
					{
						label: 'Neutri (3★)',
						data: rows.map( function ( row ) {
							return percent( row, 'neutral' );
						} ),
						backgroundColor: neutral,
						barPercentage: 0.6
					},
					{
						label: 'Nemulțumiți (1–2★)',
						data: rows.map( function ( row ) {
							return percent( row, 'negative' );
						} ),
						backgroundColor: negative,
						barPercentage: 0.6
					}
				]
			},
			options: {
				indexAxis: 'y',
				maintainAspectRatio: false,
				plugins: {
					legend: legend,
					tooltip: Object.assign( {}, tooltip, {
						callbacks: {
							label: function ( context ) {
								return ' ' + context.dataset.label + ': ' + context.raw + '%';
							}
						}
					} )
				},
				scales: {
					x: {
						stacked: true,
						min: 0,
						max: 100,
						grid: { color: border },
						ticks: {
							callback: function ( value ) {
								return value + '%';
							}
						}
					},
					y: { stacked: true, grid: { display: false }, ticks: { color: ink, font: { weight: 600 } } }
				}
			}
		} );
	}

	var monthly = document.getElementById( 'rmTeamMonthlyChart' );

	if ( monthly ) {
		new Chart( monthly, {
			type: 'bar',
			data: {
				labels: report.months,
				datasets: rows.map( function ( row ) {
					return {
						label: row.label,
						data: row.months,
						backgroundColor: row.color,
						borderRadius: 4,
						barPercentage: 0.85,
						categoryPercentage: 0.7
					};
				} )
			},
			options: {
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: legend,
					tooltip: tooltip
				},
				scales: {
					x: { grid: { display: false } },
					y: { beginAtZero: true, grid: { color: border }, ticks: { precision: 0 } }
				}
			}
		} );
	}
}() );
