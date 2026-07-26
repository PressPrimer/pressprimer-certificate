<?php
/**
 * Fake wpdb
 *
 * An in-memory stand-in for $wpdb supporting exactly the query shapes the
 * plugin's models and issuance service use. Unrecognized queries throw -
 * a test can never silently pass against an unsupported pattern. Real-DB
 * behavior is additionally verified live on the dev site per prompt.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

/**
 * Fake wpdb class
 *
 * @since 1.0.0
 */
class PPCert_Fake_WPDB {

	/**
	 * Table prefix, like the real thing.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Core meta table names, like the real thing.
	 *
	 * @var string
	 */
	public $usermeta = 'wp_usermeta';

	/**
	 * Core post meta table name.
	 *
	 * @var string
	 */
	public $postmeta = 'wp_postmeta';

	/**
	 * Last auto-increment id from insert().
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Test knob: fail this many upcoming insert() calls.
	 *
	 * @var int
	 */
	public $force_insert_failures = 0;

	/**
	 * Row storage: table => id => row (array).
	 *
	 * @var array
	 */
	private $tables = [];

	/**
	 * Auto-increment counters per table.
	 *
	 * @var array
	 */
	private $auto_increment = [];

	/**
	 * Seed a row directly (test setup).
	 *
	 * @param string $table Full table name.
	 * @param array  $row   Row data; id auto-assigned when absent.
	 * @return int Row id.
	 */
	public function seed_row( $table, array $row ) {
		if ( ! isset( $row['id'] ) ) {
			$this->auto_increment[ $table ] = ( isset( $this->auto_increment[ $table ] ) ? $this->auto_increment[ $table ] : 0 ) + 1;
			$row['id']                      = $this->auto_increment[ $table ];
		} else {
			$this->auto_increment[ $table ] = max(
				isset( $this->auto_increment[ $table ] ) ? $this->auto_increment[ $table ] : 0,
				(int) $row['id']
			);
		}

		$this->tables[ $table ][ $row['id'] ] = $row;

		return (int) $row['id'];
	}

	/**
	 * All rows of a table (test assertions).
	 *
	 * @param string $table Full table name.
	 * @return array[] Rows.
	 */
	public function rows( $table ) {
		return isset( $this->tables[ $table ] ) ? array_values( $this->tables[ $table ] ) : [];
	}

