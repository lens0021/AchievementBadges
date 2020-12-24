<?php

namespace MediaWiki\Extension\AchievementBadges\Tests\Integration;

use Language;
use LogPage;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Extension\AchievementBadges\HookHandler\Main;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use MWException;
use User;

/**
 * @group AchievementBadges
 * @group Database
 *
 * @covers \MediaWiki\Extension\AchievementBadges\Achievement
 */
class AchievementTest extends MediaWikiIntegrationTestCase {

	/** Config $config */
	private $config;

	/** @inheritDoc */
	protected function setUp(): void {
		parent::setUp();
		Main::initExtension();
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::isAchievementBadgesAvailable
	 */
	public function testIsAchievementBadgesAvailable() {
		$this->setMwGlobals( 'wg' . Constants::CONFIG_KEY_DISABLED_ACHIEVEMENTS, [
			Constants::ACHV_KEY_SIGN_UP, Constants::ACHV_KEY_ENABLE_ACHIEVEMENT_BADGES ] );

		$systemUser = User::newSystemUser( __METHOD__ );
		$anon = new User;
		$user = $this->getTestUser()->getUser();
		$this->assertFalse( Achievement::isAchievementBadgesAvailable( $systemUser ),
			'A system user cannot use AB' );

		$this->setMwGlobals( 'wg' . Constants::CONFIG_KEY_ENABLE_BETA_FEATURE, false );
		$this->assertTrue( Achievement::isAchievementBadgesAvailable( $user ),
			'Every user can use AB where wiki uses AB as a default' );
		$this->assertTrue( Achievement::isAchievementBadgesAvailable( $anon ),
			'A anonymous user can use AB where wiki uses AB as a default' );

		$this->setMwGlobals( 'wg' . Constants::CONFIG_KEY_ENABLE_BETA_FEATURE, true );
		$this->assertFalse( Achievement::isAchievementBadgesAvailable( $user ),
			'A user which do not enable AB cannot use AB where wiki uses AB as a beta feature' );
		$this->assertFalse( Achievement::isAchievementBadgesAvailable( $anon ),
			'A anonymous user cannot use AB where wiki uses AB as a beta feature' );

		$user = $this->getMutableTestUser()->getUser();
		$user->setOption( Constants::PREF_KEY_ACHIEVEMENT_ENABLE, '1' );
		$user->saveSettings();
		$this->assertTrue( Achievement::isAchievementBadgesAvailable( $user ),
			'A user which enables AB can use AB where wiki uses AB as a beta feature' );
	}

	private function assertLogging( $user, $key, $num = null ) {
		$logs = [];
		if ( $num !== null ) {
			$params = [
				'4::key' => $key,
			];
			for ( $i = 0; $i < $num; $i++ ) {
				$logs[] = [
					Constants::LOG_TYPE,
					$key,
					serialize( array_merge( $params,
						[ '5::index' => $i ] ) )
				];
			}
		} else {
			$logs[] = [
				Constants::LOG_TYPE,
				$key,
				serialize( [ '4::key' => $key, ] )
			];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$this->assertSelect(
			'logging',
			[ 'log_type', 'log_action', 'log_params' ],
			[
				'log_type' => Constants::LOG_TYPE,
				'log_action' => $key,
				'log_actor' => $user->getActorId(),
				$dbr->bitAnd( 'log_deleted', LogPage::DELETED_ACTION | LogPage::DELETED_USER ) . ' = 0 ',
			],
			$logs
		);
	}

	/** @return array */
	public static function provideStatsAchievements() {
		return [
			[
				// key
				'test-achievement-first',
				// thresholds
				[ 1, 10, 100 ],
				[
					// stats, expected earned number
					[ 1, 1 ],
					[ 2, 1 ],
					[ 9, 1 ],
					[ 10, 2 ],
					[ 100, 3 ],
				]
			],
			[
				'test-achievement-second',
				[ 1, 3 ],
				[
					[ 0, 0 ],
					[ 1, 1 ],
					[ 2, 1 ],
					[ 3, 2 ],
				]
			],
			[
				'test-achievement-third',
				[ 1, 10, 100 ],
				[
					[ 30, 2 ],
					[ 31, 2 ],
					[ 32, 2 ],
				]
			],
		];
	}

	/**
	 * @dataProvider provideStatsAchievements
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::sendStats
	 *
	 * @param string $key
	 * @param int[] $thresholds
	 * @param array $testSets
	 */
	public function testSendStats( $key, $thresholds, $testSets ) {
		$this->setMwGlobals( 'wg' . Constants::CONFIG_KEY_ACHIEVEMENTS, [
			$key => [
				'type' => 'stats',
				'thresholds' => $thresholds,
			]
		] );
		$user = $this->getTestUser()->getUser();

		foreach ( $testSets as $set ) {
			list( $stats, $expected ) = $set;
			Achievement::sendStats( [
				'key' => $key,
				'user' => $user,
				'stats' => $stats,
			] );
			$this->assertLogging( $user, $key, $expected );
		}
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::sendStats
	 */
	public function testSendStatsWithKey() {
		$user = $this->getTestUser()->getUser();

		$this->expectException( MWException::class,
			'Calls to sendStats without key should throw exception' );
		Achievement::sendStats( [ 'user' => $user, 'stats' => 1 ] );
	}

	/**
	 * @return array
	 */
	public static function provideIconPaths() {
		return [
			[ 'en', '/path/to/icon.svg', '/path/to/icon.svg' ],
			[
				'en',
				[
					'en' => '/path/to/icon.svg',
					'ko' => '/path/to/icon-ko.svg',
					'ru' => '/path/to/icon-ru.svg',
				],
				'/path/to/icon.svg'
			],
			[
				'ko',
				[
					'en' => '/path/to/icon.svg',
					'ko' => '/path/to/icon-ko.svg',
					'ru' => '/path/to/icon-ru.svg',
				],
				'/path/to/icon-ko.svg'
			],
			[
				'he',
				[
					'en' => '/path/to/icon.svg',
					'ko' => '/path/to/icon-ko.svg',
					'rtl' => '/path/to/icon-rtl.svg',
				],
				'/path/to/icon-rtl.svg'
			],
		];
	}

	/**
	 * @dataProvider provideIconPaths
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getAchievementIcon()
	 *
	 * @param string $langCode
	 * @param string|array $path
	 * @param string $fallback
	 */
	public function testGetAchievementIcon( $langCode, $path, $expected ) {
		$lang = Language::factory( $langCode );
		$this->assertEquals( $expected, Achievement::getAchievementIcon( $lang, $path ),
			"Should be $expected" );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getAchievementIcon()
	 */
	public function testAchievementIconFallback() {
		$lang = Language::factory( 'en' );
		$this->setMwGlobals( 'wg' . Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_ICON, 'foo/bar.png' );
		$this->assertEquals( 'foo/bar.png', Achievement::getAchievementIcon( $lang ),
			'A call without any parameter falls back' );
	}
}