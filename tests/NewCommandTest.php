<?php
namespace Worklane\Installer\Console\Test;

use Worklane\Installer\Console\NewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class NewCommandTest extends TestCase {

    public function test_it_can_scaffold_a_new_laravel_app() {
        $scaffoldDirectoryName = 'tests-output/my-app';
        $scaffoldDirectory = __DIR__.'/../'.$scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            (new Filesystem)->remove($scaffoldDirectory);
        }

        $app = new Application('Wordpress Project Installer');
        $app->add(new NewCommand);

        $tester = new CommandTester($app->find('new'));

        $statusCode = $tester->execute([
            'url' => 'test.test',
            'title' => '"Testing Ajah"',
            'db_name' => 'test',
            'db_user' => 'test',
            'db_password' => 'test',
            'name' => $scaffoldDirectoryName,
            '--auth' => null
        ]);

        $this->assertEquals($statusCode, 0);
        $this->assertDirectoryExists($scaffoldDirectory.'/vendor');
        $this->assertFileExists($scaffoldDirectory.'/local-config.php');
    }

}
