<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test;

use Composer\Installer;
use Composer\Console\Application;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\InstalledArrayRepository;
use Composer\Package\RootPackageInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Test\Mock\FactoryWithTestReposMock;
use Composer\Test\Mock\InstalledFilesystemRepositoryMock;
use Composer\Test\Mock\InstallationManagerMock;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;
use Composer\TestCase;

class InstallerWithCustomReposTest extends TestCase
{
    protected $prevCwd;

    public function setUp()
    {
        $this->prevCwd = getcwd();
        chdir(__DIR__);
    }

    public function tearDown()
    {
        chdir($this->prevCwd);
    }

    /**
     * @dataProvider getIntegrationTests
     */
    public function testIntegration($file, $message, $condition, $composerConfig, $lock, $installed, $run, $expectLock, $expectOutput, $expect, $expectExitCode)
    {
        if ($condition) {
            eval('$res = '.$condition.';');
            if (!$res) {
                $this->markTestSkipped($condition);
            }
        }

        $output = null;
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function ($text, $newline) use (&$output) {
                $output .= $text . ($newline ? "\n":"");
            }));

        $composer = FactoryWithTestReposMock::create($io, $composerConfig);
        // coping provider cache files in 'home' folder
        $composerHomeDir  = $composer->getConfig()->get("home");
        $composerCacheDir = $composerHomeDir.DIRECTORY_SEPARATOR."cache";
        if (is_dir($composerCacheDir)) {
            $this->rmDirRecursive($composerCacheDir);
        }
        $fixturesDir = realpath(__DIR__.'/Fixtures/installer_with_test_repos/');
        // recursively copy caches
        $this->copyRecursive($fixturesDir.DIRECTORY_SEPARATOR."cache", $composerCacheDir);

        $jsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $jsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($installed));
        $jsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        $repositoryManager = $composer->getRepositoryManager();
        $repositoryManager->setLocalRepository(new InstalledFilesystemRepositoryMock($jsonMock));

        $lockJsonMock = $this->getMockBuilder('Composer\Json\JsonFile')->disableOriginalConstructor()->getMock();
        $lockJsonMock->expects($this->any())
            ->method('read')
            ->will($this->returnValue($lock));
        $lockJsonMock->expects($this->any())
            ->method('exists')
            ->will($this->returnValue(true));

        if ($expectLock) {
            $actualLock = array();
            $lockJsonMock->expects($this->atLeastOnce())
                ->method('write')
                ->will($this->returnCallback(function ($hash, $options) use (&$actualLock) {
                    // need to do assertion outside of mock for nice phpunit output
                    // so store value temporarily in reference for later assetion
                    $actualLock = $hash;
                }));
        }

        $locker = new Locker($io, $lockJsonMock, $repositoryManager, $composer->getInstallationManager(), md5(json_encode($composerConfig)));
        $composer->setLocker($locker);

        $eventDispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $autoloadGenerator = $this->getMock('Composer\Autoload\AutoloadGenerator', array(), array($eventDispatcher));
        $composer->setAutoloadGenerator($autoloadGenerator);
        $composer->setEventDispatcher($eventDispatcher);

        $installer = Installer::create(
            $io,
            $composer
        );

        $application = new Application;
        $application->get('install')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode(!$input->getOption('no-dev'))
                ->setDryRun($input->getOption('dry-run'));

            return $installer->run();
        });

        $application->get('update')->setCode(function ($input, $output) use ($installer) {
            $installer
                ->setDevMode(!$input->getOption('no-dev'))
                ->setUpdate(true)
                ->setDryRun($input->getOption('dry-run'))
                ->setUpdateWhitelist($input->getArgument('packages'))
                ->setWhitelistDependencies($input->getOption('with-dependencies'));

            return $installer->run();
        });

        if (!preg_match('{^(install|update)\b}', $run)) {
            throw new \UnexpectedValueException('The run command only supports install and update');
        }

        $application->setAutoExit(false);
        $appOutput = fopen('php://memory', 'w+');
        $result = $application->run(new StringInput($run), new StreamOutput($appOutput));
        fseek($appOutput, 0);
        $this->assertEquals($expectExitCode, $result, $output . stream_get_contents($appOutput));

        if ($expectLock) {
            unset($actualLock['hash']);
            unset($actualLock['_readme']);
            $this->assertEquals($expectLock, $actualLock);
        }

        $installationManager = $composer->getInstallationManager();
        $this->assertSame($expect, implode("\n", $installationManager->getTrace()));

        if ($expectOutput) {
            $this->assertEquals($expectOutput, $output);
        }
    }

    protected function copyRecursive($from, $to)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $from,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                mkdir($to . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            } else {
                copy($item, $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }

    protected function rmDirRecursive($path)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ), \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
    }

    public function getIntegrationTests()
    {
        $fixturesDir = realpath(__DIR__.'/Fixtures/installer_with_test_repos/');
        $tests = array();

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = file_get_contents($file->getRealpath());

            $content = '(?:.(?!--[A-Z]))+';
            $pattern = '{^
                --TEST--\s*(?P<test>.*?)\s*
                (?:--CONDITION--\s*(?P<condition>'.$content.'))?\s*
                --COMPOSER--\s*(?P<composer>'.$content.')\s*
                (?:--LOCK--\s*(?P<lock>'.$content.'))?\s*
                (?:--INSTALLED--\s*(?P<installed>'.$content.'))?\s*
                --RUN--\s*(?P<run>.*?)\s*
                (?:--EXPECT-LOCK--\s*(?P<expectLock>'.$content.'))?\s*
                (?:--EXPECT-OUTPUT--\s*(?P<expectOutput>'.$content.'))?\s*
                (?:--EXPECT-EXIT-CODE--\s*(?P<expectExitCode>\d+))?\s*
                --EXPECT--\s*(?P<expect>.*?)\s*
            $}xs';

            $installed = array();
            $installedDev = array();
            $lock = array();
            $expectLock = array();
            $expectExitCode = 0;

            if (preg_match($pattern, $test, $match)) {
                try {
                    $message = $match['test'];
                    $condition = !empty($match['condition']) ? $match['condition'] : null;
                    $composer = JsonFile::parseJson($match['composer']);
                    if (!empty($match['lock'])) {
                        $lock = JsonFile::parseJson($match['lock']);
                        if (!isset($lock['hash'])) {
                            $lock['hash'] = md5(json_encode($composer));
                        }
                    }
                    if (!empty($match['installed'])) {
                        $installed = JsonFile::parseJson($match['installed']);
                    }
                    $run = $match['run'];
                    if (!empty($match['expectLock'])) {
                        $expectLock = JsonFile::parseJson($match['expectLock']);
                    }
                    $expectOutput = $match['expectOutput'];
                    $expect = $match['expect'];
                    $expectExitCode = (int) $match['expectExitCode'];
                } catch (\Exception $e) {
                    die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
                }
            } else {
                die(sprintf('Test "%s" is not valid, did not match the expected format.', str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[] = array(str_replace($fixturesDir.'/', '', $file), $message, $condition, $composer, $lock, $installed, $run, $expectLock, $expectOutput, $expect, $expectExitCode);
        }

        return $tests;
    }
}
