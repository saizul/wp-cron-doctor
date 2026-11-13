<?php
/**
 * POSIX shell quoting.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\SystemCron;

/**
 * Deterministic quoting for generated shell commands.
 */
final class Shell {

	/**
	 * Quotes a word for /bin/sh. Safe words are returned unchanged for readability.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function quote( string $word ): string {
		if ( '' === $word ) {
			return "''";
		}

		if ( preg_match( '#^[A-Za-z0-9_@%+=:,./-]+$#', $word ) ) {
			return $word;
		}

		return "'" . str_replace( "'", "'\\''", $word ) . "'";
	}

	/**
	 * Quotes the option value of `--name=value`.
	 *
	 * @param string $name  Option name without dashes.
	 * @param string $value Value.
	 * @return string
	 */
	public static function option( string $name, string $value ): string {
		return '--' . $name . '=' . self::quote( $value );
	}

	/**
	 * Escapes `%`, which cron turns into a newline in the command field.
	 *
	 * @param string $command Command.
	 * @return string
	 */
	public static function escape_crontab( string $command ): string {
		return str_replace( '%', '\\%', $command );
	}

	/**
	 * Escapes `%`, which systemd treats as a specifier in unit files.
	 *
	 * @param string $command Command.
	 * @return string
	 */
	public static function escape_systemd( string $command ): string {
		return str_replace( '%', '%%', $command );
	}
}
