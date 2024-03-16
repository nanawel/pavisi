<?php

namespace App\Command;

use App\Constants;
use App\Model\VoskFileProcessor\Event;
use App\Service\ElasticsearchFileIndexer;
use App\Service\FileFinderBuilder;
use App\Service\VoskFileProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteEmptyDocs extends Command
{
    public function __construct(
        protected ElasticsearchFileIndexer $elasticsearchFileIndexer,
    ) {
        parent::__construct('app:index:delete-empty-docs');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete documents from ES with an empty text')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $confirm = false;
        if ($input->getOption('no-interaction')) {
            $confirm = true;
        } else {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "Are you really sure?\nThis action will delete documents from the index and cannot be reverted!> ",
                false
            );
            if ($helper->ask($input, $output, $question)) {
                $confirm = true;
            }
        }

        if ($confirm) {
            try {
                $this->elasticsearchFileIndexer->deleteEmptyDocs();
            } catch (\Throwable $e) {
                if (method_exists($e, 'getData')) {
                    $output->writeln('<error>' . json_encode($e->getData()) . '</error>');
                }
                throw $e;
            }
            $output->writeln('The documents have been deleted.');
        }

        return Command::SUCCESS;
    }
}
