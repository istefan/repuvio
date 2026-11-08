<?php
/**
 * 3-tier RBAC helpers: business owner accounts, operator accounts (customer support)
 * and the company-access scope that every tenant query applies.
 *
 * Roles:
 * - super_admin: platform administrator.
 * - user:        business owner (tenant); owns companies, plans and operators.
 * - operator:    created by an owner (users.parent_id); may only see the dashboard and
 *                handle feedback for the companies assigned in `operator_companies`.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Role labels.
 *
 * @return array
 */
function get_role_labels() {
	return [
		'super_admin' => 'Super Admin',
		'user'        => 'Business Owner',
		'operator'    => 'Operator',
	];
}

/**
 * Human label for a role.
 *
 * @param string $role Role.
 * @return string
 */
function get_role_label( $role ) {
	return get_role_labels()[ $role ] ?? $role;
}

/**
 * Whether a user row is an operator.
 *
 * @param array|null $user User row (defaults to the current user).
 * @return bool
 */
function is_operator( $user = null ) {
	$user = null === $user ? current_user() : $user;

	return is_array( $user ) && 'operator' === $user['role'];
}

/**
 * Tenant (business owner) ID of an account: operators map to their owner.
 *
 * @param array $user User row.
 * @return int
 */
function get_account_owner_id( array $user ) {
	return 'operator' === $user['role'] ? (int) $user['parent_id'] : (int) $user['id'];
}

/**
 * Tenant (business owner) row of an account, with subscription state refreshed.
 *
 * @param array $user User row.
 * @return array|null
 */
function get_account_owner( array $user ) {
	if ( 'operator' !== $user['role'] ) {
		return $user;
	}

	$owner = get_user_by_id( (int) $user['parent_id'] );

	if ( $owner && 'user' === $owner['role'] ) {
		refresh_subscription_status( $owner );
		roll_quota_cycle( $owner );

		return $owner;
	}

	return null;
}

/**
 * Company IDs assigned to an operator (only companies still owned by the operator's owner).
 *
 * @param int  $operator_id Operator ID.
 * @param bool $refresh     Bypass the per-request cache.
 * @return int[]
 */
function get_operator_company_ids( $operator_id, $refresh = false ) {
	static $cache = [];

	$operator_id = (int) $operator_id;

	if ( ! $refresh && isset( $cache[ $operator_id ] ) ) {
		return $cache[ $operator_id ];
	}

	$ids = db_all(
		'SELECT oc.company_id FROM operator_companies oc
		INNER JOIN users o ON o.id = oc.operator_id
		INNER JOIN companies c ON c.id = oc.company_id AND c.user_id = o.parent_id
		WHERE oc.operator_id = ?',
		[ $operator_id ]
	);

	$cache[ $operator_id ] = array_map( 'intval', array_column( $ids, 'company_id' ) );

	return $cache[ $operator_id ];
}

/**
 * Extra SQL restriction for the current operator (empty for owners, admins and CLI jobs).
 *
 * Usage: list( $sql, $params ) = company_restriction_sql( 'c' ); $where .= $sql;
 *
 * @param string $alias Companies table alias.
 * @return array{0:string,1:array}
 */
function company_restriction_sql( $alias = 'c' ) {
	if ( 'cli' === PHP_SAPI || empty( $_SESSION['user_id'] ) ) {
		return [ '', [] ];
	}

	$user = current_user();

	if ( ! $user || 'operator' !== $user['role'] ) {
		return [ '', [] ];
	}

	$ids = get_operator_company_ids( (int) $user['id'] );

	if ( empty( $ids ) ) {
		return [ ' AND 1 = 0', [] ];
	}

	$placeholders = [];
	$params       = [];

	foreach ( array_values( $ids ) as $index => $id ) {
		$placeholders[]          = ':rc' . $index;
		$params[ 'rc' . $index ] = (int) $id;
	}

	return [ ' AND ' . $alias . '.id IN (' . implode( ', ', $placeholders ) . ')', $params ];
}

