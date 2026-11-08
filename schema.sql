-- =============================================================================
-- RepuVio - Reputation Management SaaS
-- Complete, idempotent MySQL schema (InnoDB, utf8mb4_unicode_ci) + seed data.
--
-- @author  Stefan Iftimie <https://www.ahoi.ro/>
-- @company Ahoi Digital SRL
--
-- Import: mysql -u USER -p DATABASE < schema.sql
-- Safe to re-run: tables use CREATE TABLE IF NOT EXISTS, seeds use INSERT IGNORE.
-- Existing installations are upgraded automatically by includes/migrations.php
-- (ALTER TABLE for new columns, new tables, plan catalogue update) on the first request.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- plans: subscription tiers managed by the Super Admin (monthly + yearly price).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(100) NOT NULL,
	`slug` VARCHAR(100) NOT NULL,
	`description` VARCHAR(500) NOT NULL DEFAULT '',
	`target_description` VARCHAR(500) NOT NULL DEFAULT '',
	`price_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`price_yearly_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`price_is_starting` TINYINT(1) NOT NULL DEFAULT 0,
	`max_reviews_per_month` INT UNSIGNED NOT NULL DEFAULT 500,
	`max_companies` INT UNSIGNED NOT NULL DEFAULT 1,
	`max_alert_emails` INT UNSIGNED NOT NULL DEFAULT 1,
	`history_months` INT UNSIGNED NOT NULL DEFAULT 12,
	`allow_ref_tags` TINYINT(1) NOT NULL DEFAULT 0,
	`includes_onboarding` TINYINT(1) NOT NULL DEFAULT 0,
	`is_active` TINYINT(1) NOT NULL DEFAULT 1,
	`sort_order` INT NOT NULL DEFAULT 0,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_plans_slug` (`slug`),
	KEY `idx_plans_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- users: super admins, business owners (tenants) and operators (parent_id = owner).
-- onboarding_dismissed / onboarding_materials_at drive the dashboard activation checklist.
-- is_showcase = 1: sales demo accounts rebuilt daily by cron (includes/showcase.php); emails to them are redirected.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`role` ENUM('super_admin','user','operator') NOT NULL DEFAULT 'user',
	`parent_id` INT UNSIGNED NULL DEFAULT NULL,
	`name` VARCHAR(150) NOT NULL,
	`email` VARCHAR(190) NOT NULL,
	`password_hash` VARCHAR(255) NOT NULL,
	`status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
	`must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
	`session_version` INT UNSIGNED NOT NULL DEFAULT 1,
	`plan_id` INT UNSIGNED NULL DEFAULT NULL,
	`billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
	`custom_max_reviews` INT UNSIGNED NULL DEFAULT NULL,
	`custom_max_companies` INT UNSIGNED NULL DEFAULT NULL,
	`subscription_status` ENUM('trialing','active','past_due','expired','canceled') NOT NULL DEFAULT 'trialing',
	`trial_ends_at` DATETIME NULL DEFAULT NULL,
	`subscription_ends_at` DATETIME NULL DEFAULT NULL,
	`quota_cycle_start` DATETIME NULL DEFAULT NULL,
	`reviews_used_this_cycle` INT UNSIGNED NOT NULL DEFAULT 0,
	`quota_alert_80_sent` TINYINT(1) NOT NULL DEFAULT 0,
	`quota_alert_100_sent` TINYINT(1) NOT NULL DEFAULT 0,
	`trial_reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
	`subscription_reminder_for` DATETIME NULL DEFAULT NULL,
	`roi_report_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`last_roi_report_period` CHAR(7) NULL DEFAULT NULL,
	`onboarding_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
	`onboarding_materials_at` DATETIME NULL DEFAULT NULL,
	`terms_accepted_at` DATETIME NULL DEFAULT NULL,
	`is_showcase` TINYINT(1) NOT NULL DEFAULT 0,
	`last_login_at` DATETIME NULL DEFAULT NULL,
	`last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_users_email` (`email`),
	KEY `idx_users_role` (`role`),
	KEY `idx_users_parent` (`parent_id`),
	KEY `idx_users_subscription` (`subscription_status`, `trial_ends_at`, `subscription_ends_at`),
	KEY `idx_users_plan` (`plan_id`),
	CONSTRAINT `fk_users_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
	CONSTRAINT `fk_users_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- companies: local businesses owned by a user (tenant-scoped). tax_id = CUI / CIF (optional RO prefix),
