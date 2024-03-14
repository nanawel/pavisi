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

    protected const TERMINAL_DEFAULT_WIDTH = 120;
    protected const PROGRESSBAR_FILENAME_LENGTH_MAX_PCT = 75;

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

        $this->voskFileProcessor->addListener(function (Event $ev) use ($output, $dryRunMode) {
            if ($ev->name === 'fileprocessor::finishing') {
                $output->writeln('<info>Run complete.</info>');
                $output->writeln(sprintf(
                    '<info>%d file(s) have been processed.</info>',
                    count($ev->payload['results'])
                ));
                $output->writeln(sprintf(
                    '<info>%d file(s) have been skipped.</info>',
                    $ev->payload['skipped_cnt']
                ));
                if (!empty($ev->payload['exceptions'])) {
                    $output->writeln(printf(
                        '<warn>%d error(s) have been encountered.</warn>',
                        count($ev->payload['exceptions'])
                    ));
                }
                if ($dryRunMode) {
                    $output->writeln('<warn>DRY-RUN ENABLED. No file has actually been processed.</warn>');
                }
            }
        });

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
                    $this->trimFilePathForProgressBar($ev->payload['file']->getRelativePathname())
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
                $progressBar->setMessage($this->trimFilePathForProgressBar($ev->payload['filepath']));
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

    protected function trimFilePathForProgressBar($filepath) {
        $termWidth = (getenv('COLUMNS') ?: self::TERMINAL_DEFAULT_WIDTH);
        $maxLength = min(
            (int) $termWidth * self::PROGRESSBAR_FILENAME_LENGTH_MAX_PCT / 100, // Percent allowed
            max($termWidth - 32, 15) // Ensure we leave enaough space for the actual progress bar
        );
        if (mb_strlen($filepath) > $maxLength) {
            return sprintf('...%s', mb_substr($filepath, -($maxLength + 4)));
        }
        return $filepath;
    }
}
