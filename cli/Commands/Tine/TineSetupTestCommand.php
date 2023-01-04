<?php

namespace App\Commands\Tine;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\ConsoleStyle;
use App\Commands\Tine\TineCommand;

class TineSetupTestCommand extends TineCommand{
    
    protected function configure() {
        parent::configure();

        $this
            ->setName('tine:setuptest')
            ->setDescription('starts setup test')
            ->addUsage('AllTests')
            ->addUsage('Addressbook/Frontend/JsonTest -f testGetAllContacts')
            ->setHelp('')
            ->addArgument(
                'path', 
                InputArgument::REQUIRED | InputArgument::IS_ARRAY, 
                'the path for the tests')
            ->addOption(
                'stopOnFailure',
                's',
                InputOption::VALUE_NONE,
                'stop on failure'
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'excludes group'
            )
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED,
                'sets filters'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new ConsoleStyle($input, $output);
        $paths = $input->getArgument('path');
        $this->initCompose();
        
        if ($input->getOption('stopOnFailure')) {
            $stopOnFailure = true;
        } else {
            $stopOnFailure = false;
        }

        foreach($paths as $path) {
            system(
                $this->getComposeString()
                . " exec -T --user tine20 web sh -c \"cd /usr/share/tests/setup/ && php -d include_path=.:/etc/tine20/ /usr/share/tine20/vendor/bin/phpunit --color --debug "
                . ($stopOnFailure === true ? ' --stop-on-failure ' : '')
                . (!empty($input->getOption('exclude')) ? ' --exclude ' . implode(",", $input->getOption('exclude')) . " ": "")
                . (!empty($input->getOption('filter')) ? ' --filter ' . $input->getOption('filter') . " ": "")
                . $path
                . "\""
                . ' 2>&1', $result_code
            );
        }
        
        if ($result_code === 0) {
            $io->success("There were 0 errors");
            return Command::SUCCESS;
        } else {
            $io->error('TESTS FAILED');
            return Command::FAILURE;
        }
    }
}
