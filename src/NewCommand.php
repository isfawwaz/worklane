<?php
namespace Worklane\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class NewCommand extends Command {

    protected $inputName = 'dev-wordpress';

    protected $inputUrl = null;

    protected $inputTitle = null;

    protected $inputDatabaseName = null;

    protected $inputDatabaseUser = null;

    protected $inputDatabasePassword = null;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure() {
        $this->setName('new')
            ->setDescription('Create new wordpress work')
            ->addArgument('name', InputArgument::OPTIONAL, "Folder name", "dev-wordpress")
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Your site url, example: site.test')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Your site title with quotes, example: "Site Title"')
            ->addOption('db_name', 'B', InputOption::VALUE_REQUIRED, 'Your database name')
            ->addOption('db_user', 'U', InputOption::VALUE_REQUIRED, 'Your database username, example: root')
            ->addOption('db_password', 'P', InputOption::VALUE_OPTIONAL, 'Your database password, can be empty')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->inputName = $input->getArgument('name');

        $this->inputUrl = $input->getOption('url');
        $this->inputTitle = addslashes( $input->getOption('title') );

        $this->inputDatabaseName = $input->getOption('db_name');
        $this->inputDatabaseUser = $input->getOption('db_user');
        $this->inputDatabasePassword = $input->getOption('db_password');

        if( empty($this->inputUrl) ) {
            throw new RuntimeException('Please fill out your project site url. Example: --url=site.test');
        }

        if( empty($this->inputTitle) ) {
            throw new RuntimeException('Please fill out your project site title. Example: --title="Site Title"');
        }

        if( empty($this->inputDatabaseName) ) {
            throw new RuntimeException('Please fill out your project database name. Example: --db_name=test');
        }

        if( empty( $this->inputDatabaseUser ) ) {
            throw new RuntimeException('Please fill out your project database user. Example: --db_user=root');
        }

        if( strtolower($this->inputDatabasePassword) == 'null' ) {
            $this->inputDatabasePassword = null;
        }

        // Check composer exists
        if( !$this->verifyCommand('composer') ) {
            throw new RuntimeException('Composer not installed');
        }

        // Check WP-CLI
        if( !$this->verifyCommand('wp') ) {
            throw new RuntimeException('WP-CLI not installed');
        }

        $name = $this->inputName;
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

        $output->writeln('<info>Crafting project...</info>');

        /**
         *  START OF DOWNLOADING PROJECT
         */
        $process = $this->downloadRepository( $directory, $input, $output );

        /**
         * START OF CREATING CONFIG
         */
        $this->createConfig( $directory, $filesystem, $output );

        /**
         * START OF INSTALLING WORDPRESS
         */
        $this->installWordpress( $directory, $output );

        /**
         * START OF ACTIVATING THEME
         */
        $this->activatingTheme( $directory, $output );

        /**
         * START OF GENERATE SHALTS
         */
        $this->generateSalt( $directory, $output );

        if ($process) {
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

    protected function verifyCommand($command) :bool {
        $windows = strpos(PHP_OS, 'WIN') === 0;
        $test = $windows ? 'where' : 'command -v';
        return is_executable(trim(shell_exec("$test $command")));
    }

    protected function downloadRepository( $directory, $input, $output ) {

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

        try {
            $process->mustRun();

            echo $process->getOutput();
        } catch (ProcessFailedException $exception) {
            echo $exception->getMessage();
            die();
        }

        $output->writeln('<info>Crafting done!</info>');
        $this->addDash( $output );

        if ($process->isSuccessful()) {
            return true;
        }

        return false;
    }

    protected function createConfig( $directory, $filesystem, $output ) {

        $output->writeln('<comment>Updating config...</comment>');

        $filesystem->copy( $directory . '/example-dynamic-config.php', $directory . '/local-config.php');

        $content = file_get_contents( $directory . '/example-dynamic-config.php' );

        $content = str_replace( "DATABASE_NAME", $this->inputDatabaseName, $content );

        $content = str_replace( "DATABASE_USER", $this->inputDatabaseUser, $content );

        $content = str_replace( "DATABASE_PASSWORD", $this->inputDatabasePassword, $content );

        $filesystem->dumpFile( $directory . '/local-config.php', $content );

        $output->writeln('<info>Config done!</info>');

        $this->addDash( $output );

    }

    protected function installWordpress( $directory, $output ) {

        $output->writeln('<comment>Installing to database...</comment>');

        $install = Process::fromShellCommandline('wp core install --url='. $this->inputUrl .' --title="'. $this->inputTitle .'" --admin_user=paperplane --admin_password=shrimp@909 --admin_email=developer@paperplane.id', $directory, null, null, null);

        try {
            $install->mustRun();

            echo $install->getOutput();
        } catch (ProcessFailedException $exception) {
            echo $exception->getMessage();
            die();
        }

        $output->writeln('<info>Installing done!</info>');

        $this->addDash( $output );

    }

    protected function activatingTheme( $directory, $output ) {

        $output->writeln('<comment>Activating theme & plugins...</comment>');

        $activating = Process::fromShellCommandline('wp theme activate gragas && wp plugin activate elementor && wp plugin activate contact-form-7', $directory, null, null, null);

        $activating->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Activating done!</info>');

        $this->addDash( $output );

    }

    protected function generateSalt( $directory, $output ) {

        $output->writeln('<comment>Generating new salts...</comment>');

        $activating = Process::fromShellCommandline('wp config shuffle-salts', $directory, null, null, null);

        $activating->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        $output->writeln('<info>Generating done!</info>');

        $this->addDash( $output );

    }

    protected function addDash( $output ) {
        $output->writeln('----------------------------------------------------------');
    }
}
