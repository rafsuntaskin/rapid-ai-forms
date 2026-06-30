<?php
/**
 * Turns decoded submission rows into CSV or JSON for download.
 *
 * Pure (no DB / no globals) so it's easy to unit-test. The REST export route
 * fetches rows via Submission_Repository::export() and hands them here.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

defined( 'ABSPATH' ) || exit;

class Submission_Exporter {

	/**
	 * Fixed leading columns, in order. Keyed by output header => row accessor.
	 * `data` columns (one per form field) are appended after these.
	 */
	const META_COLUMNS = array(
		'ID'         => 'id',
		'Form ID'    => 'form_id',
		'Form'       => 'form_title',
		'Submitted (UTC)' => 'created_at',
		'IP address' => 'ip_address',
	);

	/**
	 * Build a CSV document (with header row) from submission rows.
	 *
	 * Field columns are the union of every `data` key across the rows, in
	 * first-seen order. When a label map is supplied (single-form export), the
	 * field's human label is used as the column header instead of the raw key.
	 *
	 * @param array $rows   Decoded rows (each: id, form_id, form_title, created_at, ip_address, data[]).
	 * @param array $labels Optional map of field name => label.
	 * @return string CSV text (UTF-8, CRLF rows, BOM-free).
	 */
	public function to_csv( array $rows, array $labels = array() ) {
		$field_keys = $this->collect_field_keys( $rows );

		$header = array_keys( self::META_COLUMNS );
		foreach ( $field_keys as $key ) {
			$header[] = isset( $labels[ $key ] ) && '' !== $labels[ $key ] ? $labels[ $key ] : $key;
		}

		$lines   = array();
		$lines[] = $this->csv_line( $header );
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( self::META_COLUMNS as $accessor ) {
				$cells[] = isset( $row[ $accessor ] ) ? (string) $row[ $accessor ] : '';
			}
			$data = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();
			foreach ( $field_keys as $key ) {
				$cells[] = $this->scalarize( $data[ $key ] ?? '' );
			}
			$lines[] = $this->csv_line( $cells );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Build a pretty-printed JSON array of submissions. Each entry keeps the
	 * structured `data` object plus the useful meta fields.
	 *
	 * @param array $rows Decoded rows.
	 * @return string JSON text.
	 */
	public function to_json( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'form_id'    => isset( $row['form_id'] ) ? (int) $row['form_id'] : 0,
				'form_title' => $row['form_title'] ?? '',
				'created_at' => $row['created_at'] ?? '',
				'ip_address' => $row['ip_address'] ?? '',
				'user_agent' => $row['user_agent'] ?? '',
				'data'       => isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array(),
			);
		}
		return (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** Union of all `data` keys across rows, in first-seen order. */
	private function collect_field_keys( array $rows ) {
		$keys = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['data'] ) || ! is_array( $row['data'] ) ) {
				continue;
			}
			foreach ( array_keys( $row['data'] ) as $key ) {
				$keys[ $key ] = true;
			}
		}
		return array_keys( $keys );
	}

	/** Flatten a value to a single CSV cell. Arrays join with ", ". */
	private function scalarize( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( $this, 'scalarize' ), $value ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * Quote one CSV record (RFC 4180). Always quotes so leading-`=`/`+`/`-`/`@`
	 * cells can't be read as formulas by spreadsheet apps (CSV-injection guard).
	 */
	private function csv_line( array $cells ) {
		$escaped = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			// Neutralize formula triggers in leading position.
			if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@' ), true ) ) {
				$cell = "'" . $cell;
			}
			$escaped[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $escaped );
	}
}
