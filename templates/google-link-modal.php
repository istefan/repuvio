<?php
/**
 * "Cum găsesc linkul meu Google în 10 secunde?" helper modal (onboarding wizard + company form).
 * Open it with: data-bs-toggle="modal" data-bs-target="#rmGoogleHelpModal".
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;
?>
<div class="modal fade" id="rmGoogleHelpModal" tabindex="-1" aria-labelledby="rmGoogleHelpTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content rm-modal">
			<div class="modal-header">
				<h2 class="modal-title h5" id="rmGoogleHelpTitle"><i class="bi bi-google"></i> Cum găsesc linkul meu Google în 10 secunde?</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
			</div>
			<div class="modal-body">
				<ul class="nav nav-pills rm-tabs gap-1 mb-3" role="tablist">
					<li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#rmGoogleHelpShort" type="button" role="tab">Varianta 1 · Recomandat (10 secunde)</button></li>
					<li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#rmGoogleHelpPlace" type="button" role="tab">Varianta 2 · Place ID</button></li>
				</ul>
				<div class="tab-content">
					<div class="tab-pane fade show active" id="rmGoogleHelpShort" role="tabpanel">
						<ol class="rm-steps">
							<li><strong>Caută-ți firma pe Google</strong> (google.ro sau aplicația Google Maps), fiind autentificat cu contul Google care administrează profilul firmei.</li>
							<li>În panoul profilului tău apasă butonul <strong>„Solicită recenzii”</strong> (în engleză: <em>„Ask for reviews”</em>).</li>
							<li>Copiază linkul scurt afișat, de forma <code>https://g.page/r/CAbc123.../review</code>, și lipește-l în câmpul din <?php echo esc_html( app_name() ); ?>.</li>
						</ol>
						<div class="alert rm-alert-info small mb-0"><i class="bi bi-lightbulb"></i> Linkul <code>g.page/r/...</code> deschide direct fereastra „Scrie o recenzie”, pe telefon și pe calculator. Este cea mai sigură variantă.</div>
					</div>
					<div class="tab-pane fade" id="rmGoogleHelpPlace" role="tabpanel">
						<ol class="rm-steps">
							<li>Deschide <strong>Google Place ID Finder</strong> (butonul de mai jos).</li>
							<li>Scrie numele firmei și orașul în câmpul de căutare de pe hartă și alege firma ta din listă.</li>
							<li>Copiază codul afișat după „Place ID” (începe de obicei cu <code>ChIJ</code>) și lipește-l în <?php echo esc_html( app_name() ); ?>. Construim automat linkul <code>https://search.google.com/local/writereview?placeid=...</code>.</li>
						</ol>
						<a class="btn btn-dark btn-sm" href="https://developers.google.com/maps/documentation/places/web-service/place-id#find-id" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Deschide Google Place ID Finder</a>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<span class="small text-muted me-auto">Acceptăm: <code>g.page/r/...</code>, <code>writereview?placeid=...</code> sau Place ID.</span>
				<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Am înțeles</button>
			</div>
		</div>
	</div>
</div>
