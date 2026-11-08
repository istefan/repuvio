<?php
/**
 * Super Admin: create / edit a subscription plan (monthly + yearly price, quota, locations,
 * alert emails, history window, employee QR tags, onboarding).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$plan_id = sanitize_int_range( is_post() ? input_post( 'plan_id', '0' ) : input_get( 'id', '0' ), 0, PHP_INT_MAX, 0 );
$plan    = 0 < $plan_id ? get_plan( $plan_id ) : null;
$is_new  = null === $plan;
$errors  = [];

if ( 0 < $plan_id && $is_new ) {
	render_error_page( 404, 'Plan inexistent', 'Planul nu a fost găsit.' );
}

$form = $is_new ? [
	'name'                  => '',
	'slug'                  => '',
	'description'           => '',
	'target_description'    => '',
	'price_ron'             => '100.00',
	'price_yearly_ron'      => '1000.00',
	'price_is_starting'     => 0,
	'max_reviews_per_month' => 500,
	'max_companies'         => 1,
	'max_alert_emails'      => 1,
	'history_months'        => 12,
	'allow_ref_tags'        => 0,
	'includes_onboarding'   => 0,
	'is_active'             => 1,
	'sort_order'            => 0,
] : $plan;

if ( is_post() ) {
	$form['name']                  = sanitize_text( input_post( 'name' ), 100 );
	$form['slug']                  = sanitize_slug( '' !== input_post( 'slug' ) ? input_post( 'slug' ) : $form['name'], 100 );
	$form['description']           = sanitize_text( input_post( 'description' ), 500 );
	$form['target_description']    = sanitize_text( input_post( 'target_description' ), 500 );
	$form['price_ron']             = number_format( max( 0, (float) str_replace( ',', '.', input_post( 'price_ron', '0' ) ) ), 2, '.', '' );
	$form['price_yearly_ron']      = number_format( max( 0, (float) str_replace( ',', '.', input_post( 'price_yearly_ron', '0' ) ) ), 2, '.', '' );
	$form['price_is_starting']     = '1' === input_post( 'price_is_starting' ) ? 1 : 0;
	$form['max_reviews_per_month'] = sanitize_int_range( input_post( 'max_reviews_per_month', '0' ), 1, 10000000, 500 );
	$form['max_companies']         = sanitize_int_range( input_post( 'max_companies', '1' ), 1, 1000, 1 );
	$form['max_alert_emails']      = sanitize_int_range( input_post( 'max_alert_emails', '1' ), 1, 10, 1 );
	$form['history_months']        = sanitize_int_range( input_post( 'history_months', '12' ), 0, 120, 12 );
	$form['allow_ref_tags']        = '1' === input_post( 'allow_ref_tags' ) ? 1 : 0;
	$form['includes_onboarding']   = '1' === input_post( 'includes_onboarding' ) ? 1 : 0;

	if ( 0.0 === (float) $form['price_yearly_ron'] ) {
		$form['price_yearly_ron'] = number_format( (float) $form['price_ron'] * 10, 2, '.', '' );
	}
	$form['is_active']             = '1' === input_post( 'is_active' ) ? 1 : 0;
	$form['sort_order']            = sanitize_int_range( input_post( 'sort_order', '0' ), -1000, 1000, 0 );

	if ( 2 > mb_strlen( $form['name'] ) ) {
		$errors[] = 'Introdu numele planului.';
	}
	if ( '' === $form['slug'] ) {
		$errors[] = 'Slug invalid.';
	} elseif ( 0 < (int) db_value( 'SELECT COUNT(*) FROM plans WHERE slug = ? AND id <> ?', [ $form['slug'], $is_new ? 0 : (int) $plan['id'] ] ) ) {
		$errors[] = 'Slug-ul este deja folosit.';
	}

	if ( empty( $errors ) ) {
		$params = [
			'name'                  => $form['name'],
			'slug'                  => $form['slug'],
			'description'           => $form['description'],
			'target_description'    => $form['target_description'],
			'price_ron'             => $form['price_ron'],
			'price_yearly_ron'      => $form['price_yearly_ron'],
			'price_is_starting'     => (int) $form['price_is_starting'],
			'max_reviews_per_month' => (int) $form['max_reviews_per_month'],
			'max_companies'         => (int) $form['max_companies'],
			'max_alert_emails'      => (int) $form['max_alert_emails'],
			'history_months'        => (int) $form['history_months'],
			'allow_ref_tags'        => (int) $form['allow_ref_tags'],
			'includes_onboarding'   => (int) $form['includes_onboarding'],
			'is_active'             => (int) $form['is_active'],
			'sort_order'            => (int) $form['sort_order'],
		];

		if ( $is_new ) {
			db_query(
				'INSERT INTO plans (name, slug, description, target_description, price_ron, price_yearly_ron, price_is_starting, max_reviews_per_month, max_companies, max_alert_emails, history_months, allow_ref_tags, includes_onboarding, is_active, sort_order)
				VALUES (:name, :slug, :description, :target_description, :price_ron, :price_yearly_ron, :price_is_starting, :max_reviews_per_month, :max_companies, :max_alert_emails, :history_months, :allow_ref_tags, :includes_onboarding, :is_active, :sort_order)',
				$params
			);
			$plan_id = db_insert_id();
		} else {
			$params['id'] = (int) $plan['id'];
			db_query(
				'UPDATE plans SET name = :name, slug = :slug, description = :description, target_description = :target_description,
					price_ron = :price_ron, price_yearly_ron = :price_yearly_ron, price_is_starting = :price_is_starting,
					max_reviews_per_month = :max_reviews_per_month, max_companies = :max_companies, max_alert_emails = :max_alert_emails,
					history_months = :history_months, allow_ref_tags = :allow_ref_tags, includes_onboarding = :includes_onboarding,
					is_active = :is_active, sort_order = :sort_order
				WHERE id = :id',
				$params
			);
		}

		audit_log( 'admin_plan_saved', 'plan=' . $plan_id );
		flash( 'success', 'Planul a fost salvat.' );
		redirect( app_url( 'admin/plans.php' ) );
	}
}

$page_title       = $is_new ? 'Plan nou' : $form['name'];
$active_nav       = 'plans';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Planuri tarifare', app_url( 'admin/plans.php' ) ], [ $is_new ? 'Nou' : 'Editează', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<form method="post" class="rm-card" style="max-width: 760px;" novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<input type="hidden" name="plan_id" value="<?php echo esc_attr( $is_new ? 0 : $plan['id'] ); ?>">
	<div class="rm-card-body">
		<div class="row g-3">
			<div class="col-md-6">
				<label class="form-label" for="name">Nume</label>
				<input type="text" class="form-control" id="name" name="name" value="<?php echo esc_attr( $form['name'] ); ?>" maxlength="100" required data-slug-source>
			</div>
			<div class="col-md-6">
				<label class="form-label" for="slug">Slug</label>
				<input type="text" class="form-control" id="slug" name="slug" value="<?php echo esc_attr( $form['slug'] ); ?>" maxlength="100" data-slug-target>
			</div>
			<div class="col-12">
				<label class="form-label" for="description">Descriere scurtă</label>
				<input type="text" class="form-control" id="description" name="description" value="<?php echo esc_attr( $form['description'] ); ?>" maxlength="500">
			</div>
			<div class="col-12">
				<label class="form-label" for="target_description">Pentru cine (afișat clienților)</label>
				<input type="text" class="form-control" id="target_description" name="target_description" value="<?php echo esc_attr( $form['target_description'] ); ?>" maxlength="500" placeholder="Ex: Restaurante, cafenele, clinici medicale...">
			</div>
			<div class="col-md-4">
				<label class="form-label" for="price_ron">Preț lunar (RON)</label>
				<input type="text" inputmode="decimal" class="form-control" id="price_ron" name="price_ron" value="<?php echo esc_attr( $form['price_ron'] ); ?>" required>
			</div>
			<div class="col-md-4">
				<label class="form-label" for="price_yearly_ron">Preț anual (RON)</label>
				<input type="text" inputmode="decimal" class="form-control" id="price_yearly_ron" name="price_yearly_ron" value="<?php echo esc_attr( $form['price_yearly_ron'] ); ?>">
				<div class="form-text">0 = 10 × prețul lunar (2 luni gratuite).</div>
			</div>
			<div class="col-md-4">
				<label class="form-label" for="sort_order">Ordine afișare</label>
				<input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo esc_attr( $form['sort_order'] ); ?>">
			</div>
			<div class="col-md-6">
				<label class="form-label" for="max_reviews_per_month">Recenzii incluse / lună (cotă)</label>
				<input type="number" min="1" class="form-control" id="max_reviews_per_month" name="max_reviews_per_month" value="<?php echo esc_attr( $form['max_reviews_per_month'] ); ?>" required>
			</div>
			<div class="col-md-6">
				<label class="form-label" for="max_companies">Număr maxim de locații / companii</label>
				<input type="number" min="1" class="form-control" id="max_companies" name="max_companies" value="<?php echo esc_attr( $form['max_companies'] ); ?>" required>
			</div>
			<div class="col-md-6">
				<label class="form-label" for="max_alert_emails">Adrese email pentru alerte</label>
				<input type="number" min="1" max="10" class="form-control" id="max_alert_emails" name="max_alert_emails" value="<?php echo esc_attr( $form['max_alert_emails'] ); ?>">
			</div>
			<div class="col-md-6">
				<label class="form-label" for="history_months">Istoric feedback (luni)</label>
				<input type="number" min="0" max="120" class="form-control" id="history_months" name="history_months" value="<?php echo esc_attr( $form['history_months'] ); ?>">
				<div class="form-text">0 = nelimitat.</div>
			</div>
			<div class="col-md-4">
				<div class="form-check form-switch">
					<input class="form-check-input" type="checkbox" role="switch" id="allow_ref_tags" name="allow_ref_tags" value="1"<?php echo 1 === (int) $form['allow_ref_tags'] ? ' checked' : ''; ?>>
					<label class="form-check-label" for="allow_ref_tags">Coduri QR pe angajat (?ref=)</label>
				</div>
			</div>
			<div class="col-md-4">
				<div class="form-check form-switch">
					<input class="form-check-input" type="checkbox" role="switch" id="includes_onboarding" name="includes_onboarding" value="1"<?php echo 1 === (int) $form['includes_onboarding'] ? ' checked' : ''; ?>>
					<label class="form-check-label" for="includes_onboarding">Onboarding asistat</label>
				</div>
			</div>
			<div class="col-md-4">
				<div class="form-check form-switch">
					<input class="form-check-input" type="checkbox" role="switch" id="price_is_starting" name="price_is_starting" value="1"<?php echo 1 === (int) $form['price_is_starting'] ? ' checked' : ''; ?>>
					<label class="form-check-label" for="price_is_starting">Preț „de la” (negociabil)</label>
				</div>
			</div>
			<div class="col-12">
				<div class="form-check form-switch">
					<input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?php echo 1 === (int) $form['is_active'] ? ' checked' : ''; ?>>
					<label class="form-check-label" for="is_active">Plan activ (vizibil clienților)</label>
				</div>
			</div>
		</div>
	</div>
	<div class="rm-card-body border-top d-flex gap-2">
		<button type="submit" class="btn btn-dark">Salvează planul</button>
		<a class="btn btn-light" href="<?php echo esc_url( app_url( 'admin/plans.php' ) ); ?>">Renunță</a>
	</div>
</form>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
