<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use WP_CLI;

/**
 * Resets the WordPress and WP-CLI stubs around each test.
 */
abstract class WpTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpState::reset();
		WP_CLI::reset();
	}

	protected function tearDown(): void {
		WpState::reset();
		WP_CLI::reset();
		parent::tearDown();
	}
}
