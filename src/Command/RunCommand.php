<?php

namespace App\Command;

use App\Constants;
use App\Model\VoskFileProcessor\Event;
use App\Service\FileFinderBuilder;
use App\Service\VoskFileProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    public const ARG_FOLDER = 'folder';
    public const OPT_DRYRUN = 'dry-run';
    public const OPT_EXCLUDE = 'exclude';
    public const OPT_INCLUDE = 'include';
    public const OPT_PROGRESS = 'progress';

    public function __construct(
        protected FileFinderBuilder $fileFinderProcessor,
        protected VoskFileProcessor $voskFileProcessor,
    ) {
        parent::__construct('app:run');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run!')
            ->addArgument(
                self::ARG_FOLDER,
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'The target folder(s) containing the files to index.'
            )
            ->addOption(
                self::OPT_EXCLUDE,
                'E',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Excluded path(s)'
            )
            ->addOption(
                self::OPT_INCLUDE,
                'I',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Included path(s)'
            )
            ->addOption(
                self::OPT_DRYRUN,
                'N',
                InputOption::VALUE_OPTIONAL,
                'Dry-run (0: disabled, 1: success, 2: failure)',
                Constants::DRY_RUN_MODE_DISABLED
            )
            ->addOption(
                self::OPT_PROGRESS,
                'p',
                InputOption::VALUE_REQUIRED,
                'Show progress (0: disabled, 1: simple, 2: two-pass) Notice: needs to count files first.',
                Constants::PROGRESS_MODE_DISABLED
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finder = $this->fileFinderProcessor
            ->addFolders($input->getArgument(self::ARG_FOLDER))
            ->addExclude($input->getOption(self::OPT_EXCLUDE))
            ->addInclude($input->getOption(self::OPT_INCLUDE))
            ->build();

        if (!in_array($dryRunMode = (int) $input->getOption(self::OPT_DRYRUN), Constants::DRY_RUN_MODES)) {
            throw new InvalidOptionException("Invalid dry-run mode: $dryRunMode");
        }
        if (!in_array($progressMode = (int) $input->getOption(self::OPT_PROGRESS), Constants::PROGRESS_MODES)) {
            throw new InvalidOptionException("Invalid dry-run mode: $dryRunMode");
        }

        $output->writeln("Collecting files to process (it may take some time)...");

        if ($input->getOption(self::OPT_PROGRESS)) {
            $this->setupProgressBar($output);
        }

        $this->voskFileProcessor->run(
            $finder,
            [
                'dry-run' => $dryRunMode,
                'progress' => $progressMode
            ]
        );

        return Command::SUCCESS;
    }

    protected function setupProgressBar(OutputInterface $output): void {
        $progressBar = new ProgressBar($output);

        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'filecollector::init') {
                $progressBar->setMessage('Starting...');
                $progressBar->start();
            }
        });
        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'filecollector::calculating::start') {
                $progressBar->setFormat(
                    "%current% [%bar%] %memory:6s% %message%"
                );
            }
        });
        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'filecollector::calculating::found_file') {
                $progressBar->advance();
                $progressBar->setMessage(sprintf(
                    '%d/%d %s',
                    $ev->payload['files_count'],
                    $ev->payload['files_total'],
                    $ev->payload['file']->getRelativePathname()
                ));
            }
        });
        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'filecollector::calculating::done') {
                $progressBar->setProgress($ev->payload['files_total']);
                $progressBar->display();
                $progressBar->finish();
                $progressBar->setFormat(
                    "%current%/%max% [%bar%] %elapsed:6s%/%estimated:-6s% %memory:6s% %message%"
                );
                $progressBar->start($ev->payload['files_count']);
                $progressBar->display();
            }
        });
        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'fileprocessor::file::processed') {
                $progressBar->setMessage($ev->payload['filepath']);
                if ($ev->payload['is_complete'] && $ev->payload['status'] !== 'skipped') {
                    $progressBar->advance();
                }
            }
        });
        $this->voskFileProcessor->addListener(function (Event $ev) use ($progressBar) {
            if ($ev->name === 'fileprocessor::finishing') {
                $progressBar->finish();
            }
        });
    }
}