/**
 * Companies the account can see: owners see all their companies, operators only assigned ones.
 *
 * @param array $user User row.
 * @return array
 */
function get_accessible_companies( array $user ) {
	if ( 'operator' !== $user['role'] ) {
		return get_user_companies( (int) $user['id'] );
	}

	$ids = get_operator_company_ids( (int) $user['id'] );

	return array_values(
		array_filter(
			get_user_companies( (int) $user['parent_id'] ),
			function ( $company ) use ( $ids ) {
				return in_array( (int) $company['id'], $ids, true );
			}
		)
	);
}

/**
 * Operators of a business owner, with the names of their assigned companies.
 *
 * @param int $owner_id Owner ID.
 * @return array
 */
function get_owner_operators( $owner_id ) {
	$operators = db_all(
		"SELECT u.id, u.name, u.email, u.status, u.must_change_password, u.last_login_at, u.created_at
		FROM users u WHERE u.role = 'operator' AND u.parent_id = ? ORDER BY u.name ASC",
		[ (int) $owner_id ]
	);

	foreach ( $operators as &$operator ) {
		$operator['company_ids'] = get_operator_company_ids( (int) $operator['id'], true );
		$operator['companies']   = db_all(
			'SELECT c.id, c.name FROM operator_companies oc INNER JOIN companies c ON c.id = oc.company_id
			WHERE oc.operator_id = ? AND c.user_id = ? ORDER BY c.name ASC',
			[ (int) $operator['id'], (int) $owner_id ]
		);
	}
	unset( $operator );

	return $operators;
}

/**
 * Loads an operator only if it belongs to the given owner.
 *
 * @param int $operator_id Operator ID.
 * @param int $owner_id    Owner ID.
 * @return array|null
 */
function get_owner_operator( $operator_id, $owner_id ) {
	if ( 0 >= (int) $operator_id ) {
		return null;
	}

	return db_row(
		"SELECT * FROM users WHERE id = ? AND role = 'operator' AND parent_id = ?",
		[ (int) $operator_id, (int) $owner_id ]
	);
}

/**
 * Replaces the company assignment of an operator. Only companies of the owner are accepted.
 *
 * @param int   $operator_id Operator ID.
 * @param int   $owner_id    Owner ID.
 * @param array $company_ids Requested company IDs.
 * @return int Number of assigned companies.
 */
function save_operator_companies( $operator_id, $owner_id, array $company_ids ) {
	$owned = array_map( 'intval', array_column( get_user_companies( (int) $owner_id ), 'id' ) );
	$ids   = array_values( array_intersect( $owned, array_map( 'intval', $company_ids ) ) );

	db_transaction(
		function () use ( $operator_id, $ids ) {
			db_query( 'DELETE FROM operator_companies WHERE operator_id = ?', [ (int) $operator_id ] );

			foreach ( $ids as $company_id ) {
				db_query( 'INSERT INTO operator_companies (operator_id, company_id) VALUES (?, ?)', [ (int) $operator_id, (int) $company_id ] );
			}
		}
	);

	get_operator_company_ids( (int) $operator_id, true );

	return count( $ids );
}

/**
 * Operator count per company of an owner.
 *
 * @param int $owner_id Owner ID.
 * @return array<int,int> company_id => operators.
 */
function get_company_operator_counts( $owner_id ) {
	$rows = db_all(
		"SELECT oc.company_id, COUNT(*) AS total FROM operator_companies oc
		INNER JOIN users u ON u.id = oc.operator_id AND u.role = 'operator' AND u.status = 'active'
		INNER JOIN companies c ON c.id = oc.company_id
		WHERE c.user_id = ? GROUP BY oc.company_id",
		[ (int) $owner_id ]
	);

	return array_map( 'intval', array_column( $rows, 'total', 'company_id' ) );
}