	/**
	 * Mutate a stored row directly (test setup, e.g. simulate an edit).
	 *
	 * @param string $table   Full table name.
	 * @param int    $id      Row id.
	 * @param array  $changes Column changes.
	 * @return void
	 */
	public function mutate_row( $table, $id, array $changes ) {
		if ( isset( $this->tables[ $table ][ $id ] ) ) {
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $changes );
		}
	}

	/**
	 * wpdb::insert().
	 *
	 * Enforces the credential_id unique key on the certificates table so
	 * collision-retry behavior is exercisable.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Column data.
	 * @param array  $format Value formats (unused).
	 * @return int|false Rows inserted, or false.
	 */
	public function insert( $table, $data, $format = [] ) {
		if ( $this->force_insert_failures > 0 ) {
			$this->force_insert_failures--;
			return false;
		}

		if ( isset( $data['credential_id'] ) ) {
			foreach ( $this->rows( $table ) as $row ) {
				if ( isset( $row['credential_id'] ) && $row['credential_id'] === $data['credential_id'] ) {
					return false; // Unique key violation.
				}
			}
		}

		$this->auto_increment[ $table ] = ( isset( $this->auto_increment[ $table ] ) ? $this->auto_increment[ $table ] : 0 ) + 1;
		$data['id']                     = $this->auto_increment[ $table ];
		$this->tables[ $table ][ $data['id'] ] = $data;
		$this->insert_id                = $data['id'];

		return 1;
	}

	/**
	 * wpdb::update() with a simple equality where.
	 *
	 * @param string $table        Table name.
	 * @param array  $data         Column changes.
	 * @param array  $where        Equality conditions.
	 * @param array  $format       Value formats (unused).
	 * @param array  $where_format Where formats (unused).
	 * @return int Rows updated.
	 */
	public function update( $table, $data, $where, $format = [], $where_format = [] ) {
		$count = 0;

		foreach ( $this->rows( $table ) as $row ) {
			$match = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || (string) $row[ $column ] !== (string) $value ) {
					$match = false;
					break;
				}
			}

			if ( $match ) {
				$this->tables[ $table ][ $row['id'] ] = array_merge( $row, $data );
				$count++;
			}
		}

		return $count;
	}

	/**
	 * wpdb::delete() with a simple equality where.
	 *
	 * @param string $table        Table name.
	 * @param array  $where        Equality conditions.
	 * @param array  $where_format Where formats (unused).
	 * @return int Rows deleted.
	 */
	public function delete( $table, $where, $where_format = [] ) {
		$count = 0;

		foreach ( $this->rows( $table ) as $row ) {
			$match = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || (string) $row[ $column ] !== (string) $value ) {
					$match = false;
					break;
				}
			}

			if ( $match ) {
				unset( $this->tables[ $table ][ $row['id'] ] );
				$count++;
			}
		}

		return $count;
	}

	/**
	 * wpdb::prepare() - encodes the query and args for the router.
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string Encoded routing payload.
	 */
	public function prepare( $query, ...$args ) {
		return wp_json_encode(
			[
				'q'    => $query,
				'args' => $args,
			]
		);
	}

	/**
	 * wpdb::esc_like() - escape LIKE wildcards, like the real thing.
	 *
	 * @param string $text Raw text.
	 * @return string Escaped text.
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * wpdb::get_var() - first column of the first matching row.
	 *
	 * @param string $prepared Encoded payload from prepare().
	 * @return string|null Value or null.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	public function get_var( $prepared ) {
		$matches = $this->run_query( $prepared );

		if ( empty( $matches ) ) {
			return null;
		}

		$first = array_values( (array) $matches[0] );

		return isset( $first[0] ) ? (string) $first[0] : null;
	}

	/**
	 * wpdb::get_row() - routes the known query shapes.
	 *
	 * @param string $prepared Encoded payload from prepare().
	 * @return object|null Row object or null.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	public function get_row( $prepared ) {
		$matches = $this->run_query( $prepared );

		return empty( $matches ) ? null : (object) $matches[0];
	}

	/**
	 * wpdb::get_results() - routes the known query shapes.
	 *
	 * @param string $prepared Encoded payload from prepare().
	 * @return object[] Row objects.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	public function get_results( $prepared ) {
		return array_map(
			static function ( $row ) {
				return (object) $row;
			},
			$this->run_query( $prepared )
		);
	}

	/**
	 * wpdb::get_col() - first column of each matching row.
	 *
	 * @param string $prepared Encoded payload from prepare().
	 * @return array Column values.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	public function get_col( $prepared ) {
		return array_map(
			static function ( $row ) {
				$values = array_values( (array) $row );
				return isset( $values[0] ) ? $values[0] : null;
			},
			$this->run_query( $prepared )
		);
	}

	/**
	 * wpdb::query() - supports the event pruner's batched DELETE.
	 *
	 * @param string $prepared Encoded payload from prepare().
	 * @return int Rows affected.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	public function query( $prepared ) {
		$payload = json_decode( (string) $prepared, true );

		if ( ! is_array( $payload ) || ! isset( $payload['q'], $payload['args'] ) ) {
			throw new RuntimeException( 'PPCert_Fake_WPDB: unprepared query: ' . (string) $prepared );
		}

		$query = $payload['q'];
		$args  = $payload['args'];
		$table = isset( $args[0] ) ? (string) $args[0] : '';

		// Event pruner: DELETE prunable rows older than the cutoff.
		if ( false !== strpos( $query, "DELETE FROM %i WHERE event_type IN ( 'verified', 'viewed' ) AND created_at < %s LIMIT %d" ) ) {
			$cutoff  = (string) $args[1];
			$limit   = (int) $args[2];
			$rows    = $this->rows( $table );
			$deleted = 0;

			foreach ( $rows as $row ) {
				if ( $deleted >= $limit ) {
					break;
				}

				if ( in_array( $row['event_type'], [ 'verified', 'viewed' ], true )
					&& isset( $row['created_at'] ) && $row['created_at'] < $cutoff ) {
					unset( $this->tables[ $table ][ $row['id'] ] );
					++$deleted;
				}
			}

			return $deleted;
		}

		throw new RuntimeException( 'PPCert_Fake_WPDB: unsupported query() shape: ' . $query );
	}

	/**
	 * Read queries executed (test instrumentation, e.g. asserting the
	 * checksum gate reaches the endpoint before any DB work).
	 *
	 * @var int
	 */
	public $read_queries = 0;

	/**
	 * Route a prepared query to matching rows.
	 *
	 * @param string $prepared Encoded payload.
	 * @return array[] Matching rows in id order.
	 * @throws RuntimeException On an unsupported query shape.
	 */
	private function run_query( $prepared ) {
		$this->read_queries++;
		$payload = json_decode( (string) $prepared, true );

		if ( ! is_array( $payload ) || ! isset( $payload['q'], $payload['args'] ) ) {
			throw new RuntimeException( 'PPCert_Fake_WPDB: unprepared query: ' . (string) $prepared );
		}

		$query = $payload['q'];
		$args  = $payload['args'];
		$table = isset( $args[0] ) ? (string) $args[0] : '';
		$rows  = $this->rows( $table );

		// Template::get - by id, excluding soft-deleted.
		if ( false !== strpos( $query, 'WHERE id = %d AND deleted_at IS NULL' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['id'] === (int) $args[1] && empty( $row['deleted_at'] );
				}
			);
		}

		// Certificate/Trigger::get - by id.
		if ( preg_match( '/WHERE id = %d\s*$/', $query ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['id'] === (int) $args[1];
				}
			);
		}

		// Certificate::get_for_verification - joined single lookup.
		if ( false !== strpos( $query, 'LEFT JOIN' ) && false !== strpos( $query, 'WHERE c.credential_id = %s' ) ) {
			$templates = $this->rows( (string) $args[1] );
			$matches   = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return isset( $row['credential_id'] ) && $row['credential_id'] === $args[2];
				}
			);

			return array_map(
				static function ( $row ) use ( $templates ) {
					$row['template_title'] = null;
					foreach ( $templates as $template ) {
						if ( (int) $template['id'] === (int) $row['template_id'] ) {
							$row['template_title'] = isset( $template['title'] ) ? $template['title'] : null;
							break;
						}
					}
					return $row;
				},
				$matches
			);
		}

		// Trigger::get_for_templates - batch fetch by template id CSV.
		if ( false !== strpos( $query, 'WHERE FIND_IN_SET( template_id, %s )' ) ) {
			$ids = array_map( 'intval', explode( ',', (string) $args[1] ) );

			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $ids ) {
					return in_array( (int) $row['template_id'], $ids, true );
				}
			);
		}

		// Template::query - the templates admin list (sentinel filters +
		// EXISTS trigger-type subquery). args: [templates_table, status x2,
		// search x2, types_csv, triggers_table, types_csv, (limit, offset)].
		if ( false !== strpos( $query, 'FROM %i t WHERE t.deleted_at IS NULL' ) ) {
			$status    = (string) $args[1];
			$needle    = $this->like_to_substring( (string) $args[3] );
			$types_csv = (string) $args[5];
			$types     = '' !== $types_csv ? explode( ',', $types_csv ) : [];
			$trigger_rows = $this->rows( (string) $args[6] );

			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $status, $needle, $types, $trigger_rows ) {
					if ( ! empty( $row['deleted_at'] ) ) {
						return false;
					}

					if ( '' !== $status && $row['status'] !== $status ) {
						return false;
					}

					if ( '' !== $needle && false === stripos( isset( $row['title'] ) ? (string) $row['title'] : '', $needle ) ) {
						return false;
					}

					if ( ! empty( $types ) ) {
						$has_type = false;

						foreach ( $trigger_rows as $trigger ) {
							if ( (int) $trigger['template_id'] === (int) $row['id']
								&& in_array( (string) $trigger['trigger_type'], $types, true ) ) {
								$has_type = true;
								break;
							}
						}

						if ( ! $has_type ) {
							return false;
						}
					}

					return true;
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					return strcmp( isset( $b['updated_at'] ) ? $b['updated_at'] : '', isset( $a['updated_at'] ) ? $a['updated_at'] : '' );
				}
			);

			if ( false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
				return [ [ 'count' => count( $matches ) ] ];
			}

			return array_slice( $matches, (int) $args[9], (int) $args[8] );
		}

		// Certificate::get_list_for_recipient - the My Certificates
		// front-end list. Args: (table, template_table, recipient_id,
		// status, status, now, status, now, limit, offset). Mirrors the
		// SQL's status semantics and both hardcoded sort branches.
		if ( false !== strpos( $query, "( %s = 'all' OR ( %s = 'valid'" ) && false !== strpos( $query, 'LEFT JOIN' ) ) {
			$templates = $this->rows( (string) $args[1] );
			$status    = (string) $args[3];
			$now       = (string) $args[5];

			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args, $status, $now ) {
					if ( (int) $row['recipient_id'] !== (int) $args[2] ) {
						return false;
					}

					$expires = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '';

					if ( 'valid' === $status ) {
						return 'issued' === (string) $row['status'] && ( '' === $expires || $expires > $now );
					}

					if ( 'expired' === $status ) {
						return 'issued' === (string) $row['status'] && '' !== $expires && $expires <= $now;
					}

					return true;
				}
			);

			if ( false !== strpos( $query, 'ORDER BY t.title IS NULL, t.title ASC' ) ) {
				$title_of = static function ( $row ) use ( $templates ) {
					foreach ( $templates as $template ) {
						if ( (int) $template['id'] === (int) $row['template_id'] ) {
							return isset( $template['title'] ) ? (string) $template['title'] : null;
						}
					}
					return null;
				};

				usort(
					$matches,
					static function ( $a, $b ) use ( $title_of ) {
						$a_title = $title_of( $a );
						$b_title = $title_of( $b );

						if ( ( null === $a_title ) !== ( null === $b_title ) ) {
							return null === $a_title ? 1 : -1;
						}

						$by_title = strcmp( (string) $a_title, (string) $b_title );
						return 0 !== $by_title ? $by_title : ( (int) $b['id'] <=> (int) $a['id'] );
					}
				);
			} elseif ( false !== strpos( $query, 'ORDER BY c.expires_at IS NULL, c.expires_at ASC' ) ) {
				usort(
					$matches,
					static function ( $a, $b ) {
						$a_exp = isset( $a['expires_at'] ) ? (string) $a['expires_at'] : '';
						$b_exp = isset( $b['expires_at'] ) ? (string) $b['expires_at'] : '';

						if ( ( '' === $a_exp ) !== ( '' === $b_exp ) ) {
							return '' === $a_exp ? 1 : -1;
						}

						$by_exp = strcmp( $a_exp, $b_exp );
						return 0 !== $by_exp ? $by_exp : ( (int) $b['id'] <=> (int) $a['id'] );
					}
				);
			} else {
				usort(
					$matches,
					static function ( $a, $b ) {
						$by_date = strcmp( (string) $b['issued_at'], (string) $a['issued_at'] );
						return 0 !== $by_date ? $by_date : ( (int) $b['id'] <=> (int) $a['id'] );
					}
				);
			}

			$matches = array_slice( $matches, (int) $args[9], (int) $args[8] );

			return array_map(
				static function ( $row ) use ( $templates ) {
					$row['template_title'] = null;
					foreach ( $templates as $template ) {
						if ( (int) $template['id'] === (int) $row['template_id'] ) {
							$row['template_title'] = isset( $template['title'] ) ? $template['title'] : null;
							break;
						}
					}
					return $row;
				},
				$matches
			);
		}

		// Certificate::count_list_for_recipient - same status semantics,
		// count only. Args: (table, recipient_id, status, status, now,
		// status, now).
		if ( false !== strpos( $query, "( %s = 'all' OR ( %s = 'valid'" ) && false !== strpos( $query, 'SELECT COUNT(*)' ) ) {
			$status = (string) $args[2];
			$now    = (string) $args[4];

			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args, $status, $now ) {
					if ( (int) $row['recipient_id'] !== (int) $args[1] ) {
						return false;
					}

					$expires = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '';

					if ( 'valid' === $status ) {
						return 'issued' === (string) $row['status'] && ( '' === $expires || $expires > $now );
					}

					if ( 'expired' === $status ) {
						return 'issued' === (string) $row['status'] && '' !== $expires && $expires <= $now;
					}

					return true;
				}
			);

			return [ [ 'count' => count( $matches ) ] ];
		}

		// Certificate::get_recent_for_recipient - newest first for the
		// user profile.
		if ( false !== strpos( $query, 'WHERE c.recipient_id = %d ORDER BY c.issued_at DESC, c.id DESC LIMIT %d OFFSET %d' ) ) {
			$templates = $this->rows( (string) $args[1] );
			$matches   = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['recipient_id'] === (int) $args[2];
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					$by_date = strcmp( (string) $b['issued_at'], (string) $a['issued_at'] );
					return 0 !== $by_date ? $by_date : ( (int) $b['id'] <=> (int) $a['id'] );
				}
			);

			$matches = array_slice( $matches, (int) $args[4], (int) $args[3] );

			return array_map(
				static function ( $row ) use ( $templates ) {
					$row['template_title'] = null;
					foreach ( $templates as $template ) {
						if ( (int) $template['id'] === (int) $row['template_id'] ) {
							$row['template_title'] = isset( $template['title'] ) ? $template['title'] : null;
							break;
						}
					}
					return $row;
				},
				$matches
			);
		}

		// Certificate::count_for_recipient.
		if ( false !== strpos( $query, 'SELECT COUNT(*) FROM %i WHERE recipient_id = %d' ) ) {
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['recipient_id'] === (int) $args[1];
				}
			);

			return [ [ 'count' => count( $matches ) ] ];
		}

		// Certificate::get_batch_for_recipient - the privacy batch query.
		if ( false !== strpos( $query, 'WHERE c.recipient_id = %d ORDER BY c.id ASC LIMIT %d OFFSET %d' ) ) {
			$templates = $this->rows( (string) $args[1] );
			$matches   = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['recipient_id'] === (int) $args[2];
				}
			);

			$matches = array_slice( $matches, (int) $args[4], (int) $args[3] );

			return array_map(
				static function ( $row ) use ( $templates ) {
					$row['template_title'] = null;
					foreach ( $templates as $template ) {
						if ( (int) $template['id'] === (int) $row['template_id'] ) {
							$row['template_title'] = isset( $template['title'] ) ? $template['title'] : null;
							break;
						}
					}
					return $row;
				},
				$matches
			);
		}

		// Privacy::user_credits - the credit ledger with type names.
		if ( false !== strpos( $query, 'WHERE cr.user_id = %d' ) ) {
			$types   = $this->rows( (string) $args[1] );
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['user_id'] === (int) $args[2];
				}
			);

			return array_map(
				static function ( $row ) use ( $types ) {
					$row['credit_type_name'] = null;
					foreach ( $types as $type ) {
						if ( (int) $type['id'] === (int) $row['credit_type_id'] ) {
							$row['credit_type_name'] = isset( $type['name'] ) ? $type['name'] : null;
							break;
						}
					}
					return $row;
				},
				$matches
			);
		}

		// Certificate::get_by_credential_id.
		if ( false !== strpos( $query, 'WHERE credential_id = %s' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return isset( $row['credential_id'] ) && $row['credential_id'] === $args[1];
				}
			);
		}

		// Certificate::find_duplicate - null source_ref variant.
		if ( false !== strpos( $query, 'WHERE recipient_id = %d AND template_id = %d' ) && false !== strpos( $query, 'AND source_ref IS NULL' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['recipient_id'] === (int) $args[1]
						&& (int) $row['template_id'] === (int) $args[2]
						&& $row['source_type'] === $args[3]
						&& ( ! isset( $row['source_ref'] ) || null === $row['source_ref'] )
						&& 'revoked' !== $row['status'];
				}
			);
		}

		// Certificate::find_duplicate - source_ref variant.
		if ( false !== strpos( $query, 'WHERE recipient_id = %d AND template_id = %d' ) && false !== strpos( $query, 'AND source_ref = %s' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['recipient_id'] === (int) $args[1]
						&& (int) $row['template_id'] === (int) $args[2]
						&& $row['source_type'] === $args[3]
						&& isset( $row['source_ref'] ) && $row['source_ref'] === $args[4]
						&& 'revoked' !== $row['status'];
				}
			);
		}

		// Template::get_all.
		if ( false !== strpos( $query, 'WHERE deleted_at IS NULL ORDER BY updated_at DESC' ) ) {
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) {
					return empty( $row['deleted_at'] );
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					return strcmp( isset( $b['updated_at'] ) ? $b['updated_at'] : '', isset( $a['updated_at'] ) ? $a['updated_at'] : '' );
				}
			);

			return $matches;
		}

		// Trigger::get_for_template.
		if ( false !== strpos( $query, 'WHERE template_id = %d ORDER BY id ASC' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return (int) $row['template_id'] === (int) $args[1];
				}
			);
		}

		// Trigger::find_active.
		if ( false !== strpos( $query, 'WHERE trigger_type = %s AND source_ref = %s AND is_active = 1' ) ) {
			return $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return $row['trigger_type'] === $args[1]
						&& isset( $row['source_ref'] ) && $row['source_ref'] === $args[2]
						&& 1 === (int) $row['is_active'];
				}
			);
		}

		// Certificates admin list (fixed-shape query): sentinel-driven
		// filters + FIND_IN_SET recipient search. args: [table,
		// template_id x2, status x2, source_type x2, search,
		// credential_like, recipient_csv, (per_page, offset)].
		if ( false !== strpos( $query, "OR FIND_IN_SET( recipient_id, %s )" ) ) {
			$template_id   = (int) $args[1];
			$status        = (string) $args[3];
			$source_type   = (string) $args[5];
			$search        = (string) $args[7];
			$needle        = $this->like_to_substring( (string) $args[8] );
			$recipient_ids = array_filter( array_map( 'intval', explode( ',', (string) $args[9] ) ) );

			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $template_id, $status, $source_type, $search, $needle, $recipient_ids ) {
					if ( $template_id > 0 && (int) $row['template_id'] !== $template_id ) {
						return false;
					}

					if ( '' !== $status && (string) $row['status'] !== $status ) {
						return false;
					}

					if ( '' !== $source_type && (string) $row['source_type'] !== $source_type ) {
						return false;
					}

					if ( '' !== $search ) {
						$credential_hit = '' !== $needle && false !== stripos( (string) $row['credential_id'], $needle );
						$recipient_hit  = in_array( (int) $row['recipient_id'], $recipient_ids, true );

						if ( ! $credential_hit && ! $recipient_hit ) {
							return false;
						}
					}

					return true;
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					$by_date = strcmp( (string) $b['issued_at'], (string) $a['issued_at'] );

					return 0 !== $by_date ? $by_date : ( (int) $b['id'] <=> (int) $a['id'] );
				}
			);

			if ( 0 === strpos( $query, 'SELECT COUNT(*)' ) ) {
				return [ [ 'total' => count( $matches ) ] ];
			}

			$per_page = (int) $args[10];
			$offset   = (int) $args[11];

			return array_slice( $matches, $offset, $per_page );
		}

		// LearnPress quiz cascade: quiz item ids of a course's sections.
		// Test convenience: seed wp_learnpress_section_items rows carrying
		// section_course_id directly (the real query joins the sections
		// table; the double flattens the join).
		if ( false !== strpos( $query, "AND si.item_type = 'lp_quiz'" ) ) {
			$course_id = (int) $args[2];

			return array_map(
				static function ( $row ) {
					return [ 'item_id' => $row['item_id'] ];
				},
				$this->filter_rows(
					$rows,
					static function ( $row ) use ( $course_id ) {
						return isset( $row['section_course_id'], $row['item_id'] )
							&& (int) $row['section_course_id'] === $course_id
							&& ( ! isset( $row['item_type'] ) || 'lp_quiz' === $row['item_type'] );
					}
				)
			);
		}

		// PPQ adapter sources: published quizzes by title.
		if ( false !== strpos( $query, "WHERE status = 'published' AND title LIKE %s" ) ) {
			$needle  = $this->like_to_substring( (string) $args[1] );
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $needle ) {
					if ( ! isset( $row['status'] ) || 'published' !== $row['status'] ) {
						return false;
					}

					return '' === $needle
						|| false !== stripos( (string) $row['title'], $needle );
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					return strcmp( (string) $a['title'], (string) $b['title'] );
				}
			);

			return array_map(
				static function ( $row ) {
					return [
						'id'    => $row['id'],
						'title' => $row['title'],
					];
				},
				array_slice( $matches, 0, 50 )
			);
		}

		// Dashboard: count events of one type since a cutoff.
		if ( false !== strpos( $query, 'WHERE event_type = %s AND created_at >= %s' ) ) {
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return isset( $row['event_type'] )
						&& (string) $row['event_type'] === (string) $args[1]
						&& isset( $row['created_at'] ) && (string) $row['created_at'] >= (string) $args[2];
				}
			);

			return [ [ 'count' => count( $matches ) ] ];
		}

		// Dashboard: daily issuance counts since a cutoff.
		if ( false !== strpos( $query, 'SELECT DATE( issued_at ) AS day' ) ) {
			$counts = [];

			foreach ( $rows as $row ) {
				if ( ! isset( $row['issued_at'] ) || (string) $row['issued_at'] < (string) $args[1] ) {
					continue;
				}

				$day = substr( (string) $row['issued_at'], 0, 10 );

				$counts[ $day ] = ( isset( $counts[ $day ] ) ? $counts[ $day ] : 0 ) + 1;
			}

			ksort( $counts );

			$result = [];

			foreach ( $counts as $day => $total ) {
				$result[] = [
					'day'   => $day,
					'total' => $total,
				];
			}

			return $result;
		}

		// Dashboard: templates ranked by certificates issued.
		if ( false !== strpos( $query, 'GROUP BY c.template_id, t.title' ) ) {
			$templates = $this->rows( (string) $args[1] );
			$totals    = [];

			foreach ( $rows as $row ) {
				$template_id = (int) $row['template_id'];

				$totals[ $template_id ] = ( isset( $totals[ $template_id ] ) ? $totals[ $template_id ] : 0 ) + 1;
			}

			$result = [];

			foreach ( $totals as $template_id => $total ) {
				$title = null;

				foreach ( $templates as $template ) {
					if ( (int) $template['id'] === $template_id ) {
						$title = isset( $template['title'] ) ? $template['title'] : null;
						break;
					}
				}

				$result[] = [
					'template_id' => $template_id,
					'title'       => $title,
					'total'       => $total,
				];
			}

			usort(
				$result,
				static function ( $a, $b ) {
					$by_total = $b['total'] <=> $a['total'];

					return 0 !== $by_total ? $by_total : ( $a['template_id'] <=> $b['template_id'] );
				}
			);

			return array_slice( $result, 0, (int) $args[2] );
		}

		// Dashboard: certificates issued since a cutoff.
		if ( false !== strpos( $query, 'SELECT COUNT(*) FROM %i WHERE issued_at >= %s' ) ) {
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return isset( $row['issued_at'] ) && (string) $row['issued_at'] >= (string) $args[1];
				}
			);

			return [ [ 'count' => count( $matches ) ] ];
		}

		// Dashboard: total certificates (exact match - the bare COUNT
		// must never swallow more specific COUNT shapes).
		if ( 'SELECT COUNT(*) FROM %i' === $query ) {
			return [ [ 'count' => count( $rows ) ] ];
		}

		// Merge-fields picker: distinct user meta keys.
		if ( false !== strpos( $query, 'SELECT DISTINCT meta_key FROM %i' ) ) {
			$needle = $this->like_to_substring( (string) $args[1] );
			$keys   = [];

			foreach ( $rows as $row ) {
				$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

				if ( '' === $key || '_' === $key[0] ) {
					continue;
				}

				if ( '' !== $needle && false === strpos( $key, $needle ) ) {
					continue;
				}

				$keys[ $key ] = true;
			}

			$keys = array_keys( $keys );
			sort( $keys );
			$keys = array_slice( $keys, 0, (int) $args[3] );

			return array_map(
				static function ( $key ) {
					return [ 'meta_key' => $key ];
				},
				$keys
			);
		}

		// Merge-fields picker: current-user sample value. Projected to the
		// selected column so get_var() reads meta_value, not the row id.
		if ( false !== strpos( $query, 'WHERE meta_key = %s AND user_id = %d' ) ) {
			$matches = array_slice(
				$this->filter_rows(
					$rows,
					static function ( $row ) use ( $args ) {
						return isset( $row['meta_key'], $row['user_id'] )
							&& $row['meta_key'] === $args[1]
							&& (int) $row['user_id'] === (int) $args[2]
							&& '' !== (string) $row['meta_value'];
					}
				),
				0,
				1
			);

			return $this->project_column( $matches, 'meta_value' );
		}

		// Merge-fields picker: most-recent-user sample value.
		if ( false !== strpos( $query, "WHERE meta_key = %s AND meta_value != '' ORDER BY user_id DESC" ) ) {
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args ) {
					return isset( $row['meta_key'] )
						&& $row['meta_key'] === $args[1]
						&& '' !== (string) $row['meta_value'];
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					return (int) $b['user_id'] <=> (int) $a['user_id'];
				}
			);

			return $this->project_column( array_slice( $matches, 0, 1 ), 'meta_value' );
		}

		// Merge-fields picker: one post's meta keys + values.
		if ( false !== strpos( $query, 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d' ) ) {
			$needle  = $this->like_to_substring( (string) $args[2] );
			$matches = $this->filter_rows(
				$rows,
				static function ( $row ) use ( $args, $needle ) {
					$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

					if ( (int) $row['post_id'] !== (int) $args[1] || '' === $key || '_' === $key[0] ) {
						return false;
					}

					return '' === $needle || false !== strpos( $key, $needle );
				}
			);

			usort(
				$matches,
				static function ( $a, $b ) {
					return strcmp( (string) $a['meta_key'], (string) $b['meta_key'] );
				}
			);

			return $matches;
		}

		throw new RuntimeException( 'PPCert_Fake_WPDB: unsupported query shape: ' . $query );
	}

	/**
	 * Reduce a SQL LIKE pattern ('%needle%', esc_like-escaped) to its
	 * substring needle.
	 *
	 * @param string $like LIKE pattern.
	 * @return string Needle.
	 */
	private function like_to_substring( $like ) {
		$needle = trim( $like, '%' );

		return str_replace( [ '\\_', '\\%', '\\\\' ], [ '_', '%', '\\' ], $needle );
	}

	/**
	 * Project rows down to one column (mirrors a SELECT column list).
	 *
	 * @param array[] $rows   Full rows.
	 * @param string  $column Column to keep.
	 * @return array[] Projected rows.
	 */
	private function project_column( $rows, $column ) {
		return array_map(
			static function ( $row ) use ( $column ) {
				return [ $column => isset( $row[ $column ] ) ? $row[ $column ] : null ];
			},
			$rows
		);
	}

	/**
	 * Filter rows and reindex by id order.
	 *
	 * @param array[]  $rows      Candidate rows.
	 * @param callable $predicate Match predicate.
	 * @return array[] Matching rows.
	 */
	private function filter_rows( $rows, $predicate ) {
		$matches = array_values( array_filter( $rows, $predicate ) );

		usort(
			$matches,
			static function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);

		return $matches;
	}
}
