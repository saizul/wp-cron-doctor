<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Report\Thresholds;

final class ThresholdsTest extends TestCase {

	public function test_defaults(): void {
		$t = Thresholds::from_options( array() );

		$this->assertSame( 600, $t->overdue_warning );
		$this->assertSame( 3600, $t->overdue_critical );
		$this->assertSame( 300, $t->frequent_interval );
		$this->assertSame( 262144, $t->option_size_warning );
		$this->assertSame( 1048576, $t->option_size_critical );
		$this->assertSame( 604800, $t->as_failed_window );
	}

	public function test_parses_options_and_ignores_unknown_keys(): void {
		$t = Thresholds::from_options(
			array(
				'overdue-warning'      => '15m',
				'overdue-critical'     => '2h',
				'option-size-warning'  => '512K',
				'option-size-critical' => '2M',
				'events-warning'       => '5000',
				'as-failed-window'     => '1d',
				'as-failed-critical'   => 10,
				'format'               => 'json',
			)
		);

		$this->assertSame( 900, $t->overdue_warning );
		$this->assertSame( 7200, $t->overdue_critical );
		$this->assertSame( 524288, $t->option_size_warning );
		$this->assertSame( 2097152, $t->option_size_critical );
		$this->assertSame( 5000, $t->events_warning );
		$this->assertSame( 86400, $t->as_failed_window );
		$this->assertSame( 10, $t->as_failed_critical );
	}

	public function test_option_names_cover_every_property(): void {
		$properties = array_keys( get_object_vars( new Thresholds() ) );
		$this->assertCount( count( $properties ), Thresholds::option_names() );
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_invalid_values( array $options, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		Thresholds::from_options( $options );
	}

	public function invalid(): array {
		return array(
			'flag without value' => array( array( 'frequent' => true ), '--frequent needs a value' ),
			'bad duration'       => array( array( 'stale-after' => 'soon' ), '--stale-after: Invalid duration' ),
			'bad size'           => array( array( 'option-size-warning' => 'big' ), '--option-size-warning: Invalid size' ),
			'bad int'            => array( array( 'events-warning' => '1.5' ), '--events-warning: Expected a whole number' ),
			'warning > critical' => array( array( 'overdue-warning' => '2h' ), '--overdue-warning must not be greater than --overdue-critical' ),
			'size order'         => array( array( 'option-size-warning' => '2M' ), '--option-size-warning must not be greater' ),
			'as order'           => array( array( 'as-failed-warning' => '500' ), '--as-failed-warning must not be greater' ),
			'zero failures'      => array( array( 'as-failed-warning' => '0' ), 'at least 1' ),
		);
	}
}
