<?php
namespace Worklane\Installer\Console;

use GuzzleHttp\Client;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

class NewCommand extends Command {

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure() {
        $this->setName('new')
            ->setDescription('Create new wordpress work')
            ->addArgument('url', InputArgument::REQUIRED)
            ->addArgument('title', InputArgument::REQUIRED)
            ->addArgument('db_name', InputArgument::REQUIRED)
            ->addArgument('db_user', InputArgument::REQUIRED)
            ->addArgument('db_password', InputArgument::REQUIRED)
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');;
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) {

        if (! extension_loaded('zip')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $name = $input->getArgument('name');

        $siteUrl = $input->getArgument('url');
        $siteTitle = $input->getArgument('title');

        $DBName = $input->getArgument('db_name');
        $DBUser = $input->getArgument('db_user');
        $DBPassword = $input->getArgument('db_password');

        $directory = $name && $name !== '.' ? getcwd().'/'.$name : getcwd();

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        $filesystem = new Filesystem();

        try {
            $filesystem->mkdir( $directory );
        } catch( IOExceptionInterface $exception ) {
            $output->writeln('An error occurred while creating your directory at ' . $exception->getPath());
        }

        $output->writeln(sprintf('<info>Directory %s succesfully  created</info>', $directory));

        $output->writeln('<info>Crafting website...</info>');

        /** START OF DOWNLOADING PROJECT */

        $composer = $this->findComposer();

        $commands = [
            $composer . " create-project paperplane/dev-wordpress . --repository='". '{"type": "gitlab", "url": "https://gitlab.com/paper-plane/dev-wordpress.git"}'. "'",
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value.' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                return $value.' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        /** END OF DOWNLOADING PROJECT */

        /** START OF CREATING CONFIG */

        $output->writeln('<comment>Creating config...</comment>');

        $filesystem->copy( $directory . '/example-dynamic-config.php', $directory . '/local-config.php');

        $content = file_get_contents( $directory . '/example-dynamic-config.php' );

        $content = str_replace( "DATABASE_NAME", $DBName, $content );

        $content = str_replace( "DATABASE_USER", $DBUser, $content );

        $content = str_replace( "DATABASE_PASSWORD", $DBPassword, $content );

        $filesystem->dumpFile( $directory . '/local-config.php', $content );

        $output->writeln('<info>Config done!</info>');

        /** END OF CREATING CONFIG */

        /** START OF INSTALL WORDPPRESS */

        $output->writeln('<comment>Installing to database...</comment>');

        $install = Process::fromShellCommandline('wp core install --url='. $siteUrl .' --title='. $siteTitle .' --admin_user=paperplane --admin_password=shrimp@909 --admin_email=developer@paperplane.id', $directory, null, null, null);

        $install->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Installing done!</info>');

        /** END OF INSTALL WORDPRESS */

        /** START OF ACTIVATING THEME & PLUGINS */

        $output->writeln('<comment>Activating theme & plugins...</comment>');

        $activating = Process::fromShellCommandline('wp theme activate gragas && wp plugin activate elementor && wp plugin activate contact-form-7', $directory, null, null, null);

        $activating->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Activating done!</info>');

        /** END OF ACTIVATING */

        /** START OF GENERATING NEW SALT */

        $output->writeln('<comment>Generating new salts...</comment>');

        $activating = Process::fromShellCommandline('wp config shuffle-salts', $directory, null, null, null);

        $activating->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Generating done!</info>');

        /** END OF GENERATING */

        if ($process->isSuccessful()) {
            $output->writeln('<comment>Project ready! Build something amazing.</comment>');
            $output->writeln('<info>Admin information:</info>');
            $output->writeln('<info>Username: paperplane</info>');
            $output->writeln('<info>Password: shrimp@909</info>');
        }

        return 0;
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Project already exists!');
        }
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('dev')) {
            return 'develop';
        }

        if ($input->getOption('auth')) {
            return 'auth';
        }

        return 'master';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composerPath = getcwd().'/composer.phar';

        if (file_exists($composerPath)) {
            return '"'.PHP_BINARY.'" '.$composerPath;
        }

        return 'composer';
    }

    protected function findWpCli() {
        return 'wp-cli';
    }
}
