<?php
/**
 * Automatic database migrations for existing installations.
 *
 * schema.sql is the single source of truth for fresh installs. Older databases are upgraded
 * here on the first request after deployment: new tables are created from schema.sql, missing
 * columns / indexes / foreign keys are added, and the plan catalogue is moved to the new tiers.
 * Every step checks information_schema first, so the migration is idempotent and safe to re-run.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const REPUVIO_SCHEMA_VERSION = 10;

/*
 * Brand name used before the RepuVio rebrand (schema < 6). Needed only by migrate_to_v6() to
 * re-encrypt secrets that were derived with the old label and to rename old default settings.
 */
const LEGACY_BRAND = 'RepuMan';

/**
 * Runs pending migrations once (guarded by a MySQL named lock).
 *
 * @return void
 */
function maybe_run_migrations() {
	try {
		$version = (int) db_value( "SELECT setting_value FROM settings WHERE setting_key = 'schema_version'" );
	} catch ( Throwable $e ) {
		// Settings table missing: the schema was never imported. Nothing safe to do here.
		return;
	}

	if ( REPUVIO_SCHEMA_VERSION <= $version ) {
		return;
	}

	if ( 1 !== (int) db_value( "SELECT GET_LOCK('app_migrate', 15)" ) ) {
		return;
	}

	try {
		$version = (int) db_value( "SELECT setting_value FROM settings WHERE setting_key = 'schema_version'" );

		if ( REPUVIO_SCHEMA_VERSION > $version ) {
			if ( 2 > $version ) {
				migrate_to_v2();
			}

			if ( 3 > $version ) {
				migrate_to_v3();
			}

			if ( 4 > $version ) {
				migrate_to_v4();
			}

			if ( 5 > $version ) {
				migrate_to_v5();
			}

			if ( 6 > $version ) {
				migrate_to_v6();
			}

			if ( 7 > $version ) {
				migrate_to_v7();
			}

			if ( 8 > $version ) {
				migrate_to_v8();
			}

			if ( 9 > $version ) {
				migrate_to_v9();
			}

			if ( 10 > $version ) {
				migrate_to_v10();
			}

			db_query(
				"INSERT INTO settings (setting_key, setting_value, is_encrypted) VALUES ('schema_version', ?, 0)
				ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
				[ (string) REPUVIO_SCHEMA_VERSION ]
			);

			write_log( 'migrations.log', 'Database migrated to schema version ' . REPUVIO_SCHEMA_VERSION . '.' );
		}
	} finally {
		db_value( "SELECT RELEASE_LOCK('app_migrate')" );
	}

	if ( function_exists( 'load_settings' ) ) {
		load_settings( true );
	}
}

/**
 * Whether a table exists in the current database.
 *
 * @param string $table Table name.
 * @return bool
 */
function db_table_exists( $table ) {
	return 0 < (int) db_value(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
		[ $table ]
	);
}

/**
 * Whether a column exists.
 *
 * @param string $table  Table name.
 * @param string $column Column name.
 * @return bool
 */
function db_column_exists( $table, $column ) {
	return 0 < (int) db_value(
		'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
		[ $table, $column ]
	);
}

/**
 * Whether an index exists.
 *
 * @param string $table Table name.
 * @param string $index Index name.
 * @return bool
 */
function db_index_exists( $table, $index ) {
	return 0 < (int) db_value(
		'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
		[ $table, $index ]
	);
}

/**
 * Whether a foreign key constraint exists.
 *
 * @param string $table      Table name.
 * @param string $constraint Constraint name.
 * @return bool
 */
function db_foreign_key_exists( $table, $constraint ) {
	return 0 < (int) db_value(
		"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
		[ $table, $constraint ]
	);
}

/**
 * Returns the CREATE TABLE and selected INSERT IGNORE statements from schema.sql.
 *
 * @param array $insert_tables Tables whose INSERT IGNORE seeds should be returned.
 * @return array{create:array,insert:array}
 */
function schema_sql_statements( array $insert_tables ) {
	$file = REPUVIO_ROOT . '/schema.sql';
	$sql  = is_file( $file ) ? (string) file_get_contents( $file ) : '';

	// Drop full-line comments, then split on statement terminators at line ends.
	$sql        = preg_replace( '/^\s*--.*$/m', '', $sql );
	$statements = preg_split( '/;\s*$/m', $sql );
	$result     = [
		'create' => [],
		'insert' => [],
	];

	foreach ( $statements as $statement ) {
		$statement = trim( $statement );

		if ( 0 === strpos( $statement, 'CREATE TABLE IF NOT EXISTS' ) ) {
			$result['create'][] = $statement;
			continue;
		}

		foreach ( $insert_tables as $table ) {
			if ( 0 === strpos( $statement, 'INSERT IGNORE INTO `' . $table . '`' ) ) {
				$result['insert'][] = $statement;
			}
		}
	}

	return $result;
}