-- required for invoicing and validated with the control digit in the application.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `companies` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`name` VARCHAR(150) NOT NULL,
	`tax_id` VARCHAR(20) NOT NULL DEFAULT '',
	`slug` VARCHAR(100) NOT NULL,
	`description` TEXT NULL,
	`logo_path` VARCHAR(255) NULL DEFAULT NULL,
	`cover_path` VARCHAR(255) NULL DEFAULT NULL,
	`google_place_id` VARCHAR(255) NOT NULL DEFAULT '',
	`google_review_url` VARCHAR(500) NOT NULL DEFAULT '',
	`notification_emails` VARCHAR(500) NOT NULL DEFAULT '',
	`email_delivery_mode` ENUM('global','instant_all','smart_hybrid','daily_digest_only') NOT NULL DEFAULT 'global',
	`min_star_threshold` TINYINT UNSIGNED NOT NULL DEFAULT 3,
	`redirect_mode` ENUM('immediate','helper') NOT NULL DEFAULT 'helper',
	`cta_text` VARCHAR(255) NOT NULL DEFAULT 'Scanează codul și spune-ne cum a fost!',
	`white_label_enabled` TINYINT(1) NOT NULL DEFAULT 0,
	`is_active` TINYINT(1) NOT NULL DEFAULT 1,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_companies_slug` (`slug`),
	KEY `idx_companies_user` (`user_id`),
	CONSTRAINT `fk_companies_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- operator_companies: which companies an operator may handle.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `operator_companies` (
	`operator_id` INT UNSIGNED NOT NULL,
	`company_id` INT UNSIGNED NOT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`operator_id`, `company_id`),
	KEY `idx_operator_companies_company` (`company_id`),
	CONSTRAINT `fk_operator_companies_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_operator_companies_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ref_tags: employee / department / table tracking tags (?ref=slug) per company.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ref_tags` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`company_id` INT UNSIGNED NOT NULL,
	`label` VARCHAR(80) NOT NULL,
	`slug` VARCHAR(64) NOT NULL,
	`is_active` TINYINT(1) NOT NULL DEFAULT 1,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_ref_tags_company_slug` (`company_id`, `slug`),
	CONSTRAINT `fk_ref_tags_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reviews: every completed star interaction on the public funnel (= 1 quota review).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reviews` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`company_id` INT UNSIGNED NOT NULL,
	`rating` TINYINT UNSIGNED NOT NULL,
	`action_taken` ENUM('redirected_google','private_feedback') NOT NULL,
	`customer_name` VARCHAR(150) NOT NULL DEFAULT '',
	`customer_contact` VARCHAR(190) NOT NULL DEFAULT '',
	`message` TEXT NULL,
	`source` VARCHAR(32) NOT NULL DEFAULT 'direct',
	`ref_tag` VARCHAR(64) NOT NULL DEFAULT '',
	`ip_hash` CHAR(64) NOT NULL DEFAULT '',
	`device_hash` CHAR(64) NOT NULL DEFAULT '',
	`user_agent` VARCHAR(255) NOT NULL DEFAULT '',
	`is_locked` TINYINT(1) NOT NULL DEFAULT 0,
	`counted_in_quota` TINYINT(1) NOT NULL DEFAULT 0,
	`privacy_consent` TINYINT(1) NOT NULL DEFAULT 0,
	`ticket_status` ENUM('new','in_progress','resolved') NOT NULL DEFAULT 'new',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_reviews_company_created` (`company_id`, `created_at`),
	KEY `idx_reviews_company_ip` (`company_id`, `ip_hash`, `created_at`),
	KEY `idx_reviews_company_device` (`company_id`, `device_hash`, `created_at`),
	KEY `idx_reviews_company_ref` (`company_id`, `ref_tag`),
	KEY `idx_reviews_action` (`action_taken`),
	KEY `idx_reviews_status` (`ticket_status`),
	KEY `idx_reviews_locked` (`is_locked`),
	CONSTRAINT `fk_reviews_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- review_notes: internal resolution notes on feedback tickets.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `review_notes` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`review_id` INT UNSIGNED NOT NULL,
	`user_id` INT UNSIGNED NULL DEFAULT NULL,
	`note` TEXT NOT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_review_notes_review` (`review_id`),
	CONSTRAINT `fk_review_notes_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_review_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- funnel_views: public funnel page loads (QR scans) = "vizualizări", not quota reviews.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnel_views` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`company_id` INT UNSIGNED NOT NULL,
	`source` VARCHAR(32) NOT NULL DEFAULT 'direct',
	`ref_tag` VARCHAR(64) NOT NULL DEFAULT '',
	`ip_hash` CHAR(64) NOT NULL DEFAULT '',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_funnel_views_company_created` (`company_id`, `created_at`),
	KEY `idx_funnel_views_company_ref` (`company_id`, `ref_tag`),
	CONSTRAINT `fk_funnel_views_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rate_limits: brute-force protection keyed by IP + action.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`ip_address` VARCHAR(45) NOT NULL,
	`action` VARCHAR(50) NOT NULL,
	`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
	`window_start` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`locked_until` DATETIME NULL DEFAULT NULL,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_rate_limits_ip_action` (`ip_address`, `action`),
	KEY `idx_rate_limits_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- password_resets: single-use, hashed, expiring reset tokens.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`token_hash` CHAR(64) NOT NULL,
	`expires_at` DATETIME NOT NULL,
	`used_at` DATETIME NULL DEFAULT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_password_resets_token` (`token_hash`),
	KEY `idx_password_resets_user` (`user_id`),
	CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- payments: manually logged invoice payments.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`plan_id` INT UNSIGNED NULL DEFAULT NULL,
	`amount_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`period_start` DATE NULL DEFAULT NULL,
	`period_end` DATE NULL DEFAULT NULL,
	`method` VARCHAR(50) NOT NULL DEFAULT 'bank_transfer',
	`reference` VARCHAR(100) NOT NULL DEFAULT '',
	`notes` VARCHAR(500) NOT NULL DEFAULT '',
	`recorded_by` INT UNSIGNED NULL DEFAULT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_payments_user` (`user_id`),
	KEY `idx_payments_created` (`created_at`),
	CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_payments_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
	CONSTRAINT `fk_payments_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- subscription_requests: plan requests sent from the Abonament page (stored even if the email fails).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_requests` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`plan_id` INT UNSIGNED NULL DEFAULT NULL,
	`plan_name` VARCHAR(100) NOT NULL DEFAULT '',
	`billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
	`price_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`status` ENUM('pending','invoiced','completed','rejected') NOT NULL DEFAULT 'pending',
	`email_sent` TINYINT(1) NOT NULL DEFAULT 0,
	`admin_note` VARCHAR(500) NOT NULL DEFAULT '',
	`processed_by` INT UNSIGNED NULL DEFAULT NULL,
	`processed_at` DATETIME NULL DEFAULT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_subscription_requests_status` (`status`, `created_at`),
	KEY `idx_subscription_requests_user` (`user_id`),
	CONSTRAINT `fk_subscription_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_subscription_requests_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
	CONSTRAINT `fk_subscription_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- contact_messages: public contact form (/contact) inquiries, viewable by the Super Admin.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_messages` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(150) NOT NULL,
	`company` VARCHAR(150) NOT NULL DEFAULT '',
	`email` VARCHAR(190) NOT NULL,
	`phone` VARCHAR(50) NOT NULL DEFAULT '',
	`subject` VARCHAR(30) NOT NULL DEFAULT 'commercial',
	`message` TEXT NOT NULL,
	`gdpr_consent` TINYINT(1) NOT NULL DEFAULT 0,
	`status` ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
	`email_sent` TINYINT(1) NOT NULL DEFAULT 0,
	`ip_hash` CHAR(64) NOT NULL DEFAULT '',
	`user_agent` VARCHAR(255) NOT NULL DEFAULT '',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_contact_messages_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- print_products: physical print upsell catalogue (prices editable by the Super Admin).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_products` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`title` VARCHAR(150) NOT NULL,
	`description` VARCHAR(500) NOT NULL DEFAULT '',
	`price_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`is_active` TINYINT(1) NOT NULL DEFAULT 1,
	`sort_order` INT NOT NULL DEFAULT 0,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_print_products_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- print_orders: physical print order requests from business owners.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_orders` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`user_id` INT UNSIGNED NOT NULL,
	`company_id` INT UNSIGNED NULL DEFAULT NULL,
	`status` ENUM('new','confirmed','in_production','shipped','delivered','canceled') NOT NULL DEFAULT 'new',
	`total_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`contact_name` VARCHAR(150) NOT NULL DEFAULT '',
	`contact_phone` VARCHAR(50) NOT NULL DEFAULT '',
	`shipping_address` VARCHAR(500) NOT NULL DEFAULT '',
	`notes` VARCHAR(1000) NOT NULL DEFAULT '',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_print_orders_user` (`user_id`),
	KEY `idx_print_orders_status` (`status`, `created_at`),
	CONSTRAINT `fk_print_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_print_orders_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- print_order_items: order lines (title and price are snapshotted at order time).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_order_items` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`order_id` INT UNSIGNED NOT NULL,
	`product_id` INT UNSIGNED NULL DEFAULT NULL,
	`title` VARCHAR(150) NOT NULL,
	`unit_price_ron` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	`quantity` INT UNSIGNED NOT NULL DEFAULT 1,
	PRIMARY KEY (`id`),
	KEY `idx_print_order_items_order` (`order_id`),
	CONSTRAINT `fk_print_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `print_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT `fk_print_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `print_products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- settings: global key/value system settings (secrets encrypted at rest).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
	`setting_key` VARCHAR(100) NOT NULL,
	`setting_value` TEXT NULL,
	`is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- email_templates: every system email (Admin → Centru Email & Șabloane). subject / body_html are
