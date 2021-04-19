<?php
namespace Craft\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Create a new S. Group Craft project')
            ->addArgument('name', InputArgument::OPTIONAL, 'The directory to install to', '.')
            ->addOption('branch', null, InputOption::VALUE_NONE, 'Installs the latest branch')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $text = <<<EOD
                      _                                 _           
   ___ _ __ ___  __ _| |_ ___  __      _____  _ __   __| | ___ _ __ 
  / __| '__/ _ \/ _` | __/ _ \ \ \ /\ / / _ \| '_ \ / _` |/ _ \ '__|
 | (__| | |  __/ (_| | ||  __/  \ V  V / (_) | | | | (_| |  __/ |   
  \___|_|  \___|\__,_|\__\___|   \_/\_/ \___/|_| |_|\__,_|\___|_|   
EOD;

        $output->write(str_replace("\n", PHP_EOL, $text) . PHP_EOL);
        $output->write(PHP_EOL . '<fg=magenta>Preparing to create wonder...</>' . PHP_EOL . PHP_EOL);

        sleep(1);

        $name = $input->getArgument('name');
        $directory = $name === '.' ? '.' : getcwd() . '/' . $name;

        $version = $this->getVersion($input);
        $composer = $this->findComposer();

        $commands = [];

        // Check if we're forcing the install
        if ($input->getOption('force')) {
            if ($directory === '.') {
                // Remove all files in folder, except `.git`
                $commands[] = "find . -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +";
            } else {
                $commands[] = "rm -rf \"$directory\"";
            }
        }

        // Setup temp directory to allow installing at `.`
        $tempDirectory = $directory === '.' ? './temp' : $directory;

        // Add our private repo to composer, globally - automatically
        $commands[] = $composer . ' --global config repositories.sgroup composer https://composer.sgroup.com.au';

        // Create a project via composer, using the base-craft3 repo
        $commands[] = $composer . " create-project sgroup/base-craft \"$tempDirectory\" $version -sdev --prefer-dist";

        // If we're installing at `.`, move from temp directory into root before proceeding
        if ($directory === '.') {
            $commands[] = "mv temp/* temp/.[^.]* .";
            $commands[] = "rm -rf temp/";
        }

        // Cleanup and general prep, now its installed
        $commands[] = "cd \"$directory\"";
        $commands[] = "cp .env.example .env";
        $commands[] = "rm composer.json";
        $commands[] = "mv composer.json.default composer.json";
        $commands[] = "composer dump-autoload -o";
        $commands[] = "chmod +x craft";
        $commands[] = "./craft site-scripts/setup";

        // Final update, now that Craft is installed. We have to lock at versions due to project config.
        $commands[] = 'composer update';

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            $output->writeln(PHP_EOL . '<comment>Craft is ready! Go create wonder.</comment>');
        }

        return $process->getExitCode();
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        if ($input->getOption('branch')) {
            return $input->getOption('branch');
        }

        return 'dev-master';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composerPath = getcwd() . '/composer.phar';

        if (file_exists($composerPath)) {
            return '"' . PHP_BINARY . '" ' . $composerPath;
        }

        return 'composer';
    }

    /**
     * Run the given commands.
     *
     * @param  array  $commands
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $env
     * @return \Symfony\Component\Process\Process
     */
    protected function runCommands($commands, InputInterface $input, OutputInterface $output, array $env = [])
    {
        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value . ' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), null, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: ' . $e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    ' . $line);
        });

        return $process;
    }
}