/**
 * Adds missing columns to a table in one ALTER statement.
 *
 * @param string $table   Table.
 * @param array  $columns column => definition (including AFTER clause).
 * @return void
 */
function migration_add_columns( $table, array $columns ) {
	$parts = [];

	foreach ( $columns as $column => $definition ) {
		if ( ! db_column_exists( $table, $column ) ) {
			$parts[] = 'ADD COLUMN `' . $column . '` ' . $definition;
		}
	}

	if ( ! empty( $parts ) ) {
		db()->exec( 'ALTER TABLE `' . $table . '` ' . implode( ', ', $parts ) );
	}
}

/**
 * Adds missing indexes.
 *
 * @param string $table   Table.
 * @param array  $indexes index name => column list SQL.
 * @return void
 */
function migration_add_indexes( $table, array $indexes ) {
	foreach ( $indexes as $index => $columns ) {
		if ( ! db_index_exists( $table, $index ) ) {
			db()->exec( 'ALTER TABLE `' . $table . '` ADD KEY `' . $index . '` (' . $columns . ')' );
		}
	}
}

/**
 * Version 2: operators, ref tags, anti-abuse device hash, white-label, print upsell, new plan catalogue.
 *
 * @return void
 */
function migrate_to_v2() {
	$schema = schema_sql_statements( [ 'settings', 'print_products' ] );

	db()->exec( 'SET foreign_key_checks = 0' );

	foreach ( $schema['create'] as $statement ) {
		db()->exec( $statement );
	}

	db()->exec( 'SET foreign_key_checks = 1' );

	foreach ( $schema['insert'] as $statement ) {
		db()->exec( $statement );
	}

	// Plans: monthly + yearly price and feature flags.
	migration_add_columns(
		'plans',
		[
			'target_description'  => "VARCHAR(500) NOT NULL DEFAULT '' AFTER `description`",
			'price_yearly_ron'    => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price_ron`',
			'price_is_starting'   => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `price_yearly_ron`',
			'max_alert_emails'    => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `max_companies`',
			'history_months'      => 'INT UNSIGNED NOT NULL DEFAULT 12 AFTER `max_alert_emails`',
			'allow_ref_tags'      => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `history_months`',
			'includes_onboarding' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_ref_tags`',
		]
	);

	// Users: operators (parent_id), billing cycle, per-user custom limits, ROI report state.
	if ( false === strpos( (string) db_value( "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'" ), 'operator' ) ) {
		db()->exec( "ALTER TABLE `users` MODIFY `role` ENUM('super_admin','user','operator') NOT NULL DEFAULT 'user'" );
	}

	migration_add_columns(
		'users',
		[
			'parent_id'              => 'INT UNSIGNED NULL DEFAULT NULL AFTER `role`',
			'billing_cycle'          => "ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `plan_id`",
			'custom_max_reviews'     => 'INT UNSIGNED NULL DEFAULT NULL AFTER `billing_cycle`',
			'custom_max_companies'   => 'INT UNSIGNED NULL DEFAULT NULL AFTER `custom_max_reviews`',
			'roi_report_enabled'     => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `trial_reminder_sent`',
			'last_roi_report_period' => 'CHAR(7) NULL DEFAULT NULL AFTER `roi_report_enabled`',
		]
	);
	migration_add_indexes( 'users', [ 'idx_users_parent' => '`parent_id`' ] );

	if ( ! db_foreign_key_exists( 'users', 'fk_users_parent' ) ) {
		db()->exec( 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE' );
	}

	migration_add_columns( 'companies', [ 'white_label_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `cta_text`' ] );

	migration_add_columns(
		'reviews',
		[
			'ref_tag'     => "VARCHAR(64) NOT NULL DEFAULT '' AFTER `source`",
			'device_hash' => "CHAR(64) NOT NULL DEFAULT '' AFTER `ip_hash`",
		]
	);
	migration_add_indexes(
		'reviews',
		[
			'idx_reviews_company_ip'     => '`company_id`, `ip_hash`, `created_at`',
			'idx_reviews_company_device' => '`company_id`, `device_hash`, `created_at`',
			'idx_reviews_company_ref'    => '`company_id`, `ref_tag`',
		]
	);

	migration_add_columns( 'funnel_views', [ 'ref_tag' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER `source`" ] );
	migration_add_indexes( 'funnel_views', [ 'idx_funnel_views_company_ref' => '`company_id`, `ref_tag`' ] );

	// The billing cycle moved from plans to users: keep yearly customers yearly, then drop the old column.
	if ( db_column_exists( 'plans', 'billing_cycle' ) ) {
		db()->exec( "UPDATE users u INNER JOIN plans p ON p.id = u.plan_id SET u.billing_cycle = 'yearly' WHERE p.billing_cycle = 'yearly'" );
		db()->exec( 'ALTER TABLE `plans` DROP COLUMN `billing_cycle`' );
	}

	// Old catalogue (start / business / pro) -> Starter / Pro / Enterprise. Renamed by slug, old "pro" first.
	if ( 0 < (int) db_value( "SELECT COUNT(*) FROM plans WHERE slug IN ('start', 'business')" ) ) {
		$catalogue = [
			'pro'      => [ 'Enterprise', 'enterprise', 'Toate funcțiile Pro, 5+ locații, cote personalizate și onboarding asistat.', 'Rețele, francize și grupuri cu multe locații. Preț negociabil.', 590, 5900, 1, 5000, 5, 10, 0, 1, 1, 3 ],
			'business' => [ 'Pro', 'pro', 'Până la 3 locații și coduri QR multiple pe angajat, departament sau masă.', 'Restaurante, cafenele, clinici medicale, service-uri auto, hoteluri, firme cu mai multe echipe pe teren.', 250, 2500, 0, 1000, 3, 3, 24, 1, 0, 2 ],
			'start'    => [ 'Starter', 'starter', 'O locație, 500 de recenzii colectate pe lună.', 'Cabinete individuale, saloane de înfrumusețare, firme de intervenții/instalații, pensiuni mici, ateliere.', 100, 1000, 0, 500, 1, 1, 12, 0, 0, 1 ],
		];

		foreach ( $catalogue as $old_slug => $plan ) {
			db_query(
				'UPDATE plans SET name = ?, slug = ?, description = ?, target_description = ?, price_ron = ?, price_yearly_ron = ?, price_is_starting = ?,
					max_reviews_per_month = ?, max_companies = ?, max_alert_emails = ?, history_months = ?, allow_ref_tags = ?, includes_onboarding = ?, sort_order = ?
				WHERE slug = ?',
				array_merge( $plan, [ $old_slug ] )
			);
		}
	}

	// Custom plans created before v2 get a yearly price of 10 months.
	db()->exec( 'UPDATE plans SET price_yearly_ron = price_ron * 10 WHERE price_yearly_ron = 0' );
}

/**
 * Version 3: onboarding wizard + dashboard activation checklist state.
 *
 * @return void
 */
function migrate_to_v3() {
	migration_add_columns(
		'users',
		[
			'onboarding_dismissed'    => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_roi_report_period`',
			'onboarding_materials_at' => 'DATETIME NULL DEFAULT NULL AFTER `onboarding_dismissed`',
		]
	);
}

/**
 * Version 4: company fiscal code (CUI / CIF), required for invoicing.
 *
 * @return void
 */
function migrate_to_v4() {
	migration_add_columns( 'companies', [ 'tax_id' => "VARCHAR(20) NOT NULL DEFAULT '' AFTER `name`" ] );
}

/**
 * Version 5: subscription_requests (plan requests are stored, not only emailed).
 * The CREATE TABLE statement is read from schema.sql (single source of truth).
 *
 * @return void
 */
function migrate_to_v5() {
	if ( db_table_exists( 'subscription_requests' ) ) {
		return;
	}

	foreach ( schema_sql_statements( [] )['create'] as $statement ) {
		if ( false !== strpos( $statement, '`subscription_requests`' ) ) {
			db()->exec( $statement );
		}
	}
}

/**
 * Version 6: RepuVio rebrand.
 * - Secrets are re-encrypted from the legacy brand key labels to the brand-independent "app-secret" labels.
 * - Default settings still holding the old brand name (app name, sender name) become "RepuVio".
 *
 * @return void
 */
function migrate_to_v6() {
	$legacy_label = strtolower( LEGACY_BRAND );

	foreach ( db_all( "SELECT setting_key, setting_value FROM settings WHERE is_encrypted = 1 AND setting_value <> ''" ) as $row ) {
		// Already readable with the new keys (e.g. migration re-run): leave it.
		if ( '' !== decrypt_secret( $row['setting_value'] ) ) {
			continue;
		}

		$plain = decrypt_secret( $row['setting_value'], $legacy_label );

		if ( '' !== $plain ) {
			db_query( 'UPDATE settings SET setting_value = ? WHERE setting_key = ?', [ encrypt_secret( $plain ), $row['setting_key'] ] );
		}
	}

	db_query(
		"UPDATE settings SET setting_value = 'RepuVio' WHERE setting_key IN ('app_name', 'mail_from_name') AND setting_value = ?",
		[ LEGACY_BRAND ]
	);
}

/**
 * Version 7: legal & GDPR module.
 * - legal_* settings (provider details shown on the legal pages), seeded with INSERT IGNORE from schema.sql.
 * - contact_messages table (public /contact form).
 * - Consent records: users.terms_accepted_at, reviews.privacy_consent.
 * - The old external terms_url setting is replaced by the built-in /termeni-si-conditii page.
 *
 * @return void
 */
function migrate_to_v7() {
	$schema = schema_sql_statements( [ 'settings' ] );

	foreach ( $schema['create'] as $statement ) {
		if ( false !== strpos( $statement, '`contact_messages`' ) ) {
			db()->exec( $statement );
		}
	}

	foreach ( $schema['insert'] as $statement ) {
		db()->exec( $statement );
	}

	migration_add_columns( 'users', [ 'terms_accepted_at' => 'DATETIME NULL DEFAULT NULL AFTER `onboarding_materials_at`' ] );
	migration_add_columns( 'reviews', [ 'privacy_consent' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `counted_in_quota`' ] );

	db()->exec( "DELETE FROM settings WHERE setting_key = 'terms_url'" );
}

/**
 * v8: sales showcase accounts (users.is_showcase), rebuilt daily by includes/showcase.php.
 *
 * @return void
 */
function migrate_to_v8() {
	migration_add_columns( 'users', [ 'is_showcase' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `terms_accepted_at`' ] );
}

/**
 * v9: Email Center. Creates `email_templates` (seeded with the factory templates) and
 * `email_logs` (moves the old `email_log` rows into it, then drops it), adds the per-company
 * delivery mode and the subscription reminder flag, seeds the delivery / layout / throttling
 * settings, and moves the old `roi_reports_enabled` switch onto the `monthly_roi_report` template.
 *
 * @return void
 */
function migrate_to_v9() {
	$schema = schema_sql_statements( [ 'settings' ] );

	foreach ( $schema['create'] as $statement ) {
		if ( false !== strpos( $statement, '`email_templates`' ) || false !== strpos( $statement, '`email_logs`' ) ) {
			db()->exec( $statement );
		}
	}

	foreach ( $schema['insert'] as $statement ) {
		db()->exec( $statement );
	}

	migration_add_columns( 'companies', [ 'email_delivery_mode' => "ENUM('global','instant_all','smart_hybrid','daily_digest_only') NOT NULL DEFAULT 'global' AFTER `notification_emails`" ] );
	migration_add_columns( 'users', [ 'subscription_reminder_for' => 'DATETIME NULL DEFAULT NULL AFTER `trial_reminder_sent`' ] );

	ensure_email_templates();

	if ( '0' === (string) db_value( "SELECT setting_value FROM settings WHERE setting_key = 'roi_reports_enabled'" ) ) {
		db_query( "UPDATE email_templates SET is_enabled = 0 WHERE slug = 'monthly_roi_report'" );
	}

	db()->exec( "DELETE FROM settings WHERE setting_key = 'roi_reports_enabled'" );

	if ( db_table_exists( 'email_log' ) ) {
		db()->exec(
			"INSERT INTO email_logs (recipient_email, subject, body_html, status, error_message, sent_at, created_at)
			SELECT recipient, subject, '', status, NULLIF(error_message, ''), IF(status = 'sent', created_at, NULL), created_at FROM email_log"
		);
		db()->exec( 'DROP TABLE email_log' );
	}
}

/**
 * v10: Google Drive Backup Manager. Creates `backup_logs`, seeds the gdrive_* settings and adds the
 * `admin_backup_failed` / `admin_backup_success` email templates.
 *
 * @return void
 */
function migrate_to_v10() {
	$schema = schema_sql_statements( [ 'settings' ] );

	foreach ( $schema['create'] as $statement ) {
		if ( false !== strpos( $statement, '`backup_logs`' ) ) {
			db()->exec( $statement );
		}
	}

	foreach ( $schema['insert'] as $statement ) {
		db()->exec( $statement );
	}

	ensure_email_templates();
}