-- editable, default_* hold the factory text for 1-click reset, is_enabled is the ON/OFF switch.
-- body_html is the inner content; send_templated_email() wraps it in the master layout.
-- Rows are seeded below and by ensure_email_templates() (includes/email-templates.php).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`slug` VARCHAR(64) NOT NULL,
	`name` VARCHAR(120) NOT NULL,
	`category` ENUM('user_auth','reviews_digest','billing_quota','admin_system') NOT NULL DEFAULT 'admin_system',
	`is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`subject` VARCHAR(255) NOT NULL,
	`body_html` MEDIUMTEXT NOT NULL,
	`default_subject` VARCHAR(255) NOT NULL,
	`default_body_html` MEDIUMTEXT NOT NULL,
	`available_placeholders` TEXT NULL,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `uq_email_templates_slug` (`slug`),
	KEY `idx_email_templates_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- email_logs: every outgoing email, one row per recipient, with the final HTML (view + 1-click
-- re-send). status: sent, failed, queued (SMTP batch queue processed by cron.php),
-- skipped_disabled (template switched off). Rows older than 90 days are purged by cron.php.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`template_slug` VARCHAR(64) NULL DEFAULT NULL,
	`recipient_email` VARCHAR(190) NOT NULL,
	`recipient_user_id` INT UNSIGNED NULL DEFAULT NULL,
	`reply_to` VARCHAR(190) NOT NULL DEFAULT '',
	`subject` VARCHAR(255) NOT NULL,
	`body_html` MEDIUMTEXT NOT NULL,
	`status` ENUM('sent','failed','queued','skipped_disabled') NOT NULL DEFAULT 'queued',
	`error_message` TEXT NULL,
	`sent_at` DATETIME NULL DEFAULT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_email_logs_status` (`status`, `id`),
	KEY `idx_email_logs_created` (`created_at`),
	KEY `idx_email_logs_template` (`template_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- backup_logs: Google Drive Backup Manager history, one row per archive (db = .sql.gz, files = .zip).
