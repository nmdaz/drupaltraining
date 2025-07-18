<?php

declare(strict_types=1);

namespace Drupal\KernelTests\Scripts;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\Database\Database;
use Drupal\Core\Test\TestDatabase;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

// cspell:ignore htkey

/**
 * Tests core/scripts/test-site.php.
 *
 * This test uses the Drupal\Core\Database\Database class which has a static,
 * and the CI database services. Therefore it is defined as KernelTest so that
 * it can also run in a separate process to avoid side effects.
 *
 * @see \Drupal\TestSite\TestSiteApplication
 * @see \Drupal\TestSite\Commands\TestSiteInstallCommand
 * @see \Drupal\TestSite\Commands\TestSiteTearDownCommand
 *
 * @group Setup
 * @group #slow
 * @preserveGlobalState disabled
 */
class TestSiteApplicationTest extends KernelTestBase {

  /**
   * The PHP executable path.
   *
   * @var string
   */
  protected $php;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $php_executable_finder = new PhpExecutableFinder();
    $this->php = $php_executable_finder->find();
  }

  /**
   * @coversNothing
   */
  public function testInstallWithNonExistingFile(): void {
    $command_line = $this->php . ' core/scripts/test-site.php install --setup-file "this-class-does-not-exist" --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->run();

    $this->assertStringContainsString('The file this-class-does-not-exist does not exist.', $process->getErrorOutput());
  }

  /**
   * @coversNothing
   */
  public function testInstallWithFileWithNoClass(): void {
    $command_line = $this->php . ' core/scripts/test-site.php install --setup-file core/tests/fixtures/empty_file.php.module --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->run();

    $this->assertStringContainsString('The file core/tests/fixtures/empty_file.php.module does not contain a class', $process->getErrorOutput());
  }

  /**
   * @coversNothing
   */
  public function testInstallWithNonSetupClass(): void {
    $this->markTestIncomplete('Fix this test in https://www.drupal.org/project/drupal/issues/2962157.');

    // Use __FILE__ to test absolute paths.
    $command_line = $this->php . ' core/scripts/test-site.php install --setup-file "' . __FILE__ . '" --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root, ['COLUMNS' => PHP_INT_MAX]);
    $process->run();

    $this->assertStringContainsString('The class Drupal\KernelTests\Scripts\TestSiteApplicationTest contained in', $process->getErrorOutput());
    $this->assertStringContainsString('needs to implement \Drupal\TestSite\TestSetupInterface', $process->getErrorOutput());
  }

  /**
   * @coversNothing
   */
  public function testInstallScript(): void {
    // Install a site using the JSON output.
    $command_line = $this->php . ' core/scripts/test-site.php install --json --setup-file core/tests/Drupal/TestSite/TestSiteInstallTestScript.php --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();

    $this->assertSame(0, $process->getExitCode());
    $result = json_decode($process->getOutput(), TRUE);
    $db_prefix = $result['db_prefix'];
    $this->assertStringStartsWith('simpletest' . substr($db_prefix, 4) . ':', $result['user_agent']);

    $http_client = new Client();
    $request = (new Request('GET', getenv('SIMPLETEST_BASE_URL') . '/test-page'))
      ->withHeader('User-Agent', trim($result['user_agent']));

    $response = $http_client->send($request);
    // Ensure the test_page_test module got installed.
    $this->assertStringContainsString('Test page | Drupal', (string) $response->getBody());

    // Ensure that there are files and database tables for the tear down command
    // to clean up.
    $key = $this->addTestDatabase($db_prefix);
    $this->assertGreaterThan(0, count(Database::getConnection('default', $key)->schema()->findTables('%')));
    $test_database = new TestDatabase($db_prefix);
    $test_file = $this->root . DIRECTORY_SEPARATOR . $test_database->getTestSitePath() . DIRECTORY_SEPARATOR . '.htkey';
    $this->assertFileExists($test_file);

    // Ensure the lock file exists.
    $this->assertFileExists($this->getTestLockFile($db_prefix));

    // Install another site so we can ensure the tear down command only removes
    // one site at a time. Use the regular output.
    $command_line = $this->php . ' core/scripts/test-site.php install --setup-file core/tests/Drupal/TestSite/TestSiteInstallTestScript.php --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();
    $this->assertStringContainsString('Successfully installed a test site', $process->getOutput());
    $this->assertSame(0, $process->getExitCode());
    $regex = '/Database prefix\s+([^\s]*)/';
    $this->assertMatchesRegularExpression($regex, $process->getOutput());
    preg_match('/Database prefix\s+([^\s]*)/', $process->getOutput(), $matches);
    $other_db_prefix = $matches[1];
    $other_key = $this->addTestDatabase($other_db_prefix);
    $this->assertGreaterThan(0, count(Database::getConnection('default', $other_key)->schema()->findTables('%')));

    // Ensure the lock file exists for the new install.
    $this->assertFileExists($this->getTestLockFile($other_db_prefix));

    // Now test the tear down process as well, but keep the lock.
    $command_line = $this->php . ' core/scripts/test-site.php tear-down ' . $db_prefix . ' --keep-lock --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();
    $this->assertSame(0, $process->getExitCode());
    $this->assertStringContainsString("Successfully uninstalled $db_prefix test site", $process->getOutput());

    // Ensure that all the tables and files for this DB prefix are gone.
    $this->assertCount(0, Database::getConnection('default', $key)->schema()->findTables('%'));
    $this->assertFileDoesNotExist($test_file);

    // Ensure the other site's tables and files still exist.
    $this->assertGreaterThan(0, count(Database::getConnection('default', $other_key)->schema()->findTables('%')));
    $test_database = new TestDatabase($other_db_prefix);
    $test_file = $this->root . DIRECTORY_SEPARATOR . $test_database->getTestSitePath() . DIRECTORY_SEPARATOR . '.htkey';
    $this->assertFileExists($test_file);

    // Tear down the other site. Tear down should work if the test site is
    // broken. Prove this by removing its settings.php.
    $test_site_settings = $this->root . DIRECTORY_SEPARATOR . $test_database->getTestSitePath() . DIRECTORY_SEPARATOR . 'settings.php';
    $this->assertTrue(unlink($test_site_settings));
    $command_line = $this->php . ' core/scripts/test-site.php tear-down ' . $other_db_prefix . ' --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();
    $this->assertSame(0, $process->getExitCode());
    $this->assertStringContainsString("Successfully uninstalled $other_db_prefix test site", $process->getOutput());

    // Ensure that all the tables and files for this DB prefix are gone.
    $this->assertCount(0, Database::getConnection('default', $other_key)->schema()->findTables('%'));
    $this->assertFileDoesNotExist($test_file);

    // The lock for the first site should still exist but the second site's lock
    // is released during tear down.
    $this->assertFileExists($this->getTestLockFile($db_prefix));
    $this->assertFileDoesNotExist($this->getTestLockFile($other_db_prefix));
  }

  /**
   * @coversNothing
   */
  public function testInstallInDifferentLanguage(): void {
    $command_line = $this->php . ' core/scripts/test-site.php install --json --langcode fr --setup-file core/tests/Drupal/TestSite/TestSiteMultilingualInstallTestScript.php --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->setTimeout(500);
    $process->run();
    $this->assertEquals(0, $process->getExitCode());

    $result = json_decode($process->getOutput(), TRUE);
    $db_prefix = $result['db_prefix'];
    $http_client = new Client();
    $request = (new Request('GET', getenv('SIMPLETEST_BASE_URL') . '/test-page'))
      ->withHeader('User-Agent', trim($result['user_agent']));

    $response = $http_client->send($request);
    // Ensure the test_page_test module got installed.
    $this->assertStringContainsString('Test page | Drupal', (string) $response->getBody());
    $this->assertStringContainsString('lang="fr"', (string) $response->getBody());

    // Now test the tear down process as well.
    $command_line = $this->php . ' core/scripts/test-site.php tear-down ' . $db_prefix . ' --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->setTimeout(500);
    $process->run();
    $this->assertSame(0, $process->getExitCode());

    // Ensure that all the tables for this DB prefix are gone.
    $this->assertCount(0, Database::getConnection('default', $this->addTestDatabase($db_prefix))->schema()->findTables('%'));
  }

  /**
   * @coversNothing
   */
  public function testTearDownDbPrefixValidation(): void {
    $command_line = $this->php . ' core/scripts/test-site.php tear-down not-a-valid-prefix';
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->setTimeout(500);
    $process->run();
    $this->assertSame(1, $process->getExitCode());
    $this->assertStringContainsString('Invalid database prefix: not-a-valid-prefix', $process->getErrorOutput());
  }

  /**
   * @coversNothing
   */
  public function testUserLogin(): void {
    $this->markTestIncomplete('Fix this test in https://www.drupal.org/project/drupal/issues/2962157.');

    // Install a site using the JSON output.
    $command_line = $this->php . ' core/scripts/test-site.php install --json --setup-file core/tests/Drupal/TestSite/TestSiteInstallTestScript.php --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();

    $this->assertSame(0, $process->getExitCode());
    $result = json_decode($process->getOutput(), TRUE);
    $db_prefix = $result['db_prefix'];
    $site_path = $result['site_path'];
    $this->assertSame('sites/simpletest/' . str_replace('test', '', $db_prefix), $site_path);

    // Test the user login command with valid uid.
    $command_line = $this->php . ' core/scripts/test-site.php user-login 1 --site-path ' . $site_path;
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->run();
    $this->assertSame(0, $process->getExitCode());
    $this->assertStringContainsString('/user/reset/1/', $process->getOutput());

    $http_client = new Client();
    $request = (new Request('GET', getenv('SIMPLETEST_BASE_URL') . trim($process->getOutput())))
      ->withHeader('User-Agent', trim($result['user_agent']));

    $response = $http_client->send($request);

    // Ensure the response sets a new session.
    $this->assertTrue($response->getHeader('Set-Cookie'));

    // Test the user login command with invalid uid.
    $command_line = $this->php . ' core/scripts/test-site.php user-login invalid-uid --site-path ' . $site_path;
    $process = Process::fromShellCommandline($command_line, $this->root);
    $process->run();
    $this->assertSame(1, $process->getExitCode());
    $this->assertStringContainsString('The "uid" argument needs to be an integer, but it is "invalid-uid".', $process->getErrorOutput());

    // Now tear down the test site.
    $command_line = $this->php . ' core/scripts/test-site.php tear-down ' . $db_prefix . ' --db-url "' . getenv('SIMPLETEST_DB') . '"';
    $process = Process::fromShellCommandline($command_line, $this->root);
    // Set the timeout to a value that allows debugging.
    $process->setTimeout(500);
    $process->run();
    $this->assertSame(0, $process->getExitCode());
    $this->assertStringContainsString("Successfully uninstalled $db_prefix test site", $process->getOutput());
  }

  /**
   * Adds the installed test site to the database connection info.
   *
   * @param string $db_prefix
   *   The prefix of the installed test site.
   *
   * @return string
   *   The database key of the added connection.
   */
  protected function addTestDatabase($db_prefix): string {
    $database = Database::convertDbUrlToConnectionInfo(getenv('SIMPLETEST_DB'));
    $database['prefix'] = $db_prefix;
    $target = __CLASS__ . $db_prefix;
    Database::addConnectionInfo($target, 'default', $database);
    return $target;
  }

  /**
   * Gets the lock file path.
   *
   * @param string $db_prefix
   *   The prefix of the installed test site.
   *
   * @return string
   *   The lock file path.
   */
  protected function getTestLockFile($db_prefix): string {
    $lock_id = str_replace('test', '', $db_prefix);
    return FileSystem::getOsTemporaryDirectory() . '/test_' . $lock_id;
  }

}
