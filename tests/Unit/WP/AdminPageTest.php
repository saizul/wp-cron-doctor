<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use RuntimeException;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;
use Saizul\CronDoctor\Tests\Stubs\FakeWpdb;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\ActionSchedulerSource;
use Saizul\CronDoctor\WP\AdminPage;
use Saizul\CronDoctor\WP\SnapshotFactory;

final class AdminPageTest extends WpTestCase {

	private function page(): AdminPage {
		return new AdminPage(
			new SnapshotFactory(
				null,
				static function (): ActionSchedulerSource {
					return new ActionSchedulerSource( new FakeWpdb() );
				}
			)
		);
	}

	private function render(): string {
		ob_start();
		try {
			$this->page()->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_registers_tools_page_with_filterable_capability(): void {
		add_filter( 'wp_cron_doctor_capability', static fn() => 'manage_woocommerce' );

		$this->page()->register();

		$this->assertSame( 'wp-cron-doctor', WpState::$menu_pages[0]['slug'] );
		$this->assertSame( 'manage_woocommerce', WpState::$menu_pages[0]['capability'] );
	}

	public function test_denies_users_without_capability(): void {
		WpState::$can = false;

		$this->expectException( RuntimeException::class );
		$this->render();
	}

	public function test_renders_escaped_report_without_loopback_by_default(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( time() - 7200, '<script>alert(1)</script>' )->build();

		$html = $this->render();

		$this->assertStringContainsString( '<h1>Cron Doctor</h1>', $html );
		$this->assertStringContainsString( 'CRITICAL', $html );
		$this->assertStringContainsString( '<code>overdue</code>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( 'Include the loopback test', $html );
		$this->assertStringContainsString( 'curl -fsS', $html );
		$this->assertSame( array(), WpState::$requests );
	}

	public function test_loopback_runs_only_with_valid_nonce(): void {
		$_GET = array(
			'loopback' => '1',
			'_wpnonce' => 'forged',
		);
		$this->render();
		$this->assertSame( array(), WpState::$requests );

		$_GET['_wpnonce'] = 'nonce-' . AdminPage::NONCE_ACTION;
		$html             = $this->render();
		$this->assertCount( 1, WpState::$requests );
		$this->assertStringContainsString( 'Run the loopback test again', $html );
	}
}