-- destination: gdrive (uploaded to the "ahoi-backups" folder) or download (streamed to the admin's
-- browser). gdrive_deleted_at is set when the retention policy removed the file from Drive.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `backup_logs` (
	`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	`backup_type` ENUM('db','files','full') NOT NULL,
	`trigger_type` ENUM('cron','manual') NOT NULL DEFAULT 'manual',
	`destination` ENUM('gdrive','download') NOT NULL DEFAULT 'gdrive',
	`file_name` VARCHAR(190) NOT NULL DEFAULT '',
	`file_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`gdrive_file_id` VARCHAR(128) NOT NULL DEFAULT '',
	`gdrive_web_link` VARCHAR(500) NOT NULL DEFAULT '',
	`gdrive_deleted_at` DATETIME NULL DEFAULT NULL,
	`status` ENUM('success','failed','running') NOT NULL DEFAULT 'running',
	`duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
	`error_message` TEXT NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_backup_logs_created` (`created_at`),
	KEY `idx_backup_logs_gdrive` (`gdrive_file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_log: security-relevant actions (logins, impersonation, admin edits).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`actor_id` INT UNSIGNED NULL DEFAULT NULL,
	`target_user_id` INT UNSIGNED NULL DEFAULT NULL,
	`action` VARCHAR(60) NOT NULL,
	`details` VARCHAR(500) NOT NULL DEFAULT '',
	`ip_address` VARCHAR(45) NOT NULL DEFAULT '',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	KEY `idx_audit_log_actor` (`actor_id`),
	KEY `idx_audit_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- =============================================================================
-- SEED DATA
-- =============================================================================

-- Subscription plans (yearly price = 10 months, i.e. 2 months free).
INSERT IGNORE INTO `plans` (`id`, `name`, `slug`, `description`, `target_description`, `price_ron`, `price_yearly_ron`, `price_is_starting`, `max_reviews_per_month`, `max_companies`, `max_alert_emails`, `history_months`, `allow_ref_tags`, `includes_onboarding`, `is_active`, `sort_order`) VALUES
	(1, 'Starter', 'starter', 'O locație, 500 de recenzii colectate pe lună.', 'Cabinete individuale, saloane de înfrumusețare, firme de intervenții/instalații, pensiuni mici, ateliere.', 100.00, 1000.00, 0, 500, 1, 1, 12, 0, 0, 1, 1),
	(2, 'Pro', 'pro', 'Până la 3 locații și coduri QR multiple pe angajat, departament sau masă.', 'Restaurante, cafenele, clinici medicale, service-uri auto, hoteluri, firme cu mai multe echipe pe teren.', 250.00, 2500.00, 0, 1000, 3, 3, 24, 1, 0, 1, 2),
	(3, 'Enterprise', 'enterprise', 'Toate funcțiile Pro, 5+ locații, cote personalizate și onboarding asistat.', 'Rețele, francize și grupuri cu multe locații. Preț negociabil.', 590.00, 5900.00, 1, 5000, 5, 10, 0, 1, 1, 1, 3);

-- Default Super Admin. Login: admin@example.com / ChangeMe-2026!
-- The password MUST be changed on first login (must_change_password = 1).
INSERT IGNORE INTO `users` (`id`, `role`, `name`, `email`, `password_hash`, `status`, `must_change_password`, `subscription_status`, `quota_cycle_start`) VALUES
	(1, 'super_admin', 'Super Admin', 'admin@example.com', '$2y$12$fvmIky4ep/TcVJVJ6DgJv.HU7gK7NaW5pzBUFa7vVgOoBjVrJ5lWi', 'active', 1, 'active', NOW());

-- Physical print upsell catalogue (hidden until enable_physical_print_upsell = 1).
INSERT IGNORE INTO `print_products` (`id`, `title`, `description`, `price_ron`, `is_active`, `sort_order`) VALUES
	(1, 'Stand A4 Plexiglas', 'Suport de tejghea din plexiglas transparent cu afișul A4 personalizat inclus.', 149.00, 1, 1),
	(2, 'Flyer A5 (set 50 buc.)', 'Flyere A5 / table tent personalizate, hârtie 300 g, laminate mat.', 99.00, 1, 2),
	(3, 'Set 100 Cărți de Vizită', 'Cărți de vizită 85 × 55 mm față-verso cu cod QR, laminate.', 129.00, 1, 3),
	(4, 'Sticker Masă 100 × 100 mm (set 10 buc.)', 'Stickere rezistente la apă pentru mese, vitrine sau casa de marcat.', 79.00, 1, 4),
	(5, 'Pachet Complet Onboarding', 'Stand A4 + 50 flyere A5 + 100 cărți de vizită + 10 stickere, livrare inclusă.', 399.00, 1, 5);

-- System email templates (generated by tools/email-templates-sql.php from includes/email-templates.php).
INSERT IGNORE INTO `email_templates` (`slug`, `name`, `category`, `is_enabled`, `subject`, `body_html`, `default_subject`, `default_body_html`, `available_placeholders`) VALUES
	('welcome_trial', 'Bun venit & activare perioadă gratuită', 'user_auth', 1, 'Bine ai venit în {{app_name}}! Perioada ta gratuită a început', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Bine ai venit, {{user_name}}!</h1>\n<p>Contul <strong>{{user_email}}</strong> a fost creat. Ai <strong>{{trial_days}} zile gratuite</strong>, cu toate funcțiile deblocate, până la <strong>{{trial_ends_at}}</strong>.</p>\n<p>În 3 pași simpli ești gata:</p>\n<ol style="padding-left:20px;margin:0 0 8px;"><li>Adaugă compania și linkul tău Google Reviews.</li>\n<li>Alege de la câte stele clienții sunt trimiși pe Google.</li>\n<li>Printează codul QR din Print Studio și pune-l la vedere.</li>\n</ol>\n<p style="margin:28px 0 8px;"><a href="{{onboarding_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Pornește ghidul de configurare</a></p>\n<p style="font-size:13px;color:#64748B;">Poți intra oricând în panou de aici: <a href="{{dashboard_url}}" style="color:#64748B;">{{dashboard_url}}</a></p>', 'Bine ai venit în {{app_name}}! Perioada ta gratuită a început', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Bine ai venit, {{user_name}}!</h1>\n<p>Contul <strong>{{user_email}}</strong> a fost creat. Ai <strong>{{trial_days}} zile gratuite</strong>, cu toate funcțiile deblocate, până la <strong>{{trial_ends_at}}</strong>.</p>\n<p>În 3 pași simpli ești gata:</p>\n<ol style="padding-left:20px;margin:0 0 8px;"><li>Adaugă compania și linkul tău Google Reviews.</li>\n<li>Alege de la câte stele clienții sunt trimiși pe Google.</li>\n<li>Printează codul QR din Print Studio și pune-l la vedere.</li>\n</ol>\n<p style="margin:28px 0 8px;"><a href="{{onboarding_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Pornește ghidul de configurare</a></p>\n<p style="font-size:13px;color:#64748B;">Poți intra oricând în panou de aici: <a href="{{dashboard_url}}" style="color:#64748B;">{{dashboard_url}}</a></p>', '{"user_name":"Numele utilizatorului","user_email":"Emailul contului","trial_days":"Numărul de zile gratuite","trial_ends_at":"Data de final a perioadei gratuite","dashboard_url":"Link către panou","onboarding_url":"Link către ghidul de configurare"}'),
	('password_reset', 'Resetare parolă', 'user_auth', 1, 'Resetează parola contului tău {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Resetează parola</h1>\n<p>Bună {{user_name}},</p>\n<p>Am primit o cerere de resetare a parolei pentru contul tău (de la adresa IP {{ip_address}}). Linkul este valabil <strong>{{expires_in}}</strong> și poate fi folosit o singură dată.</p>\n<p style="margin:28px 0 8px;"><a href="{{reset_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Setează o parolă nouă</a></p>\n<p style="font-size:13px;color:#64748B;">Dacă nu tu ai cerut resetarea, ignoră acest mesaj: parola ta rămâne neschimbată.</p>', 'Resetează parola contului tău {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Resetează parola</h1>\n<p>Bună {{user_name}},</p>\n<p>Am primit o cerere de resetare a parolei pentru contul tău (de la adresa IP {{ip_address}}). Linkul este valabil <strong>{{expires_in}}</strong> și poate fi folosit o singură dată.</p>\n<p style="margin:28px 0 8px;"><a href="{{reset_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Setează o parolă nouă</a></p>\n<p style="font-size:13px;color:#64748B;">Dacă nu tu ai cerut resetarea, ignoră acest mesaj: parola ta rămâne neschimbată.</p>', '{"user_name":"Numele utilizatorului","reset_url":"Linkul unic de resetare","expires_in":"Valabilitatea linkului (ex: 60 de minute)","ip_address":"Adresa IP de la care s-a cerut resetarea"}'),
	('operator_invite', 'Invitație cont operator', 'user_auth', 1, '{{owner_name}} te-a invitat să gestionezi feedback-ul în {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai fost invitat în echipă</h1>\n<p>Bună {{operator_name}},</p>\n<p><strong>{{owner_name}}</strong> ți-a creat un cont de operator pentru <strong>{{company_name}}</strong>. Vei putea citi feedback-ul privat al clienților, schimba statusul tichetelor și adăuga note interne.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Email de autentificare</td><td>{{operator_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Parolă temporară</td><td><strong>{{temp_password}}</strong></td></tr>\n</table>\n<p>La prima autentificare vei fi rugat să îți alegi o parolă nouă.</p>\n<p style="margin:28px 0 8px;"><a href="{{login_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Autentifică-te</a></p>', '{{owner_name}} te-a invitat să gestionezi feedback-ul în {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai fost invitat în echipă</h1>\n<p>Bună {{operator_name}},</p>\n<p><strong>{{owner_name}}</strong> ți-a creat un cont de operator pentru <strong>{{company_name}}</strong>. Vei putea citi feedback-ul privat al clienților, schimba statusul tichetelor și adăuga note interne.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Email de autentificare</td><td>{{operator_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Parolă temporară</td><td><strong>{{temp_password}}</strong></td></tr>\n</table>\n<p>La prima autentificare vei fi rugat să îți alegi o parolă nouă.</p>\n<p style="margin:28px 0 8px;"><a href="{{login_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Autentifică-te</a></p>', '{"operator_name":"Numele operatorului","operator_email":"Emailul operatorului","owner_name":"Numele proprietarului care a creat contul","company_name":"Companiile atribuite","login_url":"Link către pagina de autentificare","temp_password":"Parola inițială (trebuie schimbată la prima autentificare)"}'),
	('instant_negative_feedback', 'Alertă instantanee: feedback privat (sub prag)', 'reviews_digest', 1, 'Feedback nou ({{stars}}) — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Feedback privat nou</h1>\n<p>Un client a lăsat un feedback privat pentru <strong>{{company_name}}</strong>. Contactează-l cât mai repede: un client ascultat devine adesea un client fidel.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Rating</td><td><strong style="color:#B91C1C;">{{stars}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nume</td><td>{{customer_name}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Contact</td><td>{{customer_contact}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Cod QR</td><td>{{ref_tag}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Primit la</td><td>{{created_at}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Mesaj</td><td>{{message}}</td></tr>\n</table>\n<p style="margin:28px 0 8px;"><a href="{{feedback_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide tichetul</a></p>', 'Feedback nou ({{stars}}) — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Feedback privat nou</h1>\n<p>Un client a lăsat un feedback privat pentru <strong>{{company_name}}</strong>. Contactează-l cât mai repede: un client ascultat devine adesea un client fidel.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Rating</td><td><strong style="color:#B91C1C;">{{stars}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nume</td><td>{{customer_name}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Contact</td><td>{{customer_contact}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Cod QR</td><td>{{ref_tag}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Primit la</td><td>{{created_at}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Mesaj</td><td>{{message}}</td></tr>\n</table>\n<p style="margin:28px 0 8px;"><a href="{{feedback_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide tichetul</a></p>', '{"company_name":"Numele companiei","stars":"Stelele acordate (ex: ★★☆☆☆ 2/5)","customer_name":"Numele clientului (sau —)","customer_contact":"Telefon / email client (sau —)","message":"Mesajul clientului","ref_tag":"Codul QR scanat (angajat / departament) sau „Cod general”","created_at":"Data și ora","feedback_url":"Link către tichet"}'),
	('instant_positive_review', 'Alertă instantanee: redirecționare pozitivă Google', 'reviews_digest', 0, 'Un client mulțumit ({{stars}}) a fost trimis pe Google — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Încă un client mulțumit!</h1>\n<p>Un client a dat <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong> și a fost trimis să lase o recenzie pe Google.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Cod QR</td><td>{{ref_tag}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Primit la</td><td>{{created_at}}</td></tr>\n</table>\n<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi panoul</a></p>', 'Un client mulțumit ({{stars}}) a fost trimis pe Google — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Încă un client mulțumit!</h1>\n<p>Un client a dat <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong> și a fost trimis să lase o recenzie pe Google.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Cod QR</td><td>{{ref_tag}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Primit la</td><td>{{created_at}}</td></tr>\n</table>\n<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi panoul</a></p>', '{"company_name":"Numele companiei","stars":"Stelele acordate","ref_tag":"Codul QR scanat","created_at":"Data și ora","dashboard_url":"Link către panou"}'),
	('daily_digest', 'Rezumatul zilei precedente', 'reviews_digest', 1, 'Rezumatul zilei de {{date_yesterday}} — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Rezumatul zilei de {{date_yesterday}}</h1>\n<p>Bună {{user_name}}, iată ce s-a întâmplat ieri la <strong>{{company_name}}</strong>:</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Scanări cod QR</td><td><strong>{{total_views}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Trimiși pe Google</td><td><strong style="color:#15803D;">{{google_redirects}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Feedback privat</td><td><strong style="color:#B91C1C;">{{private_feedbacks_count}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nota medie</td><td>{{avg_rating}}</td></tr>\n</table>\n<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">Feedback privat primit</h2>\n{{feedbacks_table_html}}<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide panoul</a></p>', 'Rezumatul zilei de {{date_yesterday}} — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Rezumatul zilei de {{date_yesterday}}</h1>\n<p>Bună {{user_name}}, iată ce s-a întâmplat ieri la <strong>{{company_name}}</strong>:</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Scanări cod QR</td><td><strong>{{total_views}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Trimiși pe Google</td><td><strong style="color:#15803D;">{{google_redirects}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Feedback privat</td><td><strong style="color:#B91C1C;">{{private_feedbacks_count}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nota medie</td><td>{{avg_rating}}</td></tr>\n</table>\n<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">Feedback privat primit</h2>\n{{feedbacks_table_html}}<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide panoul</a></p>', '{"user_name":"Numele proprietarului","company_name":"Numele companiei","date_yesterday":"Ziua rezumată (ex: 01.11.2026)","total_views":"Scanări / vizualizări în acea zi","google_redirects":"Clienți trimiși pe Google","private_feedbacks_count":"Feedback-uri private primite","avg_rating":"Nota medie a zilei","feedbacks_table_html":"Tabel cu toate mesajele private ale zilei (generat)","dashboard_url":"Link către panou"}'),
	('monthly_roi_report', 'Raport lunar ROI & valoare generată', 'reviews_digest', 1, 'Raport {{period_label}}: {{google_redirects}} clienți trimiși pe Google — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Valoarea generată: {{period_label}}</h1>\n<p>Bună {{user_name}}, iată ce a adus {{app_name}} afacerii tale în perioada <strong>{{period_label}}</strong>:</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Scanări cod QR</td><td><strong>{{total_views}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Clienți trimiși pe Google</td><td><strong style="color:#15803D;">{{google_redirects}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Negative interceptate privat</td><td><strong style="color:#B91C1C;">{{saved_negatives}}</strong> ({{resolved_count}} rezolvate)</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nota medie internă</td><td>{{avg_rating}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Conversie scanare → evaluare</td><td>{{conversion}}</td></tr>\n</table>\n{{locations_table_html}}{{top_ref_tags_html}}<p style="margin-top:18px;font-size:13px;color:#64748B;">Sfat: pune codul QR la vedere în momentul plății sau la predarea comenzii, acolo conversia este cea mai mare.</p>\n<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide panoul</a></p>', 'Raport {{period_label}}: {{google_redirects}} clienți trimiși pe Google — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Valoarea generată: {{period_label}}</h1>\n<p>Bună {{user_name}}, iată ce a adus {{app_name}} afacerii tale în perioada <strong>{{period_label}}</strong>:</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Scanări cod QR</td><td><strong>{{total_views}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Clienți trimiși pe Google</td><td><strong style="color:#15803D;">{{google_redirects}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Negative interceptate privat</td><td><strong style="color:#B91C1C;">{{saved_negatives}}</strong> ({{resolved_count}} rezolvate)</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Nota medie internă</td><td>{{avg_rating}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Conversie scanare → evaluare</td><td>{{conversion}}</td></tr>\n</table>\n{{locations_table_html}}{{top_ref_tags_html}}<p style="margin-top:18px;font-size:13px;color:#64748B;">Sfat: pune codul QR la vedere în momentul plății sau la predarea comenzii, acolo conversia este cea mai mare.</p>\n<p style="margin:28px 0 8px;"><a href="{{dashboard_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide panoul</a></p>', '{"user_name":"Numele proprietarului","period_label":"Perioada raportului (ex: octombrie 2026)","total_views":"Scanări / vizualizări","google_redirects":"Clienți trimiși pe Google","saved_negatives":"Feedback negativ interceptat","resolved_count":"Tichete rezolvate","avg_rating":"Nota medie internă","conversion":"Conversie scanare → evaluare","locations_table_html":"Tabel pe locații (doar la mai multe companii, generat)","top_ref_tags_html":"Top angajați / coduri QR (planuri cu etichete, generat)","dashboard_url":"Link către panou"}'),
	('quota_warning_80', 'Avertizare cotă lunară (80% consumat)', 'billing_quota', 1, 'Ai folosit 80% din recenziile lunare — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai folosit 80% din recenziile lunare</h1>\n<p>Bună {{user_name}},</p>\n<p>Pe planul <strong>{{plan_name}}</strong> ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse luna aceasta.</p>\n<p>Când limita este atinsă, clienții mulțumiți ajung în continuare pe Google, dar feedback-ul negativ nou este blocat. Un plan superior te ajută să nu pierzi niciun mesaj.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi abonamentul</a></p>', 'Ai folosit 80% din recenziile lunare — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai folosit 80% din recenziile lunare</h1>\n<p>Bună {{user_name}},</p>\n<p>Pe planul <strong>{{plan_name}}</strong> ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse luna aceasta.</p>\n<p>Când limita este atinsă, clienții mulțumiți ajung în continuare pe Google, dar feedback-ul negativ nou este blocat. Un plan superior te ajută să nu pierzi niciun mesaj.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi abonamentul</a></p>', '{"user_name":"Numele proprietarului","plan_name":"Planul curent","used_reviews":"Recenzii folosite","max_reviews":"Recenzii incluse","billing_url":"Link către pagina Abonament"}'),
	('quota_reached_100', 'Cotă lunară atinsă (100%)', 'billing_quota', 1, 'Ai atins limita lunară de recenzii — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai atins limita lunară de recenzii</h1>\n<p>Bună {{user_name}},</p>\n<p>Ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse în planul <strong>{{plan_name}}</strong>.</p>\n<p>Clienții mulțumiți sunt trimiși în continuare pe Google, însă feedback-ul negativ nou este <strong>blocat</strong> până la upgrade sau până la începutul noului ciclu.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Fă upgrade</a></p>', 'Ai atins limita lunară de recenzii — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Ai atins limita lunară de recenzii</h1>\n<p>Bună {{user_name}},</p>\n<p>Ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse în planul <strong>{{plan_name}}</strong>.</p>\n<p>Clienții mulțumiți sunt trimiși în continuare pe Google, însă feedback-ul negativ nou este <strong>blocat</strong> până la upgrade sau până la începutul noului ciclu.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Fă upgrade</a></p>', '{"user_name":"Numele proprietarului","plan_name":"Planul curent","used_reviews":"Recenzii folosite","max_reviews":"Recenzii incluse","billing_url":"Link către pagina Abonament"}'),
	('quota_exceeded_softlock', 'Cotă depășită / feedback privat blocat (soft-lock)', 'billing_quota', 1, 'Ai un feedback negativ blocat — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Feedback negativ în așteptare</h1>\n<p>Bună {{user_name}},</p>\n<p>Un client a lăsat un feedback privat de <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong>.</p>\n<p>Mesajul este <strong>blocat</strong> pentru că limita lunară a planului {{plan_name}} a fost atinsă sau abonamentul nu este activ. Reînnoiește sau fă upgrade ca să îl citești și să poți contacta clientul.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deblochează feedback-ul</a></p>', 'Ai un feedback negativ blocat — {{company_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Feedback negativ în așteptare</h1>\n<p>Bună {{user_name}},</p>\n<p>Un client a lăsat un feedback privat de <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong>.</p>\n<p>Mesajul este <strong>blocat</strong> pentru că limita lunară a planului {{plan_name}} a fost atinsă sau abonamentul nu este activ. Reînnoiește sau fă upgrade ca să îl citești și să poți contacta clientul.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deblochează feedback-ul</a></p>', '{"user_name":"Numele proprietarului","company_name":"Numele companiei","stars":"Stelele acordate","plan_name":"Planul curent","billing_url":"Link către pagina Abonament"}'),
	('trial_expiring_soon', 'Expirare perioadă gratuită / abonament în 3 zile', 'billing_quota', 1, '{{plan_name}} expiră pe {{expires_at}} — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Accesul tău expiră în curând</h1>\n<p>Bună {{user_name}},</p>\n<p><strong>{{plan_name}}</strong> expiră în <strong>{{days_left}} zile</strong>, pe <strong>{{expires_at}}</strong>.</p>\n<p>Alege sau reînnoiește abonamentul ca să primești în continuare feedback-ul privat al clienților, fără întrerupere.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Alege abonamentul</a></p>', '{{plan_name}} expiră pe {{expires_at}} — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Accesul tău expiră în curând</h1>\n<p>Bună {{user_name}},</p>\n<p><strong>{{plan_name}}</strong> expiră în <strong>{{days_left}} zile</strong>, pe <strong>{{expires_at}}</strong>.</p>\n<p>Alege sau reînnoiește abonamentul ca să primești în continuare feedback-ul privat al clienților, fără întrerupere.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Alege abonamentul</a></p>', '{"user_name":"Numele proprietarului","plan_name":"Planul (sau „Perioada de probă”)","expires_at":"Data expirării","days_left":"Zile rămase","billing_url":"Link către pagina Abonament"}'),
	('subscription_expired', 'Abonament / perioadă gratuită expirată', 'billing_quota', 1, 'Abonamentul a expirat — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Abonament expirat</h1>\n<p>Bună {{user_name}},</p>\n<p><strong>{{plan_name}}</strong> a expirat. Linkurile și codurile QR funcționează în continuare, iar clienții mulțumiți ajung pe Google, dar feedback-ul negativ nou este blocat până la reînnoire.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Reînnoiește abonamentul</a></p>', 'Abonamentul a expirat — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Abonament expirat</h1>\n<p>Bună {{user_name}},</p>\n<p><strong>{{plan_name}}</strong> a expirat. Linkurile și codurile QR funcționează în continuare, iar clienții mulțumiți ajung pe Google, dar feedback-ul negativ nou este blocat până la reînnoire.</p>\n<p style="margin:28px 0 8px;"><a href="{{billing_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Reînnoiește abonamentul</a></p>', '{"user_name":"Numele proprietarului","plan_name":"Planul (sau „Perioada de probă”)","billing_url":"Link către pagina Abonament"}'),
	('admin_new_user_alert', '[Admin] Utilizator nou înregistrat', 'admin_system', 1, '[Admin] Cont nou: {{user_name}} ({{user_email}})', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Utilizator nou înregistrat</h1>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Nume</td><td><strong>{{user_name}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Email</td><td>{{user_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Înregistrat la</td><td>{{registered_at}}</td></tr>\n</table>\n<p>Perioada gratuită a început automat.</p>\n<p style="margin:28px 0 8px;"><a href="{{admin_users_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi utilizatorii</a></p>', '[Admin] Cont nou: {{user_name}} ({{user_email}})', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Utilizator nou înregistrat</h1>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Nume</td><td><strong>{{user_name}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Email</td><td>{{user_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Înregistrat la</td><td>{{registered_at}}</td></tr>\n</table>\n<p>Perioada gratuită a început automat.</p>\n<p style="margin:28px 0 8px;"><a href="{{admin_users_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Vezi utilizatorii</a></p>', '{"user_name":"Numele noului utilizator","user_email":"Emailul noului utilizator","registered_at":"Data înregistrării","admin_users_url":"Link către lista de utilizatori"}'),
	('admin_backup_failed', '[Admin] Backup Google Drive eșuat', 'admin_system', 1, '[Admin] Backup eșuat: {{backup_label}} — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Backup-ul nu a reușit</h1>\n<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} nu a putut fi salvat în folderul Google Drive <strong>{{folder_name}}</strong>.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Eroare</td><td><span style="color:#B91C1C;">{{error_message}}</span></td></tr>\n</table>\n<p>Cauze frecvente: contul Google a fost deconectat sau parola schimbată (reconectează-l), spațiul din Google Drive este plin, ori serverul nu are spațiu pe disc pentru arhiva temporară.</p>\n<p style="margin:28px 0 8px;"><a href="{{backups_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide Backup Google Drive</a></p>', '[Admin] Backup eșuat: {{backup_label}} — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Backup-ul nu a reușit</h1>\n<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} nu a putut fi salvat în folderul Google Drive <strong>{{folder_name}}</strong>.</p>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">Eroare</td><td><span style="color:#B91C1C;">{{error_message}}</span></td></tr>\n</table>\n<p>Cauze frecvente: contul Google a fost deconectat sau parola schimbată (reconectează-l), spațiul din Google Drive este plin, ori serverul nu are spațiu pe disc pentru arhiva temporară.</p>\n<p style="margin:28px 0 8px;"><a href="{{backups_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide Backup Google Drive</a></p>', '{"backup_label":"Ce s-a salvat (bază de date / fișiere / complet)","trigger_label":"Automat (cron) sau manual","started_at":"Data și ora","error_message":"Mesajul de eroare","folder_name":"Folderul din Google Drive","folder_url":"Link către folderul din Google Drive","backups_url":"Link către pagina Backup Google Drive"}'),
	('admin_backup_success', '[Admin] Backup Google Drive reușit', 'admin_system', 0, '[Admin] Backup reușit: {{backup_label}} ({{total_size}}) — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Backup salvat în Google Drive</h1>\n<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} a fost salvat în folderul <strong>{{folder_name}}</strong>.</p>\n{{files_html}}<p>Dimensiune totală: <strong>{{total_size}}</strong>.</p>\n<p style="margin:28px 0 8px;"><a href="{{folder_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide folderul în Google Drive</a></p>', '[Admin] Backup reușit: {{backup_label}} ({{total_size}}) — {{app_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">Backup salvat în Google Drive</h1>\n<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} a fost salvat în folderul <strong>{{folder_name}}</strong>.</p>\n{{files_html}}<p>Dimensiune totală: <strong>{{total_size}}</strong>.</p>\n<p style="margin:28px 0 8px;"><a href="{{folder_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide folderul în Google Drive</a></p>', '{"backup_label":"Ce s-a salvat (bază de date / fișiere / complet)","trigger_label":"Automat (cron) sau manual","started_at":"Data și ora","files_html":"Tabel cu arhivele salvate (generat)","total_size":"Dimensiunea totală","folder_name":"Folderul din Google Drive","folder_url":"Link către folderul din Google Drive","backups_url":"Link către pagina Backup Google Drive"}'),
	('admin_contact_or_order', '[Admin] Mesaj contact / comandă printuri / cerere abonament', 'admin_system', 1, '[Admin] {{subject_type}} — {{sender_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">{{subject_type}}</h1>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">De la</td><td><strong>{{sender_name}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Email</td><td>{{sender_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Telefon</td><td>{{sender_phone}}</td></tr>\n</table>\n{{details_html}}<p style="margin:28px 0 8px;"><a href="{{admin_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide în panou</a></p>', '[Admin] {{subject_type}} — {{sender_name}}', '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">{{subject_type}}</h1>\n<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;"><tr><td style="color:#64748B;width:150px;vertical-align:top;">De la</td><td><strong>{{sender_name}}</strong></td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Email</td><td>{{sender_email}}</td></tr>\n<tr><td style="color:#64748B;width:150px;vertical-align:top;">Telefon</td><td>{{sender_phone}}</td></tr>\n</table>\n{{details_html}}<p style="margin:28px 0 8px;"><a href="{{admin_url}}" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">Deschide în panou</a></p>', '{"sender_name":"Numele expeditorului / clientului","sender_email":"Emailul expeditorului","sender_phone":"Telefonul expeditorului (sau —)","subject_type":"Tipul cererii (ex: Mesaj contact: Ofertă comercială, Comandă printuri #12)","details_html":"Detaliile mesajului / comenzii (generat)","admin_url":"Link către secțiunea din panoul admin"}');

-- Default system settings.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
	('app_name', 'RepuVio', 0),
	('support_email', 'support@example.com', 0),
	('legal_company_name', 'AHOI DIGITAL SRL', 0),
	('legal_company_url', 'https://www.ahoi.ro/', 0),
	('legal_cui', '46112959', 0),
	('legal_reg_com', 'J23/3075/2022', 0),
	('legal_caen', '6311 – Prelucrarea datelor, administrarea paginilor web și activități conexe', 0),
	('legal_address', 'Str. Sfânta Agnes, nr. 191, Vila 7, Popești-Leordeni, Ilfov, România', 0),
	('legal_email', 'iftimie.s@gmail.com', 0),
	('legal_phone', '0762 82 33 33', 0),
	('legal_last_updated', '01 Noiembrie 2026', 0),
	('app_environment', 'prod', 0),
	('review_cooldown_hours', '24', 0),
	('enable_physical_print_upsell', '0', 0),
	('review_email_delivery_mode', 'smart_hybrid', 0),
	('daily_digest_hour', '8', 0),
	('last_daily_digest_run_date', '', 0),
	('smtp_batch_limit_per_run', '30', 0),
	('smtp_delay_ms', '250', 0),
	('email_header_title', '', 0),
	('email_logo_url', '', 0),
	('email_accent_color', '#0F172A', 0),
	('email_footer_text', '© {{year}} {{app_name}} · Un serviciu {{provider_name}} · Toate drepturile rezervate.', 0),
	('email_reply_to', '', 0),
	('gdrive_backup_enabled', '0', 0),
	('gdrive_backup_db', '1', 0),
	('gdrive_backup_files', '1', 0),
	('gdrive_files_scope', 'uploads_and_config', 0),
	('gdrive_frequency', 'daily', 0),
	('gdrive_backup_hour', '3', 0),
	('gdrive_retention_count', '14', 0),
	('gdrive_keep_local', '0', 0),
	('gdrive_folder_name', 'ahoi-backups', 0),
	('gdrive_folder_id', '', 0),
	('gdrive_account_email', '', 0),
	('gdrive_use_mail_credentials', '0', 0),
	('gdrive_client_id', '', 0),
	('gdrive_client_secret', '', 1),
	('gdrive_refresh_token', '', 1),
	('gdrive_access_token', '', 1),
	('gdrive_token_expires', '0', 0),
	('gdrive_next_backup_at', '', 0),
	('gdrive_last_backup_at', '', 0),
	('gdrive_last_backup_status', '', 0),
	('trial_days', '30', 0),
	('trial_max_reviews', '50', 0),
	('trial_max_companies', '1', 0),
	('trial_max_alert_emails', '1', 0),
	('mail_driver', 'log', 0),
	('mail_from_email', 'no-reply@example.com', 0),
	('mail_from_name', 'RepuVio', 0),
	('smtp_host', '', 0),
	('smtp_port', '587', 0),
	('smtp_encryption', 'tls', 0),
	('smtp_username', '', 0),
	('smtp_password', '', 1),
	('google_email', '', 0),
	('google_client_id', '', 0),
	('google_client_secret', '', 1),
	('google_redirect_uri', '', 0),
	('google_refresh_token', '', 1),
	('google_access_token', '', 1),
	('google_token_expires', '0', 0),
	('google_app_password', '', 1),
	('bank_details', 'AHOI DIGITAL SRL\nCUI: 46112959 · Nr. Reg. Com.: J23/3075/2022\nIBAN: RO79 BTRL RONC RT0C G824 0201\nBanca: Banca Transilvania', 0);
