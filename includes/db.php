<?php
/**
 * PDO database wrapper (real prepared statements only).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Returns the shared PDO connection.
 *
 * @return PDO
 */
function db() {
	static $pdo = null;

	if ( null !== $pdo ) {
		return $pdo;
	}

	$dsn = sprintf(
		'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
		DB_HOST,
		(int) DB_PORT,
		DB_NAME
	);

	$pdo = new PDO(
		$dsn,
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_STRINGIFY_FETCHES  => false,
		]
	);

	// Keep MySQL NOW() aligned with the PHP timezone.
	$offset = ( new DateTime( 'now' ) )->format( 'P' );
	$stmt   = $pdo->prepare( "SET time_zone = ?, sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" );
	$stmt->execute( [ $offset ] );

	return $pdo;
}

/**
 * Prepares and executes a statement.
 *
 * @param string $sql    SQL with placeholders.
 * @param array  $params Bound values.
 * @return PDOStatement
 */
function db_query( $sql, array $params = [] ) {
	$stmt = db()->prepare( $sql );

	foreach ( $params as $key => $value ) {
		$param_name = is_int( $key ) ? $key + 1 : ':' . ltrim( $key, ':' );

		if ( is_int( $value ) ) {
			$type = PDO::PARAM_INT;
		} elseif ( is_bool( $value ) ) {
			$type  = PDO::PARAM_INT;
			$value = $value ? 1 : 0;
		} elseif ( null === $value ) {
			$type = PDO::PARAM_NULL;
		} else {
			$type = PDO::PARAM_STR;
		}

		$stmt->bindValue( $param_name, $value, $type );
	}

	$stmt->execute();

	return $stmt;
}

/**
 * Returns a single row or null.
 *
 * @param string $sql    SQL.
 * @param array  $params Params.
 * @return array|null
 */
function db_row( $sql, array $params = [] ) {
	$row = db_query( $sql, $params )->fetch();

	return false === $row ? null : $row;
}

/**
 * Returns all rows.
 *
 * @param string $sql    SQL.
 * @param array  $params Params.
 * @return array
 */
function db_all( $sql, array $params = [] ) {
	return db_query( $sql, $params )->fetchAll();
}

/**
 * Returns the first column of the first row or null.
 *
 * @param string $sql    SQL.
 * @param array  $params Params.
 * @return mixed
 */
function db_value( $sql, array $params = [] ) {
	$value = db_query( $sql, $params )->fetchColumn();

	return false === $value ? null : $value;
}

/**
 * Last inserted auto-increment ID.
 *
 * @return int
 */
function db_insert_id() {
	return (int) db()->lastInsertId();
}

/**
 * Runs a callback inside a transaction.
 *
 * @param callable $callback Work to do.
 * @return mixed Callback result.
 * @throws Throwable On failure (after rollback).
 */
function db_transaction( callable $callback ) {
	$pdo = db();
	$pdo->beginTransaction();

	try {
		$result = $callback( $pdo );
		$pdo->commit();

		return $result;
	} catch ( Throwable $e ) {
		if ( $pdo->inTransaction() ) {
			$pdo->rollBack();
		}
		throw $e;
	}
}
